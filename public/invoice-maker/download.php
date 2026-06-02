<?php

require_once __DIR__ . '/../../invoice-maker/bootstrap.php';

require_auth();

$id = $_GET['id'] ?? null;

if (!$id) {
    die('Document ID required.');
}

$stmt = $pdo->prepare("
    SELECT * FROM documents
    WHERE id = ? AND company_id = ?
");

$stmt->execute([
    $id,
    current_company_id()
]);

$document = $stmt->fetch();

if (!$document) {
    die('Document not found.');
}

$filePath = __DIR__ . '/../' . $document['file_path'];

if (!file_exists($filePath)) {
    die('File missing.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: inline; filename="' . basename($document['file_name']) . '"');
header('Content-Length: ' . filesize($filePath));

readfile($filePath);
exit;