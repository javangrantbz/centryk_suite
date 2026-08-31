<?php
/**
 * CSV export of a form's responses. GET ?company_id=&form_id=
 * Streams text/csv as an attachment. Auth: Centryk session + company
 * admin/manager (checked here since this is a GET download, not the JSON guard).
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/services/FormsService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    http_response_code(401);
    exit('Unauthorized');
}

$companyId = (int)($_GET['company_id'] ?? 0);
$formId = (int)($_GET['form_id'] ?? 0);
if ($companyId <= 0 || $formId <= 0) {
    http_response_code(422);
    exit('company_id and form_id are required');
}

$m = DB::pdo()->prepare("
    SELECT role FROM company_members
    WHERE user_id = :uid AND company_id = :cid AND status = 'active' AND role IN ('admin','manager')
    LIMIT 1
");
$m->execute(['uid' => (int)$user['id'], 'cid' => $companyId]);
if (!$m->fetch()) {
    http_response_code(403);
    exit('Forbidden');
}

$form = FormsService::getForm($formId, $companyId);
if (!$form) {
    http_response_code(404);
    exit('Form not found');
}

$rows = FormsService::csv($formId);

$slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($form['title'])) ?: 'form';
$slug = trim($slug, '-');
$filename = $slug . '-responses-' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents
foreach ($rows as $line) {
    fputcsv($out, $line);
}
fclose($out);
exit;
