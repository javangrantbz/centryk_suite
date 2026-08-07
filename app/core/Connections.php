<?php
require_once __DIR__ . '/DB.php';

/**
 * Centryk Connect: mutual company-to-company connections. A connection is
 * undirected once accepted, but stored as one row per pair (requester ->
 * recipient) so every lookup checks both directions.
 */
class Connections
{
    public static function status(int $companyA, int $companyB): ?string
    {
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
        $stmt = DB::pdo()->prepare(
            "SELECT c.id, c.name, cc.id AS connection_id FROM company_connections cc
             JOIN companies c ON c.id = IF(cc.requester_company_id = ?, cc.recipient_company_id, cc.requester_company_id)
             WHERE (cc.requester_company_id = ? OR cc.recipient_company_id = ?) AND cc.status = 'accepted'
             ORDER BY c.name ASC"
        );
        $stmt->execute([$companyId, $companyId, $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pending requests sent TO $companyId, awaiting their decision. */
    public static function incomingPending(int $companyId): array
    {
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
                "UPDATE company_connections SET requester_company_id=?, recipient_company_id=?, status='pending', responded_at=NULL
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
        $stmt = DB::pdo()->prepare(
            'DELETE FROM company_connections WHERE id=? AND (requester_company_id=? OR recipient_company_id=?)'
        );
        $stmt->execute([$connectionId, $companyId, $companyId]);
        return $stmt->rowCount() > 0;
    }
}
