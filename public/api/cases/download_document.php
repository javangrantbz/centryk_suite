<?php
/**
 * Stream a case document. GET ?company_id=&document_id=
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

$companyId = (int)($_GET['company_id'] ?? 0);
$docId     = (int)($_GET['document_id'] ?? 0);
if ($companyId <= 0 || $docId <= 0) {
    Response::error('company_id and document_id are required.', 422);
}

$m = DB::pdo()->prepare("SELECT role FROM company_members WHERE user_id = :u AND company_id = :c AND status = 'active' LIMIT 1");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
$role = $m->fetchColumn();
if (!$role) {
    Response::error('You need to be a member of this company.', 403);
}

$st = DB::pdo()->prepare("
    SELECT d.case_id, d.original_filename, d.stored_filename, d.mime_type, cr.company_id
    FROM case_documents d
    JOIN case_records cr ON cr.id = d.case_id
    WHERE d.id = :id AND cr.company_id = :cid
    LIMIT 1
");
$st->execute(['id' => $docId, 'cid' => $companyId]);
$doc = $st->fetch();
if (!$doc) {
    Response::error('Document not found.', 404);
}

$case = CaseManagementService::getCase((int)$doc['case_id'], $companyId, (int)$user['id'], (string)$role);
if (!$case) {
    Response::error('Document not found.', 404);
}

$path = CaseManagementService::storageDir($companyId) . '/' . $doc['stored_filename'];
if (!is_file($path)) {
    Response::error('File is missing from storage.', 404);
}

header('Content-Type: ' . (($doc['mime_type'] ?: '') !== '' ? $doc['mime_type'] : 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $doc['original_filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
