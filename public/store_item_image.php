<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';

Auth::start();
$user = Auth::user(); // may be null - market/both images are public; only 'employee' audience needs a member below

$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
if ($itemId <= 0) {
    http_response_code(404);
    exit;
}

$pdo = DB::pdo();
$stmt = $pdo->prepare('
    SELECT sl.company_id, sl.audience, sl.image_url
    FROM store_listings sl
    JOIN companies c ON c.id = sl.company_id
    WHERE sl.source_app = "onepay"
      AND sl.source_item_id = :item_id
      AND sl.enabled = 1
      AND c.status = "active"
      AND (sl.starts_at IS NULL OR sl.starts_at <= NOW())
      AND (sl.ends_at IS NULL OR sl.ends_at >= NOW())
    LIMIT 1
');
$stmt->execute(['item_id' => $itemId]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$listing) {
    http_response_code(404);
    exit;
}

$audience = (string)($listing['audience'] ?? '');
if (!in_array($audience, ['market', 'both'], true)) {
    if (!$user) {
        http_response_code(404);
        exit;
    }
    $memberStmt = $pdo->prepare('
        SELECT id
        FROM company_members
        WHERE company_id = :company_id AND user_id = :user_id AND status = "active"
        LIMIT 1
    ');
    $memberStmt->execute([
        'company_id' => (int)$listing['company_id'],
        'user_id' => (int)$user['id'],
    ]);
    if (!$memberStmt->fetch()) {
        http_response_code(404);
        exit;
    }
}

$imageUrl = trim((string)($listing['image_url'] ?? ''));
if ($imageUrl === '') {
    http_response_code(404);
    exit;
}

if (preg_match('#^https?://#i', $imageUrl)) {
    header('Location: ' . $imageUrl, true, 302);
    exit;
}

if (!str_starts_with($imageUrl, '/uploads/catalog/')) {
    http_response_code(404);
    exit;
}

$relativeFile = str_replace('\\', '/', ltrim($imageUrl, '/'));
if (str_contains($relativeFile, '..')) {
    http_response_code(404);
    exit;
}

$baseDir = realpath(dirname(__DIR__, 2) . '/onepay/public/uploads/catalog');
$filePath = realpath(dirname(__DIR__, 2) . '/onepay/public/' . $relativeFile);
if (!$baseDir || !$filePath || !str_starts_with($filePath, $baseDir) || !is_file($filePath)) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($filePath) ?: 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: public, max-age=3600');
readfile($filePath);
