<?php
require_once __DIR__ . '/Auth.php';

function require_admin(): array
{
    Auth::start();
    $user = Auth::user();

    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Not authenticated.']);
        exit;
    }

    if (empty($user['is_admin'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Admin access required.']);
        exit;
    }

    return $user;
}
