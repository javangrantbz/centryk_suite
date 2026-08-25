<?php

require_once __DIR__ . '/../../../invoice-maker/bootstrap.php';

require_auth();

$companyId = current_company_id();
$method = $_SERVER['REQUEST_METHOD'];

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