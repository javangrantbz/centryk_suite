<?php
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Audit.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$userId = (int)($in['user_id'] ?? 0);
$makeAdmin = !empty($in['is_admin']);
if (!$userId) {
    Response::error('user_id is required.', 422);
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('SELECT id, first_name, last_name, is_admin FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    Response::error('User not found.', 404);
}

if ((int)$target['id'] === (int)$admin['id'] && !$makeAdmin) {
    Response::error('You cannot remove your own admin access.', 422);
}

if ((bool)$target['is_admin'] === $makeAdmin) {
    Response::ok(['is_admin' => $makeAdmin]);
}

$update = $pdo->prepare('UPDATE users SET is_admin = :is_admin WHERE id = :id');
$update->execute(['is_admin' => $makeAdmin ? 1 : 0, 'id' => $userId]);

try {
    Audit::log([
        'actor_user_id'  => (int)$admin['id'],
        'target_user_id' => (int)$target['id'],
        'event_type'     => $makeAdmin ? 'admin.role.centryk_admin_granted' : 'admin.role.centryk_admin_revoked',
        'summary'        => trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''))
            . ($makeAdmin ? ' granted Centryk admin access to ' : ' revoked Centryk admin access from ')
            . trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? '')),
    ]);
} catch (Throwable $e) { /* audit is best-effort */ }

Response::ok(['is_admin' => $makeAdmin]);
