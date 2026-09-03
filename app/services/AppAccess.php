<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';
require_once __DIR__ . '/NotificationService.php';

/**
 * "Available Through Your Organization" — a user asking for an app they aren't
 * enrolled in. Opt-in apps self-enable elsewhere (AuthService::enableApp); this
 * covers the core apps (OnePay/MyPay/Calendar/Invoices) that need a company
 * admin to grant them.
 */
class AppAccess
{
    /** app_key[] the user currently has a pending request for. */
    public static function pendingKeys(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $stmt = DB::pdo()->prepare(
            "SELECT app_key FROM app_access_requests WHERE user_id = :uid AND status = 'pending'"
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Request access to $appKey. Grants immediately if the requester is an
     * owner/admin of any active company; otherwise records a pending request
     * and notifies each of their companies' owners/admins.
     *
     * @return array{granted?:bool, requested?:bool, message?:string}
     */
    public static function request(int $userId, string $appKey): array
    {
        $appKey = trim($appKey);
        if ($userId <= 0 || $appKey === '') {
            return ['message' => 'Bad request.'];
        }

        $pdo = DB::pdo();

        $app = $pdo->prepare("SELECT id, label FROM apps WHERE `key` = :k AND status = 'active' LIMIT 1");
        $app->execute(['k' => $appKey]);
        $app = $app->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            return ['message' => 'Unknown app.'];
        }

        $has = $pdo->prepare("SELECT 1 FROM user_app_access WHERE user_id = :uid AND app_id = :aid LIMIT 1");
        $has->execute(['uid' => $userId, 'aid' => (int)$app['id']]);
        if ($has->fetchColumn()) {
            return ['granted' => true]; // already has it — nothing to do
        }

        // Every active company the user belongs to, and whether they run any of them.
        $co = $pdo->prepare("
            SELECT cm.company_id, cm.role
            FROM company_members cm
            JOIN companies c ON c.id = cm.company_id AND c.status = 'active'
            WHERE cm.user_id = :uid AND cm.status = 'active'
        ");
        $co->execute(['uid' => $userId]);
        $memberships = $co->fetchAll(PDO::FETCH_ASSOC);

        $companyIds  = array_map(static fn($m) => (int)$m['company_id'], $memberships);
        $runsACompany = false;
        foreach ($memberships as $m) {
            if (in_array($m['role'], ['owner', 'admin'], true)) {
                $runsACompany = true;
                break;
            }
        }

        // Owner/admin → grant on the spot.
        if ($runsACompany) {
            $pdo->prepare("INSERT IGNORE INTO user_app_access (user_id, app_id) VALUES (:uid, :aid)")
                ->execute(['uid' => $userId, 'aid' => (int)$app['id']]);
            self::markGranted($userId, $appKey, $userId);
            Audit::log([
                'actor_user_id' => $userId,
                'target_user_id' => $userId,
                'event_type' => 'app_access.self_grant',
                'summary' => 'Self-granted ' . $app['label'],
                'metadata' => ['app_key' => $appKey],
            ]);
            return ['granted' => true];
        }

        if (!$companyIds) {
            return ['message' => 'Join a company first — apps are granted by a company admin.'];
        }

        // Record the request (one live row per user+app).
        $pdo->prepare("
            INSERT INTO app_access_requests (user_id, app_key, company_id, status)
            VALUES (:uid, :k, :cid, 'pending')
            ON DUPLICATE KEY UPDATE status = 'pending', company_id = VALUES(company_id),
                                    created_at = CURRENT_TIMESTAMP, decided_at = NULL, decided_by = NULL
        ")->execute(['uid' => $userId, 'k' => $appKey, 'cid' => $companyIds[0]]);

        // Notify every owner/admin of every company the user is in.
        $name = self::userName($userId);
        $ph = implode(',', array_fill(0, count($companyIds), '?'));
        $adm = $pdo->prepare("
            SELECT DISTINCT u.id
            FROM company_members cm
            JOIN users u ON u.id = cm.user_id AND u.status = 'active'
            WHERE cm.company_id IN ($ph) AND cm.status = 'active'
              AND cm.role IN ('owner', 'admin') AND u.id <> ?
        ");
        $adm->execute(array_merge($companyIds, [$userId]));
        foreach ($adm->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            NotificationService::create([
                'user_id' => (int)$adminId,
                'app_key' => 'centryk',
                'type'    => 'app_access_request',
                'title'   => $name . ' requested access to ' . $app['label'],
                'body'    => 'Grant it from the member\'s app list.',
                'url'     => 'companies.php',
                'color'   => '#4f46e5',
            ]);
        }

        Audit::log([
            'actor_user_id' => $userId,
            'event_type' => 'app_access.request',
            'summary' => 'Requested access to ' . $app['label'],
            'metadata' => ['app_key' => $appKey, 'company_ids' => $companyIds],
        ]);

        return ['requested' => true];
    }

    /** Flip a matching pending request to granted. Called from toggle-access.php. */
    public static function markGranted(int $userId, string $appKey, int $actorId): void
    {
        try {
            DB::pdo()->prepare("
                UPDATE app_access_requests
                SET status = 'granted', decided_at = NOW(), decided_by = :actor
                WHERE user_id = :uid AND app_key = :k AND status = 'pending'
            ")->execute(['actor' => $actorId ?: null, 'uid' => $userId, 'k' => trim($appKey)]);
        } catch (Throwable $e) {
            // best-effort
        }
    }

    private static function userName(int $userId): string
    {
        $stmt = DB::pdo()->prepare("SELECT TRIM(CONCAT(first_name, ' ', last_name)) FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $n = trim((string)$stmt->fetchColumn());
        return $n !== '' ? $n : 'A teammate';
    }
}
