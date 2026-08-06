<?php
/**
 * Company-wide dashboard app order.
 *
 * GET  ?company_id=5
 * POST { "company_id": 5, "order": ["onepay", "invoice", "mypay"] }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$pdo = DB::pdo();

function ensureCompanyAppOrderTable(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS company_app_order (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            app_key    VARCHAR(40) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_app_order (company_id, app_key),
            KEY idx_company_app_order (company_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
}

function requireCompanyMember(PDO $pdo, int $companyId, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT role
        FROM company_members
        WHERE company_id = :cid
          AND user_id = :uid
          AND status = "active"
        LIMIT 1
    ');
    $stmt->execute(['cid' => $companyId, 'uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        Response::error('Company access denied.', 403);
    }
    return $row;
}

function activeAppKeys(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT `key` FROM apps WHERE status = "active"');
    $keys = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
        $keys[(string)$key] = true;
    }
    return $keys;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $companyId = (int)($_GET['company_id'] ?? 0);
    if ($companyId < 1) {
        Response::error('company_id is required.');
    }

    ensureCompanyAppOrderTable($pdo);
    requireCompanyMember($pdo, $companyId, (int)$user['id']);

    $stmt = $pdo->prepare('
        SELECT app_key
        FROM company_app_order
        WHERE company_id = :cid
        ORDER BY sort_order ASC, app_key ASC
    ');
    $stmt->execute(['cid' => $companyId]);
    Response::ok(['order' => array_values($stmt->fetchAll(PDO::FETCH_COLUMN))]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId = (int)($body['company_id'] ?? 0);
$order = $body['order'] ?? [];

if ($companyId < 1 || !is_array($order)) {
    Response::error('company_id and order are required.');
}

$member = requireCompanyMember($pdo, $companyId, (int)$user['id']);
if (empty($user['is_admin']) && ($member['role'] ?? '') !== 'admin') {
    Response::error('Only a company admin can reorder apps for this company.', 403);
}

ensureCompanyAppOrderTable($pdo);
$validKeys = activeAppKeys($pdo);
$cleanOrder = [];
foreach ($order as $appKey) {
    $appKey = trim((string)$appKey);
    if ($appKey === '' || !isset($validKeys[$appKey]) || isset($cleanOrder[$appKey])) {
        continue;
    }
    $cleanOrder[$appKey] = true;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM company_app_order WHERE company_id = :cid')
        ->execute(['cid' => $companyId]);

    $insert = $pdo->prepare('
        INSERT INTO company_app_order (company_id, app_key, sort_order, updated_by)
        VALUES (:cid, :app_key, :sort_order, :updated_by)
    ');
    $position = 1;
    foreach (array_keys($cleanOrder) as $appKey) {
        $insert->execute([
            'cid' => $companyId,
            'app_key' => $appKey,
            'sort_order' => $position++,
            'updated_by' => (int)$user['id'],
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    Response::error('Could not save app order.', 500);
}

Response::ok(['order' => array_keys($cleanOrder)]);
