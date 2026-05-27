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
Response::ok(['companies' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
