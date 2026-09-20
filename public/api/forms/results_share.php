<?php
/**
 * Turn the public results page on/off, change its code, or choose whether it also
 * lists every response.
 * Body: { company_id, form_id, action: enable|options|disable, pin?, show_responses? }
 *   enable  = turn sharing on with a 4-6 digit code (or change the code; the link stays the same).
 *             show_responses (optional) sets whether the list of responses is shown; it needs a
 *             code of 6 or more digits.
 *   options = change show_responses only, keeping the current code (needs sharing on)
 *   disable = turn it off; the link stops working immediately
 * Returns: { share: { enabled, token, show_responses } } (never the code)
 */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FormResultsShare.php';

[$userId, $companyId, $in] = forms_guard();

$formId = (int)($in['form_id'] ?? 0);
$action = (string)($in['action'] ?? '');
if ($formId <= 0 || !in_array($action, ['enable', 'options', 'disable'], true)) {
    Response::error('form_id and a valid action are required.', 422);
}

try {
    if ($action === 'enable') {
        $show = array_key_exists('show_responses', $in) ? !empty($in['show_responses']) : null;
        $share = FormResultsShare::enable($formId, $companyId, $userId, (string)($in['pin'] ?? ''), $show);
    } elseif ($action === 'options') {
        $share = FormResultsShare::setShowResponses($formId, $companyId, !empty($in['show_responses']));
    } else {
        $share = FormResultsShare::disable($formId, $companyId);
    }
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}
Response::ok(['share' => $share]);
