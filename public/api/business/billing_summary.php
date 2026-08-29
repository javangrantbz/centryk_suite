<?php
/** Billing dashboard: summary + charge list. Body: { status?, company_id? } */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/BillingService.php';

require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { $in = $_POST; }

Response::ok([
    'summary' => BillingService::summary(),
    'charges' => BillingService::charges([
        'status'     => $in['status'] ?? 'due',
        'company_id' => (int)($in['company_id'] ?? 0),
    ]),
]);
