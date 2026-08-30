<?php
require_once __DIR__ . '/DB.php';

/**
 * Centryk Connect: mutual company-to-company connections. A connection is
 * undirected once accepted, but stored as one row per pair (requester ->
 * recipient) so every lookup checks both directions.
 */
class Connections
{
    private static bool $schemaChecked = false;

    private static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $columns = DB::pdo()->query('SHOW COLUMNS FROM company_connections')->fetchAll(PDO::FETCH_COLUMN);
            $columns = array_fill_keys($columns ?: [], true);
            $alter = [];

            if (!isset($columns['relationship_type'])) {
                $alter[] = "ADD COLUMN relationship_type VARCHAR(40) NOT NULL DEFAULT 'partner' AFTER status";
            }
            if (!isset($columns['relationship_note'])) {
                $alter[] = "ADD COLUMN relationship_note TEXT NULL AFTER relationship_type";
            }
            if (!isset($columns['can_share_signage'])) {
                $alter[] = "ADD COLUMN can_share_signage TINYINT(1) NOT NULL DEFAULT 1 AFTER relationship_note";
            }
            if (!isset($columns['can_share_events'])) {
                $alter[] = "ADD COLUMN can_share_events TINYINT(1) NOT NULL DEFAULT 0 AFTER can_share_signage";
            }
            if (!isset($columns['can_share_campaigns'])) {
                $alter[] = "ADD COLUMN can_share_campaigns TINYINT(1) NOT NULL DEFAULT 0 AFTER can_share_events";
            }
            if (!isset($columns['can_request_assets'])) {
                $alter[] = "ADD COLUMN can_request_assets TINYINT(1) NOT NULL DEFAULT 0 AFTER can_share_campaigns";
            }
            if (!isset($columns['can_message_admins'])) {
                $alter[] = "ADD COLUMN can_message_admins TINYINT(1) NOT NULL DEFAULT 0 AFTER can_request_assets";
            }
            if (!isset($columns['updated_at'])) {
                $alter[] = "ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER responded_at";
            }

            if ($alter) {
                DB::pdo()->exec('ALTER TABLE company_connections ' . implode(', ', $alter));
            }
        } catch (Throwable $e) {
            // Keep core connection flows available even if the schema probe fails.
        }
    }

    public static function status(int $companyA, int $companyB): ?string
    {
        self::ensureSchema();
        $stmt = DB::pdo()->prepare(
            'SELECT status FROM company_connections
             WHERE (requester_company_id = ? AND recipient_company_id = ?)
                OR (requester_company_id = ? AND recipient_company_id = ?)
             LIMIT 1'
        );
        $stmt->execute([$companyA, $companyB, $companyB, $companyA]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['status'] : null;
    }

    public static function areConnected(int $companyA, int $companyB): bool
    {
        return self::status($companyA, $companyB) === 'accepted';
    }

    /** Companies $companyId is accepted-connected with. */
    public static function listConnected(int $companyId): array
    {
        self::ensureSchema();
        try {
            $stmt = DB::pdo()->prepare(
                "SELECT c.id, c.uuid, c.name, c.logo, c.store_theme, c.business_type, c.email, c.phone, c.address, c.opening_hours,
                        cc.id AS connection_id, cc.relationship_type, cc.relationship_note,
                        cc.can_share_signage, cc.can_share_events, cc.can_share_campaigns, cc.can_request_assets, cc.can_message_admins,
                        cc.created_at, cc.responded_at,
                        (
                            SELECT COUNT(*)
                            FROM company_connection_campaign_shares s
                            WHERE s.connection_id = cc.id
                              AND (s.owner_company_id = ? OR s.recipient_company_id = ?)
                        ) AS campaign_share_count,
                        (
                            SELECT COUNT(*)
                            FROM company_connection_campaign_shares s
                            WHERE s.connection_id = cc.id
                              AND s.recipient_company_id = ?
                              AND s.status = 'accepted'
                        ) AS accepted_campaign_count,
                        (
                            SELECT COUNT(*)
                            FROM company_connection_event_shares es
                            WHERE es.connection_id = cc.id
                              AND (es.owner_company_id = ? OR es.recipient_company_id = ?)
                        ) AS event_share_count,
                        (
                            SELECT COUNT(*)
                            FROM company_connection_requests rq
                            WHERE rq.connection_id = cc.id
                              AND rq.status = 'open'
                        ) AS open_request_count,
                        (
                            SELECT COUNT(*)
                            FROM company_connection_messages m
                            WHERE m.connection_id = cc.id
                        ) AS message_count
                 FROM company_connections cc
                 JOIN companies c ON c.id = IF(cc.requester_company_id = ?, cc.recipient_company_id, cc.requester_company_id)
                 WHERE (cc.requester_company_id = ? OR cc.recipient_company_id = ?) AND cc.status = 'accepted'
                 ORDER BY c.name ASC"
            );
            $stmt->execute([
                $companyId,
                $companyId,
                $companyId,
                $companyId,
                $companyId,
                $companyId,
                $companyId,
                $companyId,
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $stmt = DB::pdo()->prepare(
                "SELECT c.id, c.uuid, c.name, c.logo, c.store_theme, c.business_type, c.email, c.phone, c.address, c.opening_hours,
                        cc.id AS connection_id, cc.relationship_type, cc.relationship_note,
                        cc.can_share_signage, cc.can_share_events, cc.can_share_campaigns, cc.can_request_assets, cc.can_message_admins,
                        cc.created_at, cc.responded_at
                 FROM company_connections cc
                 JOIN companies c ON c.id = IF(cc.requester_company_id = ?, cc.recipient_company_id, cc.requester_company_id)
                 WHERE (cc.requester_company_id = ? OR cc.recipient_company_id = ?) AND cc.status = 'accepted'
                 ORDER BY c.name ASC"
            );
            $stmt->execute([$companyId, $companyId, $companyId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    /** Pending requests sent TO $companyId, awaiting their decision. */
    public static function incomingPending(int $companyId): array
    {
        self::ensureSchema();
        $stmt = DB::pdo()->prepare(
            "SELECT cc.id, cc.created_at, c.id AS company_id, c.name AS company_name
             FROM company_connections cc
             JOIN companies c ON c.id = cc.requester_company_id
             WHERE cc.recipient_company_id = ? AND cc.status = 'pending'
             ORDER BY cc.created_at DESC"
        );
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Requests $companyId sent that are still awaiting a decision. */
    public static function outgoingPending(int $companyId): array
    {
        self::ensureSchema();
        $stmt = DB::pdo()->prepare(
            "SELECT cc.id, cc.created_at, c.id AS company_id, c.name AS company_name
             FROM company_connections cc
             JOIN companies c ON c.id = cc.recipient_company_id
             WHERE cc.requester_company_id = ? AND cc.status = 'pending'
             ORDER BY cc.created_at DESC"
        );
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function sendRequest(int $fromCompanyId, int $toCompanyId): bool
    {
        self::ensureSchema();
        if ($fromCompanyId === $toCompanyId) {
            return false;
        }
        $existing = self::status($fromCompanyId, $toCompanyId);
        if ($existing === 'pending' || $existing === 'accepted') {
            return false;
        }
        if ($existing === 'declined') {
            // Let a fresh request from either side re-open it.
            DB::pdo()->prepare(
                "UPDATE company_connections
                 SET requester_company_id=?, recipient_company_id=?, status='pending', responded_at=NULL, relationship_note=NULL
                 WHERE (requester_company_id=? AND recipient_company_id=?) OR (requester_company_id=? AND recipient_company_id=?)"
            )->execute([$fromCompanyId, $toCompanyId, $fromCompanyId, $toCompanyId, $toCompanyId, $fromCompanyId]);
            return true;
        }
        DB::pdo()->prepare(
            'INSERT INTO company_connections (requester_company_id, recipient_company_id) VALUES (?, ?)'
        )->execute([$fromCompanyId, $toCompanyId]);
        return true;
    }

    /** Accept/decline — only the recipient of a pending request may respond. */
    public static function respond(int $connectionId, int $recipientCompanyId, bool $accept): bool
    {
        self::ensureSchema();
        $stmt = DB::pdo()->prepare(
            "UPDATE company_connections SET status=?, responded_at=NOW()
             WHERE id=? AND recipient_company_id=? AND status='pending'"
        );
        $stmt->execute([$accept ? 'accepted' : 'declined', $connectionId, $recipientCompanyId]);
        return $stmt->rowCount() > 0;
    }

    /** Either side can withdraw an accepted connection or cancel a pending one they sent. */
    public static function remove(int $connectionId, int $companyId): bool
    {
        self::ensureSchema();
        $stmt = DB::pdo()->prepare(
            'DELETE FROM company_connections WHERE id=? AND (requester_company_id=? OR recipient_company_id=?)'
        );
        $stmt->execute([$connectionId, $companyId, $companyId]);
        return $stmt->rowCount() > 0;
    }

    public static function getByIdForCompany(int $connectionId, int $companyId): ?array
    {
        self::ensureSchema();
        $stmt = DB::pdo()->prepare(
            "SELECT cc.*, c.id AS other_company_id, c.name AS other_company_name
             FROM company_connections cc
             JOIN companies c ON c.id = IF(cc.requester_company_id = ?, cc.recipient_company_id, cc.requester_company_id)
             WHERE cc.id = ? AND (cc.requester_company_id = ? OR cc.recipient_company_id = ?)
             LIMIT 1"
        );
        $stmt->execute([$companyId, $connectionId, $companyId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function updateProfile(int $connectionId, int $companyId, array $data): bool
    {
        self::ensureSchema();
        $relationshipType = trim((string) ($data['relationship_type'] ?? 'partner'));
        $allowedTypes = ['partner', 'vendor', 'client', 'sister_brand', 'event_sponsor', 'campaign_partner', 'other'];
        if (!in_array($relationshipType, $allowedTypes, true)) {
            $relationshipType = 'partner';
        }

        $note = trim((string) ($data['relationship_note'] ?? ''));
        if ($note === '') {
            $note = null;
        }
        if ($note !== null) {
            $note = mb_substr($note, 0, 1000);
        }

        $stmt = DB::pdo()->prepare(
            "UPDATE company_connections
             SET relationship_type = ?,
                 relationship_note = ?,
                 can_share_signage = ?,
                 can_share_events = ?,
                 can_share_campaigns = ?,
                 can_request_assets = ?,
                 can_message_admins = ?
             WHERE id = ?
               AND status = 'accepted'
               AND (requester_company_id = ? OR recipient_company_id = ?)"
        );
        $stmt->execute([
            $relationshipType,
            $note,
            !empty($data['can_share_signage']) ? 1 : 0,
            !empty($data['can_share_events']) ? 1 : 0,
            !empty($data['can_share_campaigns']) ? 1 : 0,
            !empty($data['can_request_assets']) ? 1 : 0,
            !empty($data['can_message_admins']) ? 1 : 0,
            $connectionId,
            $companyId,
            $companyId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function permits(int $companyA, int $companyB, string $scope): bool
    {
        self::ensureSchema();
        $allowedScopes = [
            'share_signage' => 'can_share_signage',
            'share_events' => 'can_share_events',
            'share_campaigns' => 'can_share_campaigns',
            'request_assets' => 'can_request_assets',
            'message_admins' => 'can_message_admins',
        ];
        $column = $allowedScopes[$scope] ?? null;
        if ($column === null) {
            return false;
        }

        $stmt = DB::pdo()->prepare(
            "SELECT {$column}
             FROM company_connections
             WHERE ((requester_company_id=? AND recipient_company_id=?) OR (requester_company_id=? AND recipient_company_id=?))
               AND status = 'accepted'
             LIMIT 1"
        );
        $stmt->execute([$companyA, $companyB, $companyB, $companyA]);
        return (bool) $stmt->fetchColumn();
    }
}
