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
            SELECT sc.id, sc.status, sc.amount, sc.company_id, c.name AS company_name
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
    }
}
