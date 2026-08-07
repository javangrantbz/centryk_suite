<?php
/** Shared helpers: escaping, flash messages, settings, schedule resolution.
 *  All data access is scoped to the active company (vb_cid()) and uses the
 *  vb_*-prefixed tables in centryk_core. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/auth.php';

/** HTML-escape. */
function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/** One-shot flash messages stored in the session. */
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function get_setting(string $key, $default = null, ?int $companyId = null)
{
    static $cache = [];
    $cid = $companyId ?? vb_cid();
    if (!isset($cache[$cid])) {
        $cache[$cid] = [];
        $stmt = db()->prepare('SELECT setting_key, setting_value FROM vb_settings WHERE company_id = ?');
        $stmt->execute([$cid]);
        foreach ($stmt as $r) {
            $cache[$cid][$r['setting_key']] = $r['setting_value'];
        }
    }
    return $cache[$cid][$key] ?? $default;
}

function set_setting(string $key, $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO vb_settings (company_id, setting_key, setting_value) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([vb_cid(), $key, (string) $value]);
}

/**
 * Schema is created up front by database/add_signage_app.sql, so the old lazy
 * "ensure_*" creators are no-ops kept only so existing call sites don't fatal.
 */
function ensure_display_ops_tables(): void {}
function ensure_content_enhancements_schema(): void {}
function ensure_admin_qol_schema(): void {}

/**
 * Resolve a TV screen from its pairing token (the device credential the
 * unattended player presents). Returns the screen row (incl. company_id) or null.
 */
function vb_screen_by_token(?string $token): ?array
{
    $token = trim((string) $token);
    if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM vb_screens WHERE pair_token = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/**
 * Resolve a TV screen from its short-link slug (the /vb/<slug> route).
 * Slugs live in one flat namespace across all companies, same as the URL.
 */
function vb_screen_by_slug(?string $slug): ?array
{
    $slug = strtolower(trim((string) $slug));
    if ($slug === '' || !preg_match('/^[a-z0-9-]{1,64}$/', $slug)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM vb_screens WHERE slug = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

function get_active_announcement(?int $companyId = null): ?array
{
    $cid = $companyId ?? vb_cid();
    $stmt = db()->prepare(
        "SELECT id, message, style, starts_at, expires_at
         FROM vb_display_announcements
         WHERE company_id = ?
           AND cleared_at IS NULL
           AND starts_at <= NOW()
           AND expires_at > NOW()
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([$cid]);
    return $stmt->fetch() ?: null;
}

/** Most recent screen heartbeat for a company (dashboard "is the TV alive?"). */
function get_company_display_status(?int $companyId = null): ?array
{
    $cid = $companyId ?? vb_cid();
    $stmt = db()->prepare(
        'SELECT ds.*, s.name AS screen_name
         FROM vb_display_status ds
         JOIN vb_screens s ON s.id = ds.screen_id
         WHERE s.company_id = ?
         ORDER BY ds.last_seen_at DESC
         LIMIT 1'
    );
    $stmt->execute([$cid]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $lastSeen = strtotime($row['last_seen_at']);
    $row['seconds_since_seen'] = $lastSeen ? max(0, time() - $lastSeen) : null;
    $row['is_online'] = $row['seconds_since_seen'] !== null && $row['seconds_since_seen'] <= 45;
    return $row;
}

function log_activity(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    $user = current_user();
    $stmt = db()->prepare(
        'INSERT INTO vb_activity_logs (company_id, user_id, username, action, entity_type, entity_id, details, ip_address)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        vb_cid(),
        $user['id'] ?? null,
        $user['username'] ?? null,
        $action,
        $entityType,
        $entityId,
        $details !== null ? substr($details, 0, 1000) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function thumbnail_url(?string $filename): ?string
{
    return $filename ? app_base() . '/' . UPLOAD_URL . '/' . rawurlencode($filename) : null;
}

function active_marquee_messages(?int $companyId = null): array
{
    $cid = $companyId ?? vb_cid();
    $stmt = db()->prepare('SELECT message FROM vb_marquee_messages WHERE company_id = ? AND is_active = 1 ORDER BY position ASC, id ASC');
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll();
    $messages = array_values(array_filter(array_map(fn($r) => trim((string) $r['message']), $rows)));
    if (!$messages) {
        $legacy = trim((string) get_setting('marquee', ''));
        if ($legacy !== '') {
            $messages[] = $legacy;
        }
    }
    $animal = trim((string) get_setting('animal_of_day', ''));
    if ($animal !== '') {
        array_unshift($messages, 'Animal of the Day: ' . $animal);
    }
    return $messages;
}

function imagecreatefrom_upload(string $path, string $ext)
{
    return match ($ext) {
        'jpg', 'jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
        'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
        'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
}

function write_resized_image(string $source, string $dest, string $ext, int $maxWidth, int $quality = 86): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }
    $info = @getimagesize($source);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return false;
    }
    [$w, $h] = $info;
    $src = imagecreatefrom_upload($source, $ext);
    if (!$src) {
        return false;
    }
    $scale = min(1, $maxWidth / max(1, $w));
    $newW = max(1, (int) round($w * $scale));
    $newH = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($newW, $newH);
    if (in_array($ext, ['png', 'webp'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
    $ok = match ($ext) {
        'jpg', 'jpeg' => imagejpeg($dst, $dest, $quality),
        'png' => imagepng($dst, $dest, 6),
        'webp' => function_exists('imagewebp') ? imagewebp($dst, $dest, $quality) : false,
        default => false,
    };
    imagedestroy($src);
    imagedestroy($dst);
    return (bool) $ok;
}

/** Lucide icon markup for a content type — one place instead of a repeated emoji map per page. */
function content_type_icon(string $type, string $class = 'h-4 w-4'): string
{
    $name = match ($type) {
        'video'     => 'film',
        'biography' => 'file-text',
        default     => 'image',
    };
    return '<i data-lucide="' . e($name) . '" class="' . e($class) . '"></i>';
}

/** Public web URL for a stored media file. */
function media_url(string $filename): string
{
    return app_base() . '/' . UPLOAD_URL . '/' . rawurlencode($filename);
}

/** App base path relative to web root, e.g. "/centryk/visionBoard". */
function app_base(): string
{
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    // Strip trailing /admin, /display or /api so links resolve from app root.
    $script = preg_replace('#/(admin|display|api)$#', '', $script);
    return rtrim($script, '/');
}

/** Site root one level above VisionBoard, e.g. "/centryk" locally or "" in production — used for /vb/<slug> short links. */
function vb_site_root(): string
{
    $root = str_replace('\\', '/', dirname(app_base()));
    return ($root === '/' || $root === '.') ? '' : rtrim($root, '/');
}

function human_size(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($u) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $u[$i];
}

/**
 * Determine which playlist should be showing right now for a company.
 * Returns [playlist row, items array] or [null, []].
 */
function resolve_active_playlist(int $companyId): array
{
    $pdo = db();
    $now = new DateTime('now');
    $today = $now->format('Y-m-d');
    $time  = $now->format('H:i:s');
    $dow   = (int) $now->format('w'); // 0 = Sunday

    $sql = 'SELECT s.*, p.name AS playlist_name
            FROM vb_schedules s
            JOIN vb_playlists p ON p.id = s.playlist_id AND p.is_active = 1
            WHERE s.company_id = :cid
              AND s.is_enabled = 1
              AND (s.start_date IS NULL OR s.start_date <= :d1)
              AND (s.end_date   IS NULL OR s.end_date   >= :d2)
            ORDER BY s.priority DESC, s.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cid' => $companyId, ':d1' => $today, ':d2' => $today]);

    $chosen = null;
    foreach ($stmt as $s) {
        if (!empty($s['days_of_week'])) {
            $days = array_map('intval', explode(',', $s['days_of_week']));
            if (!in_array($dow, $days, true)) {
                continue;
            }
        }
        $st = $s['start_time'];
        $et = $s['end_time'];
        if ($st && $et) {
            $inWindow = ($st <= $et)
                ? ($time >= $st && $time <= $et)          // same-day window
                : ($time >= $st || $time <= $et);          // overnight window
            if (!$inWindow) {
                continue;
            }
        }
        $chosen = $s;
        break;
    }

    // Fallback: first active playlist if nothing scheduled.
    if (!$chosen) {
        $p = $pdo->prepare('SELECT * FROM vb_playlists WHERE company_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1');
        $p->execute([$companyId]);
        $row = $p->fetch();
        if (!$row) {
            return [null, []];
        }
        $playlist = $row;
        $playlistId = $row['id'];
    } else {
        $playlistId = $chosen['playlist_id'];
        $playlist = ['id' => $playlistId, 'name' => $chosen['playlist_name'], 'schedule' => $chosen['name']];
    }

    // Load ordered items.
    $itemsStmt = $pdo->prepare(
        'SELECT ci.*, pi.duration_override, pi.position, m.filename, m.thumbnail_filename, m.kind AS media_kind, m.mime
         FROM vb_playlist_items pi
         JOIN vb_content_items ci ON ci.id = pi.content_item_id AND ci.is_active = 1
         LEFT JOIN vb_media m ON m.id = ci.media_id
         WHERE pi.playlist_id = ?
           AND (ci.starts_on IS NULL OR ci.starts_on <= CURDATE())
           AND (ci.ends_on IS NULL OR ci.ends_on >= CURDATE())
         ORDER BY pi.position ASC, pi.id ASC'
    );
    $itemsStmt->execute([$playlistId]);
    $items = $itemsStmt->fetchAll();

    return [$playlist, $items];
}

/** Format items into the JSON payload the display player consumes. */
function items_to_payload(array $items): array
{
    $out = [];
    foreach ($items as $it) {
        $duration = $it['duration_override'] ?: $it['duration_seconds'] ?: DEFAULT_DURATION;
        $out[] = [
            'type'     => $it['type'],
            'title'    => $it['title'],
            'subtitle' => $it['subtitle'],
            'body'     => $it['body'],
            'duration' => (int) $duration,
            'url'      => $it['filename'] ? media_url($it['filename']) : null,
            'thumb'    => !empty($it['thumbnail_filename']) ? thumbnail_url($it['thumbnail_filename']) : null,
            'mime'     => $it['mime'] ?? null,
        ];
    }
    return $out;
}
