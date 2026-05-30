<?php
/**
 * List calendar events for a company in a given month.
 * GET ?company_id=X&ym=YYYY-MM (ym defaults to current month).
 * Caller must be an active member of the company.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$companyId = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
$ym        = $_GET['ym'] ?? date('Y-m');

if ($companyId <= 0) {
    Response::error('company_id is required.');
}
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    Response::error('ym must be YYYY-MM.');
}

$pdo = DB::pdo();

// Caller must be a member of the company
$mStmt = $pdo->prepare('SELECT role FROM company_members WHERE user_id = :uid AND company_id = :cid AND status = "active" LIMIT 1');
$mStmt->execute(['uid' => (int)$user['id'], 'cid' => $companyId]);
if (!$mStmt->fetch()) {
    Response::error('Not a member of this company.', 403);
}

[$year, $month] = array_map('intval', explode('-', $ym));
$firstDate = sprintf('%04d-%02d-01', $year, $month);
$lastDate  = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

$stmt = $pdo->prepare('
    SELECT id, company_id, title, description, event_date, event_type, color, created_by, created_at, updated_at
    FROM events
    WHERE company_id = :cid
      AND event_date BETWEEN :start AND :end
    ORDER BY event_date ASC, id ASC
');
$stmt->execute(['cid' => $companyId, 'start' => $firstDate, 'end' => $lastDate]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

Response::ok(['events' => $events]);
