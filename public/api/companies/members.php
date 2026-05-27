<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}

$companyId = (int)($_GET['company_id'] ?? 0);
if (!$companyId) {
    Response::error('company_id is required.');
}

$pdo = DB::pdo();

$access = $pdo->prepare('SELECT role FROM company_members WHERE company_id = :cid AND user_id = :uid AND status = "active"');
$access->execute(['cid' => $companyId, 'uid' => $user['id']]);
if (!$access->fetch()) {
    Response::error('Access denied.', 403);
}

$stmt = $pdo->prepare('
    SELECT u.id, u.first_name, u.last_name, u.email, u.status, cm.role, cm.created_at
    FROM company_members cm
    JOIN users u ON u.id = cm.user_id
    WHERE cm.company_id = :cid AND cm.status = "active"
    ORDER BY FIELD(cm.role, "admin", "manager", "employee"), u.first_name ASC
');
$stmt->execute(['cid' => $companyId]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

$apps = $pdo->query(
    'SELECT id, `key`, label, color FROM apps WHERE status = "active" ORDER BY sort_order'
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($members as &$member) {
    $accStmt = $pdo->prepare('SELECT app_id FROM user_app_access WHERE user_id = :uid');
    $accStmt->execute(['uid' => (int)$member['id']]);
    $enrolledIds = array_column($accStmt->fetchAll(PDO::FETCH_ASSOC), 'app_id');
    $member['apps'] = array_map(function ($a) use ($enrolledIds) {
        return ['key' => $a['key'], 'label' => $a['label'], 'color' => $a['color'], 'enrolled' => in_array($a['id'], $enrolledIds)];
    }, $apps);
}
unset($member);

Response::ok(['members' => $members, 'apps' => $apps]);
