<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Entitlements.php';

/**
 * Cross-module KPI snapshot for one company — the finance-lead view. Only the
 * sections the company is entitled to are populated. Read-only; computed from
 * the existing tables (no new storage).
 */
class BusinessInsights
{
    public static function forCompany(int $companyId): array
    {
        $out = ['company_id' => $companyId, 'as_of' => date('Y-m-d')];

        if (Entitlements::level($companyId, 'receivables') !== Entitlements::NONE) {
            $out['receivables'] = self::receivables($companyId);
            $out['bad_debt']    = self::badDebt($companyId);
        }
        if (Entitlements::level($companyId, 'routes') !== Entitlements::NONE) {
            require_once __DIR__ . '/RoutesService.php';
            $s = RoutesService::summary($companyId);
            $out['routes'] = [
                'cash_in_transit'   => $s['cash_in_transit'],
                'awaiting_approval' => $s['awaiting_approval'],
                'on_the_road'       => $s['out'],
                'variance_flags_30d' => $s['variance_flags'],
            ];
        }
        if (Entitlements::level($companyId, 'reconciliation') !== Entitlements::NONE) {
            require_once __DIR__ . '/ReconciliationService.php';
            $r = ReconciliationService::summary($companyId);
            $matched = $r['matched_count'] + $r['unmatched_credits'];
            $out['reconciliation'] = [
                'unmatched_credits' => $r['unmatched_credits'],
                'unmatched_value'   => $r['unmatched_value'],
                'match_rate'        => $matched > 0 ? round($r['matched_count'] / $matched * 100, 1) : null,
            ];
        }

        return $out;
    }

    private static function receivables(int $companyId): array
    {
        $pdo = DB::pdo();
        require_once __DIR__ . '/ReceivablesService.php';
        $t = ReceivablesService::portfolio($companyId)['totals'];

        $monthStart = date('Y-m-01');

        // Billed vs collected this month.
        $billed = (float)$pdo->query("
            SELECT COALESCE(SUM(total), 0) FROM invoices
            WHERE company_id = " . (int)$companyId . "
              AND status IN ('sent','overdue','paid','written_off')
              AND issue_date >= '" . $monthStart . "'
        ")->fetchColumn();

        $cm = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM ar_payments
            WHERE company_id = :c AND received_on >= :m
        ");
        $cm->execute(['c' => $companyId, 'm' => $monthStart]);
        $collected = (float)$cm->fetchColumn();

        // Credit sales over the trailing 90 days -> DSO.
        $sales90 = (float)$pdo->query("
            SELECT COALESCE(SUM(total), 0) FROM invoices
            WHERE company_id = " . (int)$companyId . "
              AND status IN ('sent','overdue','paid','written_off')
              AND issue_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        ")->fetchColumn();
        $dso = $sales90 > 0 ? round(($t['balance'] / $sales90) * 90, 1) : null;

        // Average days to pay, from allocations on now-settled invoices (last 180d).
        $adp = $pdo->prepare("
            SELECT AVG(DATEDIFF(p.received_on, i.issue_date))
            FROM ar_payment_allocations a
            JOIN ar_payments p ON p.id = a.ar_payment_id
            JOIN invoices i    ON i.id = a.invoice_id
            WHERE i.company_id = :c AND i.status = 'paid'
              AND p.received_on >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
        ");
        $adp->execute(['c' => $companyId]);
        $avgDaysToPay = $adp->fetchColumn();

        $aged = $t['b_1_30'] + $t['b_31_60'] + $t['b_61_90'] + $t['b_90p'];
        $cheques = ReceivablesService::chequesSummary($companyId);

        return [
            'uncleared_cheques'  => $cheques['pending_value'],
            'uncleared_cheque_count' => $cheques['pending_count'],
            'bounced_cheques_12m' => $cheques['bounced_12m_value'],
            'outstanding'        => $t['balance'],
            'overdue'            => $t['overdue'],
            'overdue_pct'        => $t['balance'] > 0 ? round($t['overdue'] / $t['balance'] * 100, 1) : 0.0,
            'current'            => $t['current'],
            'aged'               => round($aged, 2),
            'over_90'            => $t['b_90p'],
            'on_hold'            => (int)$t['on_hold'],
            'over_limit'         => (int)$t['over_limit'],
            'billed_this_month'  => round($billed, 2),
            'collected_this_month' => round($collected, 2),
            'collection_ratio'   => $billed > 0 ? round($collected / $billed * 100, 1) : null,
            'dso'                => $dso,
            'avg_days_to_pay'    => $avgDaysToPay !== null ? round((float)$avgDaysToPay, 1) : null,
        ];
    }

    private static function badDebt(int $companyId): array
    {
        require_once __DIR__ . '/ReceivablesService.php';
        $ytd = ReceivablesService::badDebtReport($companyId, date('Y-01-01'), date('Y-m-d'));

        // Write-off rate vs sales YTD.
        $salesYtd = (float)DB::pdo()->query("
            SELECT COALESCE(SUM(total), 0) FROM invoices
            WHERE company_id = " . (int)$companyId . "
              AND status IN ('sent','overdue','paid','written_off')
              AND issue_date >= '" . date('Y-01-01') . "'
        ")->fetchColumn();

        return [
            'written_off_ytd' => $ytd['total'],
            'count_ytd'       => $ytd['count'],
            'by_kind'         => $ytd['by_kind'],
            'pending'         => $ytd['pending_total'],
            'pending_count'   => $ytd['pending_count'],
            'writeoff_rate'   => $salesYtd > 0 ? round($ytd['total'] / $salesYtd * 100, 2) : null,
        ];
    }
}
