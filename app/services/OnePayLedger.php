<?php
/**
 * Pulls a company's real card-payment ledger from OnePay (gross processed,
 * refunded, transaction count, by day) — OnePay's own record of what
 * actually got charged and saved via OneLink, independent of anything
 * OneLink itself later reports. Used on the OneLink admin page instead of
 * guessing figures from nothing; the two totals (this vs. what OneLink says)
 * can eventually be diffed to catch a payment that succeeded at OneLink but
 * never made it into a local sale record.
 *
 * Read-only, fails soft — never throws, always returns a usable (possibly
 * empty) result so a OnePay outage just means the ledger shows nothing for
 * that company rather than breaking the page. Reuses the same
 * ONEPAY_SYNC_URL/ONEPAY_WEBHOOK_SECRET pair and HMAC-signing scheme already
 * used by OnePayWebhook for the outbound direction.
 */
class OnePayLedger
{
    /** @return array{days: array, total_gross: float, total_refunded: float, total_count: int} */
    public static function fetch(string $companyUuid, string $startDate, string $endDate): array
    {
        $empty = ['days' => [], 'total_gross' => 0.0, 'total_refunded' => 0.0, 'total_count' => 0];

        $syncUrl = trim((string)($_ENV['ONEPAY_SYNC_URL'] ?? ''));
        $secret  = (string)($_ENV['ONEPAY_WEBHOOK_SECRET'] ?? '');
        if ($syncUrl === '' || $secret === '' || $companyUuid === '') {
            return $empty;
        }

        $parts = parse_url($syncUrl);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $empty;
        }
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $url  = $base . '/api/onelink/ledger.php';

        $payload = json_encode([
            'company_uuid' => $companyUuid,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return $empty;
        }

        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Centryk-Signature: ' . $signature,
                ],
            ]);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($response === false || $httpCode !== 200) {
                return $empty;
            }

            $data = json_decode($response, true);
            if (!is_array($data) || empty($data['success'])) {
                return $empty;
            }

            return [
                'days'           => is_array($data['days'] ?? null) ? $data['days'] : [],
                'total_gross'    => (float)($data['total_gross'] ?? 0),
                'total_refunded' => (float)($data['total_refunded'] ?? 0),
                'total_count'    => (int)($data['total_count'] ?? 0),
            ];
        } catch (Throwable $e) {
            error_log('OnePayLedger::fetch failed: ' . $e->getMessage());
            return $empty;
        }
    }
}
