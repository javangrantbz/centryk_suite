<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';

require_admin();

$rows = DB::pdo()->query("
    SELECT id, first_name, last_name, email, status, created_at
    FROM users
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'users' => $rows]);
