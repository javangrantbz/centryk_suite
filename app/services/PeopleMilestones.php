<?php

/**
 * Staff birthdays & work anniversaries, pulled from MyPay (which owns the
 * employee dates). Display + notification input only. Fails soft to [].
 *
 * Mirrors MyPayCalendarFeed's env/curl shape (MYPAY_API_URL / MYPAY_WEBHOOK_SECRET).
 */
class PeopleMilestones
{
    /** @var array<string,array> per-request memo, "from|to|companyUuid" */
    private static array $memo = [];

    /**
     * Milestones whose this-year date falls in [$from, $to].
     * Each row: kind ('birthday'|'anniversary'), date, employee_name,
     * employee_email, company_uuid, company_name, years (anniversary only).
     */
    public static function forRange(string $from, string $to, ?string $companyUuid = null): array
    {
        if (!self::validDate($from) || !self::validDate($to)) {
            return [];
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $key = $from . '|' . $to . '|' . ($companyUuid ?? '');
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $secret = (string)($_ENV['MYPAY_WEBHOOK_SECRET'] ?? '');
        $base   = rtrim((string)($_ENV['MYPAY_API_URL'] ?? 'http://localhost/myPay'), '/');
        if ($secret === '' || !function_exists('curl_init')) {
            return self::$memo[$key] = [];
        }

        $payload = json_encode(array_filter([
            'secret'       => $secret,
            'from'         => $from,
            'to'           => $to,
            'company_uuid' => $companyUuid,
        ], static fn($v) => $v !== null));

        $rows = [];
        try {
            $ch = curl_init($base . '/api/people/milestones.php');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 4,
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($raw !== false && $code === 200) {
                $data = json_decode((string)$raw, true);
                if (is_array($data) && !empty($data['success']) && isset($data['milestones']) && is_array($data['milestones'])) {
                    $rows = $data['milestones'];
                }
            }
        } catch (Throwable $e) {
            $rows = [];
        }

        return self::$memo[$key] = $rows;
    }

    /** "🎂 Ana" / "🎉 Ana — 3 years" style label for a milestone row. */
    public static function label(array $m): string
    {
        $name = trim((string)($m['employee_name'] ?? 'Someone'));
        if (($m['kind'] ?? '') === 'anniversary') {
            $y = (int)($m['years'] ?? 0);
            return $name . ' — ' . $y . ' year' . ($y === 1 ? '' : 's');
        }
        return $name . ' — birthday';
    }

    private static function validDate(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
    }
}
