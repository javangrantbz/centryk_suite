<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}

$companyId = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
if (!$companyId) {
    Response::error('Company ID is required.', 422);
}

$pdo = DB::pdo();

// Any active member can view; only admins can edit.
$check = $pdo->prepare("
    SELECT role FROM company_members
    WHERE company_id = :cid AND user_id = :uid AND status = 'active'
    LIMIT 1
");
$check->execute(['cid' => $companyId, 'uid' => $user['id']]);
$role = $check->fetchColumn();
if ($role === false) {
    Response::error('Permission denied.', 403);
}

$stmt = $pdo->prepare("
    SELECT name, email, phone, phone2, phone3, address, tax_number, logo, opening_hours
    FROM companies WHERE id = :id LIMIT 1
");
$stmt->execute(['id' => $companyId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    Response::error('Company not found.', 404);
}

Response::ok(['profile' => $profile, 'can_edit' => ($role === 'admin')]);
