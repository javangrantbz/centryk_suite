<?php
/**
 * Upcoming staff birthdays & work anniversaries for one company, for the
 * dashboard "people this week" nudge. Session-authenticated; the caller must
 * be a member of the company. Fails soft to an empty list.
 *
 * GET ?company_id=<id>&days=<n>   (days default 14, max 60)
 * Returns: { success, milestones: [ {kind, date, employee_name, years} ] }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/PeopleMilestones.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$companyId = (int) ($_GET['company_id'] ?? 0);
$days = (int) ($_GET['days'] ?? 14);
$days = max(1, min(60, $days));
if ($companyId <= 0) {
    Response::ok(['milestones' => []]);
}

$pdo = DB::pdo();
$chk = $pdo->prepare("
    SELECT c.uuid
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id AND c.status = 'active'
    WHERE cm.company_id = :cid AND cm.user_id = :uid AND cm.status = 'active'
    LIMIT 1
");
$chk->execute(['cid' => $companyId, 'uid' => (int) $user['id']]);
$uuid = (string) ($chk->fetchColumn() ?: '');
if ($uuid === '') {
    Response::ok(['milestones' => []]);
}

$rows = PeopleMilestones::forRange(date('Y-m-d'), date('Y-m-d', strtotime('+' . $days . ' days')), $uuid);

// Trim the payload to what the nudge needs.
$out = array_map(static function ($m) {
    return [
        'kind'          => $m['kind'] ?? '',
        'date'          => $m['date'] ?? '',
        'employee_name' => $m['employee_name'] ?? '',
        'years'         => $m['years'] ?? null,
    ];
}, $rows);

Response::ok(['milestones' => $out]);
