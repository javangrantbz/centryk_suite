<?php
/**
 * Update a calendar event.
 * POST { id, title, description, event_date, event_type, color, attendee_ids? }
 * Caller must be the event creator.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/NotificationService.php';

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
$attendeeIds = array_values(array_unique(array_filter(array_map('intval', (array)($body['attendee_ids'] ?? [])), static function ($attendeeId) {
    return $attendeeId > 0;
})));

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

$canEdit = ((int)$existing['created_by'] === (int)$user['id']);
if (!$canEdit) {
    Response::error('Only the creator can edit this event.', 403);
}

$pdo->beginTransaction();
try {
    $prevStmt = $pdo->prepare('SELECT user_id FROM event_attendees WHERE event_id = :id');
    $prevStmt->execute(['id' => $id]);
    $previousAttendeeIds = array_map('intval', $prevStmt->fetchAll(PDO::FETCH_COLUMN));

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

    $pdo->prepare('DELETE FROM event_attendees WHERE event_id = :id')->execute(['id' => $id]);

    if (!empty($attendeeIds)) {
        $attendeeIds[] = (int)$existing['created_by'];
        $attendeeIds = array_values(array_unique($attendeeIds));

        $placeholders = implode(',', array_fill(0, count($attendeeIds), '?'));
        $validStmt = $pdo->prepare("
            SELECT cm.user_id
            FROM company_members cm
            JOIN users u ON u.id = cm.user_id
            WHERE cm.company_id = ?
              AND cm.status = 'active'
              AND u.status = 'active'
              AND cm.user_id IN ($placeholders)
        ");
        $validStmt->execute(array_merge([(int)$existing['company_id']], $attendeeIds));
        $validIds = array_map('intval', $validStmt->fetchAll(PDO::FETCH_COLUMN));

        if (count($validIds) !== count($attendeeIds)) {
            throw new InvalidArgumentException('One or more selected employees are not active members of this company.');
        }

        $attStmt = $pdo->prepare('INSERT IGNORE INTO event_attendees (event_id, user_id) VALUES (:event_id, :user_id)');
        foreach ($validIds as $uid) {
            $attStmt->execute(['event_id' => $id, 'user_id' => $uid]);
        }

        $newlyAddedIds = array_values(array_diff($validIds, $previousAttendeeIds, [(int)$user['id']]));
        if (!empty($newlyAddedIds)) {
            $actorName = trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? '')));
            if ($actorName === '') {
                $actorName = 'Someone';
            }
            $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'http://localhost/centryk/public'), '/');
            $eventUrl = $appUrl . '/calendar.php?' . http_build_query([
                'company_id' => (int)$existing['company_id'],
                'ym' => substr($eventDate, 0, 7),
            ]);
            foreach ($newlyAddedIds as $uid) {
                NotificationService::create([
                    'user_id' => $uid,
                    'company_id' => (int)$existing['company_id'],
                    'app_key' => 'calendar',
                    'type' => 'calendar.event_added',
                    'title' => $actorName . ' added you to an event',
                    'body' => $title . ' on ' . $eventDate,
                    'url' => $eventUrl,
                    'icon' => 'calendar-plus',
                    'color' => '#14b8a6',
                ]);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof InvalidArgumentException) {
        Response::error($e->getMessage());
    }
    error_log('Calendar event update failed: ' . $e->getMessage());
    Response::error('Could not save event.');
}

$out = $pdo->prepare('
    SELECT e.id, e.company_id, e.title, e.description, e.event_date, e.event_type, e.color, e.created_by, e.created_at, e.updated_at,
           COALESCE(GROUP_CONCAT(ea.user_id ORDER BY u.first_name ASC, u.last_name ASC SEPARATOR ","), "") AS attendee_ids
    FROM events e
    LEFT JOIN event_attendees ea ON ea.event_id = e.id
    LEFT JOIN users u ON u.id = ea.user_id
    WHERE e.id = :id
    GROUP BY e.id
    LIMIT 1
');
$out->execute(['id' => $id]);

$event = $out->fetch(PDO::FETCH_ASSOC);
$event['attendee_ids'] = $event['attendee_ids'] !== '' ? array_map('intval', explode(',', $event['attendee_ids'])) : [];

Response::ok(['event' => $event]);
