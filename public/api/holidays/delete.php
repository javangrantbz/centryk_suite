<?php
/**
 * Delete a Belize public/bank holiday. Platform admin only.
 * POST JSON: { id }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/AuthService.php';
require_once __DIR__ . '/../../../app/services/PublicHolidays.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    Response::error('Unauthorized.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$in = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($in['id'] ?? 0);
if ($id <= 0) {
    Response::error('id is required.', 422);
}

try {
    PublicHolidays::delete($id, (int)$me['user']['id']);
} catch (Throwable $e) {
    Response::error('Could not delete the holiday.', 500);
}

Response::ok([]);
