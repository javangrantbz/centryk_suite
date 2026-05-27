<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthenticated.', 401);
    exit;
}

try {
    $stmt = DB::pdo()->prepare("UPDATE users SET onboarding_seen = 1 WHERE id = :id");
    $stmt->execute(['id' => $user['id']]);
    Response::ok(['message' => 'Onboarding dismissed.']);
} catch (Exception $e) {
    Response::error('Could not update record.', 500);
}
