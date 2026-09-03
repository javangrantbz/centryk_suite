<?php
/**
 * Server-to-server: Belize public & bank holidays in a date range. National
 * data, no company scoping. Used by MyPay to show holiday panels / payroll-
 * period callouts.
 *
 * POST { secret: '<PROVISION_SECRET>', from?: 'YYYY-MM-DD', to?: 'YYYY-MM-DD' }
 *   from defaults to today, to defaults to today + 120 days.
 * Returns: { success, holidays: [ {holiday_date, name, category, pay_rate, observed_note}, ... ] }
 */
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/PublicHolidays.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$secret = trim((string)($body['secret'] ?? ''));

$expected = $_ENV['PROVISION_SECRET'] ?? '';
if ($expected === '' || $secret === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}

$from = trim((string)($body['from'] ?? ''));
$to   = trim((string)($body['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d', strtotime('+120 days'));
}
if ($to < $from) {
    [$from, $to] = [$to, $from];
}

Response::ok(['holidays' => PublicHolidays::forRange($from, $to)]);
