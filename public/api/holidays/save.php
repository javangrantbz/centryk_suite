<?php
/**
 * Create or update a Belize public/bank holiday. Platform admin only.
 * POST JSON: { id?, holiday_date, name, category, pay_rate, observed_note?, active? }
 * Returns: { success, id }
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

try {
    $id = PublicHolidays::save($in, (int)$me['user']['id']);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (Throwable $e) {
    Response::error('Could not save the holiday.', 500);
}

Response::ok(['id' => $id]);
