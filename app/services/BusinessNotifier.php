<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/NotificationService.php';

/**
 * Proactive Centryk Business alerts — settlement variances (event-driven) and
 * the daily sweep for newly-overdue customer invoices and subscription charges.
 * The daily sweep is idempotent per day: it only fires on the exact day an
 * item crosses a milestone, so running scripts/business_daily.php once a day
 * is enough.
 */
class BusinessNotifier
{
    private const INVOICE_MILESTONES = [1, 7, 14, 30, 60, 90];

    /** Active-admin user ids for a company. */
    private static function companyAdmins(int $companyId): array
    {
        $stmt = DB::pdo()->prepare("
            SELECT user_id FROM company_members
            WHERE company_id = :c AND status = 'active' AND role IN ('admin','manager')
        ");
        $stmt->execute(['c' => $companyId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function platformAdmins(): array
    {
        return array_map('intval', DB::pdo()
            ->query("SELECT id FROM users WHERE is_admin = 1 AND status = 'active'")
            ->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function push(array $userIds, array $n): void
    {
        foreach ($userIds as $uid) {
            NotificationService::create(['user_id' => $uid, 'app_key' => 'centryk'] + $n);
        }
    }

    /** Called after a route settlement is approved. Alerts on a flagged variance. */
    public static function settlementVariance(int $companyId, int $tripId, float $variance, string $routeName): void
    {
        if (abs($variance) <= 0.01) {
            return;
        }
        self::push(self::companyAdmins($companyId), [
            'company_id' => $companyId,
            'type'       => 'routes_variance',
            'title'      => 'Cash variance on ' . $routeName,
            'body'       => 'Trip settlement was ' . ($variance < 0 ? 'short' : 'over') . ' by BZD '
                . number_format(abs($variance), 2) . '.',
            'url'        => 'routes.php?company_id=' . $companyId,
            'icon'       => 'alert-triangle',
            'color'      => '#dc2626',
        ]);
    }

    /**
     * Daily sweep. Returns a per-check count summary.
     * @return array<string,int>
     */
    public static function runDaily(): array
    {
        $pdo = DB::pdo();
        $out = ['invoice_alerts' => 0, 'billing_alerts' => 0, 'cheque_alerts' => 0];

        // ── Customer invoices that hit an overdue milestone today ──────────
        $due = "COALESCE(i.due_date, DATE_ADD(i.issue_date, INTERVAL COALESCE(c.payment_terms_days,0) DAY))";
        $inv = $pdo->query("
            SELECT i.company_id, i.invoice_number, c.id AS customer_id, c.name AS customer_name,
                   (i.total - i.amount_paid) AS outstanding,
                   DATEDIFF(CURDATE(), {$due}) AS days_overdue
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            WHERE i.status IN ('sent','overdue') AND (i.total - i.amount_paid) > 0
              AND DATEDIFF(CURDATE(), {$due}) IN (" . implode(',', self::INVOICE_MILESTONES) . ")
        ")->fetchAll(PDO::FETCH_ASSOC);

        // only for companies that actually have Receivables
        require_once __DIR__ . '/../core/Entitlements.php';
        foreach ($inv as $r) {
            $cid = (int)$r['company_id'];
            if (Entitlements::level($cid, 'receivables') === Entitlements::NONE) {
                continue;
            }
            $d = (int)$r['days_overdue'];
            self::push(self::companyAdmins($cid), [
                'company_id' => $cid,
                'type'       => 'ar_overdue',
                'title'      => $r['customer_name'] . ' — ' . $d . ' days overdue',
                'body'       => $r['invoice_number'] . ' is BZD ' . number_format((float)$r['outstanding'], 2)
                    . ' outstanding, ' . $d . ' days past due.',
                'url'        => 'receivables_statement.php?company_id=' . $cid . '&customer_id=' . (int)$r['customer_id'],
                'icon'       => 'clock',
                'color'      => $d >= 30 ? '#dc2626' : '#b45309',
            ]);
            $out['invoice_alerts']++;
        }

        // ── Post-dated cheques that come due to deposit today ────────────
        if ($pdo->query("SHOW COLUMNS FROM ar_payments LIKE 'clearance_status'")->fetch()) {
            $cheques = $pdo->query("
                SELECT p.id, p.company_id, p.amount, p.cheque_number, c.name AS customer_name
                FROM ar_payments p
                JOIN customers c ON c.id = p.customer_id
                WHERE p.method = 'cheque' AND p.clearance_status = 'pending'
                  AND p.cheque_date = CURDATE()
            ")->fetchAll(PDO::FETCH_ASSOC);
            require_once __DIR__ . '/../core/Entitlements.php';
            foreach ($cheques as $ch) {
                $cid = (int) $ch['company_id'];
                if (Entitlements::level($cid, 'receivables') === Entitlements::NONE) {
                    continue;
                }
                self::push(self::companyAdmins($cid), [
                    'company_id' => $cid,
                    'type'       => 'cheque_due',
                    'title'      => 'Cheque due to deposit — ' . $ch['customer_name'],
                    'body'       => 'Cheque ' . ($ch['cheque_number'] ?: '') . ' for BZD '
                        . number_format((float) $ch['amount'], 2) . ' is dated today.',
                    'url'        => 'receivables.php?company_id=' . $cid,
                    'icon'       => 'calendar-check',
                    'color'      => '#b45309',
                ]);
                $out['cheque_alerts'] = ($out['cheque_alerts'] ?? 0) + 1;
            }
        }

        // ── Subscription charges that fell overdue yesterday ──────────────
        if ($pdo->query("SHOW TABLES LIKE 'company_subscription_charges'")->fetch()) {
            $charges = $pdo->query("
                SELECT sc.id, sc.amount, sc.currency, c.name AS company_name
                FROM company_subscription_charges sc
                JOIN companies c ON c.id = sc.company_id
                WHERE sc.status = 'due' AND sc.due_on = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
            ")->fetchAll(PDO::FETCH_ASSOC);
            if ($charges) {
                $admins = self::platformAdmins();
                foreach ($charges as $ch) {
                    self::push($admins, [
                        'type'  => 'billing_overdue',
                        'title' => 'Overdue: ' . $ch['company_name'],
                        'body'  => 'Subscription charge of ' . $ch['currency'] . ' '
                            . number_format((float)$ch['amount'], 2) . ' is now past due.',
                        'url'   => 'admin-business-billing.php',
                        'icon'  => 'credit-card',
                        'color' => '#dc2626',
                    ]);
                    $out['billing_alerts']++;
                }
            }
        }

        return $out;
    }
}
