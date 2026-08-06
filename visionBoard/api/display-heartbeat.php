<?php
/**
 * Public heartbeat endpoint for the unattended TV player. It stores only the
 * current playback state so staff can confirm the display is alive.
 */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$screen = vb_screen_by_token($_GET['screen'] ?? '');
if (!$screen) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Unknown or unpaired screen.']);
    exit;
}
$screenId = (int) $screen['id'];

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$clip = static function ($value, int $max): ?string {
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return null;
    }
    return substr($value, 0, $max);
};

// Keep the screen's own last_seen fresh too.
db()->prepare('UPDATE vb_screens SET last_seen_at = NOW() WHERE id = ?')->execute([$screenId]);

$stmt = db()->prepare(
    "INSERT INTO vb_display_status
        (screen_id, last_seen_at, current_title, current_type, current_index, next_title, next_type,
         playlist_name, item_count, player_state, client_time, client_version, last_error, user_agent)
     VALUES
        (:screen_id, NOW(), :current_title, :current_type, :current_index, :next_title, :next_type,
         :playlist_name, :item_count, :player_state, :client_time, :client_version, :last_error, :user_agent)
     ON DUPLICATE KEY UPDATE
        last_seen_at = VALUES(last_seen_at),
        current_title = VALUES(current_title),
        current_type = VALUES(current_type),
        current_index = VALUES(current_index),
        next_title = VALUES(next_title),
        next_type = VALUES(next_type),
        playlist_name = VALUES(playlist_name),
        item_count = VALUES(item_count),
        player_state = VALUES(player_state),
        client_time = VALUES(client_time),
        client_version = VALUES(client_version),
        last_error = VALUES(last_error),
        user_agent = VALUES(user_agent)"
);

$stmt->execute([
    ':screen_id' => $screenId,
    ':current_title' => $clip($data['current_title'] ?? null, 200),
    ':current_type' => $clip($data['current_type'] ?? null, 40),
    ':current_index' => isset($data['current_index']) ? (int) $data['current_index'] : null,
    ':next_title' => $clip($data['next_title'] ?? null, 200),
    ':next_type' => $clip($data['next_type'] ?? null, 40),
    ':playlist_name' => $clip($data['playlist_name'] ?? null, 150),
    ':item_count' => max(0, (int) ($data['item_count'] ?? 0)),
    ':player_state' => $clip($data['player_state'] ?? 'playing', 40) ?: 'playing',
    ':client_time' => $clip($data['client_time'] ?? null, 60),
    ':client_version' => $clip($data['client_version'] ?? null, 40),
    ':last_error' => $clip($data['last_error'] ?? null, 255),
    ':user_agent' => $clip($_SERVER['HTTP_USER_AGENT'] ?? null, 255),
]);

echo json_encode(['ok' => true, 'server_time' => date('c')], JSON_UNESCAPED_SLASHES);
