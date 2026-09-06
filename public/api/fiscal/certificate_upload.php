<?php
/**
 * Belize BTS e-invoicing: upload a company's mTLS/signing certificate
 * (PFX/P12, generated + downloaded via BTS's own EFDR Portal - Centryk
 * never provisions this itself). POST multipart/form-data:
 *   company_id, certificate (file), expires_on (YYYY-MM-DD, optional)
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/services/FiscalInvoicingService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$companyId = (int)($_POST['company_id'] ?? 0);
if ($companyId <= 0) {
    Response::error('company_id is required.', 422);
}

$m = DB::pdo()->prepare("
    SELECT role FROM company_members
    WHERE user_id = :u AND company_id = :c AND status = 'active' LIMIT 1
");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
if ($m->fetchColumn() !== 'admin') {
    Response::error('You need to be an admin of this company.', 403);
}
$file = $_FILES['certificate'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    Response::error('A certificate file (PFX/P12) is required.');
}
if ((int)$file['size'] > 512 * 1024) {
    Response::error('That certificate file is unexpectedly large (max 512 KB).');
}

$profile = FiscalInvoicingService::getProfile($companyId);
if (empty($profile['tin'])) {
    Response::error('Set this company\'s TIN in the fiscal profile before uploading a certificate - it doubles as the PFX password.');
}

// Sanity-check the file actually opens with the expected password (the
// company's TIN) before accepting it, so a wrong file/password is caught
// immediately rather than surfacing later at submission time. Try both the
// TIN as stored and its bare 6 digits (the EFDR Portal uses the 6-digit
// TIN; the profile may hold it with a "-GST" suffix).
$pfxContents = file_get_contents($file['tmp_name']);
$certs = [];
$tinAsStored = (string)$profile['tin'];
$tinDigits   = preg_replace('/\D/', '', $tinAsStored) ?? '';
$opened = openssl_pkcs12_read((string)$pfxContents, $certs, $tinAsStored)
    || ($tinDigits !== '' && $tinDigits !== $tinAsStored && openssl_pkcs12_read((string)$pfxContents, $certs, $tinDigits));
if (!$opened) {
    Response::error('Could not open that certificate with this company\'s TIN as the password. Check you downloaded the right file from the EFDR Portal.');
}

$destPath = FiscalInvoicingService::certificatePath($companyId);
$destDir = dirname($destPath);
if (!is_dir($destDir)) {
    mkdir($destDir, 0700, true);
}
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    Response::error('Could not save the certificate.');
}
@chmod($destPath, 0600);

$expiresOn = null;
$certInfo = openssl_x509_parse($certs['cert']);
if ($certInfo && !empty($certInfo['validTo_time_t'])) {
    $expiresOn = date('Y-m-d', (int)$certInfo['validTo_time_t']);
}

// certificate_path/expiry aren't part of saveProfile()'s field set (that
// method is the general-purpose registration-info form); written directly.
DB::pdo()->prepare('UPDATE company_fiscal_profiles SET certificate_path = :path, certificate_expires_on = :exp WHERE company_id = :c')
    ->execute(['path' => $destPath, 'exp' => $expiresOn, 'c' => $companyId]);

Audit::log([
    'actor_user_id' => (int)$user['id'],
    'company_id'    => $companyId,
    'event_type'    => 'fiscal.certificate_uploaded',
    'summary'       => 'Uploaded a BTS e-invoicing certificate',
    'metadata'      => ['expires_on' => $expiresOn],
]);

Response::ok(['profile' => FiscalInvoicingService::getProfile($companyId)]);
