<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Connections.php';
require_once __DIR__ . '/NotificationService.php';

class ConnectionMessageService
{
    private static bool $schemaChecked = false;

    private static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            DB::pdo()->exec(
                "CREATE TABLE IF NOT EXISTS company_connection_messages (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    connection_id INT UNSIGNED NOT NULL,
                    sender_company_id INT UNSIGNED NOT NULL,
                    recipient_company_id INT UNSIGNED NOT NULL,
                    sender_user_id INT UNSIGNED NULL,
                    message TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ccm_connection_created (connection_id, created_at),
                    INDEX idx_ccm_recipient_created (recipient_company_id, created_at),
                    INDEX idx_ccm_sender_created (sender_company_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            DB::pdo()->exec(
                "CREATE TABLE IF NOT EXISTS company_connection_message_reads (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    message_id INT UNSIGNED NOT NULL,
                    company_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED NULL,
                    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_ccmr_message_company (message_id, company_id),
                    INDEX idx_ccmr_company_read (company_id, read_at),
                    INDEX idx_ccmr_message (message_id),
                    CONSTRAINT fk_ccmr_message FOREIGN KEY (message_id) REFERENCES company_connection_messages(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ccmr_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ccmr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            // Keep Connect working even if message schema setup fails here.
        }
    }

    public static function listForCompany(int $companyId, int $limit = 100): array
    {
        self::ensureSchema();
        if ($companyId <= 0) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $stmt = DB::pdo()->prepare(
            "SELECT m.*, sender.name AS sender_company_name, recipient.name AS recipient_company_name,
                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS sender_name
             FROM company_connection_messages m
             JOIN companies sender ON sender.id = m.sender_company_id
             JOIN companies recipient ON recipient.id = m.recipient_company_id
             LEFT JOIN users u ON u.id = m.sender_user_id
             WHERE m.sender_company_id = ? OR m.recipient_company_id = ?
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$companyId, $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(int $companyId, int $targetCompanyId, int $actorUserId, array $data): array
    {
        self::ensureSchema();
        $connection = Connections::getByIdForCompany((int) ($data['connection_id'] ?? 0), $companyId);
        if (!$connection || ($connection['status'] ?? '') !== 'accepted') {
            return ['success' => false, 'message' => 'That connection is not available.'];
        }
        if ((int) ($connection['other_company_id'] ?? 0) !== $targetCompanyId) {
            return ['success' => false, 'message' => 'That connection does not match the selected company.'];
        }
        if (!Connections::permits($companyId, $targetCompanyId, 'message_admins')) {
            return ['success' => false, 'message' => 'This connection is not allowed to send admin messages yet.'];
        }

        $message = trim((string) ($data['message'] ?? ''));
        if ($message === '') {
            return ['success' => false, 'message' => 'A message is required.'];
        }
        $message = mb_substr($message, 0, 2000);

        $stmt = DB::pdo()->prepare(
            'INSERT INTO company_connection_messages
             (connection_id, sender_company_id, recipient_company_id, sender_user_id, message)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $connection['id'],
            $companyId,
            $targetCompanyId,
            $actorUserId > 0 ? $actorUserId : null,
            $message,
        ]);

        self::notifyCompanyAdmins(
            $targetCompanyId,
            'New partner message',
            ($connection['other_company_name'] ?? 'A connected company') . ': ' . mb_substr($message, 0, 120)
        );

        return ['success' => true, 'message' => 'Partner message sent.'];
    }

    public static function unreadIncomingSummary(int $companyId): array
    {
        self::ensureSchema();
        if ($companyId <= 0) {
            return ['count' => 0, 'threads' => []];
        }

        $pdo = DB::pdo();
        $threadStmt = $pdo->prepare(
            "SELECT m.id, m.connection_id, m.sender_company_id, m.recipient_company_id, m.sender_user_id, m.message, m.created_at,
                    sender.name AS sender_company_name,
                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS sender_name
             FROM company_connection_messages m
             JOIN companies sender ON sender.id = m.sender_company_id
             LEFT JOIN users u ON u.id = m.sender_user_id
             JOIN (
                SELECT m1.connection_id, MAX(m1.id) AS latest_message_id
                FROM company_connection_messages m1
                LEFT JOIN company_connection_message_reads r1
                  ON r1.message_id = m1.id AND r1.company_id = ?
                WHERE m1.recipient_company_id = ?
                  AND r1.id IS NULL
                GROUP BY m1.connection_id
             ) latest ON latest.latest_message_id = m.id
             ORDER BY m.created_at DESC, m.id DESC"
        );
        $threadStmt->execute([$companyId, $companyId]);
        $threads = $threadStmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM company_connection_messages m
             LEFT JOIN company_connection_message_reads r
               ON r.message_id = m.id AND r.company_id = ?
             WHERE m.recipient_company_id = ?
               AND r.id IS NULL"
        );
        $countStmt->execute([$companyId, $companyId]);

        return [
            'count' => (int) $countStmt->fetchColumn(),
            'threads' => $threads,
        ];
    }

    public static function markConnectionRead(int $companyId, int $userId, int $connectionId): int
    {
        self::ensureSchema();
        if ($companyId <= 0 || $connectionId <= 0) {
            return 0;
        }

        $stmt = DB::pdo()->prepare(
            "INSERT IGNORE INTO company_connection_message_reads (message_id, company_id, user_id, read_at)
             SELECT m.id, ?, ?, NOW()
             FROM company_connection_messages m
             WHERE m.connection_id = ?
               AND m.recipient_company_id = ?"
        );
        $stmt->execute([
            $companyId,
            $userId > 0 ? $userId : null,
            $connectionId,
            $companyId,
        ]);

        return (int) $stmt->rowCount();
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
                    'app_key' => 'centryk',
                    'type' => 'connection.message',
                    'title' => $title,
                    'body' => $body,
                    'url' => 'connections.php?company_id=' . $companyId,
                    'icon' => 'messages-square',
                    'color' => '#f59e0b',
                ]);
            }
        } catch (Throwable $e) {
            // Messaging should still work even if notifications fail.
        }
    }
}
