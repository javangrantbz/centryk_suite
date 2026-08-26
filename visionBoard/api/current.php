<?php
/**
 * JSON feed for the TV display. Returns the playlist that should be showing
 * right now for the requesting screen's company, its items, and display
 * settings. The player authenticates with its screen token (?screen=...).
 */
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$screen = vb_screen_by_token($_GET['screen'] ?? '');
if (!$screen) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Unknown or unpaired screen.']);
    exit;
}
$companyId = (int) $screen['company_id'];
$companyStmt = db()->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
$companyStmt->execute([$companyId]);
$companyName = trim((string) $companyStmt->fetchColumn());
$companyName = $companyName !== '' ? $companyName : 'Your Company';

// Record that the screen checked in.
$seen = db()->prepare('UPDATE vb_screens SET last_seen_at = NOW() WHERE id = ?');
$seen->execute([(int) $screen['id']]);

[$playlist, $items] = resolve_active_playlist($companyId, $screen);

$payload = items_to_payload($items);
$announcement = get_active_announcement($companyId);

// Active visitor QR codes for this company, in display order.
$qrCodes = [];
$qrStmt = db()->prepare('SELECT caption, url, display_seconds FROM vb_qr_codes WHERE company_id = ? AND is_active = 1 ORDER BY position ASC, id ASC');
$qrStmt->execute([$companyId]);
foreach ($qrStmt as $q) {
    $qrCodes[] = ['caption' => $q['caption'], 'url' => $q['url'], 'display_seconds' => $q['display_seconds'] ? (int) $q['display_seconds'] : null];
}
if (get_setting('donation_qr_enabled', '0', $companyId) === '1' && trim((string) get_setting('donation_qr_url', '', $companyId)) !== '') {
    $qrCodes[] = [
        'caption' => get_setting('donation_qr_caption', 'Support ' . $companyName, $companyId),
        'url' => trim((string) get_setting('donation_qr_url', '', $companyId)),
    ];
}
if (get_setting('feedback_qr_enabled', '0', $companyId) === '1' && trim((string) get_setting('feedback_qr_url', '', $companyId)) !== '') {
    $qrCodes[] = [
        'caption' => get_setting('feedback_qr_caption', 'Share feedback', $companyId),
        'url' => trim((string) get_setting('feedback_qr_url', '', $companyId)),
    ];
}

// A signature the player uses to detect changes and reload only when needed.
$signature = md5(json_encode([
    $playlist['id'] ?? 0,
    array_map(fn($i) => [$i['type'],$i['url'],$i['title'],$i['duration']], $payload),
    $announcement ? [$announcement['id'], $announcement['message'], $announcement['style'], $announcement['expires_at']] : null,
]));

echo json_encode([
    'ok'        => true,
    'playlist'  => $playlist['name'] ?? null,
    'signature' => $signature,
    'announcement' => $announcement,
    'settings'  => [
        'marquee'    => get_setting('marquee', '', $companyId),
        'marquees'   => active_marquee_messages($companyId),
        'marquee_scroll_seconds' => (int) get_setting('marquee_scroll_seconds', '22', $companyId),
        'animal_of_day' => get_setting('animal_of_day', '', $companyId),
        'show_clock' => get_setting('show_clock', '1', $companyId) === '1',
        'weather_widget_enabled' => get_setting('weather_widget_enabled', '0', $companyId) === '1',
        'weather_latitude' => (float) get_setting('weather_latitude', '17.3536', $companyId),
        'weather_longitude' => (float) get_setting('weather_longitude', '-88.5497', $companyId),
        'weather_label' => get_setting('weather_label', $companyName, $companyId),
        'theme'      => get_setting('theme', 'jungle', $companyId),
        'transition' => get_setting('transition', 'fade', $companyId),
        'qr_enabled' => get_setting('qr_enabled', '1', $companyId) === '1',
        'qr_rotate_seconds' => (int) get_setting('qr_rotate_seconds', '10', $companyId),
        'qr_codes'   => $qrCodes,
    ],
    'items'     => $payload,
    'server_time' => date('c'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
