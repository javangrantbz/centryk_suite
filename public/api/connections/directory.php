<?php
/**
 * Companies available to send a Centryk Connect request to.
 * GET ?company_id=X -> { companies: [{id, name}] } (directory-visible, excluding self)
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$companyId = (int) ($_GET['company_id'] ?? 0);
if ($companyId <= 0) {
    Response::error('company_id is required.');
}

$stmt = DB::pdo()->prepare(
    "SELECT id, name FROM companies WHERE status='active' AND directory_visible=1 AND id != ? ORDER BY name ASC"
);
$stmt->execute([$companyId]);

Response::ok(['companies' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
