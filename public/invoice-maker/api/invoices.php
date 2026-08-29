<?php

require_once __DIR__ . '/../../../invoice-maker/bootstrap.php';

require_auth();

$companyId = current_company_id();
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Credit control (Centryk Business — Receivables). When the company holds the
 * 'receivables' package, a customer who is on credit hold or over their limit
 * can't be issued a new invoice unless the request explicitly overrides it
 * (which is audited). Draft invoices are always allowed. No-op for companies
 * without the package, so plain invoice-maker is unchanged.
 */
function inv_assert_credit_ok(PDO $pdo, int $companyId, int $customerId, array $data): void
{
    $cw = dirname(__DIR__, 3);
    require_once $cw . '/app/core/Entitlements.php';
    if (Entitlements::level($companyId, 'receivables') === Entitlements::NONE) {
        return;
    }
    require_once $cw . '/app/services/ReceivablesService.php';
    try {
        $c = ReceivablesService::creditStatus($companyId, $customerId);
    } catch (Throwable $e) {
        return; // unknown customer etc. — let the normal insert path handle it
    }
    if ($c['status'] === 'ok') {
        return;
    }

    $reason = [
        'hold'       => 'This customer is on credit hold.',
        'over_limit' => 'This customer is over their credit limit'
            . ($c['credit_limit'] !== null ? ' (BZD ' . number_format((float)$c['balance'], 2)
                . ' of BZD ' . number_format((float)$c['credit_limit'], 2) . ').' : '.'),
        'blocked'    => 'This customer is on credit hold and over their limit.',
    ][$c['status']] ?? 'This customer has a credit block.';

    if (empty($data['override_credit_hold'])) {
        json_response([
            'error'         => $reason . ' Clear the hold in Receivables, or resubmit with override.',
            'credit_status' => $c,
            'needs_override' => true,
        ], 409);
    }

    require_once $cw . '/app/core/Audit.php';
    Audit::log([
        'actor_user_id' => (int)($_SESSION['user']['id'] ?? 0) ?: null,
        'company_id'    => $companyId,
        'event_type'    => 'receivables.credit_hold.overridden',
        'summary'       => 'Invoice issued despite ' . $c['status'] . ' credit status (customer #' . $customerId . ')',
        'metadata'      => ['customer_id' => $customerId, 'credit_status' => $c],
    ]);
}

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare("
            SELECT invoices.*, customers.name AS customer_name
            FROM invoices
            JOIN customers ON customers.id = invoices.customer_id
            WHERE invoices.id = ? AND invoices.company_id = ?
        ");
        $stmt->execute([$id, $companyId]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            json_response(['error' => 'Invoice not found'], 404);
        }

        $items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
        $items->execute([$id]);
        $invoice['items'] = $items->fetchAll();

        json_response($invoice);
    }

    $stmt = $pdo->prepare("
        SELECT invoices.*, customers.name AS customer_name
        FROM invoices
        JOIN customers ON customers.id = invoices.customer_id
        WHERE invoices.company_id = ?
        ORDER BY invoices.created_at DESC
    ");
    $stmt->execute([$companyId]);

    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $subtotal = 0;
    $items = $data['items'] ?? [];

    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 1);
        $price = (float)($item['unit_price'] ?? 0);
        $subtotal += $qty * $price;
    }

    $tax = (float)($data['tax'] ?? 0);
    $discount = (float)($data['discount'] ?? 0);
    $total = $subtotal + $tax - $discount;

    $newStatus = $data['status'] ?? 'draft';
    if ($newStatus !== 'draft' && !empty($data['customer_id'])) {
        inv_assert_credit_ok($pdo, (int)$companyId, (int)$data['customer_id'], $data);
    }

    $stmt = $pdo->prepare("
        INSERT INTO invoices
        (company_id, customer_id, invoice_number, status, issue_date, due_date, subtotal, tax, discount, total, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $companyId,
        $data['customer_id'],
        $data['invoice_number'],
        $data['status'] ?? 'draft',
        $data['issue_date'],
        $data['due_date'] ?? null,
        $subtotal,
        $tax,
        $discount,
        $total,
        $data['notes'] ?? ''
    ]);

    $invoiceId = $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO invoice_items
        (invoice_id, description, quantity, unit_price, total)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 1);
        $price = (float)($item['unit_price'] ?? 0);

        $itemStmt->execute([
            $invoiceId,
            $item['description'] ?? '',
            $qty,
            $price,
            $qty * $price
        ]);
    }

    json_response([
        'success' => true,
        'id' => $invoiceId
    ]);
}

if ($method === 'PATCH') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $_GET['id'] ?? null;

    if (!$id) {
        json_response(['error' => 'Invoice ID is required'], 400);
    }

    if (isset($data['status'])) {
        if (in_array($data['status'], ['sent', 'overdue'], true)) {
            $own = $pdo->prepare("SELECT customer_id, status FROM invoices WHERE id = ? AND company_id = ?");
            $own->execute([$id, $companyId]);
            $row = $own->fetch();
            if ($row && !in_array($row['status'], ['sent', 'overdue'], true) && $row['customer_id']) {
                inv_assert_credit_ok($pdo, (int)$companyId, (int)$row['customer_id'], $data);
            }
        }
        $stmt = $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ? AND company_id = ?");
        $stmt->execute([$data['status'], $id, $companyId]);

        json_response(['success' => true]);
    }

    json_response(['error' => 'Nothing to update'], 400);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;

    if (!$id) {
        json_response(['error' => 'Invoice ID is required'], 400);
    }

    // POS receipts mirror completed OnePay sales — deleting one here would
    // leave OnePay's own sales row untouched and the two systems disagreeing.
    $check = $pdo->prepare("SELECT source_app, source_ref FROM invoices WHERE id = ? AND company_id = ?");
    $check->execute([$id, $companyId]);
    $existing = $check->fetch();

    if (!$existing) {
        json_response(['error' => 'Invoice not found'], 404);
    }
    if (inv_is_pos_receipt($existing)) {
        json_response(['error' => inv_pos_receipt_delete_message()], 403);
    }

    $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);

    json_response(['success' => true]);
}

json_response(['error' => 'Method not allowed'], 405);