<?php
/**
 * Delete a calendar event.
 * POST { id }
 * Caller must be the event creator.
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

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = (int)($body['id'] ?? 0);
if ($id <= 0) Response::error('id is required.');

$pdo = DB::pdo();

$evt = $pdo->prepare('SELECT id, company_id, created_by FROM events WHERE id = :id LIMIT 1');
$evt->execute(['id' => $id]);
$existing = $evt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    Response::error('Event not found.', 404);
}

$canDelete = ((int)$existing['created_by'] === (int)$user['id']);
if (!$canDelete) {
    Response::error('Only the creator can delete this event.', 403);
}

$pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);

Response::ok(['deleted' => true]);
