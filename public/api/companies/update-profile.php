<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}

// Multipart POST (so the optional logo file can ride along).
$companyId = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
if (!$companyId) {
    Response::error('Company ID is required.', 422);
}

$pdo = DB::pdo();

// Only admins of this company may edit its profile.
$check = $pdo->prepare("
    SELECT id FROM company_members
    WHERE company_id = :cid AND user_id = :uid AND role = 'admin' AND status = 'active'
    LIMIT 1
");
$check->execute(['cid' => $companyId, 'uid' => $user['id']]);
if (!$check->fetch()) {
    Response::error('Permission denied.', 403);
}

// Keep the existing logo unless a new one is uploaded.
$cur = $pdo->prepare("SELECT logo FROM companies WHERE id = :id LIMIT 1");
$cur->execute(['id' => $companyId]);
$logo = (string)($cur->fetchColumn() ?: '');

if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
    $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        Response::error('Logo must be a PNG, JPG, WEBP or SVG image.', 422);
    }
    if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
        Response::error('Logo must be under 2MB.', 422);
    }
    // Stored relative to the Centryk public root (public/uploads/companies).
    $dir = __DIR__ . '/../../uploads/companies';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $safe = 'co' . $companyId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $safe)) {
        Response::error('Could not save the logo. Please try again.', 500);
    }
    $logo = 'uploads/companies/' . $safe;
}

$data = [
    'email'         => trim($_POST['email'] ?? ''),
    'phone'         => trim($_POST['phone'] ?? ''),
    'phone2'        => trim($_POST['phone2'] ?? ''),
    'phone3'        => trim($_POST['phone3'] ?? ''),
    'address'       => trim($_POST['address'] ?? ''),
    'tax_number'    => trim($_POST['tax_number'] ?? ''),
    'opening_hours' => trim($_POST['opening_hours'] ?? ''),
    'logo'          => $logo,
    'id'            => $companyId,
];

$stmt = $pdo->prepare("
    UPDATE companies SET
        email = :email, phone = :phone, phone2 = :phone2, phone3 = :phone3,
        address = :address, tax_number = :tax_number, opening_hours = :opening_hours, logo = :logo
    WHERE id = :id
");
$stmt->execute($data);

Response::ok(['message' => 'Company profile updated.', 'logo' => $logo]);
