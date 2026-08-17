<?php
/**
 * Outbound webhook: notify OnePay the moment a company's business profile
 * changes (name, logo, contact details) so OnePay can refresh the branding it
 * shows in its sidebar and POS in real time, instead of waiting for the owning
 * member's next SSO login.
 *
 * Design notes (Centryk runs on live data):
 *   - This NEVER throws and NEVER breaks the calling action. If OnePay is down
 *     or misconfigured the profile is still saved in Centryk; the push is simply
 *     skipped. OnePay self-heals on the member's next SSO login (see
 *     onepay/public/sso.php syncCompanyToOnePay), which remains the safety net.
 *   - It is a NO-OP until ONEPAY_SYNC_URL and ONEPAY_WEBHOOK_SECRET are set in
 *     .env, so shipping this code changes nothing until you opt in.
 *   - Signed with HMAC-SHA256 over the raw body, sent as X-Centryk-Signature
 *     (same scheme as MyPayWebhook).
 */
class OnePayWebhook
{
    /**
     * Fire-and-forget notify OnePay that a company profile was updated.
     *
     * @param PDO $pdo
     * @param int $companyId  Centryk companies.id
     */
    public static function companyProfileSynced(PDO $pdo, int $companyId): void
    {
        try {
            $url    = trim((string)($_ENV['ONEPAY_SYNC_URL'] ?? ''));
            $secret = (string)($_ENV['ONEPAY_WEBHOOK_SECRET'] ?? '');
            if ($url === '' || $secret === '') {
                return; // not configured — silently skip
            }

            $stmt = $pdo->prepare('
                SELECT uuid, name, logo,
                       business_type, customer_noun_singular, customer_noun_plural
                FROM companies
                WHERE id = :id AND status = "active"
                LIMIT 1
            ');
            $stmt->execute(['id' => $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return; // company not found — nothing to sync
            }

            $payload = [
                'event'        => 'company.profile.updated',
                'company_uuid' => $row['uuid'],
                'company_name' => $row['name'],
                // Path relative to the Centryk public root (e.g.
                // uploads/companies/xxx.png); OnePay resolves it against
                // CENTRYK_URL. Empty string means "no logo".
                'logo'         => (string)($row['logo'] ?? ''),
                // Business type + customer noun drive the invoicing UI wording in
                // OnePay. Nulls are sent as empty strings → OnePay keeps its
                // "Customer/Customers" default.
                'business_type'          => (string)($row['business_type'] ?? ''),
                'customer_noun_singular' => (string)($row['customer_noun_singular'] ?? ''),
                'customer_noun_plural'   => (string)($row['customer_noun_plural'] ?? ''),
                'sent_at'      => gmdate('c'),
            ];

            $rawBody   = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $signature = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

            self::post($url, $rawBody, $signature);
        } catch (Throwable $e) {
            // Swallow everything — syncing must never disrupt the core action.
            error_log('OnePayWebhook::companyProfileSynced failed: ' . $e->getMessage());
        }
    }

    /**
     * Fire-and-forget push of a company's OneLink gateway credentials to OnePay
     * so its POS can charge cards. Same rules as companyProfileSynced: never
     * throws, and a no-op until ONEPAY_SYNC_URL + ONEPAY_WEBHOOK_SECRET are set.
     *
     * @param PDO $pdo
     * @param int $companyId  Centryk companies.id
     */
    public static function onelinkCredentialsSynced(PDO $pdo, int $companyId): void
    {
        try {
            $url    = trim((string)($_ENV['ONEPAY_SYNC_URL'] ?? ''));
            $secret = (string)($_ENV['ONEPAY_WEBHOOK_SECRET'] ?? '');
            if ($url === '' || $secret === '') {
                return; // not configured — silently skip
            }

            // NOTE: the "salt" OneLink's own API actually authenticates against
            // is the u_uuid it assigned at account creation (onelink_uuid here),
            // not the sSalt we generated and submitted to /user/create — confirmed
            // live against /user/transactions (see OneLinkPaymentsService). For a
            // company auto-provisioned via OneLinkProvisioning, onelink_uuid is
            // set and is the correct value to push. For a company still on the
            // older manual-paste-in flow (api/banking/save.php), onelink_uuid is
            // never populated — fall back to the manually-entered salt column,
            // since that's the only value we have for those.
            $stmt = $pdo->prepare('
                SELECT c.uuid, o.base_url, o.terminal_id, o.salt, o.onelink_uuid, o.token, o.enabled
                FROM companies c
                JOIN onelink_credentials o ON o.company_id = c.id
                WHERE c.id = :id AND c.status = "active"
                LIMIT 1
            ');
            $stmt->execute(['id' => $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return; // no credentials saved yet — nothing to push
            }

            $salt = (string)$row['onelink_uuid'] !== '' ? (string)$row['onelink_uuid'] : (string)$row['salt'];

            $payload = [
                'event'        => 'company.onelink.updated',
                'company_uuid' => $row['uuid'],
                'base_url'     => (string)$row['base_url'],
                'terminal_id'  => (string)$row['terminal_id'],
                'salt'         => $salt,
                'token'        => (string)$row['token'],
                'enabled'      => ((int)$row['enabled'] === 1),
                'sent_at'      => gmdate('c'),
            ];

            $rawBody   = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $signature = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

            self::post($url, $rawBody, $signature);
        } catch (Throwable $e) {
            error_log('OnePayWebhook::onelinkCredentialsSynced failed: ' . $e->getMessage());
        }
    }

    /**
     * Short-timeout POST. Errors are intentionally non-fatal.
     */
    private static function post(string $url, string $rawBody, string $signature): void
    {
        if (!function_exists('curl_init')) {
            error_log('OnePayWebhook: cURL unavailable — skipping sync.');
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
            error_log('OnePayWebhook: POST to ' . $url . ' failed: ' . $err);
        }
    }
}
