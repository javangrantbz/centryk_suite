<?php

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/FormsService.php';

/**
 * Public, passcode-protected results page for a form (/results/<token>).
 *
 * The page shows aggregate charts and totals only; it never includes names,
 * phone numbers, emails or free-text answers. Access needs a 4 to 6 digit code
 * that the company sets and that is stored hashed. Because a short code is easy
 * to guess, wrong attempts are throttled: 5 misses from one address, or 30 across
 * everyone, lock the link for 15 minutes.
 */
class FormResultsShare
{
    private const MAX_PER_IP = 5;
    private const MAX_PER_FORM = 30;
    private const WINDOW_MINUTES = 15;

    private static function pdo(): PDO
    {
        return DB::pdo();
    }

    /** Builder view of a form's sharing state (never includes the code). */
    public static function status(int $formId, int $companyId): array
    {
        if (!FormsService::getForm($formId, $companyId)) {
            throw new RuntimeException('Form not found.');
        }
        $st = self::pdo()->prepare("SELECT token, enabled FROM form_results_shares WHERE form_id = :id");
        $st->execute(['id' => $formId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return [
            'enabled' => $row ? (bool)$row['enabled'] : false,
            'token'   => $row ? (string)$row['token'] : null,
        ];
    }

    /** 4 to 6 digits, nothing else. */
    public static function cleanPin(string $pin): string
    {
        $pin = trim($pin);
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new RuntimeException('The code must be 4 to 6 digits.');
        }
        return $pin;
    }

    /** Turn sharing on (or change the code). Keeps the same link when one already exists. */
    public static function enable(int $formId, int $companyId, int $userId, string $pin): array
    {
        $pin = self::cleanPin($pin);
        if (!FormsService::getForm($formId, $companyId)) {
            throw new RuntimeException('Form not found.');
        }
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        self::pdo()->prepare("
            INSERT INTO form_results_shares (form_id, token, pin_hash, enabled, created_by)
            VALUES (:id, :tok, :hash, 1, :uid)
            ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash), enabled = 1
        ")->execute(['id' => $formId, 'tok' => bin2hex(random_bytes(16)), 'hash' => $hash, 'uid' => $userId]);

        // A new code starts with a clean slate.
        self::pdo()->prepare("DELETE FROM form_results_attempts WHERE form_id = :id")->execute(['id' => $formId]);
        return self::status($formId, $companyId);
    }

    public static function disable(int $formId, int $companyId): array
    {
        if (!FormsService::getForm($formId, $companyId)) {
            throw new RuntimeException('Form not found.');
        }
        self::pdo()->prepare("UPDATE form_results_shares SET enabled = 0 WHERE form_id = :id")->execute(['id' => $formId]);
        return self::status($formId, $companyId);
    }

    /** Public lookup by link token: only an enabled share on an active company. */
    public static function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $st = self::pdo()->prepare("
            SELECT s.form_id, s.pin_hash, f.title, f.theme, f.company_id, f.status AS form_status,
                   c.name AS company_name, c.logo AS company_logo
            FROM form_results_shares s
            JOIN form_forms f ON f.id = s.form_id
            JOIN companies c ON c.id = f.company_id
            WHERE s.token = :t AND s.enabled = 1 AND c.status = 'active'
        ");
        $st->execute(['t' => $token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** True while too many wrong codes have been tried recently. */
    public static function isLocked(int $formId, string $ip): bool
    {
        $ipHash = self::ipHash($formId, $ip);
        $st = self::pdo()->prepare("
            SELECT COUNT(*) AS n_all, SUM(ip_hash = :ip) AS n_ip
            FROM form_results_attempts
            WHERE form_id = :id AND attempted_at > (NOW() - INTERVAL " . self::WINDOW_MINUTES . " MINUTE)
        ");
        $st->execute(['id' => $formId, 'ip' => $ipHash]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return (int)$r['n_ip'] >= self::MAX_PER_IP || (int)$r['n_all'] >= self::MAX_PER_FORM;
    }

    /**
     * Check a code. Returns 'ok', 'bad' or 'locked'. While locked the code is not
     * even checked, so the lock can't be used to test guesses.
     */
    public static function verify(array $share, string $pin, string $ip): string
    {
        $formId = (int)$share['form_id'];
        if (self::isLocked($formId, $ip)) {
            return 'locked';
        }
        if (preg_match('/^\d{4,6}$/', trim($pin)) && password_verify(trim($pin), (string)$share['pin_hash'])) {
            return 'ok';
        }
        self::pdo()->prepare("INSERT INTO form_results_attempts (form_id, ip_hash) VALUES (:id, :ip)")
            ->execute(['id' => $formId, 'ip' => self::ipHash($formId, $ip)]);
        return 'bad';
    }

    private static function ipHash(int $formId, string $ip): string
    {
        return hash('sha256', $formId . '|' . $ip);
    }
}
