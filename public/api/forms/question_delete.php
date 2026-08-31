<?php
/** Delete a question. Body: { company_id, form_id, question_id } → { questions } */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FormsService.php';

[, $companyId, $in] = forms_guard();

$formId = (int)($in['form_id'] ?? 0);
$qid = (int)($in['question_id'] ?? 0);
if ($formId <= 0 || $qid <= 0) {
    Response::error('form_id and question_id are required.', 422);
}
try {
    FormsService::deleteQuestion($qid, $formId, $companyId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}
Response::ok(['questions' => FormsService::questions($formId)]);
