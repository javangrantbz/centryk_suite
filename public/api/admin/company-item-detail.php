<?php
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/OnePayCompanyProfile.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$companyUuid = trim((string)($_GET['company_uuid'] ?? ''));
$itemId = (int)($_GET['item_id'] ?? 0);
if ($companyUuid === '' || $itemId <= 0) {
    Response::error('company_uuid and item_id are required.', 422);
}

$detail = OnePayCompanyProfile::fetchItemDetail($companyUuid, $itemId);
if (!$detail['item']) {
    Response::error(OnePayCompanyProfile::lastError() ?: 'Item not found.', 404);
}

Response::ok($detail);
