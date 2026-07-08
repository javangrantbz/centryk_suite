<?php
/**
 * OnePay → Invoices: read back invoice status/paid amounts for a set of OnePay
 * source refs, so OnePay can show paid / unpaid / overdue against its sales.
 *
 * Expected JSON body: provision_secret, company_uuid, source_refs: [ ... ]
 * Returns: { statuses: { "<source_ref>": { status, total, amount_paid, invoice_number } } }
 */
require_once __DIR__ . '/_bootstrap.php';

$body      = onepay_request();
$pdo       = DB::pdo();
$companyId = onepay_company_id($pdo, $body);

$refs = is_array($body['source_refs'] ?? null)
    ? array_values(array_unique(array_filter(array_map(static fn($r) => trim((string)$r), $body['source_refs']))))
    : [];

if (!$refs) {
    Response::ok(['statuses' => (object)[]]);
}

$placeholders = implode(',', array_fill(0, count($refs), '?'));
$sql = "SELECT source_ref, invoice_number, status, total, amount_paid
        FROM invoices
        WHERE company_id = ? AND source_app = 'onepay' AND source_ref IN ($placeholders)";
$st = $pdo->prepare($sql);
$st->execute(array_merge([$companyId], $refs));

$out = [];
foreach ($st->fetchAll() as $r) {
    $out[$r['source_ref']] = [
        'invoice_number' => $r['invoice_number'],
        'status'         => $r['status'],
        'total'          => (float)$r['total'],
        'amount_paid'    => (float)$r['amount_paid'],
    ];
}

Response::ok(['statuses' => $out ?: (object)[]]);
