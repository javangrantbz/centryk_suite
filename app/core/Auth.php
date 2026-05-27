<?php
require_once __DIR__ . '/DB.php';

class Auth
{
    public static function start(): void
    {
        $config = require __DIR__ . '/../config/config.php';
        if (session_status() === PHP_SESSION_NONE) {
            session_name($config['session_name']);
            session_start();
        }
    }

    public static function attempt(string $email, string $password): ?array
    {
        $pdo             = DB::pdo();
        $normalizedEmail = strtolower(trim($email));

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email AND status = "active" LIMIT 1');
        $stmt->execute(['email' => $normalizedEmail]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
            self::recordLoginEvent($user['id'] ?? null, $normalizedEmail, false);
            return null;
        }

        self::login((int)$user['id']);
        self::recordLoginEvent((int)$user['id'], $normalizedEmail, true);

        // Update last login timestamp
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $user['id']]);

        unset($user['password_hash']);
        return $user;
    }

    public static function login(int $userId): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id']     = $userId;
        $_SESSION['logged_in_at'] = time();
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function user(): ?array
    {
        self::start();
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        try {
            $stmt = DB::pdo()->prepare(
                'SELECT id, uuid, first_name, last_name, email, phone, status, mfa_enabled, is_admin, last_login_at, created_at
                 FROM users WHERE id = :id AND status = "active" LIMIT 1'
            );
            $stmt->execute(['id' => $_SESSION['user_id']]);
        } catch (Throwable $e) {
            // is_admin column not yet added — run database/migrate_v2_admin.sql
            $stmt = DB::pdo()->prepare(
                'SELECT id, uuid, first_name, last_name, email, phone, status, mfa_enabled, 0 AS is_admin, last_login_at, created_at
                 FROM users WHERE id = :id AND status = "active" LIMIT 1'
            );
            $stmt->execute(['id' => $_SESSION['user_id']]);
        }
        return $stmt->fetch() ?: null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    // --- SSO token methods ---

    public static function issueToken(int $userId, string $appKey): string
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 60); // 60-second window

        DB::pdo()->prepare(
            'INSERT INTO sso_tokens (user_id, token, app_key, expires_at) VALUES (:user_id, :token, :app_key, :expires_at)'
        )->execute([
            'user_id'    => $userId,
            'token'      => $token,
            'app_key'    => $appKey,
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }

    public static function consumeToken(string $token, string $appKey): ?array
    {
        $pdo  = DB::pdo();
        $stmt = $pdo->prepare(
            'SELECT t.user_id, t.used, t.expires_at
             FROM sso_tokens t
             WHERE t.token = :token AND t.app_key = :app_key LIMIT 1'
        );
        $stmt->execute(['token' => $token, 'app_key' => $appKey]);
        $row = $stmt->fetch();

        if (!$row || $row['used'] || strtotime($row['expires_at']) < time()) {
            return null;
        }

        // Mark as used (one-time)
        $pdo->prepare('UPDATE sso_tokens SET used = 1 WHERE token = :token')
            ->execute(['token' => $token]);

        $userStmt = $pdo->prepare(
            'SELECT id, uuid, first_name, last_name, email, phone, status, mfa_enabled FROM users WHERE id = :id AND status = "active" LIMIT 1'
        );
        $userStmt->execute(['id' => $row['user_id']]);
        $user = $userStmt->fetch();
        if (!$user) {
            return null;
        }

        // Include company memberships so apps can auto-provision company access
        $coStmt = $pdo->prepare('
            SELECT c.id, c.uuid, c.name, cm.role
            FROM company_members cm
            JOIN companies c ON c.id = cm.company_id
            WHERE cm.user_id = :uid AND cm.status = "active" AND c.status = "active"
            ORDER BY c.name ASC
        ');
        $coStmt->execute(['uid' => $row['user_id']]);
        $user['companies'] = $coStmt->fetchAll(PDO::FETCH_ASSOC);

        return $user;
    }

    public static function recordLoginEvent(?int $userId, string $email, bool $success): void
    {
        try {
            DB::pdo()->prepare(
                'INSERT INTO login_events (user_id, email, ip_address, user_agent, success)
                 VALUES (:user_id, :email, :ip_address, :user_agent, :success)'
            )->execute([
                'user_id'    => $userId,
                'email'      => $email,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'success'    => $success ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            // Never block login on audit failure
        }
    }
}
