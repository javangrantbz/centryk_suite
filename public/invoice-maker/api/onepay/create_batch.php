<?php
/**
 * OnePay → Invoices: open a new invoice batch (one "invoice everyone" run).
 * Returns the batch_id so OnePay can then push each student's invoice with this
 * batch_id via upsert_invoice.php, and finally issue/email them with
 * send_invoices.php. Counts + totals are recalculated by send_invoices.php.
 *
 * Expected JSON body: provision_secret, company_uuid, title (required),
 *   description (optional), created_by_email (optional — resolved to a user id).
 */
require_once __DIR__ . '/_bootstrap.php';

$body      = onepay_request();
$pdo       = DB::pdo();
$companyId = onepay_company_id($pdo, $body);

$title = trim((string)($body['title'] ?? ''));
if ($title === '') {
    Response::error('title is required.');
}
$description = trim((string)($body['description'] ?? '')) ?: null;

// Best-effort attribution: map the acting OnePay admin's email to a Centryk user.
$createdBy = null;
$email = strtolower(trim((string)($body['created_by_email'] ?? '')));
if ($email !== '') {
    $st = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
    $st->execute(['e' => $email]);
    $createdBy = (int)($st->fetchColumn() ?: 0) ?: null;
}

$pdo->prepare('INSERT INTO invoice_batches (company_id, title, description, status, created_by)
               VALUES (:c, :t, :d, "draft", :u)')
    ->execute(['c' => $companyId, 't' => $title, 'd' => $description, 'u' => $createdBy]);

Response::ok([
    'batch_id' => (int)$pdo->lastInsertId(),
    'title'    => $title,
]);
