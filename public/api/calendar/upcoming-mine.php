<?php
/**
 * Upcoming events for the logged-in user, across every company they belong to.
 * Session-authenticated (browser) — powers the calendar preview in the account
 * header. Mirrors the shape of upcoming.php (server-to-server) but scoped to the
 * current user instead of a single company + shared secret.
 *
 * GET — returns up to 5 events in the next 30 days.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$pdo  = DB::pdo();
$stmt = $pdo->prepare(
    'SELECT e.title, e.event_date, e.event_type, e.color
     FROM events e
     JOIN company_members cm
       ON cm.company_id = e.company_id
      AND cm.user_id = :uid
      AND cm.status = "active"
     WHERE e.event_date >= CURDATE()
       AND e.event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY e.event_date ASC, e.id ASC
     LIMIT 5'
);
$stmt->execute(['uid' => (int)$user['id']]);

Response::ok(['events' => $stmt->fetchAll()]);
