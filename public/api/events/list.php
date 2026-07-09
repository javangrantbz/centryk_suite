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
$membership = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$membership) {
    Response::error('Not a member of this company.', 403);
}
$isAdmin = ($membership['role'] ?? '') === 'admin';

[$year, $month] = array_map('intval', explode('-', $ym));
$firstDate = sprintf('%04d-%02d-01', $year, $month);
$lastDate  = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

$stmt = $pdo->prepare('
    SELECT e.id, e.company_id, e.title, e.description, e.event_date, e.event_type, e.color, e.created_by, e.created_at, e.updated_at,
           COALESCE(GROUP_CONCAT(ea.user_id ORDER BY u.first_name ASC, u.last_name ASC SEPARATOR ","), "") AS attendee_ids
    FROM events e
    LEFT JOIN event_attendees ea ON ea.event_id = e.id
    LEFT JOIN users u ON u.id = ea.user_id
    WHERE e.company_id = :cid
      AND e.event_date BETWEEN :start AND :end
      AND (
          :is_admin = 1
          OR e.created_by = :uid_creator
          OR NOT EXISTS (SELECT 1 FROM event_attendees ea0 WHERE ea0.event_id = e.id)
          OR EXISTS (SELECT 1 FROM event_attendees ea1 WHERE ea1.event_id = e.id AND ea1.user_id = :uid_attendee)
      )
    GROUP BY e.id
    ORDER BY e.event_date ASC, e.id ASC
');
$stmt->execute([
    'cid' => $companyId,
    'start' => $firstDate,
    'end' => $lastDate,
    'is_admin' => $isAdmin ? 1 : 0,
    'uid_creator' => (int)$user['id'],
    'uid_attendee' => (int)$user['id'],
]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($events as &$event) {
    $event['attendee_ids'] = $event['attendee_ids'] !== '' ? array_map('intval', explode(',', $event['attendee_ids'])) : [];
}
unset($event);

Response::ok(['events' => $events]);
