<?php
/**
 * Read-only pull of MyPay task due dates and approved leave, for display on
 * the Centryk calendar. MyPay keeps owning assignment/approval; this never
 * writes anything back. Fails soft on any error — the calendar must render
 * fine even if MyPay is down, misconfigured, or slow.
 */
class MyPayCalendarFeed
{
    /** @return array{tasks: array, leave: array} */
    public static function fetch(string $companyUuid, string $startDate, string $endDate): array
    {
        $empty = ['tasks' => [], 'leave' => []];

        $secret = (string)($_ENV['MYPAY_WEBHOOK_SECRET'] ?? '');
        if ($companyUuid === '' || $secret === '' || !function_exists('curl_init')) {
            return $empty;
        }

        $base = rtrim((string)($_ENV['MYPAY_API_URL'] ?? 'http://localhost/myPay'), '/');
        $url  = $base . '/api/calendar/centryk-feed.php';

        $payload = json_encode([
            'secret'       => $secret,
            'company_uuid' => $companyUuid,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
        ]);
        if ($payload === false) {
            return $empty;
        }

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
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
                'tasks' => is_array($data['tasks'] ?? null) ? $data['tasks'] : [],
                'leave' => is_array($data['leave'] ?? null) ? $data['leave'] : [],
            ];
        } catch (Throwable $e) {
            error_log('MyPayCalendarFeed::fetch failed: ' . $e->getMessage());
            return $empty;
        }
    }
}
