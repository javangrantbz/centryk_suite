<?php

require_once __DIR__ . '/../../../invoice-maker/bootstrap.php';

require_auth();

$companyId = current_company_id();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT 
            documents.*,
            customers.name AS customer_name,
            quotes.quote_number,
            invoices.invoice_number
        FROM documents
        LEFT JOIN customers ON customers.id = documents.customer_id
        LEFT JOIN quotes ON quotes.id = documents.quote_id
        LEFT JOIN invoices ON invoices.id = documents.invoice_id
        WHERE documents.company_id = ?
        ORDER BY documents.created_at DESC
    ");

    $stmt->execute([$companyId]);

    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        json_response(['error' => 'No file uploaded'], 400);
    }

    $title = $_POST['title'] ?? 'Untitled Document';
    $customerId = $_POST['customer_id'] ?? null;
    $quoteId = $_POST['quote_id'] ?? null;
    $invoiceId = $_POST['invoice_id'] ?? null;

    $customerId = $customerId ?: null;
    $quoteId = $quoteId ?: null;
    $invoiceId = $invoiceId ?: null;

    $originalName = $_FILES['document']['name'];
    $tmpName = $_FILES['document']['tmp_name'];
    $fileType = $_FILES['document']['type'];
    $fileSize = $_FILES['document']['size'];

    $maxSize = 10 * 1024 * 1024;

    if ($fileSize > $maxSize) {
        json_response(['error' => 'File is too large. Maximum size is 10MB.'], 400);
    }

    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'txt'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions)) {
        json_response(['error' => 'File type not allowed'], 400);
    }

    $safeName = uniqid('doc_', true) . '.' . $extension;
    $relativePath = 'storage/documents/' . $safeName;
    $destination = __DIR__ . '/../../' . $relativePath;

    if (!move_uploaded_file($tmpName, $destination)) {
        json_response(['error' => 'Upload failed'], 500);
    }

    $stmt = $pdo->prepare("
        INSERT INTO documents
        (company_id, customer_id, invoice_id, quote_id, title, file_name, file_path, file_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $companyId,
        $customerId,
        $invoiceId,
        $quoteId,
        $title,
        $originalName,
        $relativePath,
        $fileType
    ]);

    json_response([
        'success' => true,
        'id' => $pdo->lastInsertId(),
        'file_path' => $relativePath
    ]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;

    if (!$id) {
        json_response(['error' => 'Document ID is required'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    $document = $stmt->fetch();

    if (!$document) {
        json_response(['error' => 'Document not found'], 404);
    }

    $fullPath = __DIR__ . '/../../' . $document['file_path'];

    if (file_exists($fullPath)) {
        unlink($fullPath);
    }

    $delete = $pdo->prepare("DELETE FROM documents WHERE id = ? AND company_id = ?");
    $delete->execute([$id, $companyId]);

    json_response(['success' => true]);
}

json_response(['error' => 'Method not allowed'], 405);