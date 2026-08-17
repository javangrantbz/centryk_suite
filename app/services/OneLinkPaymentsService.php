<?php
/**
 * Read-only wrapper around OneLink's transaction-reporting API
 * (POST /user/transactions, POST /user/transactions/status), used by
 * onelink-payments.php to show a company's real payment activity instead of
 * template data. Credentials come from onelink_credentials (populated by
 * OneLinkProvisioning::provision()).
 *
 * Confirmed against the live docs page (https://op.onelink.bz/docs.php):
 * body-based auth (token/terminalId/salt in the JSON body, no headers),
 * Content-Type: application/json.
 */
class OneLinkPaymentsService
{
    /**
     * Fetches the enabled onelink_credentials row for a company, or null.
     *
     * Confirmed against the live API: the `salt` field the reporting endpoints
     * (/user/transactions, /user/transactions/status) actually authenticate
     * against is the `u_uuid` OneLink assigned at account creation (stored
     * here as onelink_uuid) - NOT the sSalt value we generated and submitted
     * to /user/create. Sending our own sSalt back fails with "CREDENTIALS
     * COULD NOT AUTHENTICATED"; sending onelink_uuid succeeds. So `salt`
     * below is aliased from onelink_uuid, not the onelink_credentials.salt
     * column.
     */
    public static function credentials(PDO $pdo, int $companyId): ?array
    {
        $stmt = $pdo->prepare('
            SELECT base_url, terminal_id, onelink_uuid AS salt, token
            FROM onelink_credentials
            WHERE company_id = :cid AND enabled = 1
              AND terminal_id <> "" AND onelink_uuid <> "" AND token <> ""
            LIMIT 1
        ');
        $stmt->execute(['cid' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** POST /user/transactions — paginated (20/page), newest first. */
    public static function transactions(array $creds, int $page = 1): array
    {
        return self::post($creds, '/user/transactions', [
            'page' => max(1, $page),
        ]);
    }

    /** POST /user/transactions/status — all matching rows + count/totalAmount. $status: 0=Unsettled, 2=Settled. */
    public static function byStatus(array $creds, int $status): array
    {
        return self::post($creds, '/user/transactions/status', [
            'status' => $status,
        ]);
    }

    private static function post(array $creds, string $path, array $extra): array
    {
        $url = rtrim((string)($creds['base_url'] ?: 'https://op.onelink.bz'), '/') . $path;
        $payload = array_merge([
            'token'      => (string)$creds['token'],
            'terminalId' => (string)$creds['terminal_id'],
            'salt'       => (string)$creds['salt'],
        ], $extra);
        $json = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'message' => 'OneLink request failed: ' . $err];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'message' => 'Invalid OneLink response.'];
        }
        return $decoded;
    }
}
