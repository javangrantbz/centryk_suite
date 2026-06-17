<?php
/**
 * Outbound webhook: notify MyPay (and future payroll-style apps) the moment a
 * person's company membership changes, so MyPay can sync its payroll roster in
 * real time instead of waiting for the next pull of company_roster.php.
 *
 * Design notes (important — Centryk runs on live data):
 *   - This NEVER throws and NEVER breaks the calling action. If MyPay is down,
 *     misconfigured, or slow, the member is still added in Centryk; the sync is
 *     simply skipped (MyPay's periodic pull of company_roster.php remains the
 *     safety net / source of truth).
 *   - It is a NO-OP until MYPAY_SYNC_URL is set in .env, so shipping this code
 *     changes nothing until you explicitly opt in.
 *   - Signed with HMAC-SHA256 using MYPAY_WEBHOOK_SECRET (the same shared secret
 *     used for the inbound termination webhook), sent as X-Centryk-Signature.
 */
class MyPayWebhook
{
    /**
     * Fire-and-forget notify MyPay that a company membership was added/updated.
     *
     * @param PDO    $pdo
     * @param int    $companyId  Centryk companies.id
     * @param int    $userId     Centryk users.id
     * @param string $source     Originating app key ('onepay', 'centryk', 'mypay', ...)
     * @param string $changeType 'added' | 'updated'
     */
    public static function memberSynced(
        PDO $pdo,
        int $companyId,
        int $userId,
        string $source = 'centryk',
        string $changeType = 'added'
    ): void {
        try {
            $url    = trim((string)($_ENV['MYPAY_SYNC_URL'] ?? ''));
            $secret = (string)($_ENV['MYPAY_WEBHOOK_SECRET'] ?? '');
            if ($url === '' || $secret === '') {
                return; // not configured — silently skip
            }

            $stmt = $pdo->prepare('
                SELECT c.uuid AS company_uuid, c.name AS company_name,
                       u.uuid AS user_uuid, u.first_name, u.last_name, u.email,
                       u.status AS account_status,
                       cm.role, cm.status AS member_status
                FROM company_members cm
                JOIN companies c ON c.id = cm.company_id
                JOIN users u     ON u.id = cm.user_id
                WHERE cm.company_id = :cid AND cm.user_id = :uid
                LIMIT 1
            ');
            $stmt->execute(['cid' => $companyId, 'uid' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return; // membership not found — nothing to sync
            }

            $payload = [
                'event'        => 'company.member.synced',
                'change'       => $changeType,
                'source'       => $source,
                'company_uuid' => $row['company_uuid'],
                'company_name' => $row['company_name'],
                'member'       => [
                    'user_id'        => $userId,
                    'uuid'           => $row['user_uuid'],
                    'first_name'     => $row['first_name'],
                    'last_name'      => $row['last_name'],
                    'email'          => $row['email'],
                    'role'           => $row['role'],
                    'member_status'  => $row['member_status'],
                    'account_status' => $row['account_status'],
                ],
                'sent_at'      => gmdate('c'),
            ];

            $rawBody   = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $signature = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

            self::post($url, $rawBody, $signature);
        } catch (Throwable $e) {
            // Swallow everything — syncing must never disrupt the core action.
            error_log('MyPayWebhook::memberSynced failed: ' . $e->getMessage());
        }
    }

    /**
     * Short-timeout POST. Errors are intentionally non-fatal.
     */
    private static function post(string $url, string $rawBody, string $signature): void
    {
        if (!function_exists('curl_init')) {
            error_log('MyPayWebhook: cURL unavailable — skipping sync.');
            return;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Centryk-Signature: ' . $signature,
            ],
        ]);
        curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            error_log('MyPayWebhook: POST to ' . $url . ' failed: ' . $err);
        }
    }
}
