<?php
require_once __DIR__ . '/../../../../app/core/Auth.php';
require_once __DIR__ . '/../../../../app/core/Response.php';
require_once __DIR__ . '/../../../../app/services/NotificationService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Not logged in.', 401);
}

Response::ok([
    'notifications' => NotificationService::recent((int)$user['id'], 20),
    'unread_count'  => NotificationService::unreadCount((int)$user['id']),
]);
