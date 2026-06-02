<?php

require_once __DIR__ . '/../../../invoice-maker/bootstrap.php';

require_auth();

$companyId = current_company_id();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE company_id = ? ORDER BY created_at DESC");
    $stmt->execute([$companyId]);
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $stmt = $pdo->prepare("
        INSERT INTO customers 
        (company_id, name, company, email, phone, address, tax_number)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $companyId,
        $data['name'] ?? '',
        $data['company'] ?? '',
        $data['email'] ?? '',
        $data['phone'] ?? '',
        $data['address'] ?? '',
        $data['tax_number'] ?? ''
    ]);

    json_response(['success' => true, 'id' => $pdo->lastInsertId()]);
}

json_response(['error' => 'Method not allowed'], 405);