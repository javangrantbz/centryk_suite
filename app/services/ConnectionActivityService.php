<?php
require_once __DIR__ . '/../core/DB.php';

class ConnectionActivityService
{
    public static function listForCompany(int $companyId, int $limit = 40): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $items = [];

        try {
            $stmt = DB::pdo()->prepare(
                "SELECT cc.status, cc.created_at, cc.responded_at, c.name AS other_company_name
                 FROM company_connections cc
                 JOIN companies c ON c.id = IF(cc.requester_company_id = ?, cc.recipient_company_id, cc.requester_company_id)
                 WHERE cc.requester_company_id = ? OR cc.recipient_company_id = ?
                 ORDER BY GREATEST(COALESCE(cc.responded_at, '1970-01-01'), cc.created_at) DESC
                 LIMIT 50"
            );
            $stmt->execute([$companyId, $companyId, $companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = [
                    'kind' => 'connection',
                    'title' => match ((string) $row['status']) {
                        'accepted' => 'Connection accepted',
                        'declined' => 'Connection declined',
                        default => 'Connection requested',
                    },
                    'body' => (string) $row['other_company_name'],
                    'created_at' => (string) ($row['responded_at'] ?: $row['created_at']),
                    'color' => '#7c3aed',
                ];
            }
        } catch (Throwable $e) {
        }

        try {
            $stmt = DB::pdo()->prepare(
                "SELECT r.subject, r.status, r.created_at, r.handled_at,
                        requester.name AS requester_company_name, recipient.name AS recipient_company_name
                 FROM company_connection_requests r
                 JOIN companies requester ON requester.id = r.requester_company_id
                 JOIN companies recipient ON recipient.id = r.recipient_company_id
                 WHERE r.requester_company_id = ? OR r.recipient_company_id = ?
                 ORDER BY COALESCE(r.handled_at, r.created_at) DESC
                 LIMIT 50"
            );
            $stmt->execute([$companyId, $companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $verb = match ((string) $row['status']) {
                    'fulfilled' => 'fulfilled',
                    'declined' => 'declined',
                    default => 'opened',
                };
                $items[] = [
                    'kind' => 'request',
                    'title' => 'Partner request ' . $verb,
                    'body' => $row['subject'] . ' • ' . $row['requester_company_name'] . ' / ' . $row['recipient_company_name'],
                    'created_at' => (string) ($row['handled_at'] ?: $row['created_at']),
                    'color' => '#0f172a',
                ];
            }
        } catch (Throwable $e) {
        }

        try {
            $stmt = DB::pdo()->prepare(
                "SELECT s.title, s.status, s.created_at, s.responded_at,
                        owner.name AS owner_company_name, recipient.name AS recipient_company_name
                 FROM company_connection_campaign_shares s
                 JOIN companies owner ON owner.id = s.owner_company_id
                 JOIN companies recipient ON recipient.id = s.recipient_company_id
                 WHERE s.owner_company_id = ? OR s.recipient_company_id = ?
                 ORDER BY COALESCE(s.responded_at, s.created_at) DESC
                 LIMIT 50"
            );
            $stmt->execute([$companyId, $companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $verb = match ((string) $row['status']) {
                    'accepted' => 'accepted',
                    'declined' => 'declined',
                    'revoked' => 'revoked',
                    default => 'shared',
                };
                $items[] = [
                    'kind' => 'campaign_share',
                    'title' => 'Shared campaign ' . $verb,
                    'body' => $row['title'] . ' • ' . $row['owner_company_name'] . ' / ' . $row['recipient_company_name'],
                    'created_at' => (string) ($row['responded_at'] ?: $row['created_at']),
                    'color' => '#ec4899',
                ];
            }
        } catch (Throwable $e) {
        }

        try {
            $stmt = DB::pdo()->prepare(
                "SELECT s.title, s.status, s.created_at, s.responded_at,
                        owner.name AS owner_company_name, recipient.name AS recipient_company_name
                 FROM company_connection_event_shares s
                 JOIN companies owner ON owner.id = s.owner_company_id
                 JOIN companies recipient ON recipient.id = s.recipient_company_id
                 WHERE s.owner_company_id = ? OR s.recipient_company_id = ?
                 ORDER BY COALESCE(s.responded_at, s.created_at) DESC
                 LIMIT 50"
            );
            $stmt->execute([$companyId, $companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $verb = match ((string) $row['status']) {
                    'accepted' => 'accepted',
                    'declined' => 'declined',
                    'revoked' => 'revoked',
                    default => 'shared',
                };
                $items[] = [
                    'kind' => 'event_share',
                    'title' => 'Shared event ' . $verb,
                    'body' => $row['title'] . ' • ' . $row['owner_company_name'] . ' / ' . $row['recipient_company_name'],
                    'created_at' => (string) ($row['responded_at'] ?: $row['created_at']),
                    'color' => '#14b8a6',
                ];
            }
        } catch (Throwable $e) {
        }

        try {
            $stmt = DB::pdo()->prepare(
                "SELECT m.message, m.created_at, sender.name AS sender_company_name,
                        TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS sender_name
                 FROM company_connection_messages m
                 JOIN companies sender ON sender.id = m.sender_company_id
                 LEFT JOIN users u ON u.id = m.sender_user_id
                 WHERE m.sender_company_id = ? OR m.recipient_company_id = ?
                 ORDER BY m.created_at DESC
                 LIMIT 50"
            );
            $stmt->execute([$companyId, $companyId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $label = trim((string) ($row['sender_name'] ?: $row['sender_company_name']));
                $items[] = [
                    'kind' => 'message',
                    'title' => 'Partner message',
                    'body' => $label . ': ' . mb_substr((string) $row['message'], 0, 140),
                    'created_at' => (string) $row['created_at'],
                    'color' => '#f59e0b',
                ];
            }
        } catch (Throwable $e) {
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return array_slice($items, 0, max(1, min(100, $limit)));
    }
}
