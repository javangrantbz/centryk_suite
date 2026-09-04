<?php
/** Belize BTS e-invoicing: invoices that could become a fiscal document. GET ?company_id= */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$companyId = (int)($_GET['company_id'] ?? 0);
if ($companyId <= 0) {
    Response::error('company_id is required.', 422);
}

$m = DB::pdo()->prepare("
    SELECT 1 FROM company_members
    WHERE user_id = :u AND company_id = :c AND status = 'active' AND role IN ('admin','manager') LIMIT 1
");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
if (!$m->fetchColumn()) {
    Response::error('You need to be an admin or manager of this company.', 403);
}
$stmt = DB::pdo()->prepare("
    SELECT i.id, i.invoice_number, i.total, i.status
    FROM invoices i
    WHERE i.company_id = :c
      AND i.status NOT IN ('draft', 'cancelled')
      AND NOT EXISTS (
          SELECT 1 FROM fiscal_documents f
          WHERE f.source_app = 'invoice-maker' AND f.source_ref = CAST(i.id AS CHAR) AND f.company_id = i.company_id
            AND f.status <> 'cancelled'
      )
    ORDER BY i.issue_date DESC, i.id DESC
    LIMIT 100
");
$stmt->execute(['c' => $companyId]);

Response::ok(['invoices' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
