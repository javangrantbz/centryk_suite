<?php
/**
 * Insights widgets backed by sibling apps (MyPay people/leave/payroll, OnePay
 * sales). Read-only, aggregates only, and soft-failing: if the sibling is down
 * or not configured the widget is returned flagged `offline` so the page says
 * "couldn't reach MyPay" instead of misleading the user with an empty chart.
 */
class InsightsExternal
{
    public static function mypay(array $company, int $days): ?array
    {
        $end = date('Y-m-d');
        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
        $d = self::mypayCall((string)$company['uuid'], $start, $end);
        $open = ['label' => 'Open MyPay', 'href' => 'switch.php?app=mypay'];

        $leaveCta = ['title' => 'Track sick days and vacation', 'body' => 'Employees request time off in MyPay, managers approve it, and balances accrue automatically.', 'label' => 'Set up leave', 'href' => 'switch.php?app=mypay'];
        $accCta = ['title' => 'Show employees their accrued vacation', 'body' => 'Set an annual entitlement per leave type and MyPay keeps every balance up to date.', 'label' => 'Set up leave types', 'href' => 'switch.php?app=mypay'];
        $payCta = ['title' => 'Run your first payroll', 'body' => 'Calculate pay, PAYE and SSB, then issue payslips. Your payroll cost shows up here run by run.', 'label' => 'Open payroll', 'href' => 'switch.php?app=mypay'];

        $leaveSample = ['labels' => ['Vacation', 'Sick', 'Personal'], 'datasets' => [['label' => 'Days', 'data' => [14, 6, 3]]]];
        $accSample = ['labels' => ['Vacation', 'Sick', 'Personal'], 'datasets' => [['label' => 'Days', 'data' => [42, 20, 8]]]];
        $paySample = ['labels' => ['Jun', 'Jul', 'Aug', 'Sep'], 'datasets' => [['label' => 'Gross', 'data' => [8200, 8400, 8300, 8900]], ['label' => 'Net', 'data' => [6900, 7050, 6980, 7400]]]];

        if ($d === null) {
            $mk = static function (string $id, string $title, string $type, array $sample, array $cta) use ($open): array {
                $w = InsightsService::w($id, 'mypay', $title, $type, [], [['label' => $title, 'data' => []]], $sample, $cta, $open);
                $w['offline'] = true;
                return $w;
            };
            return ['kpis' => [], 'widgets' => [
                $mk('mypay_leave', 'Sick days, vacation and time off', 'doughnut', $leaveSample, $leaveCta),
                $mk('mypay_accrued', 'Accrued time off', 'bar', $accSample, $accCta),
                $mk('mypay_payroll', 'Payroll cost by run', 'bar', $paySample, $payCta),
            ]];
        }

        $leave = $d['leave_by_type'] ?? [];
        $acc = $d['accrued'] ?? [];
        $pay = $d['payroll'] ?? [];
        $pending = (int)($d['pending_requests'] ?? 0);
        $onLeave = (int)($d['on_leave_today'] ?? 0);
        $head = (int)($d['headcount'] ?? 0);

        $kpis = [InsightsService::kpi('mypay', 'Employees', $head,
            ($onLeave ? $onLeave . ' on leave today' : 'None on leave today') . ($pending ? ', ' . $pending . ' request' . ($pending > 1 ? 's' : '') . ' waiting' : ''),
            $pending ? 'warn' : 'neutral')];

        return ['kpis' => $kpis, 'widgets' => [
            InsightsService::w('mypay_leave', 'mypay', 'Sick days, vacation and time off', 'doughnut',
                array_column($leave, 'type'), [['label' => 'Days', 'data' => array_column($leave, 'days')]], $leaveSample, $leaveCta,
                $open, 'Approved days, last ' . $days . ' days'),
            InsightsService::w('mypay_accrued', 'mypay', 'Accrued time off', 'bar',
                array_column($acc, 'type'), [['label' => 'Days available', 'data' => array_column($acc, 'balance')]], $accSample, $accCta,
                ['label' => 'Open leave', 'href' => $d['review_url'] ?? 'switch.php?app=mypay'], 'Total balance across employees'),
            InsightsService::w('mypay_payroll', 'mypay', 'Payroll cost by run', 'bar',
                array_column($pay, 'label'),
                [['label' => 'Gross', 'data' => array_column($pay, 'gross')], ['label' => 'Net', 'data' => array_column($pay, 'net')]],
                $paySample, $payCta, $open, 'Last ' . max(1, count($pay)) . ' approved or posted runs'),
        ]];
    }

    public static function onepay(array $company, int $days): ?array
    {
        $end = date('Y-m-d');
        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
        $d = self::onepayCall((string)$company['uuid'], $start, $end);
        $open = ['label' => 'Open OnePay', 'href' => 'switch.php?app=onepay'];
        $cta = ['title' => 'Ring up your first sale', 'body' => 'Use OnePay at the counter and your daily sales, payment mix and best sellers appear here.', 'label' => 'Open POS', 'href' => 'switch.php?app=onepay'];

        $salesSample = ['labels' => ['W1', 'W2', 'W3', 'W4', 'W5'], 'datasets' => [['label' => 'Sales', 'data' => [900, 1300, 1100, 1700, 2100]]]];
        $methSample = ['labels' => ['Cash', 'Card', 'Transfer'], 'datasets' => [['label' => 'Amount', 'data' => [55, 35, 10]]]];
        $topSample = ['labels' => ['Item A', 'Item B', 'Item C'], 'datasets' => [['label' => 'Revenue', 'data' => [400, 260, 120]]]];

        if ($d === null) {
            $mk = static function (string $id, string $title, string $type, array $sample) use ($open, $cta): array {
                $w = InsightsService::w($id, 'onepay', $title, $type, [], [['label' => $title, 'data' => []]], $sample, $cta, $open);
                $w['offline'] = true;
                return $w;
            };
            return ['kpis' => [], 'widgets' => [
                $mk('onepay_sales', 'Sales over time', 'bar', $salesSample),
                $mk('onepay_methods', 'How customers pay', 'doughnut', $methSample),
                $mk('onepay_top', 'Best sellers', 'bar', $topSample),
            ]];
        }

        $rows = [];
        foreach ($d['days'] ?? [] as $r) { $rows[(string)$r['date']] = (float)$r['revenue']; }
        $labels = []; $vals = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i day"));
            $labels[] = date('M j', strtotime($day));
            $vals[] = $rows[$day] ?? 0;
        }
        [$labels, $vals] = InsightsService::bucket($labels, $vals);
        $t = $d['totals'] ?? [];
        $meth = $d['by_method'] ?? [];
        $top = $d['top_items'] ?? [];

        return [
            'kpis' => [InsightsService::kpi('onepay', 'Sales', InsightsService::money((float)($t['revenue'] ?? 0)),
                (int)($t['sales'] ?? 0) . ' sales, avg ' . InsightsService::money((float)($t['avg_ticket'] ?? 0)))],
            'widgets' => [
                InsightsService::w('onepay_sales', 'onepay', 'Sales over time', 'bar', $labels, [['label' => 'Sales', 'data' => $vals]], $salesSample, $cta, $open, 'Completed sales, last ' . $days . ' days'),
                InsightsService::w('onepay_methods', 'onepay', 'How customers pay', 'doughnut',
                    array_map(fn($m) => ucwords(str_replace('_', ' ', (string)$m['method'])), $meth), [['label' => 'Amount', 'data' => array_column($meth, 'amount')]], $methSample, $cta, $open),
                InsightsService::w('onepay_top', 'onepay', 'Best sellers', 'bar',
                    array_map(fn($i) => mb_strimwidth((string)$i['name'], 0, 22, '…'), $top), [['label' => 'Revenue', 'data' => array_column($top, 'revenue')]], $topSample, $cta, $open),
            ],
        ];
    }

    // ── transport ────────────────────────────────────────────────────────────

    /** @return array|null decoded body, or null when the app is unreachable/unconfigured */
    private static function mypayCall(string $uuid, string $start, string $end): ?array
    {
        $secret = (string)($_ENV['MYPAY_WEBHOOK_SECRET'] ?? '');
        if ($uuid === '' || $secret === '') { return null; }
        $base = rtrim((string)($_ENV['MYPAY_API_URL'] ?? 'http://localhost/myPay'), '/');
        return self::post($base . '/api/insights/summary.php',
            json_encode(['secret' => $secret, 'company_uuid' => $uuid, 'start_date' => $start, 'end_date' => $end]), []);
    }

    private static function onepayCall(string $uuid, string $start, string $end): ?array
    {
        $syncUrl = trim((string)($_ENV['ONEPAY_SYNC_URL'] ?? ''));
        $secret = (string)($_ENV['ONEPAY_WEBHOOK_SECRET'] ?? '');
        $parts = parse_url($syncUrl);
        if ($uuid === '' || $secret === '' || empty($parts['scheme']) || empty($parts['host'])) { return null; }
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $payload = json_encode(['company_uuid' => $uuid, 'start_date' => $start, 'end_date' => $end], JSON_UNESCAPED_SLASHES);
        return self::post($base . '/api/insights/summary.php', $payload,
            ['X-Centryk-Signature: sha256=' . hash_hmac('sha256', $payload, $secret)]);
    }

    private static function post(string $url, string|false $payload, array $headers): ?array
    {
        if ($payload === false || !function_exists('curl_init')) { return null; }
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            ]);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($res === false || $code !== 200) { return null; }
            $data = json_decode($res, true);
            return (is_array($data) && !empty($data['success'])) ? $data : null;
        } catch (Throwable $e) {
            error_log('InsightsExternal failed: ' . $e->getMessage());
            return null;
        }
    }
}
