<?php

class TvMetricsService
{
    public static function currentViewerCount(int $eventId): int
    {
        $window = (int)tv_config('viewer_active_window_seconds');
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM tv_viewer_sessions
             WHERE event_id = :event_id
               AND ended_at IS NULL
               AND last_seen_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)'
        );
        $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->bindValue(':window', $window, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public static function recordHeartbeat(int $eventId, ?int $userId, string $sessionToken): int
    {
        $existing = db()->prepare('SELECT id FROM tv_viewer_sessions WHERE session_token = :token LIMIT 1');
        $existing->execute(['token' => $sessionToken]);
        $id = (int)($existing->fetchColumn() ?: 0);

        if ($id > 0) {
            db()->prepare(
                'UPDATE tv_viewer_sessions
                 SET last_seen_at = NOW(), ended_at = NULL
                 WHERE id = :id'
            )->execute(['id' => $id]);
        } else {
            db()->prepare(
                'INSERT INTO tv_viewer_sessions (
                    user_id, event_id, session_token, ip_address, user_agent,
                    started_at, last_seen_at
                 ) VALUES (
                    :user_id, :event_id, :session_token, :ip_address, :user_agent,
                    NOW(), NOW()
                 )'
            )->execute([
                'user_id' => $userId,
                'event_id' => $eventId,
                'session_token' => $sessionToken,
                'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        }

        return self::currentViewerCount($eventId);
    }

    public static function dashboardStats(int $organizationId): array
    {
        $stats = [
            'live_now' => 0,
            'upcoming_events' => 0,
            'total_viewers' => 0,
            'total_channels' => 0,
            'total_events' => 0,
            'unique_viewers' => 0,
            'total_watch_sessions' => 0,
            'average_watch_minutes' => 0,
        ];

        $stmt = db()->prepare(
            'SELECT
                SUM(CASE WHEN status = "live" THEN 1 ELSE 0 END) AS live_now,
                SUM(CASE WHEN status = "scheduled" THEN 1 ELSE 0 END) AS upcoming_events,
                COUNT(*) AS total_events
             FROM tv_events
             WHERE organization_id = :organization_id'
        );
        $stmt->execute(['organization_id' => $organizationId]);
        $stats = array_merge($stats, $stmt->fetch() ?: []);

        $channels = db()->prepare('SELECT COUNT(*) FROM tv_channels WHERE organization_id = :organization_id');
        $channels->execute(['organization_id' => $organizationId]);
        $stats['total_channels'] = (int)$channels->fetchColumn();

        $sessions = db()->prepare(
            'SELECT
                COUNT(*) AS total_watch_sessions,
                COUNT(DISTINCT COALESCE(CAST(user_id AS CHAR), session_token)) AS unique_viewers,
                COALESCE(AVG(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, last_seen_at))), 0) AS avg_seconds
             FROM tv_viewer_sessions vs
             JOIN tv_events e ON e.id = vs.event_id
             WHERE e.organization_id = :organization_id'
        );
        $sessions->execute(['organization_id' => $organizationId]);
        $sessionRow = $sessions->fetch() ?: [];
        $stats['total_watch_sessions'] = (int)($sessionRow['total_watch_sessions'] ?? 0);
        $stats['unique_viewers'] = (int)($sessionRow['unique_viewers'] ?? 0);
        $stats['average_watch_minutes'] = round(((int)($sessionRow['avg_seconds'] ?? 0)) / 60, 1);

        $viewers = db()->prepare(
            'SELECT COUNT(*)
             FROM tv_viewer_sessions vs
             JOIN tv_events e ON e.id = vs.event_id
             WHERE e.organization_id = :organization_id
               AND vs.ended_at IS NULL
               AND vs.last_seen_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)'
        );
        $viewers->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
        $viewers->bindValue(':window', (int)tv_config('viewer_active_window_seconds'), PDO::PARAM_INT);
        $viewers->execute();
        $stats['total_viewers'] = (int)$viewers->fetchColumn();

        return $stats;
    }

    public static function recentActivity(int $organizationId, int $limit = 8): array
    {
        $stmt = db()->prepare(
            'SELECT l.*, u.first_name, u.last_name
             FROM tv_audit_logs l
             LEFT JOIN users u ON u.id = l.user_id
             WHERE l.organization_id = :organization_id
             ORDER BY l.created_at DESC
             LIMIT ' . max(1, (int)$limit)
        );
        $stmt->execute(['organization_id' => $organizationId]);
        return $stmt->fetchAll();
    }

    public static function mostWatchedEvents(int $organizationId, int $limit = 5): array
    {
        $stmt = db()->prepare(
            'SELECT e.title, e.slug, COUNT(vs.id) AS sessions, COUNT(DISTINCT COALESCE(CAST(vs.user_id AS CHAR), vs.session_token)) AS unique_viewers
             FROM tv_events e
             LEFT JOIN tv_viewer_sessions vs ON vs.event_id = e.id
             WHERE e.organization_id = :organization_id
             GROUP BY e.id
             ORDER BY sessions DESC, unique_viewers DESC, e.start_at DESC
             LIMIT ' . max(1, (int)$limit)
        );
        $stmt->execute(['organization_id' => $organizationId]);
        return $stmt->fetchAll();
    }

    public static function viewerSessions(int $organizationId, int $limit = 25): array
    {
        $stmt = db()->prepare(
            'SELECT vs.*, e.title, u.first_name, u.last_name, u.email
             FROM tv_viewer_sessions vs
             JOIN tv_events e ON e.id = vs.event_id
             LEFT JOIN users u ON u.id = vs.user_id
             WHERE e.organization_id = :organization_id
             ORDER BY vs.last_seen_at DESC
             LIMIT ' . max(1, (int)$limit)
        );
        $stmt->execute(['organization_id' => $organizationId]);
        return $stmt->fetchAll();
    }
}

