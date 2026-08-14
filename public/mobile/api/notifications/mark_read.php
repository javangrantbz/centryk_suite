<?php
require_once __DIR__ . '/../../../../app/core/Auth.php';
require_once __DIR__ . '/../../../../app/core/Response.php';
require_once __DIR__ . '/../../../../app/services/NotificationService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Not logged in.', 401);
}

$updated = NotificationService::markAllRead((int)$user['id']);

Response::ok(['updated' => $updated]);
