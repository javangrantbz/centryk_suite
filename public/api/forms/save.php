<?php
/**
 * Create or update a form.
 * Body: { company_id, id?, template?, title?, description?, status?, access?,
 *         one_response_per_person?, confirmation_message?, slug?, theme?,
 *         reviews_enabled?, fb_recommend_url? }
 * template: 'review' (only when creating) starts from the customer review &
 * ratings form. Returns: { id, form }
 */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FormsService.php';

[$userId, $companyId, $in] = forms_guard();

$id = (int)($in['id'] ?? 0);
$allowed = [
    'title', 'description', 'status', 'access', 'one_response_per_person', 'unique_contacts',
    'confirmation_message', 'qr_message', 'short_code', 'slug', 'theme', 'reviews_enabled', 'fb_recommend_url', 'fb_auto_redirect',
];

try {
    if ($id <= 0) {
        $template = ($in['template'] ?? '') === 'review' ? 'review' : '';
        $id = FormsService::createForm($companyId, $userId, (string)($in['title'] ?? ''), $template);
        // The title was applied on create; don't let a blank one overwrite the template's default.
        $allowed = array_values(array_diff($allowed, ['title']));
    }
    $fields = array_intersect_key($in, array_flip($allowed));
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
