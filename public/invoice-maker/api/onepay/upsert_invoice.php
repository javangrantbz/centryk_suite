<?php
/**
 * OnePay → Invoices: create or update an invoice from an OnePay sale / student
 * charge. Idempotent, keyed by (company_id, source_app='onepay', source_ref).
 * Re-posting the same source_ref updates the existing invoice instead of
 * creating a duplicate — so POS re-syncs and status corrections are safe.
 *
 * Customer is matched within the company by email (falls back to name when no
 * email), mirroring how Centryk SSO already reconciles people across apps.
 *
 * Expected JSON body:
 *   provision_secret, company_uuid, source_ref (required),
 *   customer: { name, email, phone, address },
 *   items: [ { description, quantity, unit_price } ],
 *   tax, discount, total, amount_paid, status, issue_date, due_date, notes,
 *   invoice_number (optional), batch_id (optional)
 */
require_once __DIR__ . '/_bootstrap.php';

$body      = onepay_request();
$pdo       = DB::pdo();
$companyId = onepay_company_id($pdo, $body);

$sourceRef = trim((string)($body['source_ref'] ?? ''));
if ($sourceRef === '') {
    Response::error('source_ref is required.');
}

// ── Upsert the customer within this company ─────────────────────────────────
$cust    = is_array($body['customer'] ?? null) ? $body['customer'] : [];
$name    = trim((string)($cust['name'] ?? '')) ?: 'Walk-in Customer';
$email   = strtolower(trim((string)($cust['email'] ?? '')));
$phone   = trim((string)($cust['phone'] ?? ''));
$address = trim((string)($cust['address'] ?? ''));

$customerId = 0;
if ($email !== '') {
    $st = $pdo->prepare('SELECT id FROM customers WHERE company_id = :c AND LOWER(email) = :e LIMIT 1');
    $st->execute(['c' => $companyId, 'e' => $email]);
    $customerId = (int)($st->fetchColumn() ?: 0);
} else {
    $st = $pdo->prepare('SELECT id FROM customers WHERE company_id = :c AND name = :n LIMIT 1');
    $st->execute(['c' => $companyId, 'n' => $name]);
    $customerId = (int)($st->fetchColumn() ?: 0);
}

if ($customerId) {
    // Only overwrite phone/address when new values are supplied.
    $pdo->prepare('UPDATE customers SET name = :n,
                     phone   = COALESCE(NULLIF(:p, ""), phone),
                     address = COALESCE(NULLIF(:a, ""), address)
                   WHERE id = :id')
        ->execute(['n' => $name, 'p' => $phone, 'a' => $address, 'id' => $customerId]);
} else {
    $pdo->prepare('INSERT INTO customers (company_id, name, email, phone, address)
                   VALUES (:c, :n, :e, :p, :a)')
        ->execute([
            'c' => $companyId,
            'n' => $name,
            'e' => ($email !== '' ? $email : null),
            'p' => ($phone !== '' ? $phone : null),
            'a' => ($address !== '' ? $address : null),
        ]);
    $customerId = (int)$pdo->lastInsertId();
}

// ── Line items + totals (mirrors app/views/invoices/create.php) ─────────────
$items    = is_array($body['items'] ?? null) ? $body['items'] : [];
$subtotal = 0.0;
$clean    = [];
foreach ($items as $it) {
    $desc = trim((string)($it['description'] ?? ''));
    if ($desc === '') continue;
    $qty   = (float)($it['quantity'] ?? 1);
    $price = (float)($it['unit_price'] ?? 0);
    $line  = $qty * $price;
    $clean[] = ['d' => $desc, 'q' => $qty, 'p' => $price, 't' => $line];
    $subtotal += $line;
}

$tax        = (float)($body['tax'] ?? 0);
$discount   = (float)($body['discount'] ?? 0);
$total      = isset($body['total']) ? (float)$body['total'] : ($subtotal + $tax - $discount);
$amountPaid = (float)($body['amount_paid'] ?? 0);
$status     = in_array($body['status'] ?? '', ['draft', 'sent', 'paid', 'overdue', 'cancelled'], true)
              ? $body['status'] : 'sent';
$issueDate  = trim((string)($body['issue_date'] ?? '')) ?: date('Y-m-d');
$dueDate    = trim((string)($body['due_date'] ?? '')) ?: null;
$notes      = trim((string)($body['notes'] ?? '')) ?: null;
$batchId    = isset($body['batch_id']) && (int)$body['batch_id'] > 0 ? (int)$body['batch_id'] : null;

// ── Idempotent upsert by (company_id, 'onepay', source_ref) ─────────────────
$find = $pdo->prepare('SELECT id, invoice_number, share_token FROM invoices
                       WHERE company_id = :c AND source_app = "onepay" AND source_ref = :r LIMIT 1');
$find->execute(['c' => $companyId, 'r' => $sourceRef]);
$existing = $find->fetch();

if ($existing) {
    $invoiceId     = (int)$existing['id'];
    $invoiceNumber = $existing['invoice_number'];
    $shareToken    = $existing['share_token'];
    $pdo->prepare('UPDATE invoices SET customer_id = :cust, status = :s, issue_date = :i,
                     due_date = :d, subtotal = :sub, tax = :tax, discount = :disc, total = :tot,
                     amount_paid = :paid, notes = :notes, batch_id = :batch
                   WHERE id = :id')
        ->execute([
            'cust' => $customerId, 's' => $status, 'i' => $issueDate, 'd' => $dueDate,
            'sub' => $subtotal, 'tax' => $tax, 'disc' => $discount, 'tot' => $total,
            'paid' => $amountPaid, 'notes' => $notes, 'batch' => $batchId, 'id' => $invoiceId,
        ]);
    $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = :id')->execute(['id' => $invoiceId]);
} else {
    $invoiceNumber = trim((string)($body['invoice_number'] ?? '')) ?: ('INV-' . date('Ymd') . '-' . rand(100, 999));
    $shareToken    = bin2hex(random_bytes(20)); // 40 hex chars → CHAR(40)
    $pdo->prepare('INSERT INTO invoices
                     (company_id, customer_id, invoice_number, status, issue_date, due_date,
                      subtotal, tax, discount, total, amount_paid, notes, share_token,
                      source_app, source_ref, batch_id)
                   VALUES (:c, :cust, :num, :s, :i, :d, :sub, :tax, :disc, :tot, :paid, :notes,
                           :tok, "onepay", :ref, :batch)')
        ->execute([
            'c' => $companyId, 'cust' => $customerId, 'num' => $invoiceNumber, 's' => $status,
            'i' => $issueDate, 'd' => $dueDate, 'sub' => $subtotal, 'tax' => $tax, 'disc' => $discount,
            'tot' => $total, 'paid' => $amountPaid, 'notes' => $notes, 'tok' => $shareToken,
            'ref' => $sourceRef, 'batch' => $batchId,
        ]);
    $invoiceId = (int)$pdo->lastInsertId();
}

// ── (Re)insert line items ───────────────────────────────────────────────────
$itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total)
                           VALUES (:i, :d, :q, :p, :t)');
foreach ($clean as $c) {
    $itemStmt->execute(['i' => $invoiceId, 'd' => $c['d'], 'q' => $c['q'], 'p' => $c['p'], 't' => $c['t']]);
}

// ── Auto-post the electronic payment to the AR ledger ──────────────────────
// When the company runs Centryk Business — Receivables and the sale was paid,
// record it as a first-class receipt on the customer account (idempotent), so
// it reconciles against the card settlement deposit later.
$receiptPosted = false;
if ($amountPaid > 0.004 && $customerId > 0) {
    try {
        require_once __DIR__ . '/../../../../app/core/Entitlements.php';
        if (Entitlements::level($companyId, 'receivables') !== Entitlements::NONE) {
            require_once __DIR__ . '/../../../../app/services/ReceivablesService.php';
            $pm = ReceivablesService::postOnepayReceipt(
                $companyId, $invoiceId, $customerId, (float) $amountPaid,
                $sourceRef, trim((string) ($body['settlement_ref'] ?? '')),
                $issueDate, null
            );
            $receiptPosted = !empty($pm['created']);
        }
    } catch (Throwable $e) {
        // never fail a POS sync over the ledger side
        error_log('[onepay] receipt post failed for sale ' . $sourceRef . ': ' . $e->getMessage());
    }
}

Response::ok([
    'invoice_id'     => $invoiceId,
    'invoice_number' => $invoiceNumber,
    'share_token'    => $shareToken,
    'customer_id'    => $customerId,
    'status'         => $status,
    'created'        => !$existing,
    'receipt_posted' => $receiptPosted,
]);
