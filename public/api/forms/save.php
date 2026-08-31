<?php
/**
 * Create or update a form.
 * Body: { company_id, id?, title?, description?, status?, access?,
 *         one_response_per_person?, confirmation_message? }
 * Returns: { id }
 */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FormsService.php';

[$userId, $companyId, $in] = forms_guard();

$id = (int)($in['id'] ?? 0);

try {
    if ($id <= 0) {
        $id = FormsService::createForm($companyId, $userId, (string)($in['title'] ?? ''));
    }
    $fields = array_intersect_key($in, array_flip([
        'title', 'description', 'status', 'access',
        'one_response_per_person', 'confirmation_message',
    ]));
    if ($fields) {
        FormsService::updateForm($id, $companyId, $fields);
    }
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}

$form = FormsService::getForm($id, $companyId);
if (!$form) {
    Response::error('Form not found.', 404);
}
Response::ok(['id' => $id, 'form' => $form]);
