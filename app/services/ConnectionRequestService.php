<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Connections.php';
require_once __DIR__ . '/NotificationService.php';

class ConnectionRequestService
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
                "CREATE TABLE IF NOT EXISTS company_connection_requests (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    connection_id INT UNSIGNED NOT NULL,
                    requester_company_id INT UNSIGNED NOT NULL,
                    recipient_company_id INT UNSIGNED NOT NULL,
                    request_type VARCHAR(40) NOT NULL DEFAULT 'general',
                    subject VARCHAR(160) NOT NULL,
                    details TEXT NULL,
                    status ENUM('open','fulfilled','declined') NOT NULL DEFAULT 'open',
                    created_by_user_id INT UNSIGNED NULL,
                    handled_by_user_id INT UNSIGNED NULL,
                    handled_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_ccr_recipient (recipient_company_id, status, created_at),
                    INDEX idx_ccr_requester (requester_company_id, status, created_at),
                    INDEX idx_ccr_connection (connection_id, created_at),
                    CONSTRAINT fk_ccr_connection FOREIGN KEY (connection_id) REFERENCES company_connections(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ccr_requester_company FOREIGN KEY (requester_company_id) REFERENCES companies(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ccr_recipient_company FOREIGN KEY (recipient_company_id) REFERENCES companies(id) ON DELETE CASCADE,
                    CONSTRAINT fk_ccr_created_by_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
                    CONSTRAINT fk_ccr_handled_by_user FOREIGN KEY (handled_by_user_id) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            // Keep Connect working even if this feature's table cannot be created here.
        }
    }

    private static function requestTypes(): array
    {
        return [
            'asset' => true,
            'campaign' => true,
            'event' => true,
            'promotion' => true,
            'general' => true,
        ];
    }

    public static function listForCompany(int $companyId): array
    {
        self::ensureSchema();
        if ($companyId <= 0) {
            return ['incoming' => [], 'outgoing' => []];
        }

        $baseSql = "
            SELECT r.*, requester.name AS requester_company_name, recipient.name AS recipient_company_name
            FROM company_connection_requests r
            JOIN companies requester ON requester.id = r.requester_company_id
            JOIN companies recipient ON recipient.id = r.recipient_company_id
            WHERE %s
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT 100
        ";

        $incoming = DB::pdo()->prepare(sprintf($baseSql, 'r.recipient_company_id = ?'));
        $incoming->execute([$companyId]);

        $outgoing = DB::pdo()->prepare(sprintf($baseSql, 'r.requester_company_id = ?'));
        $outgoing->execute([$companyId]);

        return [
            'incoming' => $incoming->fetchAll(PDO::FETCH_ASSOC),
            'outgoing' => $outgoing->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public static function create(int $requesterCompanyId, int $recipientCompanyId, int $actorUserId, array $data): array
    {
        self::ensureSchema();
        $connection = self::acceptedConnectionBetween($requesterCompanyId, $recipientCompanyId);
        if (!$connection) {
            return ['success' => false, 'message' => 'You can only send requests to accepted connections.'];
        }
        if (!Connections::permits($requesterCompanyId, $recipientCompanyId, 'request_assets')) {
            return ['success' => false, 'message' => 'This connection is not allowed to send partner requests yet.'];
        }

        $type = trim((string) ($data['request_type'] ?? 'general'));
        if (!isset(self::requestTypes()[$type])) {
            $type = 'general';
        }

        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            return ['success' => false, 'message' => 'A short subject is required.'];
        }
        $subject = mb_substr($subject, 0, 160);

        $details = trim((string) ($data['details'] ?? ''));
        if ($details !== '') {
            $details = mb_substr($details, 0, 2000);
        } else {
            $details = null;
        }

        $stmt = DB::pdo()->prepare(
            'INSERT INTO company_connection_requests
             (connection_id, requester_company_id, recipient_company_id, request_type, subject, details, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $connection['id'],
            $requesterCompanyId,
            $recipientCompanyId,
            $type,
            $subject,
            $details,
            $actorUserId > 0 ? $actorUserId : null,
        ]);

        self::notifyRecipientAdmins(
            $recipientCompanyId,
            $requesterCompanyId,
            'New partner request',
            $connection['other_company_name'] . ': ' . $subject
        );

        return ['success' => true, 'message' => 'Partner request sent.'];
    }

    public static function respond(int $requestId, int $companyId, int $actorUserId, string $status): array
    {
        self::ensureSchema();
        if (!in_array($status, ['fulfilled', 'declined'], true)) {
            return ['success' => false, 'message' => 'Invalid request status.'];
        }

        $stmt = DB::pdo()->prepare(
            'SELECT r.*, requester.name AS requester_company_name, recipient.name AS recipient_company_name
             FROM company_connection_requests r
             JOIN companies requester ON requester.id = r.requester_company_id
             JOIN companies recipient ON recipient.id = r.recipient_company_id
             WHERE r.id = ? AND r.recipient_company_id = ?
             LIMIT 1'
        );
        $stmt->execute([$requestId, $companyId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            return ['success' => false, 'message' => 'That partner request was not found.'];
        }
        if (($request['status'] ?? '') !== 'open') {
            return ['success' => false, 'message' => 'That partner request is already closed.'];
        }

        $update = DB::pdo()->prepare(
            "UPDATE company_connection_requests
             SET status = ?, handled_by_user_id = ?, handled_at = NOW()
             WHERE id = ? AND recipient_company_id = ? AND status = 'open'"
        );
        $update->execute([$status, $actorUserId > 0 ? $actorUserId : null, $requestId, $companyId]);
        if ($update->rowCount() <= 0) {
            return ['success' => false, 'message' => 'That partner request is no longer open.'];
        }

        $verb = $status === 'fulfilled' ? 'fulfilled' : 'declined';
        self::notifyRecipientAdmins(
            (int) $request['requester_company_id'],
            (int) $request['recipient_company_id'],
            'Partner request updated',
            $request['recipient_company_name'] . ' ' . $verb . ': ' . $request['subject']
        );

        return ['success' => true, 'message' => $status === 'fulfilled' ? 'Marked fulfilled.' : 'Request declined.'];
    }

    private static function acceptedConnectionBetween(int $companyA, int $companyB): ?array
    {
        $stmt = DB::pdo()->prepare(
            "SELECT cc.*, c.name AS other_company_name
             FROM company_connections cc
             JOIN companies c ON c.id = ?
             WHERE ((cc.requester_company_id=? AND cc.recipient_company_id=?) OR (cc.requester_company_id=? AND cc.recipient_company_id=?))
               AND cc.status='accepted'
             LIMIT 1"
        );
        $stmt->execute([$companyB, $companyA, $companyB, $companyB, $companyA]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function notifyRecipientAdmins(int $recipientCompanyId, int $otherCompanyId, string $title, string $body): void
    {
        try {
            $stmt = DB::pdo()->prepare(
                "SELECT cm.user_id
                 FROM company_members cm
                 WHERE cm.company_id = ? AND cm.status = 'active' AND cm.role = 'admin'"
            );
            $stmt->execute([$recipientCompanyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                NotificationService::create([
                    'user_id' => (int) $row['user_id'],
                    'company_id' => $recipientCompanyId,
                    'app_key' => 'centryk',
                    'type' => 'connection.request',
                    'title' => $title,
                    'body' => $body,
                    'url' => 'connections.php?company_id=' . $recipientCompanyId,
                    'icon' => 'handshake',
                    'color' => '#7c3aed',
                ]);
            }
        } catch (Throwable $e) {
            // Requests should still work even if notifications fail.
        }
    }
}
