<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$input     = json_decode(file_get_contents('php://input'), true);
$companyId = isset($input['company_id']) ? (int)$input['company_id'] : 0;
$name      = isset($input['name']) ? trim($input['name']) : '';

if (!$companyId || $name === '') {
    Response::error('Company ID and name are required.', 422);
}

$pdo = DB::pdo();

// User must be an admin of this company
$check = $pdo->prepare("
    SELECT id FROM company_members
    WHERE company_id = :cid AND user_id = :uid AND role = 'admin' AND status = 'active'
    LIMIT 1
");
$check->execute(['cid' => $companyId, 'uid' => $user['id']]);
if (!$check->fetch()) {
    Response::error('Permission denied.', 403);
}

$stmt = $pdo->prepare("UPDATE companies SET name = :name WHERE id = :id");
$stmt->execute(['name' => $name, 'id' => $companyId]);

Response::ok(['message' => 'Company renamed.']);
