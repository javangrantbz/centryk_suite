<?php
/**
 * Insert or update one question.
 * Body: { company_id, form_id, question: { id?, type, label, help_text,
 *         required, options[], config{} } }
 * Returns: { id, questions }
 */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FormsService.php';

[, $companyId, $in] = forms_guard();

$formId = (int)($in['form_id'] ?? 0);
$q = $in['question'] ?? null;
if ($formId <= 0 || !is_array($q)) {
    Response::error('form_id and question are required.', 422);
}

try {
    $qid = FormsService::saveQuestion($formId, $companyId, $q);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['id' => $qid, 'questions' => FormsService::questions($formId)]);
