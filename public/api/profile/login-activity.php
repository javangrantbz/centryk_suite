<?php
/**
 * Recent sign-in activity for the logged-in user (Profile → Sign-in activity).
 * Reads from login_events (populated by Auth::recordLoginEvent).
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/DB.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}

$pdo  = DB::pdo();
$stmt = $pdo->prepare(
    'SELECT ip_address, user_agent, success, created_at
     FROM login_events
     WHERE user_id = :uid
     ORDER BY created_at DESC
     LIMIT 12'
);
$stmt->execute(['uid' => (int)$user['id']]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** Friendly "Browser · OS" label + a lucide icon name from a user-agent string. */
function la_label(string $ua): array
{
    if ($ua === '') {
        return ['Unknown device', 'monitor'];
    }
    $browser = 'Browser';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $browser = $name; break; }
    }
    $os = 'Unknown OS';
    $icon = 'monitor';
    if (stripos($ua, 'Windows') !== false) {
        $os = 'Windows';
    } elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
        $os = 'iOS'; $icon = 'smartphone';
    } elseif (stripos($ua, 'Android') !== false) {
        $os = 'Android'; $icon = 'smartphone';
    } elseif (stripos($ua, 'Mac OS') !== false) {
        $os = 'macOS';
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }
    return [$browser . ' · ' . $os, $icon];
}

$events = array_map(function ($r) {
    [$label, $icon] = la_label((string)($r['user_agent'] ?? ''));
    return [
        'label'      => $label,
        'icon'       => $icon,
        'ip'         => $r['ip_address'] ?: 'Unknown IP',
        'success'    => (int)$r['success'] === 1,
        'created_at' => $r['created_at'],
    ];
}, $rows);

Response::ok([
    'events'     => $events,
    'last_login' => $user['last_login_at'] ?? null,
]);
