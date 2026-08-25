<?php
/**
 * Auto-provisions a OneLink merchant account via OneLink's POST /user/create
 * API, replacing the old fully-manual "platform admin pastes in a terminal
 * ID/salt/token they got out of band" flow.
 *
 * We choose terminalId and sSalt ourselves (OneLink doesn't generate them);
 * OneLink returns a token (used for /processPayment, same as before) and an
 * access_code the merchant enters at https://op.onelink.bz/docs.php's login
 * tab to manage their own account — OneLink's side handles bank-detail
 * entry, nothing here submits banking info anywhere.
 *
 * Confirmed against the live docs page's own test-console JS (not just the
 * prose): POST https://op.onelink.bz/user/create, Content-Type: application/json,
 * no additional auth header.
 */
require_once __DIR__ . '/OnePayWebhook.php';
require_once __DIR__ . '/MailerService.php';

class OneLinkProvisioning
{
    private const BASE_URL = 'https://op.onelink.bz';

    /**
     * Idempotent: a no-op returning {success:true, already:true} if this
     * company is already enabled. Never throws — callers get a result array
     * either way, so a signup-time call can be fire-and-forget while an
     * admin-triggered call can still show the user what happened.
     */
    public static function provision(PDO $pdo, int $companyId): array
    {
        try {
            $existing = $pdo->prepare('SELECT enabled FROM onelink_credentials WHERE company_id = :cid LIMIT 1');
            $existing->execute(['cid' => $companyId]);
            if ((int)($existing->fetchColumn() ?: 0) === 1) {
                return ['success' => true, 'already' => true, 'message' => 'Already provisioned.'];
            }

            $companyStmt = $pdo->prepare('
                SELECT c.uuid, c.name, c.address, c.phone AS company_phone, c.email AS company_email,
                       u.first_name, u.last_name, u.email AS admin_email, u.phone AS admin_phone
                FROM companies c
                JOIN users u ON u.id = c.owner_id
                WHERE c.id = :cid AND c.status = "active"
                LIMIT 1
            ');
            $companyStmt->execute(['cid' => $companyId]);
            $row = $companyStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return self::fail($pdo, $companyId, 'Company not found or inactive.');
            }

            $email = trim((string)($row['company_email'] ?: $row['admin_email']));
            $phone = trim((string)($row['company_phone'] ?: $row['admin_phone']));
            $name  = trim((string)($row['first_name'] . ' ' . $row['last_name']));

            $missing = [];
            if ($email === '') $missing[] = 'email';
            if ($phone === '') $missing[] = 'phone';
            if ($name === '')  $missing[] = 'admin name';
            if (!empty($missing)) {
                return self::fail($pdo, $companyId, 'Missing required ' . implode(', ', $missing)
                    . ' (checked both the company record and its admin) — add it in the company profile, then try again.');
            }

            $terminalId = self::generateTerminalId((string)$row['uuid']);
            $salt       = bin2hex(random_bytes(24));
            $password   = bin2hex(random_bytes(24)); // generated, never persisted — access_code is the retrieval mechanism

            $payload = [
                'uFname'     => self::sanitizeForLegacyCharset($name),
                'uUname'     => self::generateUsername($email, $companyId),
                'uEmail'     => $email,
                'uPassword'  => $password,
                'uPhone'     => $phone,
                'bname'      => self::sanitizeForLegacyCharset((string)$row['name']),
                'terminalId' => $terminalId,
                'location'   => self::sanitizeForLegacyCharset(trim((string)$row['address']) ?: 'Belize'),
                'sSalt'      => $salt,
                'roleId'     => 8,
            ];

            $result = self::post('/user/create', $payload);

            // Email must be unique on OneLink's side — one admin can own multiple
            // companies, so retry once with a +tag on collision.
            if (empty($result['success']) && stripos((string)($result['message'] ?? ''), 'already') !== false) {
                $payload['uEmail'] = self::tagEmail($email, $companyId);
                $email = $payload['uEmail'];
                $result = self::post('/user/create', $payload);
            }

            if (empty($result['success']) || empty($result['user'])) {
                return self::fail($pdo, $companyId, (string)($result['message'] ?? 'OneLink did not return a user.'));
            }

            $u = $result['user'];
            $pdo->prepare('
                INSERT INTO onelink_credentials
                    (company_id, base_url, terminal_id, salt, token, access_code, fee_percentage, onelink_uid, onelink_uuid, enabled, provisioned_at, provision_error)
                VALUES
                    (:cid, :base_url, :terminal_id, :salt, :token, :access_code, :fee_pct, :uid, :uuid, 1, NOW(), NULL)
                ON DUPLICATE KEY UPDATE
                    base_url = VALUES(base_url), terminal_id = VALUES(terminal_id), salt = VALUES(salt),
                    token = VALUES(token), access_code = VALUES(access_code), fee_percentage = VALUES(fee_percentage),
                    onelink_uid = VALUES(onelink_uid), onelink_uuid = VALUES(onelink_uuid),
                    enabled = 1, provisioned_at = NOW(), provision_error = NULL
            ')->execute([
                'cid'         => $companyId,
                'base_url'    => self::BASE_URL,
                'terminal_id' => $terminalId,
                'salt'        => $salt,
                'token'       => (string)($u['token'] ?? ''),
                'access_code' => (string)($u['access_code'] ?? ''),
                'fee_pct'     => isset($u['perc']) ? (float)$u['perc'] : null,
                'uid'         => isset($u['uid']) ? (int)$u['uid'] : null,
                'uuid'        => (string)($u['u_uuid'] ?? ''),
            ]);

            OnePayWebhook::onelinkCredentialsSynced($pdo, $companyId);
            self::emailAccessCode($email, $name, (string)$row['name'], (string)($u['access_code'] ?? ''));

            // A company can save its settlement bank account in Centryk before
            // provisioning ever runs (they're independent forms/flows) — push
            // it now rather than leaving it stranded until the company happens
            // to touch the banking form again.
            $bankStmt = $pdo->prepare('SELECT bank_name, account_holder, account_number FROM company_bank_accounts WHERE company_id = :cid LIMIT 1');
            $bankStmt->execute(['cid' => $companyId]);
            if ($bank = $bankStmt->fetch(PDO::FETCH_ASSOC)) {
                $sync = self::syncBankInfo($pdo, $companyId, (string)$bank['account_holder'], (string)$bank['bank_name'], (string)$bank['account_number']);
                $pdo->prepare('
                    UPDATE company_bank_accounts
                    SET onelink_synced_at = :synced_at, onelink_sync_error = :sync_error
                    WHERE company_id = :cid
                ')->execute([
                    'synced_at'  => !empty($sync['success']) ? date('Y-m-d H:i:s') : null,
                    'sync_error' => empty($sync['success']) ? substr((string)($sync['message'] ?? ''), 0, 255) : null,
                    'cid'        => $companyId,
                ]);
            }

            return [
                'success'     => true,
                'access_code' => (string)($u['access_code'] ?? ''),
                'message'     => 'OneLink account provisioned.',
            ];
        } catch (Throwable $e) {
            error_log('OneLinkProvisioning::provision failed: ' . $e->getMessage());
            return self::fail($pdo, $companyId, $e->getMessage());
        }
    }

    /**
     * The three settlement banks OneLink's /user/bank/info actually accepts
     * (confirmed against the live docs page, including its error response
     * listing them back verbatim). Anything else — the other Belize banks and
     * credit unions our own bank_name dropdown offers — is rejected server
     * side, so we pre-check here rather than firing a request guaranteed to
     * fail. Matched case-insensitively; the canonical spelling (OneLink's,
     * which matches ours) is what gets sent.
     */
    private const SUPPORTED_BANKS = ['Belize Bank', 'Heritage Bank', 'Atlantic Bank'];

    /**
     * Pushes a company's settlement bank account to OneLink via
     * POST /user/bank/info, so the merchant no longer has to separately log
     * into OneLink's own portal to enter it — Centryk's own banking form is
     * now the single place this gets typed.
     *
     * Unlike provision(), this endpoint has no token/terminalId/salt
     * auth — just the numeric `uid` OneLink returned from /user/create.
     * "Already on file" (same bank+account already stored for this uid) is
     * treated as success, not an error: the end state matches what we asked
     * for either way.
     */
    public static function syncBankInfo(PDO $pdo, int $companyId, string $accountHolder, string $bankName, string $accountNumber): array
    {
        $uidStmt = $pdo->prepare('SELECT onelink_uid FROM onelink_credentials WHERE company_id = :cid AND enabled = 1 LIMIT 1');
        $uidStmt->execute(['cid' => $companyId]);
        $uid = $uidStmt->fetchColumn();
        if (!$uid) {
            return ['success' => false, 'skipped' => true, 'message' => 'Company is not yet provisioned with OneLink.'];
        }

        $canonicalBank = null;
        foreach (self::SUPPORTED_BANKS as $supported) {
            if (strcasecmp($supported, $bankName) === 0) {
                $canonicalBank = $supported;
                break;
            }
        }
        if ($canonicalBank === null) {
            return [
                'success'          => false,
                'unsupported_bank' => true,
                'message'          => 'OneLink only settles to ' . implode(', ', self::SUPPORTED_BANKS)
                    . ' — "' . $bankName . '" is saved in Centryk but was not sent to OneLink.',
            ];
        }

        $result = self::post('/user/bank/info', [
            'uid'     => (int)$uid,
            'aName'   => self::sanitizeForLegacyCharset($accountHolder),
            'bName'   => $canonicalBank,
            'bNumber' => $accountNumber,
        ]);

        if (!empty($result['success'])) {
            return [
                'success'  => true,
                'bi_uuid'  => (string)($result['bankInfo']['bi_uuid'] ?? ''),
            ];
        }

        $message = (string)($result['message'] ?? 'OneLink rejected the bank details.');
        if (stripos($message, 'already on file') !== false) {
            return ['success' => true, 'already' => true];
        }

        return ['success' => false, 'message' => $message];
    }

    private static function fail(PDO $pdo, int $companyId, string $message): array
    {
        try {
            $pdo->prepare('
                INSERT INTO onelink_credentials (company_id, provision_error)
                VALUES (:cid, :err)
                ON DUPLICATE KEY UPDATE provision_error = VALUES(provision_error)
            ')->execute(['cid' => $companyId, 'err' => substr($message, 0, 255)]);
        } catch (Throwable $e) {
            error_log('OneLinkProvisioning::fail could not record error: ' . $e->getMessage());
        }
        return ['success' => false, 'message' => $message];
    }

    private static function generateTerminalId(string $companyUuid): string
    {
        return 'CTK-' . strtoupper(substr(str_replace('-', '', $companyUuid), 0, 10));
    }

    /**
     * OneLink's own database rejected a value containing U+2044 (fraction
     * slash, e.g. from an address like "4⁄M..." — likely a word-processor
     * autocorrect artifact) with a raw SQL charset error. Normalize the
     * "smart punctuation" characters that commonly sneak in via copy-paste
     * (fraction slash, smart quotes, em/en dash, ellipsis, no-break space)
     * to their plain-ASCII equivalents, without stripping genuine accented
     * letters (é, ñ, etc.) that are legitimate in real names/addresses.
     */
    private static function sanitizeForLegacyCharset(string $value): string
    {
        $replacements = [
            "\xE2\x81\x84" => '/',   // U+2044 fraction slash
            "\xE2\x80\x98" => "'",   // U+2018 left single quote
            "\xE2\x80\x99" => "'",   // U+2019 right single quote
            "\xE2\x80\x9C" => '"',   // U+201C left double quote
            "\xE2\x80\x9D" => '"',   // U+201D right double quote
            "\xE2\x80\x93" => '-',   // U+2013 en dash
            "\xE2\x80\x94" => '-',   // U+2014 em dash
            "\xE2\x80\xA6" => '...', // U+2026 horizontal ellipsis
            "\xC2\xA0"     => ' ',   // U+00A0 non-breaking space
        ];
        return trim(str_replace(array_keys($replacements), array_values($replacements), $value));
    }

    private static function generateUsername(string $email, int $companyId): string
    {
        $local = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $email)[0] ?? 'merchant'));
        return substr($local, 0, 20) . $companyId;
    }

    private static function tagEmail(string $email, int $companyId): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return $email;
        }
        return $parts[0] . '+company' . $companyId . '@' . $parts[1];
    }

    private static function post(string $path, array $payload): array
    {
        $url  = self::BASE_URL . $path;
        $json = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 45,
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

    private static function emailAccessCode(string $toEmail, string $adminName, string $companyName, string $accessCode): void
    {
        if ($accessCode === '') {
            return;
        }
        try {
            $greeting = $adminName !== '' ? $adminName : 'there';
            $html = '
                <div style="font-family:Arial,sans-serif;line-height:1.7;color:#0f172a;max-width:580px">
                  <h2 style="margin:0 0 16px;font-size:21px">Your OneLink payment account is ready</h2>
                  <p style="margin:0 0 12px">Hi ' . htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') . ',</p>
                  <p style="margin:0 0 12px">A OneLink card-payment account has been created for <strong>' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>
                  <p style="margin:0 0 12px">Access code: <strong style="font-family:monospace;font-size:16px">' . htmlspecialchars($accessCode, ENT_QUOTES, 'UTF-8') . '</strong></p>
                  <p style="margin:0 0 24px">Use it to log into OneLink and finish setting up your account (including your settlement bank details).</p>
                  <a href="https://op.onelink.bz/docs.php" style="display:inline-block;background:#0f172a;color:#fff;font-weight:bold;padding:14px 28px;border-radius:10px;text-decoration:none;font-size:15px">Log in to OneLink</a>
                </div>
            ';
            $text = "Your OneLink payment account is ready\n\n"
                . "A OneLink card-payment account has been created for {$companyName}.\n"
                . "Access code: {$accessCode}\n\n"
                . "Log in at https://op.onelink.bz/docs.php to finish setup (including your settlement bank details).";
            (new MailerService())->send($toEmail, 'Your OneLink payment account is ready', $html, $text, 'onelink_provisioned');
        } catch (Throwable $e) {
            error_log('OneLinkProvisioning::emailAccessCode failed: ' . $e->getMessage());
        }
    }
}
