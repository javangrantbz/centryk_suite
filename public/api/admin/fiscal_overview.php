<?php
/** Belize BTS e-invoicing: platform-wide overview for Centryk admins. GET ?status=&company_id= */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/FiscalInvoicingService.php';

require_admin();

Response::ok([
    'profiles'  => FiscalInvoicingService::platformProfiles(),
    'documents' => FiscalInvoicingService::platformDocuments([
        'status'     => $_GET['status'] ?? '',
        'company_id' => $_GET['company_id'] ?? '',
    ]),
]);
