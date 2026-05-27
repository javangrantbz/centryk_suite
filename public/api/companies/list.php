<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}

$pdo  = DB::pdo();
$stmt = $pdo->prepare('
    SELECT c.id, c.uuid, c.name, c.status, cm.role, c.created_at,
           (SELECT COUNT(*) FROM company_members WHERE company_id = c.id AND status = "active") AS member_count
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.user_id = :uid AND cm.status = "active" AND c.status = "active"
    ORDER BY c.name ASC
');
$stmt->execute(['uid' => $user['id']]);
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Per-app active user counts for each company the current user belongs to
$appCounts = [];
if ($companies) {
    $ids = array_map(function ($c) { return (int)$c['id']; }, $companies);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $acStmt = $pdo->prepare("
        SELECT cm.company_id, a.key AS app_key, COUNT(DISTINCT cm.user_id) AS cnt
        FROM company_members cm
        JOIN user_app_access uaa ON uaa.user_id = cm.user_id
        JOIN apps a ON a.id = uaa.app_id
        WHERE cm.company_id IN ($placeholders)
          AND cm.status = 'active'
          AND a.status  = 'active'
        GROUP BY cm.company_id, a.key
    ");
    $acStmt->execute($ids);
    foreach ($acStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cid = (int)$row['company_id'];
        if (!isset($appCounts[$cid])) { $appCounts[$cid] = []; }
        $appCounts[$cid][$row['app_key']] = (int)$row['cnt'];
    }
}

foreach ($companies as &$c) {
    $c['app_counts'] = isset($appCounts[(int)$c['id']]) ? $appCounts[(int)$c['id']] : (object)[];
}
unset($c);

Response::ok(['companies' => $companies]);
