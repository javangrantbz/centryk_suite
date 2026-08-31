<?php
/** Reorder a form's questions. Body: { company_id, form_id, order: [id, ...] } */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FormsService.php';

[, $companyId, $in] = forms_guard();

$formId = (int)($in['form_id'] ?? 0);
$order = $in['order'] ?? null;
if ($formId <= 0 || !is_array($order) || !$order) {
    Response::error('form_id and order are required.', 422);
}
try {
    FormsService::reorderQuestions($formId, $companyId, $order);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}
Response::ok([]);
