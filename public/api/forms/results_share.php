<?php
/**
 * Turn the public results page on/off, or change its code.
 * Body: { company_id, form_id, action: enable|disable, pin? }
 *   enable  = turn sharing on with a 4-6 digit code (or change the code; the link stays the same)
 *   disable = turn it off; the link stops working immediately
 * Returns: { share: { enabled, token } } (never the code)
 */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FormResultsShare.php';

[$userId, $companyId, $in] = forms_guard();

$formId = (int)($in['form_id'] ?? 0);
$action = (string)($in['action'] ?? '');
if ($formId <= 0 || !in_array($action, ['enable', 'disable'], true)) {
    Response::error('form_id and a valid action are required.', 422);
}

try {
    $share = $action === 'enable'
        ? FormResultsShare::enable($formId, $companyId, $userId, (string)($in['pin'] ?? ''))
        : FormResultsShare::disable($formId, $companyId);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}
Response::ok(['share' => $share]);
