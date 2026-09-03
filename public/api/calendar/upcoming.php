<?php
/**
 * Upcoming events for the Calendar preview popover shown in OnePay / MyPay.
 *
 * Server-to-server only: the calling app proxies this from its backend using
 * the shared secret it already holds for Centryk (no browser cross-origin).
 *
 * POST JSON: {
 *   "app_key":      "onepay" | "mypay",
 *   "secret":       "<shared secret for that app>",
 *   "company_uuid": "<centryk company uuid>",
 *   "email":        "<user email, optional — enforces membership>"
 * }
 * Returns up to 5 events in the next 30 days for that company.
 */
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/services/PublicHolidays.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$appKey      = trim($body['app_key']      ?? '');
$secret      = trim($body['secret']       ?? '');
$companyUuid = trim($body['company_uuid'] ?? '');
$email       = trim($body['email']        ?? '');

// Each app authenticates with the shared secret it already uses with Centryk.
$secrets = [
    'mypay'  => $_ENV['MYPAY_WEBHOOK_SECRET'] ?? '',
    'onepay' => $_ENV['PROVISION_SECRET']     ?? '',
];
$expected = $secrets[$appKey] ?? '';

if ($expected === '' || $secret === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}
if ($companyUuid === '') {
    Response::error('company_uuid is required.');
}

$pdo = DB::pdo();

// Resolve the company by its uuid.
$coStmt = $pdo->prepare('SELECT id FROM companies WHERE uuid = :uuid AND status = "active" LIMIT 1');
$coStmt->execute(['uuid' => $companyUuid]);
$companyId = (int)($coStmt->fetchColumn() ?: 0);
if (!$companyId) {
    Response::error('Company not found.', 404);
}

// If an email is supplied, make sure that user actually belongs to the company.
$memberUserId = 0;
$memberRole = '';
if ($email !== '') {
    $memStmt = $pdo->prepare(
        'SELECT u.id, cm.role
         FROM company_members cm
         JOIN users u ON u.id = cm.user_id
         WHERE cm.company_id = :cid AND u.email = :email AND cm.status = "active"
         LIMIT 1'
    );
    $memStmt->execute(['cid' => $companyId, 'email' => strtolower($email)]);
    $member = $memStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        Response::error('Not a member of this company.', 403);
    }
    $memberUserId = (int)$member['id'];
    $memberRole = (string)$member['role'];
}

if ($memberUserId > 0) {
    $evStmt = $pdo->prepare(
        'SELECT title, event_date, event_type, color
         FROM events
         WHERE company_id = :cid
           AND event_date >= CURDATE()
           AND event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           AND (
               :is_admin = 1
               OR created_by = :uid_creator
               OR NOT EXISTS (SELECT 1 FROM event_attendees ea0 WHERE ea0.event_id = events.id)
               OR EXISTS (SELECT 1 FROM event_attendees ea1 WHERE ea1.event_id = events.id AND ea1.user_id = :uid_attendee)
           )
         ORDER BY event_date ASC, id ASC
         LIMIT 5'
    );
    $evStmt->execute([
        'cid' => $companyId,
        'uid_creator' => $memberUserId,
        'uid_attendee' => $memberUserId,
        'is_admin' => $memberRole === 'admin' ? 1 : 0,
    ]);
} else {
    $evStmt = $pdo->prepare(
        'SELECT title, event_date, event_type, color
         FROM events
         WHERE company_id = :cid
           AND event_date >= CURDATE()
           AND event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY event_date ASC, id ASC
         LIMIT 5'
    );
    $evStmt->execute(['cid' => $companyId]);
}

$events = $evStmt->fetchAll(PDO::FETCH_ASSOC);

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
    // no holidays table yet
}
usort($events, static fn($a, $b) => strcmp((string)$a['event_date'], (string)$b['event_date']));

Response::ok(['events' => array_slice($events, 0, 6)]);
