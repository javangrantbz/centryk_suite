<?php
/**
 * Moderate a customer review and (when approved) push it to the company's
 * Facebook Page.
 * Body: { company_id, response_id, action: approve|reject|save_text|post, text? }
 * Returns: { review, facebook: { posted, message } }
 *
 * approve = optionally edit the text, mark approved, then post to Facebook if a
 * Page is connected. post = retry posting an already-approved review.
 */
require_once __DIR__ . '/../../../app/core/forms_guard.php';
require_once __DIR__ . '/../../../app/services/FormsService.php';
require_once __DIR__ . '/../../../app/services/FacebookPagePoster.php';

[$userId, $companyId, $in] = forms_guard();

$responseId = (int)($in['response_id'] ?? 0);
$action = (string)($in['action'] ?? '');
$text = array_key_exists('text', $in) ? (string)$in['text'] : null;
if ($responseId <= 0 || $action === '') {
    Response::error('response_id and action are required.', 422);
}

$facebook = null;
try {
    if ($action === 'post') {
        // Retry: nothing to change on the review, just push the approved text.
        $review = ['id' => $responseId];
    } else {
        $review = FormsService::moderate($responseId, $companyId, $userId, $action, $text);
    }

    if ($action === 'approve' || $action === 'post') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/', 3));
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($dir, '/');

        $tok = DB::pdo()->prepare("
            SELECT f.share_token FROM form_responses r JOIN form_forms f ON f.id = r.form_id
            WHERE r.id = :id AND f.company_id = :cid
        ");
        $tok->execute(['id' => $responseId, 'cid' => $companyId]);
        $shareToken = (string)$tok->fetchColumn();
        if ($shareToken === '') {
            Response::error('Review not found.', 404);
        }
        $facebook = FacebookPagePoster::postReview($companyId, $responseId, $base . '/f.php?t=' . $shareToken);
    }
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok(['review' => $review, 'facebook' => $facebook]);
