<?php
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

require_admin();

$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 1) {
    $limit = 100;
}
if ($limit > 250) {
    $limit = 250;
}

$pdo = DB::pdo();

try {
    $auditStmt = $pdo->prepare(
        "SELECT
            ae.id,
            ae.event_type,
            ae.summary,
            ae.metadata_json,
            ae.created_at,
            actor.id AS actor_id,
            actor.first_name AS actor_first_name,
            actor.last_name AS actor_last_name,
            actor.email AS actor_email,
            target.id AS target_id,
            target.first_name AS target_first_name,
            target.last_name AS target_last_name,
            target.email AS target_email,
            c.id AS company_id,
            c.name AS company_name
         FROM audit_events ae
         LEFT JOIN users actor ON actor.id = ae.actor_user_id
         LEFT JOIN users target ON target.id = ae.target_user_id
         LEFT JOIN companies c ON c.id = ae.company_id
         ORDER BY ae.created_at DESC, ae.id DESC
         LIMIT {$limit}"
    );
    $auditStmt->execute();
    $auditRows = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

    $loginStmt = $pdo->prepare(
        "SELECT
            le.id,
            le.email,
            le.ip_address,
            le.user_agent,
            le.success,
            le.created_at,
            u.id AS user_id,
            u.first_name,
            u.last_name
         FROM login_events le
         LEFT JOIN users u ON u.id = le.user_id
         ORDER BY le.created_at DESC, le.id DESC
         LIMIT {$limit}"
    );
    $loginStmt->execute();
    $loginRows = $loginStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    Response::error('Audit trail database migration is missing. Run database/add_audit_trail.sql first.', 500);
}

$events = [];

foreach ($auditRows as $row) {
    $events[] = [
        'source' => 'change',
        'sort_at' => $row['created_at'],
        'created_at' => $row['created_at'],
        'event_type' => $row['event_type'],
        'summary' => $row['summary'],
        'actor' => [
            'id' => $row['actor_id'] ? (int)$row['actor_id'] : null,
            'name' => trim(($row['actor_first_name'] ?? '') . ' ' . ($row['actor_last_name'] ?? '')) ?: null,
            'email' => $row['actor_email'] ?: null,
        ],
        'target' => [
            'id' => $row['target_id'] ? (int)$row['target_id'] : null,
            'name' => trim(($row['target_first_name'] ?? '') . ' ' . ($row['target_last_name'] ?? '')) ?: null,
            'email' => $row['target_email'] ?: null,
        ],
        'company' => [
            'id' => $row['company_id'] ? (int)$row['company_id'] : null,
            'name' => $row['company_name'] ?: null,
        ],
        'metadata' => self_decode_audit_metadata($row['metadata_json'] ?? null),
    ];
}

foreach ($loginRows as $row) {
    $events[] = [
        'source' => 'login',
        'sort_at' => $row['created_at'],
        'created_at' => $row['created_at'],
        'event_type' => $row['success'] ? 'auth.login.success' : 'auth.login.failed',
        'summary' => $row['success']
            ? 'Successful login'
            : 'Failed login attempt',
        'actor' => [
            'id' => $row['user_id'] ? (int)$row['user_id'] : null,
            'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: null,
            'email' => $row['email'],
        ],
        'target' => null,
        'company' => null,
        'metadata' => array_filter([
            'ip_address' => $row['ip_address'] ?: null,
            'user_agent' => $row['user_agent'] ?: null,
        ]),
    ];
}

usort($events, static function (array $a, array $b): int {
    $timeCompare = strcmp((string)$b['sort_at'], (string)$a['sort_at']);
    if ($timeCompare !== 0) {
        return $timeCompare;
    }

    return strcmp((string)$b['event_type'], (string)$a['event_type']);
});

$events = array_slice($events, 0, $limit);
$stats = [
    'change_events' => count($auditRows),
    'login_events' => count($loginRows),
    'failed_logins' => count(array_filter($loginRows, static fn (array $row): bool => !(bool)$row['success'])),
];

Response::ok([
    'events' => $events,
    'stats' => $stats,
]);

function self_decode_audit_metadata(?string $json): array
{
    if (!$json) {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}
