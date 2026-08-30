<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Connections.php';
require_once __DIR__ . '/NotificationService.php';

class ConnectionEventShareService
{
    private static bool $schemaChecked = false;

    private static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $pdo = DB::pdo();
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS company_connection_event_shares (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    connection_id INT UNSIGNED NOT NULL,
                    owner_company_id INT UNSIGNED NOT NULL,
                    recipient_company_id INT UNSIGNED NOT NULL,
                    title VARCHAR(180) NOT NULL,
                    description TEXT NULL,
                    event_date DATE NOT NULL,
                    event_type VARCHAR(40) NOT NULL DEFAULT 'other',
                    color VARCHAR(20) NOT NULL DEFAULT 'slate',
                    status ENUM('pending','accepted','declined','revoked') NOT NULL DEFAULT 'pending',
                    created_by_user_id INT UNSIGNED NULL,
                    responded_by_user_id INT UNSIGNED NULL,
                    accepted_event_id INT UNSIGNED NULL,
                    responded_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_cces_owner (owner_company_id, status, event_date),
                    INDEX idx_cces_recipient (recipient_company_id, status, event_date),
                    INDEX idx_cces_connection (connection_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $evtColumns = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
            $evtColumns = array_fill_keys($evtColumns ?: [], true);
            if (!isset($evtColumns['source_connection_event_share_id'])) {
                $pdo->exec('ALTER TABLE events ADD COLUMN source_connection_event_share_id INT UNSIGNED NULL AFTER created_by');
                $pdo->exec('ALTER TABLE events ADD INDEX idx_events_source_connection_share (source_connection_event_share_id)');
            }
        } catch (Throwable $e) {
            // Leave other Connect features working even if this schema step fails.
        }
    }

    public static function listForCompany(int $companyId): array
    {
        self::ensureSchema();
        if ($companyId <= 0) {
            return ['incoming' => [], 'outgoing' => []];
        }

        $sql = "
            SELECT s.*, owner.name AS owner_company_name, recipient.name AS recipient_company_name
            FROM company_connection_event_shares s
            JOIN companies owner ON owner.id = s.owner_company_id
            JOIN companies recipient ON recipient.id = s.recipient_company_id
            WHERE %s
            ORDER BY s.event_date ASC, s.created_at DESC, s.id DESC
            LIMIT 100
        ";

        $incoming = DB::pdo()->prepare(sprintf($sql, 's.recipient_company_id = ?'));
        $incoming->execute([$companyId]);

        $outgoing = DB::pdo()->prepare(sprintf($sql, 's.owner_company_id = ?'));
        $outgoing->execute([$companyId]);

        return [
            'incoming' => $incoming->fetchAll(PDO::FETCH_ASSOC),
            'outgoing' => $outgoing->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public static function create(int $ownerCompanyId, int $recipientCompanyId, int $actorUserId, array $data): array
    {
        self::ensureSchema();
        $connection = Connections::getByIdForCompany((int) ($data['connection_id'] ?? 0), $ownerCompanyId);
        if (!$connection || ($connection['status'] ?? '') !== 'accepted') {
            return ['success' => false, 'message' => 'That connection is not available.'];
        }
        if ((int) ($connection['other_company_id'] ?? 0) !== $recipientCompanyId) {
            return ['success' => false, 'message' => 'That connection does not match the selected company.'];
        }
        if (!Connections::permits($ownerCompanyId, $recipientCompanyId, 'share_events')) {
            return ['success' => false, 'message' => 'This connection is not allowed to share events yet.'];
        }

        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $eventDate = trim((string) ($data['event_date'] ?? ''));
        $eventType = trim((string) ($data['event_type'] ?? 'other'));
        $color = trim((string) ($data['color'] ?? 'slate'));

        if ($title === '') {
            return ['success' => false, 'message' => 'Event title is required.'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            return ['success' => false, 'message' => 'Event date must be YYYY-MM-DD.'];
        }

        $allowedTypes = ['meeting', 'holiday', 'deadline', 'training', 'other'];
        $allowedColors = ['slate', 'blue', 'teal', 'green', 'amber', 'red', 'purple'];
        if (!in_array($eventType, $allowedTypes, true)) {
            $eventType = 'other';
        }
        if (!in_array($color, $allowedColors, true)) {
            $color = 'slate';
        }

        $stmt = DB::pdo()->prepare(
            'INSERT INTO company_connection_event_shares
             (connection_id, owner_company_id, recipient_company_id, title, description, event_date, event_type, color, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $connection['id'],
            $ownerCompanyId,
            $recipientCompanyId,
            mb_substr($title, 0, 180),
            $description !== '' ? mb_substr($description, 0, 2000) : null,
            $eventDate,
            $eventType,
            $color,
            $actorUserId > 0 ? $actorUserId : null,
        ]);

        self::notifyCompanyAdmins(
            $recipientCompanyId,
            'Shared event offer',
            ($connection['other_company_name'] ?? 'A connected company') . ': ' . $title . ' on ' . $eventDate
        );

        return ['success' => true, 'message' => 'Shared event sent.'];
    }

    public static function respond(int $shareId, int $recipientCompanyId, int $actorUserId, string $action): array
    {
        self::ensureSchema();
        if (!in_array($action, ['accepted', 'declined'], true)) {
            return ['success' => false, 'message' => 'Invalid shared event action.'];
        }

        $pdo = DB::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM company_connection_event_shares
             WHERE id = ? AND recipient_company_id = ?
             LIMIT 1'
        );
        $stmt->execute([$shareId, $recipientCompanyId]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$share) {
            return ['success' => false, 'message' => 'That shared event was not found.'];
        }
        if (($share['status'] ?? '') !== 'pending') {
            return ['success' => false, 'message' => 'That shared event is no longer pending.'];
        }

        $pdo->beginTransaction();
        try {
            $acceptedEventId = null;
            if ($action === 'accepted') {
                $eventStmt = $pdo->prepare(
                    'INSERT INTO events
                     (company_id, title, description, event_date, event_type, color, created_by, source_connection_event_share_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $eventStmt->execute([
                    $recipientCompanyId,
                    $share['title'],
                    $share['description'],
                    $share['event_date'],
                    $share['event_type'],
                    $share['color'],
                    $actorUserId,
                    $shareId,
                ]);
                $acceptedEventId = (int) $pdo->lastInsertId();
            }

            $upd = $pdo->prepare(
                'UPDATE company_connection_event_shares
                 SET status = ?, responded_by_user_id = ?, accepted_event_id = ?, responded_at = NOW()
                 WHERE id = ? AND recipient_company_id = ? AND status = "pending"'
            );
            $upd->execute([
                $action,
                $actorUserId > 0 ? $actorUserId : null,
                $acceptedEventId,
                $shareId,
                $recipientCompanyId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Could not update shared event.'];
        }

        self::notifyCompanyAdmins(
            (int) $share['owner_company_id'],
            'Shared event updated',
            'Your event "' . $share['title'] . '" was ' . ($action === 'accepted' ? 'accepted' : 'declined') . '.'
        );

        return ['success' => true, 'message' => $action === 'accepted' ? 'Event added to your calendar.' : 'Shared event declined.'];
    }

    public static function revoke(int $shareId, int $ownerCompanyId): array
    {
        self::ensureSchema();
        $pdo = DB::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM company_connection_event_shares
             WHERE id = ? AND owner_company_id = ?
             LIMIT 1'
        );
        $stmt->execute([$shareId, $ownerCompanyId]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$share) {
            return ['success' => false, 'message' => 'That shared event was not found.'];
        }
        if (!in_array((string) ($share['status'] ?? ''), ['pending', 'accepted'], true)) {
            return ['success' => false, 'message' => 'That shared event cannot be revoked now.'];
        }

        $pdo->beginTransaction();
        try {
            if (!empty($share['accepted_event_id'])) {
                $del = $pdo->prepare('DELETE FROM events WHERE id = ? AND company_id = ?');
                $del->execute([(int) $share['accepted_event_id'], (int) $share['recipient_company_id']]);
            }

            $upd = $pdo->prepare(
                'UPDATE company_connection_event_shares
                 SET status = "revoked", responded_at = NOW()
                 WHERE id = ? AND owner_company_id = ?'
            );
            $upd->execute([$shareId, $ownerCompanyId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Could not revoke shared event.'];
        }

        self::notifyCompanyAdmins(
            (int) $share['recipient_company_id'],
            'Shared event revoked',
            'A connected company revoked the event "' . $share['title'] . '".'
        );

        return ['success' => true, 'message' => 'Shared event revoked.'];
    }

    private static function notifyCompanyAdmins(int $companyId, string $title, string $body): void
    {
        try {
            $stmt = DB::pdo()->prepare(
                "SELECT user_id FROM company_members
                 WHERE company_id = ? AND status = 'active' AND role = 'admin'"
            );
            $stmt->execute([$companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                NotificationService::create([
                    'user_id' => (int) $row['user_id'],
                    'company_id' => $companyId,
                    'app_key' => 'calendar',
                    'type' => 'connection.event_share',
                    'title' => $title,
                    'body' => $body,
                    'url' => 'connections.php?company_id=' . $companyId,
                    'icon' => 'calendar-plus',
                    'color' => '#14b8a6',
                ]);
            }
        } catch (Throwable $e) {
            // Notifications are best-effort.
        }
    }
}
