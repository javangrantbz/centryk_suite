<?php
/**
 * The signed-in user's active / due / next-24h online conferences, for the
 * always-visible header pill. Polling this also sends any due reminders.
 * GET. Returns { conferences: [...], server_time }.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/ConferenceService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

try {
    $rows = ConferenceService::mine((int)$user['id']);
} catch (Throwable $e) {
    error_log('conferences/mine failed: ' . $e->getMessage());
    Response::ok(['conferences' => [], 'server_time' => time()]);
}

$out = [];
foreach ($rows as $c) {
    $out[] = [
        'event_id'     => (int)$c['event_id'],
        'title'        => $c['title'],
        'state'        => $c['state'],
        'start_ts'     => (int)$c['start_ts'],
        'when'         => ConferenceService::whenLabel($c),
        'join_url'     => $c['join_url'],
        'is_host'      => (bool)$c['is_host'],
        'host_name'    => $c['host_name'],
        'company_name' => $c['company_name'],
        'in_room'      => $c['state'] === 'active' ? count(ConferenceService::participantsNow((int)$c['event_id'])) : 0,
    ];
}

Response::ok(['conferences' => $out, 'server_time' => time()]);
