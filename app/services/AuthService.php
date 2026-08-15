<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/DB.php';

class AuthService
{
    public static function me(): array
    {
        $user = Auth::user();
        if (!$user) {
            return ['authenticated' => false];
        }

        return [
            'authenticated' => true,
            'user'          => $user,
            'apps'          => self::allAppsWithEnrollment((int)$user['id']),
        ];
    }

    public static function accessibleApps(int $userId): array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT a.key, a.label, a.description, a.url_local, a.url_production, a.icon, a.color
             FROM apps a
             JOIN user_app_access ua ON ua.app_id = a.id
             WHERE ua.user_id = :user_id AND a.status = "active"
             ORDER BY a.sort_order ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public static function allAppsWithEnrollment(int $userId): array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT a.key, a.label, a.description, a.url_local, a.url_production, a.icon, a.color,
                    COALESCE(a.opt_in, 0) AS opt_in,
                    (ua.user_id IS NOT NULL) AS enrolled
             FROM apps a
             LEFT JOIN user_app_access ua ON ua.app_id = a.id AND ua.user_id = :user_id
             WHERE a.status = "active"
             ORDER BY a.sort_order ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Self-enable an opt-in app for the given user (idempotent).
     * Returns true if granted, false if the app is not opt-in or not found.
     */
    public static function enableApp(int $userId, string $appKey): bool
    {
        $stmt = DB::pdo()->prepare(
            'SELECT id FROM apps WHERE `key` = :key AND status = "active" AND COALESCE(opt_in, 0) = 1 LIMIT 1'
        );
        $stmt->execute(['key' => $appKey]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        DB::pdo()->prepare('INSERT IGNORE INTO user_app_access (user_id, app_id) VALUES (:uid, :aid)')
            ->execute(['uid' => $userId, 'aid' => (int)$row['id']]);
        return true;
    }

    public static function launchApp(int $userId, string $appKey, string $companyUuid = '', string $redirectPath = ''): ?string
    {
        $stmt = DB::pdo()->prepare(
            'SELECT a.url_local, a.url_production FROM apps a
             JOIN user_app_access ua ON ua.app_id = a.id
             WHERE ua.user_id = :user_id AND a.key = :app_key AND a.status = "active" LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'app_key' => $appKey]);
        $app = $stmt->fetch();

        if (!$app) {
            return null;
        }

        // Use the production URL unless we're actually running on localhost,
        // so launching from the live server doesn't redirect to localhost.
        $host    = $_SERVER['HTTP_HOST'] ?? '';
        $isLocal = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host) === 1;
        $base    = ($isLocal || empty($app['url_production'])) ? $app['url_local'] : $app['url_production'];

        $token = Auth::issueToken($userId, $appKey);
        $url   = $base . '?sso_token=' . $token;
        if ($companyUuid !== '') {
            $url .= '&company_uuid=' . urlencode($companyUuid);
        }
        // Where to land inside the spoke app after SSO — e.g. deep-linking an
        // admin straight to an employee's record in MyPay. Only same-site
        // relative paths are ever accepted (enforced on the receiving end too);
        // this is just passed through as-is here.
        if ($redirectPath !== '') {
            $url .= '&redirect=' . urlencode($redirectPath);
        }
        return $url;
    }
}
