<?php
/** Delete a form (and its questions/responses). Body: { company_id, id } */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FormsService.php';

[, $companyId, $in] = forms_guard();

$id = (int)($in['id'] ?? 0);
if ($id <= 0) {
    Response::error('id is required.', 422);
}
FormsService::deleteForm($id, $companyId);
Response::ok([]);
