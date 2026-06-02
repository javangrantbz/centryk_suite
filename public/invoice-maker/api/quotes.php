<?php

require_once __DIR__ . '/../../../invoice-maker/bootstrap.php';

require_auth();

$companyId = current_company_id();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare("
            SELECT quotes.*, customers.name AS customer_name
            FROM quotes
            JOIN customers ON customers.id = quotes.customer_id
            WHERE quotes.id = ? AND quotes.company_id = ?
        ");
        $stmt->execute([$id, $companyId]);
        $quote = $stmt->fetch();

        if (!$quote) {
            json_response(['error' => 'Quote not found'], 404);
        }

        $items = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = ?");
        $items->execute([$id]);
        $quote['items'] = $items->fetchAll();

        json_response($quote);
    }

    $stmt = $pdo->prepare("
        SELECT quotes.*, customers.name AS customer_name
        FROM quotes
        JOIN customers ON customers.id = quotes.customer_id
        WHERE quotes.company_id = ?
        ORDER BY quotes.created_at DESC
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
        INSERT INTO quotes
        (company_id, customer_id, quote_number, status, issue_date, expiry_date, subtotal, tax, discount, total, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $companyId,
        $data['customer_id'],
        $data['quote_number'],
        $data['status'] ?? 'draft',
        $data['issue_date'],
        $data['expiry_date'] ?? null,
        $subtotal,
        $tax,
        $discount,
        $total,
        $data['notes'] ?? ''
    ]);

    $quoteId = $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO quote_items
        (quote_id, description, quantity, unit_price, total)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 1);
        $price = (float)($item['unit_price'] ?? 0);

        $itemStmt->execute([
            $quoteId,
            $item['description'] ?? '',
            $qty,
            $price,
            $qty * $price
        ]);
    }

    json_response([
        'success' => true,
        'id' => $quoteId
    ]);
}

if ($method === 'PATCH') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $_GET['id'] ?? null;

    if (!$id) {
        json_response(['error' => 'Quote ID is required'], 400);
    }

    if (isset($data['status'])) {
        $stmt = $pdo->prepare("UPDATE quotes SET status = ? WHERE id = ? AND company_id = ?");
        $stmt->execute([$data['status'], $id, $companyId]);

        json_response(['success' => true]);
    }

    json_response(['error' => 'Nothing to update'], 400);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;

    if (!$id) {
        json_response(['error' => 'Quote ID is required'], 400);
    }

    $stmt = $pdo->prepare("DELETE FROM quotes WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);

    json_response(['success' => true]);
}

json_response(['error' => 'Method not allowed'], 405);