<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';

/**
 * Centryk Business subscription billing.
 *
 * All subscriptions bill monthly. An "annual" billing_interval means the
 * customer committed to a year; the monthly charge is price / 12. runCycle()
 * materialises the current month's charges (idempotent); a person clears them
 * from admin-business-billing.php.
 */
class BillingService
{
    /** A subscription flips to past_due once a charge is this many days past due_on. */
    private const PAST_DUE_GRACE_DAYS = 7;

    private static function monthlyAmount(array $sub): float
    {
        $price = (float)$sub['price'];
        return $sub['billing_interval'] === 'annual' ? round($price / 12, 2) : round($price, 2);
    }

    /**
     * Create a charge for every billable subscription for the calendar month
     * containing $asOf (default: today). Skips subscriptions that already have
     * a charge for that period.
     *
     * @return array{created:int, month:string, skipped:int}
     */
    public static function runCycle(?string $asOf = null, ?int $actorId = null): array
    {
        $pdo = DB::pdo();
        $asOf = $asOf && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
        $periodStart = date('Y-m-01', strtotime($asOf));
        $periodEnd   = date('Y-m-t', strtotime($asOf));
        $dueOn       = date('Y-m-d', strtotime($periodStart . ' +14 days'));

        $subs = $pdo->query("
            SELECT id, company_id, package_key, price, currency, billing_interval
            FROM company_subscriptions
            WHERE status IN ('active', 'past_due')
        ")->fetchAll(PDO::FETCH_ASSOC);

        $ins = $pdo->prepare("
            INSERT IGNORE INTO company_subscription_charges
                (subscription_id, company_id, package_key, period_start, period_end, amount, currency, due_on, created_by)
            VALUES (:sid, :cid, :pkg, :ps, :pe, :amt, :cur, :due, :by)
        ");

        $created = 0;
        $skipped = 0;
        foreach ($subs as $s) {
            $amt = self::monthlyAmount($s);
            if ($amt <= 0) { $skipped++; continue; }
            $ins->execute([
                'sid' => $s['id'], 'cid' => $s['company_id'], 'pkg' => $s['package_key'],
                'ps' => $periodStart, 'pe' => $periodEnd, 'amt' => $amt,
                'cur' => $s['currency'] ?: 'BZD', 'due' => $dueOn, 'by' => $actorId,
            ]);
            $ins->rowCount() > 0 ? $created++ : $skipped++;
        }

        Audit::log([
            'actor_user_id' => $actorId,
            'event_type'    => 'billing.cycle.run',
            'summary'       => "Billing run for {$periodStart}: {$created} charge(s) created",
            'metadata'      => ['period_start' => $periodStart, 'created' => $created, 'skipped' => $skipped],
        ]);

        return ['created' => $created, 'skipped' => $skipped, 'month' => $periodStart];
    }

    /**
     * Dunning sweep. Two transitions, both idempotent:
     *   - an 'active' subscription with a 'due' charge more than
     *     PAST_DUE_GRACE_DAYS past its due_on  ->  'past_due'
     *     (Entitlements::syncFromSubscription then drops it to READ)
     *   - a 'past_due' subscription whose charges are all settled
     *     (nothing 'due')  ->  back to 'active'  (entitlement resumes FULL)
     * Revoking outright stays a deliberate admin action.
     *
     * @return array{past_due:int, recovered:int, as_of:string}
     */
    public static function runDunning(?string $asOf = null, ?int $actorId = null): array
    {
        require_once __DIR__ . '/../core/Entitlements.php';
        $pdo  = DB::pdo();
        $asOf = $asOf && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');

        // ── active -> past_due ────────────────────────────────────────────
        $toPastDue = $pdo->prepare("
            SELECT s.id, s.company_id, s.package_key,
                   MIN(sc.due_on) AS oldest_due, SUM(sc.amount) AS owed
            FROM company_subscriptions s
            JOIN company_subscription_charges sc
              ON sc.subscription_id = s.id AND sc.status = 'due'
             AND sc.due_on < DATE_SUB(:asof1, INTERVAL :grace DAY)
            WHERE s.status = 'active'
            GROUP BY s.id, s.company_id, s.package_key
        ");
        $toPastDue->execute(['asof1' => $asOf, 'grace' => self::PAST_DUE_GRACE_DAYS]);
        $pastDue = 0;
        foreach ($toPastDue->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $pdo->prepare("UPDATE company_subscriptions SET status = 'past_due' WHERE id = :id")
                ->execute(['id' => $s['id']]);
            Entitlements::syncFromSubscription((int)$s['id'], $actorId);
            Audit::log([
                'actor_user_id' => $actorId,
                'company_id'    => (int)$s['company_id'],
                'event_type'    => 'billing.subscription.past_due',
                'summary'       => "Subscription #{$s['id']} ({$s['package_key']}) past due — "
                    . number_format((float)$s['owed'], 2) . " owed since {$s['oldest_due']}; access dropped to read-only",
                'metadata'      => ['subscription_id' => (int)$s['id'], 'owed' => round((float)$s['owed'], 2), 'oldest_due' => $s['oldest_due']],
            ]);
            $pastDue++;
        }

        // ── past_due -> active (nothing 'due' left) ───────────────────────
        $toActive = $pdo->query("
            SELECT s.id, s.company_id, s.package_key
            FROM company_subscriptions s
            WHERE s.status = 'past_due'
              AND NOT EXISTS (
                  SELECT 1 FROM company_subscription_charges sc
                  WHERE sc.subscription_id = s.id AND sc.status = 'due'
              )
        ")->fetchAll(PDO::FETCH_ASSOC);
        $recovered = 0;
        foreach ($toActive as $s) {
            $pdo->prepare("UPDATE company_subscriptions SET status = 'active' WHERE id = :id")
                ->execute(['id' => $s['id']]);
            Entitlements::syncFromSubscription((int)$s['id'], $actorId);
            Audit::log([
                'actor_user_id' => $actorId,
                'company_id'    => (int)$s['company_id'],
                'event_type'    => 'billing.subscription.recovered',
                'summary'       => "Subscription #{$s['id']} ({$s['package_key']}) back in good standing — full access restored",
                'metadata'      => ['subscription_id' => (int)$s['id']],
            ]);
            $recovered++;
        }

        Audit::log([
            'actor_user_id' => $actorId,
            'event_type'    => 'billing.dunning.run',
            'summary'       => "Dunning sweep {$asOf}: {$pastDue} to past-due, {$recovered} recovered",
            'metadata'      => ['as_of' => $asOf, 'past_due' => $pastDue, 'recovered' => $recovered],
        ]);

        return ['past_due' => $pastDue, 'recovered' => $recovered, 'as_of' => $asOf];
    }

    public static function summary(): array
    {
        $pdo = DB::pdo();
        $mrr = (float)$pdo->query("
            SELECT COALESCE(SUM(CASE WHEN billing_interval = 'annual' THEN price / 12 ELSE price END), 0)
            FROM company_subscriptions WHERE status IN ('active', 'trialing')
        ")->fetchColumn();

        $month = date('Y-m-01');
        $r = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN period_start = :m1 THEN amount ELSE 0 END), 0)                     AS billed_this_month,
                COALESCE(SUM(CASE WHEN status = 'due' THEN amount ELSE 0 END), 0)                          AS outstanding,
                SUM(status = 'due')                                                                       AS due_count,
                SUM(status = 'due' AND due_on < CURDATE())                                                 AS overdue_count,
                COALESCE(SUM(CASE WHEN status = 'paid' AND paid_on >= :m2 THEN amount ELSE 0 END), 0)      AS collected_this_month
            FROM company_subscription_charges
        ");
        $r->execute(['m1' => $month, 'm2' => $month]);
        $row = $r->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'mrr'                 => round($mrr, 2),
            'billed_this_month'   => round((float)($row['billed_this_month'] ?? 0), 2),
            'outstanding'         => round((float)($row['outstanding'] ?? 0), 2),
            'due_count'           => (int)($row['due_count'] ?? 0),
            'overdue_count'       => (int)($row['overdue_count'] ?? 0),
            'collected_this_month' => round((float)($row['collected_this_month'] ?? 0), 2),
            'current_month'       => $month,
        ];
    }

    public static function charges(array $filters = []): array
    {
        $where = ['1=1'];
        $args  = [];
        $status = $filters['status'] ?? 'due';
        if (in_array($status, ['due', 'paid', 'waived', 'void'], true)) {
            $where[] = 'sc.status = :status';
            $args['status'] = $status;
        }
        if (!empty($filters['company_id'])) {
            $where[] = 'sc.company_id = :cid';
            $args['cid'] = (int)$filters['company_id'];
        }

        $stmt = DB::pdo()->prepare("
            SELECT sc.id, sc.company_id, c.name AS company_name, sc.package_key, bp.label AS package_label,
                   sc.period_start, sc.period_end, sc.amount, sc.currency, sc.status, sc.due_on,
                   sc.paid_on, sc.paid_method, sc.invoice_ref, sc.note,
                   (sc.status = 'due' AND sc.due_on < CURDATE()) AS overdue
            FROM company_subscription_charges sc
            JOIN companies c ON c.id = sc.company_id
            LEFT JOIN business_packages bp ON bp.`key` = sc.package_key
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sc.period_start DESC, c.name ASC
            LIMIT 300
        ");
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function updateCharge(int $chargeId, string $action, array $data, ?int $actorId): void
    {
        $pdo = DB::pdo();
        $c = $pdo->prepare("
            SELECT sc.id, sc.status, sc.amount, sc.company_id, sc.subscription_id, c.name AS company_name
            FROM company_subscription_charges sc JOIN companies c ON c.id = sc.company_id
            WHERE sc.id = :id LIMIT 1
        ");
        $c->execute(['id' => $chargeId]);
        $charge = $c->fetch(PDO::FETCH_ASSOC);
        if (!$charge) {
            throw new RuntimeException('Charge not found.');
        }

        switch ($action) {
            case 'paid':
                $method = trim((string)($data['method'] ?? ''));
                $paidOn = (string)($data['paid_on'] ?? date('Y-m-d'));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn)) { $paidOn = date('Y-m-d'); }
                $pdo->prepare("
                    UPDATE company_subscription_charges
                    SET status = 'paid', paid_on = :on, paid_method = :m, invoice_ref = :ref
                    WHERE id = :id
                ")->execute([
                    'on' => $paidOn, 'm' => mb_substr($method, 0, 40),
                    'ref' => mb_substr(trim((string)($data['invoice_ref'] ?? '')), 0, 120), 'id' => $chargeId,
                ]);
                break;
            case 'waive':
            case 'void':
                $pdo->prepare("UPDATE company_subscription_charges SET status = :s, note = :n WHERE id = :id")
                    ->execute(['s' => $action === 'waive' ? 'waived' : 'void', 'n' => mb_substr(trim((string)($data['note'] ?? '')), 0, 255), 'id' => $chargeId]);
                break;
            case 'reopen':
                $pdo->prepare("UPDATE company_subscription_charges SET status = 'due', paid_on = NULL, paid_method = '' WHERE id = :id")
                    ->execute(['id' => $chargeId]);
                break;
            default:
                throw new InvalidArgumentException('Unknown action.');
        }

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => (int)$charge['company_id'],
            'event_type'    => 'billing.charge.' . $action,
            'summary'       => ucfirst($action) . " subscription charge #{$chargeId} for {$charge['company_name']} ("
                . number_format((float)$charge['amount'], 2) . ')',
            'metadata'      => ['charge_id' => $chargeId, 'from' => $charge['status'], 'action' => $action],
        ]);

        // Clearing the last outstanding charge on a past-due subscription
        // restores it (and full access) straight away.
        if (in_array($action, ['paid', 'waive', 'void'], true) && !empty($charge['subscription_id'])) {
            $sid = (int)$charge['subscription_id'];
            $stillDue = $pdo->prepare("
                SELECT s.status,
                       (SELECT COUNT(*) FROM company_subscription_charges sc
                         WHERE sc.subscription_id = s.id AND sc.status = 'due') AS due_left
                FROM company_subscriptions s WHERE s.id = :id LIMIT 1
            ");
            $stillDue->execute(['id' => $sid]);
            $sub = $stillDue->fetch(PDO::FETCH_ASSOC);
            if ($sub && $sub['status'] === 'past_due' && (int)$sub['due_left'] === 0) {
                require_once __DIR__ . '/../core/Entitlements.php';
                $pdo->prepare("UPDATE company_subscriptions SET status = 'active' WHERE id = :id")->execute(['id' => $sid]);
                Entitlements::syncFromSubscription($sid, $actorId);
                Audit::log([
                    'actor_user_id' => $actorId,
                    'company_id'    => (int)$charge['company_id'],
                    'event_type'    => 'billing.subscription.recovered',
                    'summary'       => "Subscription #{$sid} for {$charge['company_name']} back in good standing — full access restored",
                    'metadata'      => ['subscription_id' => $sid, 'trigger' => 'charge.' . $action],
                ]);
            }
        }
    }
}
