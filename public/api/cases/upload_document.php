<?php
/**
 * Attach a file to a case. POST multipart/form-data:
 *   company_id, id (case id), file
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/CaseManagementService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$companyId = (int)($_POST['company_id'] ?? 0);
$caseId    = (int)($_POST['id'] ?? 0);
if ($companyId <= 0 || $caseId <= 0) {
    Response::error('company_id and id are required.', 422);
}

$m = DB::pdo()->prepare("SELECT role FROM company_members WHERE user_id = :u AND company_id = :c AND status = 'active' LIMIT 1");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
$role = $m->fetchColumn();
if (!$role) {
    Response::error('You need to be a member of this company.', 403);
}

$case = CaseManagementService::getCase($caseId, $companyId, (int)$user['id'], (string)$role);
if (!$case) {
    Response::error('Case not found.', 404);
}

$file = $_FILES['file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    Response::error('A file is required.');
}
if ((int)$file['size'] > 15 * 1024 * 1024) {
    Response::error('That file is too large (max 15MB).');
}
$allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'txt', 'csv'];
$ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    Response::error('File type not allowed.');
}

$dir = CaseManagementService::storageDir($companyId);
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    Response::error('Could not prepare storage for this file.', 500);
}
$storedName = bin2hex(random_bytes(16)) . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $storedName)) {
    Response::error('Upload failed.', 500);
}

$id = CaseManagementService::addDocument(
    $caseId,
    $companyId,
    (int)$user['id'],
    (string)$file['name'],
    $storedName,
    (string)($file['type'] ?? ''),
    (int)$file['size']
);

Response::ok(['id' => $id, 'documents' => CaseManagementService::documents($caseId)]);
