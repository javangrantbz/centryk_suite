<?php
/** Duplicate a form and its questions (not responses). Body: { company_id, id } → { id } */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FormsService.php';

[$userId, $companyId, $in] = forms_guard();

$id = (int)($in['id'] ?? 0);
if ($id <= 0) {
    Response::error('id is required.', 422);
}
try {
    $newId = FormsService::duplicateForm($id, $companyId, $userId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}
Response::ok(['id' => $newId]);
