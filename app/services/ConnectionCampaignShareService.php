<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Connections.php';
require_once __DIR__ . '/NotificationService.php';

class ConnectionCampaignShareService
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
            $connColumns = $pdo->query('SHOW COLUMNS FROM company_connections')->fetchAll(PDO::FETCH_COLUMN);
            $connColumns = array_fill_keys($connColumns ?: [], true);
            if (!isset($connColumns['can_share_campaigns'])) {
                $pdo->exec("ALTER TABLE company_connections ADD COLUMN can_share_campaigns TINYINT(1) NOT NULL DEFAULT 0 AFTER can_share_events");
            }

            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS company_connection_campaign_shares (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    connection_id INT UNSIGNED NOT NULL,
                    owner_company_id INT UNSIGNED NOT NULL,
                    recipient_company_id INT UNSIGNED NOT NULL,
                    title VARCHAR(180) NOT NULL,
                    summary TEXT NULL,
                    offer_text VARCHAR(255) NULL,
                    cta_label VARCHAR(80) NULL,
                    cta_url VARCHAR(500) NULL,
                    starts_on DATE NULL,
                    ends_on DATE NULL,
                    audience_notes TEXT NULL,
                    recipient_notes TEXT NULL,
                    status ENUM('pending','accepted','declined','revoked') NOT NULL DEFAULT 'pending',
                    created_by_user_id INT UNSIGNED NULL,
                    responded_by_user_id INT UNSIGNED NULL,
                    responded_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_cccs_owner (owner_company_id, status, created_at),
                    INDEX idx_cccs_recipient (recipient_company_id, status, created_at),
                    INDEX idx_cccs_connection (connection_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            // Leave other Connect features working even if campaign schema setup fails.
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
            FROM company_connection_campaign_shares s
            JOIN companies owner ON owner.id = s.owner_company_id
            JOIN companies recipient ON recipient.id = s.recipient_company_id
            WHERE %s
            ORDER BY COALESCE(s.starts_on, DATE(s.created_at)) DESC, s.created_at DESC, s.id DESC
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
        if (!Connections::permits($ownerCompanyId, $recipientCompanyId, 'share_campaigns')) {
            return ['success' => false, 'message' => 'This connection is not allowed to share campaigns yet.'];
        }

        $title = trim((string) ($data['title'] ?? ''));
        $summary = trim((string) ($data['summary'] ?? ''));
        $offerText = trim((string) ($data['offer_text'] ?? ''));
        $ctaLabel = trim((string) ($data['cta_label'] ?? ''));
        $ctaUrl = trim((string) ($data['cta_url'] ?? ''));
        $startsOn = trim((string) ($data['starts_on'] ?? ''));
        $endsOn = trim((string) ($data['ends_on'] ?? ''));
        $audienceNotes = trim((string) ($data['audience_notes'] ?? ''));
        $recipientNotes = trim((string) ($data['recipient_notes'] ?? ''));

        if ($title === '') {
            return ['success' => false, 'message' => 'Campaign title is required.'];
        }
        if ($ctaUrl !== '' && !preg_match('#^https?://#i', $ctaUrl)) {
            return ['success' => false, 'message' => 'CTA link must start with http:// or https://'];
        }
        if ($startsOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn)) {
            return ['success' => false, 'message' => 'Start date must be YYYY-MM-DD.'];
        }
        if ($endsOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn)) {
            return ['success' => false, 'message' => 'End date must be YYYY-MM-DD.'];
        }
        if ($startsOn !== '' && $endsOn !== '' && $endsOn < $startsOn) {
            return ['success' => false, 'message' => 'End date cannot be earlier than start date.'];
        }

        $stmt = DB::pdo()->prepare(
            'INSERT INTO company_connection_campaign_shares
             (connection_id, owner_company_id, recipient_company_id, title, summary, offer_text, cta_label, cta_url, starts_on, ends_on, audience_notes, recipient_notes, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $connection['id'],
            $ownerCompanyId,
            $recipientCompanyId,
            mb_substr($title, 0, 180),
            $summary !== '' ? mb_substr($summary, 0, 3000) : null,
            $offerText !== '' ? mb_substr($offerText, 0, 255) : null,
            $ctaLabel !== '' ? mb_substr($ctaLabel, 0, 80) : null,
            $ctaUrl !== '' ? mb_substr($ctaUrl, 0, 500) : null,
            $startsOn !== '' ? $startsOn : null,
            $endsOn !== '' ? $endsOn : null,
            $audienceNotes !== '' ? mb_substr($audienceNotes, 0, 1000) : null,
            $recipientNotes !== '' ? mb_substr($recipientNotes, 0, 1000) : null,
            $actorUserId > 0 ? $actorUserId : null,
        ]);

        self::notifyCompanyAdmins(
            $recipientCompanyId,
            'Shared campaign offer',
            ($connection['other_company_name'] ?? 'A connected company') . ': ' . $title
        );

        return ['success' => true, 'message' => 'Shared campaign sent.'];
    }

    public static function respond(int $shareId, int $recipientCompanyId, int $actorUserId, string $action): array
    {
        self::ensureSchema();
        if (!in_array($action, ['accepted', 'declined'], true)) {
            return ['success' => false, 'message' => 'Invalid shared campaign action.'];
        }

        $stmt = DB::pdo()->prepare(
            'SELECT * FROM company_connection_campaign_shares
             WHERE id = ? AND recipient_company_id = ?
             LIMIT 1'
        );
        $stmt->execute([$shareId, $recipientCompanyId]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$share) {
            return ['success' => false, 'message' => 'That shared campaign was not found.'];
        }
        if (($share['status'] ?? '') !== 'pending') {
            return ['success' => false, 'message' => 'That shared campaign is no longer pending.'];
        }

        $upd = DB::pdo()->prepare(
            'UPDATE company_connection_campaign_shares
             SET status = ?, responded_by_user_id = ?, responded_at = NOW()
             WHERE id = ? AND recipient_company_id = ? AND status = "pending"'
        );
        $upd->execute([$action, $actorUserId > 0 ? $actorUserId : null, $shareId, $recipientCompanyId]);
        if ($upd->rowCount() <= 0) {
            return ['success' => false, 'message' => 'That shared campaign is no longer pending.'];
        }

        self::notifyCompanyAdmins(
            (int) $share['owner_company_id'],
            'Shared campaign updated',
            'Your campaign "' . $share['title'] . '" was ' . ($action === 'accepted' ? 'accepted' : 'declined') . '.'
        );

        return ['success' => true, 'message' => $action === 'accepted' ? 'Campaign accepted.' : 'Shared campaign declined.'];
    }

    public static function revoke(int $shareId, int $ownerCompanyId): array
    {
        self::ensureSchema();
        $stmt = DB::pdo()->prepare(
            'SELECT * FROM company_connection_campaign_shares
             WHERE id = ? AND owner_company_id = ?
             LIMIT 1'
        );
        $stmt->execute([$shareId, $ownerCompanyId]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$share) {
            return ['success' => false, 'message' => 'That shared campaign was not found.'];
        }
        if (!in_array((string) ($share['status'] ?? ''), ['pending', 'accepted'], true)) {
            return ['success' => false, 'message' => 'That shared campaign cannot be revoked now.'];
        }

        $upd = DB::pdo()->prepare(
            'UPDATE company_connection_campaign_shares
             SET status = "revoked", responded_at = NOW()
             WHERE id = ? AND owner_company_id = ?'
        );
        $upd->execute([$shareId, $ownerCompanyId]);
        if ($upd->rowCount() <= 0) {
            return ['success' => false, 'message' => 'Could not revoke shared campaign.'];
        }

        self::notifyCompanyAdmins(
            (int) $share['recipient_company_id'],
            'Shared campaign revoked',
            'A connected company revoked the campaign "' . $share['title'] . '".'
        );

        return ['success' => true, 'message' => 'Shared campaign revoked.'];
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
                    'type' => 'connection.campaign_share',
                    'title' => $title,
                    'body' => $body,
                    'url' => 'connections.php?company_id=' . $companyId,
                    'icon' => 'megaphone',
                    'color' => '#ec4899',
                ]);
            }
        } catch (Throwable $e) {
            // Notifications are best-effort.
        }
    }
}
