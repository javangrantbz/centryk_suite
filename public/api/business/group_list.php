<?php
/** Admin: every company group with its members, companies and group entitlements. */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/GroupsService.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$pdo = DB::pdo();
$groups = $pdo->query("
    SELECT g.id, g.name, g.status, g.created_at,
           (SELECT COUNT(*) FROM companies c WHERE c.group_id = g.id) AS company_count
    FROM company_groups g
    ORDER BY g.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($groups as &$g) {
    $g['id'] = (int)$g['id'];
    $detail = GroupsService::detail($g['id']);
    $g['companies']    = $detail['companies'] ?? [];
    $g['members']      = $detail['members'] ?? [];
    $g['entitlements'] = $detail['entitlements'] ?? [];
}
unset($g);

Response::ok(['groups' => $groups]);
