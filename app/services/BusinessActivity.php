<?php
require_once __DIR__ . '/../core/DB.php';

/**
 * Recent Centryk Business activity for one company — a merged feed of the
 * audit events the module services write (receivables, reconciliation, routes,
 * billing, entitlement changes). Read-only; for the "Recent activity" panel on
 * business.php.
 */
class BusinessActivity
{
    private const PREFIXES = [
        'receivables.', 'reconciliation.', 'routes.', 'billing.', 'entitlement.',
    ];

    public static function forCompany(int $companyId, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $like = [];
        $args = ['cid' => $companyId];
        foreach (self::PREFIXES as $i => $p) {
            $like[] = "e.event_type LIKE :p{$i}";
            $args["p{$i}"] = $p . '%';
        }

        $stmt = DB::pdo()->prepare("
            SELECT e.event_type, e.summary, e.created_at,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS actor_name
            FROM audit_events e
            LEFT JOIN users u ON u.id = e.actor_user_id
            WHERE e.company_id = :cid AND (" . implode(' OR ', $like) . ")
            ORDER BY e.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute($args);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'module'     => explode('.', $r['event_type'])[0],
                'event_type' => $r['event_type'],
                'summary'    => $r['summary'],
                'actor'      => trim((string)$r['actor_name']) ?: 'System',
                'at'         => $r['created_at'],
            ];
        }
        return $rows;
    }
}
