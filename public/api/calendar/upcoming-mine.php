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
require_once __DIR__ . '/../../../app/services/PublicHolidays.php';
require_once __DIR__ . '/../../../app/services/PeopleMilestones.php';

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
       AND (
           e.created_by = :uid_creator
           OR cm.role = "admin"
           OR NOT EXISTS (SELECT 1 FROM event_attendees ea0 WHERE ea0.event_id = e.id)
           OR EXISTS (SELECT 1 FROM event_attendees ea1 WHERE ea1.event_id = e.id AND ea1.user_id = :uid_attendee)
       )
     ORDER BY e.event_date ASC, e.id ASC
     LIMIT 5'
);
$stmt->execute([
    'uid' => (int)$user['id'],
    'uid_creator' => (int)$user['id'],
    'uid_attendee' => (int)$user['id'],
]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Belize public/bank holidays in the same 30-day window (national, everyone).
try {
    foreach (PublicHolidays::forRange(date('Y-m-d'), date('Y-m-d', strtotime('+30 days'))) as $h) {
        $events[] = [
            'title'      => $h['name'] . ' · ' . PublicHolidays::rateLabel((float)$h['pay_rate']) . ' pay',
            'event_date' => $h['holiday_date'],
            'event_type' => 'holiday',
            'color'      => 'rose',
        ];
    }
} catch (Throwable $e) {
    // no holidays table yet — return just the events
}

// Birthdays & work anniversaries in the user's own companies.
try {
    $myCos = $pdo->prepare("
        SELECT c.uuid FROM company_members cm
        JOIN companies c ON c.id = cm.company_id AND c.status = 'active'
        WHERE cm.user_id = :uid AND cm.status = 'active'
    ");
    $myCos->execute(['uid' => (int)$user['id']]);
    $myUuids = array_flip($myCos->fetchAll(PDO::FETCH_COLUMN));
    if ($myUuids) {
        foreach (PeopleMilestones::forRange(date('Y-m-d'), date('Y-m-d', strtotime('+30 days'))) as $m) {
            if (!isset($myUuids[$m['company_uuid'] ?? ''])) { continue; }
            $isAnniv = ($m['kind'] ?? '') === 'anniversary';
            $events[] = [
                'title'      => $isAnniv
                    ? trim((string)$m['employee_name']) . ' · ' . (int)($m['years'] ?? 0) . ' yr' . ((int)($m['years'] ?? 0) === 1 ? '' : 's')
                    : trim((string)$m['employee_name']) . ' · birthday',
                'event_date' => $m['date'],
                'event_type' => $isAnniv ? 'anniversary' : 'birthday',
                'color'      => $isAnniv ? 'teal' : 'pink',
            ];
        }
    }
} catch (Throwable $e) {
    // MyPay unreachable — skip milestones
}

usort($events, static fn($a, $b) => strcmp((string)$a['event_date'], (string)$b['event_date']));

Response::ok(['events' => array_slice($events, 0, 8)]);
