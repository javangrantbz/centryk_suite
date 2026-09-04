<?php
/** Belize BTS e-invoicing: one fiscal document (any company), for the platform-admin monitor. GET ?id= */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/FiscalInvoicingService.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    Response::error('id is required.', 422);
}

$document = FiscalInvoicingService::adminGetDocument($id);
if (!$document) {
    Response::error('Document not found.', 404);
}

Response::ok(['document' => $document]);
