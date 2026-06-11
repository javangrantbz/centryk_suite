<?php
/**
 * Unread notification count for the logged-in Centryk user.
 * Lightweight endpoint for header-bell polling.
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

Response::ok(['unread_count' => NotificationService::unreadCount((int)$user['id'])]);
