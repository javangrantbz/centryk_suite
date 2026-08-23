<?php
/**
 * Viewer-initiated checkout for a 'paid' event - unlike the api/stream/*
 * endpoints, this IS called by a logged-in browser session, never by the
 * streaming server. Requires a real Centryk session; card data is only ever
 * forwarded to OneLink, never stored (see database/add_tv_payments.sql and
 * TvPaymentService).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

$user = tv_user();
if (!$user) {
    Response::error('You must be signed in to pay for access.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

tv_verify_csrf();

$eventId = (int)($_POST['event_id'] ?? 0);
if ($eventId <= 0) {
    Response::error('event_id is required.', 400);
}

$card = [
    'number' => (string)($_POST['card_number'] ?? ''),
    'expiry' => (string)($_POST['card_expiry'] ?? ''),
    'cvv' => (string)($_POST['card_cvv'] ?? ''),
    'holder' => (string)($_POST['card_holder'] ?? ''),
];

$result = TvPaymentService::chargeForEventAccess($eventId, (int)$user['id'], $card);

if (!$result['success']) {
    Response::error($result['message'], 402);
}

Response::ok(['message' => $result['message']]);
