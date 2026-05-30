<?php
/**
 * Update a calendar event.
 * POST { id, title, description, event_date, event_type, color }
 * Caller must be the event creator OR a company admin.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$id          = (int)($body['id'] ?? 0);
$title       = trim((string)($body['title'] ?? ''));
$description = trim((string)($body['description'] ?? ''));
$eventDate   = trim((string)($body['event_date'] ?? ''));
$eventType   = trim((string)($body['event_type'] ?? 'other'));
$color       = trim((string)($body['color'] ?? 'slate'));

if ($id <= 0)        Response::error('id is required.');
if ($title === '')   Response::error('title is required.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
    Response::error('event_date must be YYYY-MM-DD.');
}

$allowedTypes  = ['meeting', 'holiday', 'deadline', 'training', 'other'];
$allowedColors = ['slate', 'blue', 'teal', 'green', 'amber', 'red', 'purple'];
if (!in_array($eventType, $allowedTypes, true)) $eventType = 'other';
if (!in_array($color,     $allowedColors, true)) $color     = 'slate';

$pdo = DB::pdo();

$evt = $pdo->prepare('SELECT id, company_id, created_by FROM events WHERE id = :id LIMIT 1');
$evt->execute(['id' => $id]);
$existing = $evt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    Response::error('Event not found.', 404);
}

// Creator OR company admin can edit
$canEdit = ((int)$existing['created_by'] === (int)$user['id']);
if (!$canEdit) {
    $mStmt = $pdo->prepare('SELECT role FROM company_members WHERE user_id = :uid AND company_id = :cid AND status = "active" LIMIT 1');
    $mStmt->execute(['uid' => (int)$user['id'], 'cid' => (int)$existing['company_id']]);
    $row = $mStmt->fetch(PDO::FETCH_ASSOC);
    $canEdit = $row && $row['role'] === 'admin';
}
if (!$canEdit) {
    Response::error('Only the creator or a company admin can edit this event.', 403);
}

$upd = $pdo->prepare('
    UPDATE events
       SET title = :title, description = :description, event_date = :date,
           event_type = :type, color = :color
     WHERE id = :id
');
$upd->execute([
    'title'       => $title,
    'description' => $description !== '' ? $description : null,
    'date'        => $eventDate,
    'type'        => $eventType,
    'color'       => $color,
    'id'          => $id,
]);

$out = $pdo->prepare('SELECT id, company_id, title, description, event_date, event_type, color, created_by, created_at, updated_at FROM events WHERE id = :id LIMIT 1');
$out->execute(['id' => $id]);

Response::ok(['event' => $out->fetch(PDO::FETCH_ASSOC)]);
