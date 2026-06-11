<?php
/**
 * Recent notifications + unread count for the logged-in Centryk user.
 * Session-authenticated (browser) — powers the header bell dropdown.
 * GET
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/services/NotificationService.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$userId = (int)$user['id'];

Response::ok([
    'notifications' => NotificationService::recent($userId, 15),
    'unread_count'  => NotificationService::unreadCount($userId),
]);
