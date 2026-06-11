<?php
/**
 * Cross-application notifications, owned by Centryk.
 *
 * Apps either call these methods directly (local, shared DB) or insert through
 * their own Centryk DB connection. Reads are always scoped to a single
 * recipient (centryk users.id).
 */
require_once __DIR__ . '/../core/DB.php';

class NotificationService
{
    /** Create a notification for a recipient. Returns the new id (or 0 on no-op). */
    public static function create(array $n): int
    {
        $userId = (int)($n['user_id'] ?? 0);
        $title  = trim((string)($n['title'] ?? ''));
        if ($userId <= 0 || $title === '') {
            return 0;
        }

        $stmt = DB::pdo()->prepare("
            INSERT INTO notifications
                (user_id, company_id, app_key, type, title, body, url, icon, color)
            VALUES
                (:user_id, :company_id, :app_key, :type, :title, :body, :url, :icon, :color)
        ");
        $stmt->execute([
            'user_id'    => $userId,
            'company_id' => isset($n['company_id']) && $n['company_id'] !== null ? (int)$n['company_id'] : null,
            'app_key'    => substr((string)($n['app_key'] ?? 'centryk'), 0, 40),
            'type'       => substr((string)($n['type'] ?? 'general'), 0, 60),
            'title'      => substr($title, 0, 180),
            'body'       => substr((string)($n['body'] ?? ''), 0, 500),
            'url'        => substr((string)($n['url'] ?? ''), 0, 500),
            'icon'       => substr((string)($n['icon'] ?? ''), 0, 40),
            'color'      => substr((string)($n['color'] ?? ''), 0, 7),
        ]);

        return (int)DB::pdo()->lastInsertId();
    }

    /** Recent notifications for a recipient (newest first). */
    public static function recent(int $userId, int $limit = 15): array
    {
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $stmt = DB::pdo()->prepare("
            SELECT id, app_key, type, title, body, url, icon, color, read_at, created_at
            FROM notifications
            WHERE user_id = :user_id
            ORDER BY created_at DESC, id DESC
            LIMIT {$limit}
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /** Count of unread notifications for a recipient. */
    public static function unreadCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $stmt = DB::pdo()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL");
        $stmt->execute(['user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /** Mark every unread notification for a recipient as read. Returns rows affected. */
    public static function markAllRead(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $stmt = DB::pdo()->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = :user_id AND read_at IS NULL");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->rowCount();
    }

    /** Mark a single notification (scoped to the recipient) as read. */
    public static function markRead(int $userId, int $id): int
    {
        if ($userId <= 0 || $id <= 0) {
            return 0;
        }
        $stmt = DB::pdo()->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = :user_id AND id = :id AND read_at IS NULL");
        $stmt->execute(['user_id' => $userId, 'id' => $id]);
        return $stmt->rowCount();
    }
}
