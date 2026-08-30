<?php
require_once __DIR__ . '/../core/DB.php';

/**
 * Belize GST (General Sales Tax) — a monthly output-tax summary built from the
 * company's sales invoices, to help prepare the GST return. Belize standard
 * rate is 12.5%. This is a working summary, NOT the return and NOT tax advice.
 *
 * Basis: invoice date (accrual). Includes sent / overdue / paid / written_off
 * invoices issued in the period; excludes draft and cancelled.
 *
 * Invoices that carry a recorded tax amount use it as-is. Invoices with no tax
 * split are handled per $treatUntaxed:
 *   'inclusive'  -> price is treated as GST-inclusive; GST = total * 12.5/112.5
 *   'zerorated'  -> no GST (exports / exempt / non-registered period)
 */
class BusinessTax
{
    public const GST_RATE = 12.5;

    private static function inclusiveGst(float $grossInclusive): float
    {
        return round($grossInclusive * self::GST_RATE / (100 + self::GST_RATE), 2);
    }

    /**
     * @param string $period  'YYYY-MM'
     * @param string $treatUntaxed  'inclusive' | 'zerorated'
     */
    public static function gstReport(int $companyId, string $period, string $treatUntaxed = 'inclusive'): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = date('Y-m', strtotime('-1 month'));
        }
        $treatUntaxed = $treatUntaxed === 'zerorated' ? 'zerorated' : 'inclusive';
        $start = $period . '-01';
        $end   = date('Y-m-d', strtotime($start . ' +1 month'));

        $pdo = DB::pdo();

        $tin = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(v.business_tax_number), ''), NULL) AS tin,
                   COALESCE(NULLIF(TRIM(v.business_name), ''), c.name) AS name
            FROM companies c LEFT JOIN invoice_settings v ON v.company_id = c.id
            WHERE c.id = :c LIMIT 1
        ");
        $tin->execute(['c' => $companyId]);
        $co = $tin->fetch(PDO::FETCH_ASSOC) ?: ['tin' => null, 'name' => 'Company'];

        $inv = $pdo->prepare("
            SELECT COUNT(*) AS n,
                   COALESCE(SUM(total), 0) AS gross,
                   COALESCE(SUM(CASE WHEN tax > 0 THEN 1 ELSE 0 END), 0) AS taxed_n,
                   COALESCE(SUM(CASE WHEN tax > 0 THEN total ELSE 0 END), 0) AS taxed_gross,
                   COALESCE(SUM(CASE WHEN tax > 0 THEN tax   ELSE 0 END), 0) AS taxed_tax,
                   COALESCE(SUM(CASE WHEN COALESCE(tax,0) = 0 THEN 1 ELSE 0 END), 0) AS untaxed_n,
                   COALESCE(SUM(CASE WHEN COALESCE(tax,0) = 0 THEN total ELSE 0 END), 0) AS untaxed_gross
            FROM invoices
            WHERE company_id = :c
              AND status IN ('sent','overdue','paid','written_off')
              AND issue_date >= :start AND issue_date < :end
        ");
        $inv->execute(['c' => $companyId, 'start' => $start, 'end' => $end]);
        $r = $inv->fetch(PDO::FETCH_ASSOC) ?: [];

        $taxedTax     = round((float)($r['taxed_tax'] ?? 0), 2);
        $untaxedGross = round((float)($r['untaxed_gross'] ?? 0), 2);
        $imputedGst   = $treatUntaxed === 'inclusive' ? self::inclusiveGst($untaxedGross) : 0.0;

        $outputTax = round($taxedTax + $imputedGst, 2);

        // Bad-debt relief: write-offs approved in the period, GST portion of the
        // written-off amount (pro-rata to the original invoice).
        $bd = ['n' => 0, 'writeoff_total' => 0.0, 'gst_relief' => 0.0];
        if ($pdo->query("SHOW TABLES LIKE 'ar_writeoffs'")->fetch()) {
            $wo = $pdo->prepare("
                SELECT w.amount, i.total, i.tax
                FROM ar_writeoffs w JOIN invoices i ON i.id = w.invoice_id
                WHERE w.company_id = :c AND w.status = 'approved'
                  AND w.decided_at >= :start AND w.decided_at < :end
            ");
            $wo->execute(['c' => $companyId, 'start' => $start, 'end' => $end]);
            foreach ($wo->fetchAll(PDO::FETCH_ASSOC) as $w) {
                $amt   = (float)$w['amount'];
                $total = (float)$w['total'];
                $tax   = (float)$w['tax'];
                $gst = $tax > 0 && $total > 0
                    ? round($amt * ($tax / $total), 2)
                    : ($treatUntaxed === 'inclusive' ? self::inclusiveGst($amt) : 0.0);
                $bd['n']++;
                $bd['writeoff_total'] += $amt;
                $bd['gst_relief']     += $gst;
            }
            $bd['writeoff_total'] = round($bd['writeoff_total'], 2);
            $bd['gst_relief']     = round($bd['gst_relief'], 2);
        }

        return [
            'period'       => $period,
            'period_label' => date('F Y', strtotime($start)),
            'gst_rate'     => self::GST_RATE,
            'basis'        => 'invoice date (accrual)',
            'company'      => $co['name'],
            'tin'          => $co['tin'],
            'treat_untaxed_as' => $treatUntaxed,
            'sales' => [
                'invoice_count' => (int)($r['n'] ?? 0),
                'gross_total'   => round((float)($r['gross'] ?? 0), 2),
                'with_recorded_tax' => [
                    'count' => (int)($r['taxed_n'] ?? 0),
                    'gross' => round((float)($r['taxed_gross'] ?? 0), 2),
                    'tax'   => $taxedTax,
                    'net'   => round((float)($r['taxed_gross'] ?? 0) - $taxedTax, 2),
                ],
                'without_recorded_tax' => [
                    'count'       => (int)($r['untaxed_n'] ?? 0),
                    'gross'       => $untaxedGross,
                    'imputed_gst' => $imputedGst,
                    'net'         => round($untaxedGross - $imputedGst, 2),
                ],
            ],
            'output_tax'      => $outputTax,
            'bad_debt_relief' => $bd,
            'net_output_tax'  => round($outputTax - $bd['gst_relief'], 2),
        ];
    }
}
