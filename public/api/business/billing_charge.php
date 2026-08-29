<?php
/** Update a charge. Body: { charge_id, action: paid|waive|void|reopen, method?, paid_on?, invoice_ref?, note? } */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/BillingService.php';

$admin = require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { $in = $_POST; }

$chargeId = (int)($in['charge_id'] ?? 0);
$action   = (string)($in['action'] ?? '');
if ($chargeId <= 0 || $action === '') {
    Response::error('charge_id and action are required.', 422);
}

try {
    BillingService::updateCharge($chargeId, $action, $in, (int)$admin['id']);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 404);
}

Response::ok(['action' => $action]);
