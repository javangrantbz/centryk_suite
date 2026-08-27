<?php
/**
 * "Finish your profile" save — used by onboarding.php?resume=profile.
 * Narrow on purpose: only touches phone / email / address / logo, so it can't
 * clobber the other company columns the full profile form owns
 * (api/companies/update-profile.php rewrites everything).
 *
 * Multipart POST: {
 *   company_id (required),
 *   snooze = "1"           — just push the dashboard reminder ~14 days out,
 *   phone, email, address  — trimmed and saved as-is,
 *   logo (file, optional)  — PNG/JPG/WEBP/SVG, <=2MB
 * }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/OnePayWebhook.php';

Auth::start();
$caller = Auth::user();
if (!$caller) {
    Response::error('Unauthorized.', 401);
}

$companyId = (int)($_POST['company_id'] ?? 0);
if (!$companyId) {
    Response::error('company_id is required.', 422);
}

$pdo = DB::pdo();

// Caller must administer this company (or be a site admin).
if (empty($caller['is_admin'])) {
    $chk = $pdo->prepare('SELECT 1 FROM company_members
                          WHERE company_id = :cid AND user_id = :uid
                            AND role = "admin" AND status = "active" LIMIT 1');
    $chk->execute(['cid' => $companyId, 'uid' => (int)$caller['id']]);
    if (!$chk->fetch()) {
        Response::error('You are not an admin of this company.', 403);
    }
}

// "Skip for now" — snooze the reminder, change nothing else.
if (($_POST['snooze'] ?? '') === '1') {
    $pdo->prepare('UPDATE companies
                   SET profile_prompt_snoozed_until = DATE_ADD(NOW(), INTERVAL 14 DAY)
                   WHERE id = :id')
        ->execute(['id' => $companyId]);
    Response::ok(['snoozed' => true]);
}

// Keep the existing logo unless a new one is uploaded (same rules as
// api/companies/update-profile.php).
$cur = $pdo->prepare('SELECT logo FROM companies WHERE id = :id LIMIT 1');
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
    $dir = __DIR__ . '/../../uploads/companies';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $safe = 'co' . $companyId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $safe)) {
        Response::error('Could not save the logo. Please try again.', 500);
    }
    $logo = 'uploads/companies/' . $safe;
}

$pdo->prepare('UPDATE companies
               SET phone = :phone, email = :email, address = :address, logo = :logo,
                   profile_prompt_snoozed_until = NULL
               WHERE id = :id')
    ->execute([
        'phone'   => trim((string)($_POST['phone'] ?? '')),
        'email'   => trim((string)($_POST['email'] ?? '')),
        'address' => trim((string)($_POST['address'] ?? '')),
        'logo'    => $logo,
        'id'      => $companyId,
    ]);

// Refresh OnePay branding (name/logo). Fire-and-forget.
OnePayWebhook::companyProfileSynced($pdo, $companyId);

Response::ok(['message' => 'Company profile saved.', 'logo' => $logo]);
