<?php
/**
 * Public: record a response to an open form.
 * Body: { token, answers: { "<question_id>": <value|value[]> } }
 * No builder auth. If the form is login_required, a Centryk session is needed.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/FormsService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$token = trim((string)($in['token'] ?? ''));
$answers = $in['answers'] ?? [];
if ($token === '' || !is_array($answers)) {
    Response::error('token and answers are required.', 422);
}

$form = FormsService::getFormByToken($token);
if (!$form) {
    Response::error('Form not found.', 404);
}
if ($form['status'] !== 'open') {
    Response::error('This form is not accepting responses.', 409);
}

$userId = null;
if ($form['access'] === 'login_required') {
    Auth::start();
    $u = Auth::user();
    if (!$u) {
        Response::error('Please sign in to Centryk to respond to this form.', 401, ['login_required' => true]);
    }
    $userId = (int)$u['id'];
} else {
    Auth::start();
    $u = Auth::user();
    if ($u) {
        $userId = (int)$u['id'];
    }
}

// Soft per-device dedupe key for anonymous respondents.
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$respondentKey = hash('sha256', $form['id'] . '|' . $ip . '|' . $ua);

// Normalise "<qid>" keys to ints.
$byId = [];
foreach ($answers as $k => $v) {
    $byId[(int)$k] = $v;
}

try {
    FormsService::recordResponse($form, $byId, $userId, $respondentKey);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 422);
}

Response::ok([
    'confirmation_message' => $form['confirmation_message'] !== ''
        ? $form['confirmation_message']
        : 'Thanks — your response has been recorded.',
]);
