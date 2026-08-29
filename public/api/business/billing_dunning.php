<?php
/** Run the dunning sweep: overdue subscriptions -> past_due (read-only),
 *  settled ones -> active. Body: { as_of? } */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/BillingService.php';

$admin = require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { $in = $_POST; }

Response::ok(BillingService::runDunning($in['as_of'] ?? null, (int)$admin['id']));
