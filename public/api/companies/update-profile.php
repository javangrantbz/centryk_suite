<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/OnePayWebhook.php';

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

// Business type + what the company calls its customers. The noun drives the
// invoicing wording in OnePay; blank falls back there to Customer/Customers.
$allowedTypes = ['school','gym','clinic','salon','grocery','services','property','retail','restaurant','auto_sales','auto_rental','other'];
$businessType = strtolower(trim($_POST['business_type'] ?? ''));
if ($businessType !== '' && !in_array($businessType, $allowedTypes, true)) {
    $businessType = 'other';
}
$nounS = trim($_POST['customer_noun_singular'] ?? '');
$nounP = trim($_POST['customer_noun_plural'] ?? '');

$storeTheme = trim($_POST['store_theme'] ?? '');
if ($storeTheme !== '') {
    $themeBase = 'assets/store_theme/';
    $themeFile = basename($storeTheme);
    $themePath = $themeBase . $themeFile;
    $themeFullPath = __DIR__ . '/../../' . $themePath;
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,80}\.(png|jpe?g|webp)$/i', $themeFile) || !is_file($themeFullPath)) {
        Response::error('Choose a valid store theme.', 422);
    }
    $storeTheme = $themePath;
}

$data = [
    'email'         => trim($_POST['email'] ?? ''),
    'phone'         => trim($_POST['phone'] ?? ''),
    'phone2'        => trim($_POST['phone2'] ?? ''),
    'phone3'        => trim($_POST['phone3'] ?? ''),
    'address'       => trim($_POST['address'] ?? ''),
    'tax_number'    => trim($_POST['tax_number'] ?? ''),
    'opening_hours' => trim($_POST['opening_hours'] ?? ''),
    'directory_visible' => isset($_POST['directory_visible']) && (string)$_POST['directory_visible'] === '0' ? 0 : 1,
    'business_type' => $businessType !== '' ? $businessType : null,
    'noun_s'        => $nounS !== '' ? $nounS : null,
    'noun_p'        => $nounP !== '' ? $nounP : null,
    'store_theme'   => $storeTheme !== '' ? $storeTheme : null,
    'logo'          => $logo,
    'id'            => $companyId,
];

$stmt = $pdo->prepare("
    UPDATE companies SET
        email = :email, phone = :phone, phone2 = :phone2, phone3 = :phone3,
        address = :address, tax_number = :tax_number, opening_hours = :opening_hours,
        directory_visible = :directory_visible,
        business_type = :business_type,
        customer_noun_singular = :noun_s, customer_noun_plural = :noun_p,
        store_theme = :store_theme,
        logo = :logo
    WHERE id = :id
");
$stmt->execute($data);

// Push the updated profile (name/logo) to OnePay so its branding refreshes
// instantly. Fire-and-forget: never blocks or fails the save.
OnePayWebhook::companyProfileSynced($pdo, $companyId);

Response::ok(['message' => 'Company profile updated.', 'logo' => $logo]);
