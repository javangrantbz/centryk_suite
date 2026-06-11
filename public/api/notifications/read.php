<?php
/**
 * Mark notifications read for the logged-in Centryk user.
 * POST  (no body = mark all read; id=<n> = mark one read)
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
$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;

$updated = $id > 0
    ? NotificationService::markRead($userId, $id)
    : NotificationService::markAllRead($userId);

Response::ok([
    'updated'      => $updated,
    'unread_count' => NotificationService::unreadCount($userId),
]);
