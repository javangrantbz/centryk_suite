<?php
$active = 'index';
$pageTitle = 'V Board';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo = db();
$companyId = vb_cid();

function vb_resequence_playlist(PDO $pdo, int $playlistId): void
{
    $ids = $pdo->prepare('SELECT id FROM vb_playlist_items WHERE playlist_id=? ORDER BY position ASC, id ASC');
    $ids->execute([$playlistId]);
    $upd = $pdo->prepare('UPDATE vb_playlist_items SET position=? WHERE id=?');
    $pos = 0;
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $piId) {
        $upd->execute([$pos++, $piId]);
    }
}

function vb_playlist_owned_by_company(PDO $pdo, int $playlistId, int $companyId): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM vb_playlists WHERE id=? AND company_id=?');
    $s->execute([$playlistId, $companyId]);
    return (bool) $s->fetchColumn();
}

function vb_screen_owned_by_company(PDO $pdo, int $screenId, int $companyId): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM vb_screens WHERE id=? AND company_id=?');
    $s->execute([$screenId, $companyId]);
    return (bool) $s->fetchColumn();
}

function vb_is_ajax_request(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function vb_ensure_playlist_screen(PDO $pdo, int $companyId, int $playlistId, string $playlistName): array
{
    $stmt = $pdo->prepare('SELECT * FROM vb_screens WHERE company_id=? AND playlist_id=? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$companyId, $playlistId]);
    $screen = $stmt->fetch();
    if ($screen) {
        if (trim((string) ($screen['slug'] ?? '')) === '') {
            $slug = vb_generate_numeric_screen_slug($pdo, (int) $screen['id']);
            $pdo->prepare('UPDATE vb_screens SET slug=? WHERE id=? AND company_id=?')->execute([$slug, (int) $screen['id'], $companyId]);
            $screen['slug'] = $slug;
        }
        return $screen;
    }

    $fallbackName = trim($playlistName) !== '' ? trim($playlistName) . ' Screen' : 'V Board Screen';
    $legacyStmt = $pdo->prepare('SELECT * FROM vb_screens WHERE company_id=? AND playlist_id IS NULL AND name=? ORDER BY id ASC LIMIT 1');
    $legacyStmt->execute([$companyId, $fallbackName]);
    $legacy = $legacyStmt->fetch();
    if ($legacy) {
        $slug = trim((string) ($legacy['slug'] ?? '')) !== '' ? (string) $legacy['slug'] : vb_generate_numeric_screen_slug($pdo, (int) $legacy['id']);
        $pdo->prepare('UPDATE vb_screens SET playlist_id=?, slug=? WHERE id=? AND company_id=?')
            ->execute([$playlistId, $slug, (int) $legacy['id'], $companyId]);
        $legacy['playlist_id'] = $playlistId;
        $legacy['slug'] = $slug;
        return $legacy;
    }

    $token = bin2hex(random_bytes(32));
    $slug = vb_generate_numeric_screen_slug($pdo);
    $pdo->prepare(
        'INSERT INTO vb_screens (company_id, playlist_id, name, location, pair_token, slug, is_active)
         VALUES (?,?,?,?,?,?,1)'
    )->execute([$companyId, $playlistId, $fallbackName, 'V Board display', $token, $slug]);
    $id = (int) $pdo->lastInsertId();
    $fetch = $pdo->prepare('SELECT * FROM vb_screens WHERE id=? LIMIT 1');
    $fetch->execute([$id]);
    return $fetch->fetch() ?: [];
}

function vb_slugify(string $value): string
{
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $value));
    $slug = trim((string) $slug, '-');
    return $slug !== '' ? substr($slug, 0, 48) : 'company';
}

function vb_generate_screen_slug(PDO $pdo, string $companyName, int $excludeId = 0): string
{
    $base = vb_slugify($companyName);
    $candidate = $base . '-1';
    $i = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM vb_screens WHERE slug = ? AND id != ?');
    while (true) {
        $stmt->execute([$candidate, $excludeId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
        $i++;
        $candidate = $base . '-' . $i;
    }
}

function vb_generate_numeric_screen_slug(PDO $pdo, int $excludeId = 0): string
{
    $i = 1;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM vb_screens WHERE slug = ? AND id != ?');
    while (true) {
        $candidate = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $stmt->execute([$candidate, $excludeId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
        $i++;
    }
}

function vb_upload_media_batch(PDO $pdo, int $companyId, ?array $files, ?int $userId): array
{
    $created = [];
    if (!$files || !is_array($files['name'] ?? null)) {
        return $created;
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0775, true);
    }
    for ($i = 0; $i < count($files['name']); $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $orig = $files['name'][$i];
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed = $GLOBALS['ALLOWED_TYPES'];
        if (!isset($allowed[$ext])) {
            continue;
        }
        if (($files['size'][$i] ?? 0) > MAX_UPLOAD_BYTES) {
            continue;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($files['tmp_name'][$i]);
        [$expectedMime, $kind] = $allowed[$ext];
        if (strpos((string) $mime, $kind . '/') !== 0) {
            continue;
        }
        $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $thumbName = null;
        $dest = UPLOAD_DIR . '/' . $newName;
        $stored = false;
        if ($kind === 'image' && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $stored = write_resized_image($files['tmp_name'][$i], $dest, $ext, 1920, 86);
            if ($stored) {
                $thumbName = 'thumb_' . $newName;
                if (!write_resized_image($files['tmp_name'][$i], UPLOAD_DIR . '/' . $thumbName, $ext, 520, 78)) {
                    $thumbName = null;
                }
            }
        }
        if (!$stored) {
            $stored = move_uploaded_file($files['tmp_name'][$i], $dest);
        }
        if (!$stored) {
            continue;
        }
        $storedSize = is_file($dest) ? filesize($dest) : ($files['size'][$i] ?? 0);
        $pdo->prepare(
            'INSERT INTO vb_media (company_id, filename, thumbnail_filename, original_name, kind, mime, size_bytes, uploaded_by)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$companyId, $newName, $thumbName, $orig, $kind, $mime, $storedSize, $userId]);
        $created[] = (int) $pdo->lastInsertId();
    }
    return $created;
}

function vb_attach_media_to_playlist(PDO $pdo, int $companyId, int $playlistId, array $mediaIds, array $durationOverrides = []): void
{
    if (!$mediaIds) {
        return;
    }
    $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds))));
    if (!$mediaIds) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
    $params = array_merge([$companyId], $mediaIds);
    $stmt = $pdo->prepare("SELECT * FROM vb_media WHERE company_id = ? AND id IN ({$placeholders}) ORDER BY created_at ASC, id ASC");
    $stmt->execute($params);
    $mediaRows = $stmt->fetchAll();
    if (!$mediaRows) {
        return;
    }
    $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM vb_playlist_items WHERE playlist_id=?');
    $maxStmt->execute([$playlistId]);
    $position = (int) $maxStmt->fetchColumn();

    $insertItem = $pdo->prepare(
        'INSERT INTO vb_content_items (company_id, title, type, media_id, subtitle, body, duration_seconds, starts_on, ends_on, is_active)
         VALUES (?,?,?,?,?,?,?,?,?,1)'
    );
    $insertPlaylistItem = $pdo->prepare(
        'INSERT INTO vb_playlist_items (playlist_id, content_item_id, position, duration_override) VALUES (?,?,?,?)'
    );

    foreach ($mediaRows as $media) {
        $mediaId = (int) $media['id'];
        $title = pathinfo((string) $media['original_name'], PATHINFO_FILENAME);
        $title = trim($title) !== '' ? trim($title) : ucfirst((string) $media['kind']);
        $type = $media['kind'] === 'video' ? 'video' : 'image';
        $override = isset($durationOverrides[$mediaId]) ? max(1, (int) $durationOverrides[$mediaId]) : null;
        $duration = $type === 'video' ? ($override ?: 60) : 10;
        $insertItem->execute([$companyId, $title, $type, $mediaId, null, null, $duration, null, null]);
        $contentId = (int) $pdo->lastInsertId();
        $insertPlaylistItem->execute([$playlistId, $contentId, $position++, $type === 'image' ? 10 : null]);
    }
}

function vb_demo_company_profile(PDO $pdo, int $companyId, string $fallbackName): array
{
    $stmt = $pdo->prepare(
        'SELECT name, business_type, email, phone, phone2, phone3, address
         FROM companies
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$companyId]);
    $row = $stmt->fetch() ?: [];

    $name = trim((string) ($row['name'] ?? ''));
    $businessType = trim((string) ($row['business_type'] ?? ''));
    $email = trim((string) ($row['email'] ?? ''));
    $address = trim((string) ($row['address'] ?? ''));
    $phones = [];
    foreach (['phone', 'phone2', 'phone3'] as $key) {
        $phone = trim((string) ($row[$key] ?? ''));
        if ($phone !== '') {
            $phones[] = $phone;
        }
    }

    return [
        'name' => $name !== '' ? $name : $fallbackName,
        'business_type' => $businessType,
        'email' => $email,
        'address' => $address,
        'phones' => $phones,
    ];
}

function vb_demo_svg_markup(string $headline, string $kicker, array $lines): string
{
    $esc = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };
    $lineMarkup = '';
    $y = 610;
    foreach ($lines as $line) {
        if (trim((string) $line) === '') {
            continue;
        }
        $lineMarkup .= '<text x="140" y="' . $y . '" font-family="Segoe UI, Arial, sans-serif" font-size="50" font-weight="600" fill="#f8fafc">' . $esc((string) $line) . '</text>';
        $y += 92;
    }

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="1080" viewBox="0 0 1920 1080">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#0f172a"/>
      <stop offset="50%" stop-color="#1e3a8a"/>
      <stop offset="100%" stop-color="#312e81"/>
    </linearGradient>
  </defs>
  <rect width="1920" height="1080" fill="url(#bg)"/>
  <circle cx="1690" cy="200" r="260" fill="rgba(255,255,255,.06)"/>
  <circle cx="260" cy="910" r="330" fill="rgba(255,255,255,.05)"/>
  <text x="140" y="210" font-family="Segoe UI, Arial, sans-serif" font-size="46" font-weight="800" fill="#fda4af" letter-spacing="4">{$esc($kicker)}</text>
  <text x="140" y="430" font-family="Segoe UI, Arial, sans-serif" font-size="122" font-weight="800" fill="#ffffff">{$esc($headline)}</text>
  {$lineMarkup}
</svg>
SVG;
}

function vb_demo_media_upsert(PDO $pdo, int $companyId, string $basename, string $displayName, string $svg, ?int $existingMediaId, ?int $userId): int
{
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0775, true);
    }
    $fileName = $basename . '.svg';
    $filePath = UPLOAD_DIR . '/' . $fileName;
    file_put_contents($filePath, $svg);
    $svgSize = is_file($filePath) ? filesize($filePath) : strlen($svg);

    if ($existingMediaId) {
        $pdo->prepare(
            'UPDATE vb_media
             SET filename=?, thumbnail_filename=NULL, original_name=?, kind=?, mime=?, size_bytes=?, uploaded_by=?
             WHERE id=? AND company_id=?'
        )->execute([$fileName, $displayName, 'image', 'image/svg+xml', $svgSize, $userId, $existingMediaId, $companyId]);
        return $existingMediaId;
    }

    $pdo->prepare(
        'INSERT INTO vb_media (company_id, filename, thumbnail_filename, original_name, kind, mime, size_bytes, uploaded_by)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$companyId, $fileName, null, $displayName, 'image', 'image/svg+xml', $svgSize, $userId]);
    return (int) $pdo->lastInsertId();
}

function vb_next_untitled_board_name(PDO $pdo, int $companyId): string
{
    $check = $pdo->prepare('SELECT COUNT(*) FROM vb_playlists WHERE company_id=? AND name=?');
    for ($i = 1; $i <= 999; $i++) {
        $candidate = 'Untitled' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $check->execute([$companyId, $candidate]);
        if (!(int) $check->fetchColumn()) {
            return $candidate;
        }
    }

    return 'Untitled' . time();
}

function vb_seed_demo_playlist(PDO $pdo, int $companyId, string $companyName, ?int $userId): array
{
    $demoName = 'Demo';
    $profile = vb_demo_company_profile($pdo, $companyId, $companyName);
    $safeName = preg_replace('/[^A-Za-z0-9]+/', ' ', $profile['name']);
    $safeName = trim((string) $safeName) !== '' ? trim((string) $safeName) : 'Your Company';

    $existing = $pdo->prepare('SELECT id FROM vb_playlists WHERE company_id=? AND name=? LIMIT 1');
    $existing->execute([$companyId, $demoName]);
    $playlistId = (int) $existing->fetchColumn();

    $businessType = $profile['business_type'] !== '' ? $profile['business_type'] : 'The best company in the world';
    $contactBits = [];
    if (!empty($profile['phones'])) {
        $contactBits[] = 'Phone: ' . $profile['phones'][0];
    }
    if ($profile['email'] !== '') {
        $contactBits[] = 'Email: ' . $profile['email'];
    }
    if (!$contactBits) {
        $contactBits[] = 'Public contact details can appear here.';
    }
    $addressBits = [];
    if ($profile['address'] !== '') {
        $addressBits[] = $profile['address'];
    }
    if (count($profile['phones']) > 1) {
        $addressBits[] = 'More phones: ' . implode(' • ', array_slice($profile['phones'], 1));
    }
    if (!$addressBits) {
        $addressBits[] = 'Public address details can appear here.';
    }

    $demoCards = [
        [
            'title' => $safeName,
            'subtitle' => 'Welcome to ' . $safeName,
            'body' => $businessType,
            'file' => 'demo_' . $companyId . '_card_1',
            'display' => 'Demo - Company Overview.svg',
            'svg' => vb_demo_svg_markup($safeName, 'CENTRYK V BOARD DEMO', ['Welcome to ' . $safeName, $businessType]),
        ],
        [
            'title' => 'Contact',
            'subtitle' => 'How to reach ' . $safeName,
            'body' => implode("\n", $contactBits),
            'file' => 'demo_' . $companyId . '_card_2',
            'display' => 'Demo - Contact.svg',
            'svg' => vb_demo_svg_markup('Contact', $safeName, $contactBits),
        ],
        [
            'title' => 'Visit Us',
            'subtitle' => 'Public Information',
            'body' => implode("\n", $addressBits),
            'file' => 'demo_' . $companyId . '_card_3',
            'display' => 'Demo - Visit Us.svg',
            'svg' => vb_demo_svg_markup('Visit Us', $safeName, $addressBits),
        ],
    ];

    if (!$playlistId) {
        $pdo->prepare('INSERT INTO vb_playlists (company_id, name, description, is_active) VALUES (?,?,?,1)')
            ->execute([$companyId, $demoName, 'Centryk sample playlist']);
        $playlistId = (int) $pdo->lastInsertId();

        $contentStmt = $pdo->prepare(
            'INSERT INTO vb_content_items (company_id, title, type, media_id, subtitle, body, duration_seconds, starts_on, ends_on, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,1)'
        );
        $playlistItemStmt = $pdo->prepare(
            'INSERT INTO vb_playlist_items (playlist_id, content_item_id, position, duration_override) VALUES (?,?,?,?)'
        );

        foreach ($demoCards as $index => $card) {
            $mediaId = vb_demo_media_upsert($pdo, $companyId, $card['file'], $card['display'], $card['svg'], null, $userId);
            $contentStmt->execute([$companyId, $card['title'], 'image', $mediaId, $card['subtitle'], $card['body'], 10, null, null]);
            $contentId = (int) $pdo->lastInsertId();
            $playlistItemStmt->execute([$playlistId, $contentId, $index, 10]);
        }
    } else {
        $demoItemsStmt = $pdo->prepare(
            'SELECT ci.id, ci.type, ci.media_id
             FROM vb_playlist_items pi
             JOIN vb_content_items ci ON ci.id = pi.content_item_id
             WHERE pi.playlist_id = ?
             ORDER BY pi.position ASC, pi.id ASC'
        );
        $demoItemsStmt->execute([$playlistId]);
        $demoItems = $demoItemsStmt->fetchAll();
        $contentStmt = $pdo->prepare(
            'INSERT INTO vb_content_items (company_id, title, type, media_id, subtitle, body, duration_seconds, starts_on, ends_on, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,1)'
        );
        $playlistItemStmt = $pdo->prepare(
            'INSERT INTO vb_playlist_items (playlist_id, content_item_id, position, duration_override) VALUES (?,?,?,?)'
        );
        foreach ($demoCards as $index => $card) {
            $existingItem = $demoItems[$index] ?? null;
            $existingMediaId = $existingItem ? (int) ($existingItem['media_id'] ?? 0) : null;
            $mediaId = vb_demo_media_upsert($pdo, $companyId, $card['file'], $card['display'], $card['svg'], $existingMediaId ?: null, $userId);
            if ($existingItem) {
                $pdo->prepare(
                    'UPDATE vb_content_items
                     SET title=?, type=?, media_id=?, subtitle=?, body=?, duration_seconds=10, is_active=1
                     WHERE id=? AND company_id=?'
                )->execute([$card['title'], 'image', $mediaId, $card['subtitle'], $card['body'], (int) $existingItem['id'], $companyId]);
            } else {
                $contentStmt->execute([$companyId, $card['title'], 'image', $mediaId, $card['subtitle'], $card['body'], 10, null, null]);
                $contentId = (int) $pdo->lastInsertId();
                $playlistItemStmt->execute([$playlistId, $contentId, $index, 10]);
            }
        }
    }

    $screen = vb_ensure_playlist_screen($pdo, $companyId, $playlistId, $demoName);
    $screenId = (int) ($screen['id'] ?? 0);

    return ['playlist_id' => $playlistId, 'screen_id' => $screenId];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $playlistId = (int) ($_POST['playlist_id'] ?? 0);

    if (!in_array($action, ['create_playlist', 'create_board_card', 'create_demo_playlist', 'ensure_screen_link', ''], true) && !vb_playlist_owned_by_company($pdo, $playlistId, $companyId)) {
        $screenActions = ['add_screen', 'rename_screen', 'regenerate_screen', 'set_screen_slug', 'toggle_screen', 'delete_screen'];
        $screenId = (int) ($_POST['screen_id'] ?? 0);
        if (!in_array($action, $screenActions, true) || ($action !== 'add_screen' && !vb_screen_owned_by_company($pdo, $screenId, $companyId))) {
            http_response_code(404);
            die('Playlist not found.');
        }
    }

    switch ($action) {
        case 'create_playlist':
            $name = trim($_POST['name'] ?? '') ?: 'Untitled playlist';
            $description = trim($_POST['description'] ?? '');
            $stmt = $pdo->prepare('INSERT INTO vb_playlists (company_id, name, description) VALUES (?,?,?)');
            $stmt->execute([$companyId, $name, $description]);
            $newId = (int) $pdo->lastInsertId();
            vb_ensure_playlist_screen($pdo, $companyId, $newId, $name);
            log_activity('created', 'playlist', $newId, $name);
            flash('Playlist created.');
            redirect('index.php?vboardid=' . $newId);

        case 'create_board_card':
            $name = trim($_POST['name'] ?? '') ?: 'Untitled V Board';
            $description = trim($_POST['description'] ?? '');
            $stmt = $pdo->prepare('INSERT INTO vb_playlists (company_id, name, description, is_active) VALUES (?,?,?,1)');
            $stmt->execute([$companyId, $name, $description]);
            $newId = (int) $pdo->lastInsertId();
            vb_ensure_playlist_screen($pdo, $companyId, $newId, $name);
            $selectedMediaIds = array_filter(array_map('intval', $_POST['seed_media_ids'] ?? []));
            $uploadedMediaIds = vb_upload_media_batch($pdo, $companyId, $_FILES['files'] ?? null, current_user()['id'] ?? null);
            vb_attach_media_to_playlist($pdo, $companyId, $newId, array_merge($selectedMediaIds, $uploadedMediaIds));
            if (isset($_POST['open_scheduling'])) {
                $company = vb_company();
                $companyName = (string) ($company['name'] ?? 'Your Company');
                $screenStmt = $pdo->prepare('SELECT id FROM vb_screens WHERE company_id=? ORDER BY id ASC LIMIT 1');
                $screenStmt->execute([$companyId]);
                $screenId = (int) $screenStmt->fetchColumn();
                if (!$screenId) {
                    $token = bin2hex(random_bytes(32));
                    $slug = vb_generate_screen_slug($pdo, $companyName);
                    $screenName = trim($companyName) !== '' ? $companyName . ' Display 1' : 'Display 1';
                    $pdo->prepare('INSERT INTO vb_screens (company_id, name, location, pair_token, slug, is_active) VALUES (?,?,?,?,?,1)')
                        ->execute([$companyId, $screenName, 'Main display', $token, $slug]);
                    log_activity('created', 'screen', (int) $pdo->lastInsertId(), $screenName);
                }
            }
            log_activity('created', 'playlist', $newId, $name);
            flash('V Board created.');
            $qs = 'index.php?vboardid=' . $newId;
            if (isset($_POST['open_scheduling'])) {
                $qs .= '&open_schedule=1&schedule_playlist=' . $newId;
            }
            redirect($qs);

        case 'upload_to_board':
            $uploadedMediaIds = vb_upload_media_batch($pdo, $companyId, $_FILES['files'] ?? null, current_user()['id'] ?? null);
            vb_attach_media_to_playlist($pdo, $companyId, $playlistId, $uploadedMediaIds);
            flash($uploadedMediaIds ? 'Media uploaded to vBoard.' : 'No files were uploaded.', $uploadedMediaIds ? 'success' : 'error');
            redirect('index.php?vboardid=' . $playlistId);

        case 'upload_library_media':
            $uploadedMediaIds = vb_upload_media_batch($pdo, $companyId, $_FILES['files'] ?? null, current_user()['id'] ?? null);
            flash($uploadedMediaIds ? 'Media added to your library.' : 'No files were uploaded.', $uploadedMediaIds ? 'success' : 'error');
            redirect('index.php?vboardid=' . $playlistId);

        case 'quick_add_media':
            $mediaId = (int) ($_POST['media_id'] ?? 0);
            $mediaDuration = (int) ($_POST['media_duration'] ?? 0);
            vb_attach_media_to_playlist($pdo, $companyId, $playlistId, [$mediaId], $mediaDuration > 0 ? [$mediaId => $mediaDuration] : []);
            redirect('index.php?vboardid=' . $playlistId);

        case 'quick_remove_media':
            $mediaId = (int) ($_POST['media_id'] ?? 0);
            $pdo->prepare(
                'DELETE pi FROM vb_playlist_items pi
                 JOIN vb_content_items ci ON ci.id = pi.content_item_id
                 WHERE pi.playlist_id = ? AND ci.media_id = ?'
            )->execute([$playlistId, $mediaId]);
            vb_resequence_playlist($pdo, $playlistId);
            redirect('index.php?vboardid=' . $playlistId);

        case 'delete_library_media':
            $mediaId = (int) ($_POST['media_id'] ?? 0);
            $mediaStmt = $pdo->prepare('SELECT filename, thumbnail_filename FROM vb_media WHERE id=? AND company_id=? LIMIT 1');
            $mediaStmt->execute([$mediaId, $companyId]);
            $mediaRow = $mediaStmt->fetch();
            $usageStmt = $pdo->prepare(
                'SELECT DISTINCT p.name
                 FROM vb_playlist_items pi
                 JOIN vb_content_items ci ON ci.id = pi.content_item_id
                 JOIN vb_playlists p ON p.id = pi.playlist_id
                 WHERE ci.company_id = ? AND ci.media_id = ?
                 ORDER BY p.name ASC, p.id ASC'
            );
            $usageStmt->execute([$companyId, $mediaId]);
            $playlistNames = array_values(array_filter(array_map(static fn($row) => trim((string) ($row['name'] ?? '')), $usageStmt->fetchAll())));

            if (vb_is_ajax_request()) {
                header('Content-Type: application/json; charset=utf-8');
                if (!$mediaRow) {
                    http_response_code(404);
                    echo json_encode(['ok' => false, 'message' => 'Media not found.']);
                    exit;
                }
                if ($playlistNames) {
                    echo json_encode([
                        'ok' => false,
                        'message' => 'This media is currently used in: ' . implode(', ', $playlistNames),
                        'playlists' => $playlistNames,
                    ]);
                    exit;
                }
            }

            if ($mediaRow && !$playlistNames) {
                $pdo->prepare('DELETE FROM vb_content_items WHERE company_id=? AND media_id=?')->execute([$companyId, $mediaId]);
                $pdo->prepare('DELETE FROM vb_media WHERE id=? AND company_id=?')->execute([$mediaId, $companyId]);
                foreach ([$mediaRow['filename'] ?? null, $mediaRow['thumbnail_filename'] ?? null] as $fileName) {
                    $fileName = trim((string) $fileName);
                    if ($fileName !== '') {
                        $filePath = UPLOAD_DIR . '/' . $fileName;
                        if (is_file($filePath)) {
                            @unlink($filePath);
                        }
                    }
                }
                if (vb_is_ajax_request()) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['ok' => true, 'message' => 'Media removed from your library.', 'media_id' => $mediaId]);
                    exit;
                }
                flash('Media removed from your library.');
                redirect('index.php?vboardid=' . $playlistId);
            }

            if ($playlistNames) {
                flash('This media is currently used in: ' . implode(', ', $playlistNames), 'error');
            }
            redirect('index.php?vboardid=' . $playlistId);

        case 'create_demo_playlist':
            $company = vb_company();
            $demo = vb_seed_demo_playlist($pdo, $companyId, (string) ($company['name'] ?? 'Your Company'), current_user()['id'] ?? null);
            log_activity('created', 'playlist', $demo['playlist_id'], 'seeded Centryk demo');
            flash('Sample demo created.');
            redirect('index.php?vboardid=' . $demo['playlist_id']);

        case 'ensure_screen_link':
            $company = vb_company();
            $companyName = (string) ($company['name'] ?? 'Your Company');
            $screenStmt = $pdo->prepare('SELECT id FROM vb_screens WHERE company_id=? ORDER BY id ASC LIMIT 1');
            $screenStmt->execute([$companyId]);
            $screenId = (int) $screenStmt->fetchColumn();
            if (!$screenId) {
                $token = bin2hex(random_bytes(32));
                $slug = vb_generate_screen_slug($pdo, $companyName);
                $screenName = trim($companyName) !== '' ? $companyName . ' Display 1' : 'Display 1';
                $pdo->prepare('INSERT INTO vb_screens (company_id, name, location, pair_token, slug, is_active) VALUES (?,?,?,?,?,1)')
                    ->execute([$companyId, $screenName, 'Main display', $token, $slug]);
                $screenId = (int) $pdo->lastInsertId();
                log_activity('created', 'screen', $screenId, $screenName);
                flash('TV link generated.');
            }
            redirect('index.php?vboardid=' . $playlistId);

        case 'update_playlist':
            $pdo->prepare('UPDATE vb_playlists SET name=?, description=?, is_active=? WHERE id=? AND company_id=?')
                ->execute([trim($_POST['name'] ?? ''), trim($_POST['description'] ?? ''), isset($_POST['is_active']) ? 1 : 0, $playlistId, $companyId]);
            log_activity('updated', 'playlist', $playlistId, trim($_POST['name'] ?? ''));
            flash('Playlist saved.');
            redirect('index.php?vboardid=' . $playlistId);

        case 'delete_playlist':
            $deleteConfirm = trim((string) ($_POST['delete_confirm'] ?? ''));
            if ($deleteConfirm !== 'Delete') {
                flash('Type Delete to remove this vBoard.', 'error');
                redirect('index.php?vboardid=' . $playlistId);
            }
            $pdo->prepare('DELETE FROM vb_screens WHERE company_id=? AND playlist_id=?')->execute([$companyId, $playlistId]);
            $pdo->prepare('DELETE FROM vb_playlists WHERE id=? AND company_id=?')->execute([$playlistId, $companyId]);
            log_activity('deleted', 'playlist', $playlistId);
            flash('Playlist deleted.');
            redirect('index.php');

        case 'duplicate_playlist':
            $src = $pdo->prepare('SELECT * FROM vb_playlists WHERE id=? AND company_id=?');
            $src->execute([$playlistId, $companyId]);
            $playlist = $src->fetch();
            if ($playlist) {
                $sourceName = trim((string) ($playlist['name'] ?? ''));
                $sourceDescription = (string) ($playlist['description'] ?? '');
                $isDemoSource = strtolower($sourceName) === 'demo'
                    || $sourceDescription === 'Centryk sample playlist'
                    || preg_match('/\bdemo\b/i', $sourceName);
                $newName = $isDemoSource
                    ? vb_next_untitled_board_name($pdo, $companyId)
                    : ($sourceName !== '' ? $sourceName . ' copy' : vb_next_untitled_board_name($pdo, $companyId));
                $newDescription = $isDemoSource ? '' : $sourceDescription;
                $pdo->prepare('INSERT INTO vb_playlists (company_id, name, description, is_active) VALUES (?,?,?,?)')
                    ->execute([$companyId, $newName, $newDescription, $playlist['is_active']]);
                $newId = (int) $pdo->lastInsertId();
                $copyItems = $pdo->prepare('SELECT * FROM vb_playlist_items WHERE playlist_id=? ORDER BY position ASC, id ASC');
                $copyItems->execute([$playlistId]);
                $ins = $pdo->prepare('INSERT INTO vb_playlist_items (playlist_id, content_item_id, position, duration_override) VALUES (?,?,?,?)');
                foreach ($copyItems as $row) {
                    $ins->execute([$newId, $row['content_item_id'], $row['position'], $row['duration_override']]);
                }
                vb_ensure_playlist_screen($pdo, $companyId, $newId, $newName);
                log_activity('duplicated', 'playlist', $newId, 'from #' . $playlistId);
                flash($isDemoSource ? 'Demo copied into a new vBoard.' : 'Playlist duplicated.');
                redirect($isDemoSource ? ('index.php?new_vboard=' . $newId) : ('index.php?vboardid=' . $newId));
            }
            redirect('index.php');

        case 'add_item':
            $contentId = (int) ($_POST['content_item_id'] ?? 0);
            $own = $pdo->prepare('SELECT COUNT(*) FROM vb_content_items WHERE id=? AND company_id=?');
            $own->execute([$contentId, $companyId]);
            if (!$own->fetchColumn()) {
                flash('That item is not available.', 'error');
                redirect('index.php?vboardid=' . $playlistId);
            }
            $dur = ($_POST['duration_override'] ?? '') !== '' ? max(1, (int) $_POST['duration_override']) : null;
            $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM vb_playlist_items WHERE playlist_id=?');
            $maxStmt->execute([$playlistId]);
            $maxPos = (int) $maxStmt->fetchColumn();
            $pdo->prepare('INSERT INTO vb_playlist_items (playlist_id, content_item_id, position, duration_override) VALUES (?,?,?,?)')
                ->execute([$playlistId, $contentId, $maxPos, $dur]);
            log_activity('added_item', 'playlist', $playlistId, 'content #' . $contentId);
            flash('Item added to playlist.');
            redirect('index.php?vboardid=' . $playlistId);

        case 'remove_item':
            $pdo->prepare('DELETE FROM vb_playlist_items WHERE id=? AND playlist_id=?')->execute([(int) ($_POST['item_id'] ?? 0), $playlistId]);
            vb_resequence_playlist($pdo, $playlistId);
            log_activity('removed_item', 'playlist', $playlistId, 'playlist item #' . (int) ($_POST['item_id'] ?? 0));
            redirect('index.php?vboardid=' . $playlistId);

        case 'set_duration':
            $dur = ($_POST['duration_override'] ?? '') !== '' ? max(1, (int) $_POST['duration_override']) : null;
            $pdo->prepare('UPDATE vb_playlist_items SET duration_override=? WHERE id=? AND playlist_id=?')
                ->execute([$dur, (int) ($_POST['item_id'] ?? 0), $playlistId]);
            flash('Duration updated.');
            redirect('index.php?vboardid=' . $playlistId);

        case 'reorder_items':
            $order = array_filter(array_map('intval', explode(',', $_POST['order'] ?? '')));
            $upd = $pdo->prepare('UPDATE vb_playlist_items SET position=? WHERE id=? AND playlist_id=?');
            foreach ($order as $pos => $itemId) {
                $upd->execute([$pos, $itemId, $playlistId]);
            }
            vb_resequence_playlist($pdo, $playlistId);
            log_activity('reordered', 'playlist', $playlistId);
            flash('Playlist order saved.');
            redirect('index.php?vboardid=' . $playlistId);

        case 'move':
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
            $rows = $pdo->prepare('SELECT id, position FROM vb_playlist_items WHERE playlist_id=? ORDER BY position ASC, id ASC');
            $rows->execute([$playlistId]);
            $list = $rows->fetchAll();
            foreach ($list as $i => $r) {
                if ((int) $r['id'] === $itemId) {
                    $j = $dir === 'up' ? $i - 1 : $i + 1;
                    if ($j >= 0 && $j < count($list)) {
                        $upd = $pdo->prepare('UPDATE vb_playlist_items SET position=? WHERE id=?');
                        $upd->execute([$list[$j]['position'], $list[$i]['id']]);
                        $upd->execute([$list[$i]['position'], $list[$j]['id']]);
                    }
                    break;
                }
            }
            vb_resequence_playlist($pdo, $playlistId);
            log_activity('moved_item', 'playlist', $playlistId, 'playlist item #' . $itemId . ' ' . $dir);
            redirect('index.php?vboardid=' . $playlistId);

        case 'add_screen':
            $name = trim($_POST['name'] ?? '') ?: 'New screen';
            $location = trim($_POST['location'] ?? '') ?: null;
            $token = bin2hex(random_bytes(32));
            $slug = vb_generate_screen_slug($pdo, (string) (vb_company()['name'] ?? 'company'));
            $pdo->prepare('INSERT INTO vb_screens (company_id, name, location, pair_token, slug) VALUES (?,?,?,?,?)')
                ->execute([$companyId, $name, $location, $token, $slug]);
            log_activity('created', 'screen', (int) $pdo->lastInsertId(), $name);
            flash('Display added.');
            redirect('index.php' . ($playlistId ? '?vboardid=' . $playlistId : ''));

        case 'rename_screen':
            $screenId = (int) ($_POST['screen_id'] ?? 0);
            $name = trim($_POST['name'] ?? '') ?: 'Screen';
            $location = trim($_POST['location'] ?? '') ?: null;
            $pdo->prepare('UPDATE vb_screens SET name=?, location=? WHERE id=? AND company_id=?')
                ->execute([$name, $location, $screenId, $companyId]);
            log_activity('updated', 'screen', $screenId, $name);
            flash('Display updated.');
            redirect('index.php' . ($playlistId ? '?vboardid=' . $playlistId : ''));

        case 'regenerate_screen':
            $screenId = (int) ($_POST['screen_id'] ?? 0);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE vb_screens SET pair_token=? WHERE id=? AND company_id=?')
                ->execute([$token, $screenId, $companyId]);
            log_activity('regenerated_token', 'screen', $screenId);
            flash('A new display link was generated.');
            redirect('index.php' . ($playlistId ? '?vboardid=' . $playlistId : ''));

        case 'set_screen_slug':
            $screenId = (int) ($_POST['screen_id'] ?? 0);
            $slug = strtolower(trim($_POST['slug'] ?? ''));
            if ($slug === '') {
                $pdo->prepare('UPDATE vb_screens SET slug = NULL WHERE id=? AND company_id=?')
                    ->execute([$screenId, $companyId]);
                flash('Short link removed.');
            } elseif (!preg_match('/^[a-z0-9-]{1,64}$/', $slug)) {
                flash('Short link can only contain lowercase letters, numbers, and hyphens.', 'error');
            } else {
                $dupe = $pdo->prepare('SELECT COUNT(*) FROM vb_screens WHERE slug = ? AND id != ?');
                $dupe->execute([$slug, $screenId]);
                if ($dupe->fetchColumn() > 0) {
                    flash('That short link is already taken.', 'error');
                } else {
                    $pdo->prepare('UPDATE vb_screens SET slug=? WHERE id=? AND company_id=?')
                        ->execute([$slug, $screenId, $companyId]);
                    log_activity('updated', 'screen', $screenId, 'short link set to /vb/' . $slug);
                    flash('Short link saved.');
                }
            }
            redirect('index.php' . ($playlistId ? '?vboardid=' . $playlistId : ''));

        case 'toggle_screen':
            $screenId = (int) ($_POST['screen_id'] ?? 0);
            $pdo->prepare('UPDATE vb_screens SET is_active = 1 - is_active WHERE id=? AND company_id=?')
                ->execute([$screenId, $companyId]);
            log_activity('toggled', 'screen', $screenId);
            redirect('index.php' . ($playlistId ? '?vboardid=' . $playlistId : ''));

        case 'delete_screen':
            $screenId = (int) ($_POST['screen_id'] ?? 0);
            $pdo->prepare('DELETE FROM vb_screens WHERE id=? AND company_id=?')->execute([$screenId, $companyId]);
            log_activity('deleted', 'screen', $screenId);
            flash('Display removed.');
            redirect('index.php' . ($playlistId ? '?vboardid=' . $playlistId : ''));
    }
}

$company = vb_company();
$companyName = (string) ($company['name'] ?? 'Your Company');
$demoName = 'Demo';
vb_seed_demo_playlist($pdo, $companyId, $companyName, current_user()['id'] ?? null);

$playlistsStmt = $pdo->prepare(
    'SELECT p.*, (SELECT COUNT(*) FROM vb_playlist_items pi WHERE pi.playlist_id=p.id) AS item_count
     FROM vb_playlists p
     WHERE p.company_id=?
     ORDER BY p.created_at DESC, p.id DESC'
);
$playlistsStmt->execute([$companyId]);
$playlists = $playlistsStmt->fetchAll();
usort($playlists, static function (array $a, array $b) use ($demoName): int {
    $aDemo = ((string) ($a['name'] ?? '')) === $demoName;
    $bDemo = ((string) ($b['name'] ?? '')) === $demoName;
    if ($aDemo !== $bDemo) {
        return $aDemo ? -1 : 1;
    }
    return ((int) $b['id']) <=> ((int) $a['id']);
});

foreach ($playlists as &$playlistRow) {
    if (
        ($playlistRow['description'] ?? '') === 'Centryk sample playlist'
        && preg_match('/\bdemo\b/i', (string) ($playlistRow['name'] ?? ''))
        && (string) ($playlistRow['name'] ?? '') !== 'Demo'
    ) {
        $pdo->prepare('UPDATE vb_playlists SET name=? WHERE id=? AND company_id=?')->execute(['Demo', (int) $playlistRow['id'], $companyId]);
        $playlistRow['name'] = 'Demo';
    }
}
unset($playlistRow);

$selectedPlaylistId = isset($_GET['vboardid']) ? (int) $_GET['vboardid'] : (isset($_GET['edit']) ? (int) $_GET['edit'] : 0);
$newBoardId = isset($_GET['new_vboard']) ? (int) $_GET['new_vboard'] : 0;

$editing = null;
foreach ($playlists as $playlist) {
    if ((int) $playlist['id'] === $selectedPlaylistId) {
        $editing = $playlist;
        break;
    }
}

$playlistItems = [];
if ($editing) {
    $itemsStmt = $pdo->prepare(
        'SELECT pi.*, ci.title, ci.type, ci.duration_seconds, ci.is_active, m.filename, m.thumbnail_filename, m.kind AS media_kind
         FROM vb_playlist_items pi
         JOIN vb_content_items ci ON ci.id=pi.content_item_id
         LEFT JOIN vb_media m ON m.id=ci.media_id
         WHERE pi.playlist_id=?
         ORDER BY pi.position ASC, pi.id ASC'
    );
    $itemsStmt->execute([$editing['id']]);
    $playlistItems = $itemsStmt->fetchAll();
}
$playlistItemCount = count($playlistItems);
$playlistDurationSeconds = 0;
foreach ($playlistItems as $pi) {
    $playlistDurationSeconds += (int) ($pi['duration_override'] ?: $pi['duration_seconds'] ?: 0);
}
$playlistSchedules = [];
if ($editing) {
    $scheduleStmt = $pdo->prepare(
        'SELECT id, name, start_date, end_date, start_time, end_time, days_of_week, priority, is_enabled
         FROM vb_schedules
         WHERE company_id = ? AND playlist_id = ?
         ORDER BY priority DESC, id DESC'
    );
    $scheduleStmt->execute([$companyId, (int) $editing['id']]);
    $playlistSchedules = $scheduleStmt->fetchAll();
}
$isDemoBoard = $editing && strtolower(trim((string) ($editing['name'] ?? ''))) === 'demo';
$boardMediaMap = [];
foreach ($playlistItems as $pi) {
    $mid = (int) ($pi['media_id'] ?? 0);
    if ($mid > 0) {
        $boardMediaMap[$mid] = true;
    }
}

$mediaLibraryStmt = $pdo->prepare('SELECT id, original_name, kind, filename, thumbnail_filename, created_at FROM vb_media WHERE company_id=? ORDER BY created_at DESC, id DESC');
$mediaLibraryStmt->execute([$companyId]);
$mediaLibrary = $mediaLibraryStmt->fetchAll();

$mediaCountStmt = $pdo->prepare('SELECT COUNT(*) FROM vb_media WHERE company_id=?');
$mediaCountStmt->execute([$companyId]);
$mediaCount = (int) $mediaCountStmt->fetchColumn();

$screenCountStmt = $pdo->prepare('SELECT COUNT(*) FROM vb_screens WHERE company_id=?');
$screenCountStmt->execute([$companyId]);
$screenCount = (int) $screenCountStmt->fetchColumn();

$screensStmt = $pdo->prepare(
    'SELECT s.*, ds.player_state, ds.playlist_name
     FROM vb_screens s
     LEFT JOIN vb_display_status ds ON ds.screen_id = s.id
     WHERE s.company_id = ?
     ORDER BY s.created_at ASC'
);
$screensStmt->execute([$companyId]);
$screens = $screensStmt->fetchAll();
$playlistScreens = [];
foreach ($playlists as $playlistRow) {
    $playlistScreens[(int) $playlistRow['id']] = vb_ensure_playlist_screen($pdo, $companyId, (int) $playlistRow['id'], (string) ($playlistRow['name'] ?? 'V Board'));
}

$previewStmt = $pdo->prepare(
    'SELECT pi.playlist_id, m.filename, m.thumbnail_filename, m.kind
     FROM vb_playlist_items pi
     JOIN vb_content_items ci ON ci.id = pi.content_item_id
     JOIN vb_media m ON m.id = ci.media_id
     JOIN vb_playlists p ON p.id = pi.playlist_id
     WHERE p.company_id = ?
     ORDER BY pi.position ASC, pi.id ASC'
);
$previewStmt->execute([$companyId]);
$playlistPreviews = [];
foreach ($previewStmt->fetchAll() as $previewRow) {
    $pid = (int) $previewRow['playlist_id'];
    if (!isset($playlistPreviews[$pid])) {
        $playlistPreviews[$pid] = $previewRow;
    }
}

$scheme = (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http');
$displayBase = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . app_base() . '/display/';
$shortBase = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . vb_site_root() . '/vb/';
$schedulePanelPlaylistId = isset($_GET['schedule_playlist']) ? (int) $_GET['schedule_playlist'] : ($editing ? (int) $editing['id'] : 0);
$openSchedulePanel = isset($_GET['open_schedule']) && $_GET['open_schedule'] === '1' && $schedulePanelPlaylistId > 0;

require __DIR__ . '/../includes/header.php';
?>
<div class="space-y-5">
  <?php if (!$editing): ?>
  <section class="grid gap-4 xl:grid-cols-3">
    <?php foreach ($playlists as $playlist):
      $playlistId = (int) $playlist['id'];
      $playlistScreen = $playlistScreens[$playlistId] ?? null;
      $isSelected = $editing && (int) $editing['id'] === $playlistId;
      $isNewBoardCard = !$editing && $newBoardId > 0 && $playlistId === $newBoardId;
      $isDemoCard = strtolower(trim((string) ($playlist['name'] ?? ''))) === 'demo';
      $preview = $playlistPreviews[$playlistId] ?? null;
      $previewUrl = null;
      if ($preview) {
          $previewUrl = thumbnail_url($preview['thumbnail_filename']) ?: media_url($preview['filename']);
      }
      $cardClasses = $isSelected ? 'ring-2 ring-rose-400 shadow-lg shadow-rose-100' : 'shadow-sm hover:-translate-y-0.5 hover:shadow-md';
    ?>
      <?php if ($isDemoCard): ?>
      <article class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-950 text-white transition-all <?= $cardClasses ?><?= $isNewBoardCard ? ' js-new-board-card opacity-0 translate-y-2' : '' ?>">
        <div class="relative min-h-[230px]">
          <form method="post" class="absolute right-4 top-4 z-10">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="duplicate_playlist">
            <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
            <button type="submit" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-white/10 bg-black/30 text-slate-100 hover:bg-black/45" title="Use this demo">
              <i data-lucide="copy" class="h-4 w-4"></i>
            </button>
          </form>
          <?php if ($previewUrl): ?>
            <div class="absolute inset-0">
              <?php if (($preview['kind'] ?? '') === 'video'): ?>
                <div class="h-full w-full bg-gradient-to-br from-slate-900 via-slate-800 to-slate-700"></div>
              <?php else: ?>
                <img src="<?= e($previewUrl) ?>" alt="" class="h-full w-full scale-105 object-cover object-center opacity-10 saturate-35 brightness-[0.55]">
              <?php endif; ?>
            </div>
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/92 to-slate-900/82"></div>
          <?php else: ?>
            <div class="absolute inset-0 bg-gradient-to-br from-slate-900 via-slate-800 to-slate-700"></div>
          <?php endif; ?>

          <a href="index.php?vboardid=<?= $playlistId ?>" class="relative flex h-full min-h-[230px] flex-col justify-between px-5 py-5 pr-16">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-xs font-black uppercase tracking-[0.22em] text-rose-300">Demo</p>
                <h2 class="mt-2 text-xl font-black tracking-tight text-white">Demo</h2>
              </div>
              <span class="rounded-full bg-white/10 px-3 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-slate-200">Read Only</span>
            </div>

            <div class="space-y-3">
              <p class="max-w-md text-sm text-slate-200">Shared sample board using your company public information.</p>
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/10 px-2.5 py-1 text-[10px] font-semibold text-slate-200">
                  <span class="uppercase tracking-[0.14em] text-slate-400">Items</span>
                  <span class="font-black text-white"><?= (int) $playlist['item_count'] ?></span>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-slate-900">
                  <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i> Open Board
                </span>
              </div>
            </div>
          </a>
        </div>
      </article>
      <?php else: ?>
      <article class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-950 text-white transition-all <?= $cardClasses ?><?= $isNewBoardCard ? ' js-new-board-card opacity-0 translate-y-2' : '' ?>">
        <div class="relative min-h-[230px]">
          <?php if ($previewUrl): ?>
            <div class="absolute inset-0">
              <?php if (($preview['kind'] ?? '') === 'video'): ?>
                <div class="h-full w-full bg-gradient-to-br from-slate-900 via-blue-950 to-violet-950"></div>
              <?php else: ?>
                <img src="<?= e($previewUrl) ?>" alt="" class="h-full w-full scale-110 object-cover object-center opacity-18 saturate-60 brightness-[0.6]">
              <?php endif; ?>
            </div>
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-950/92 to-slate-900/72"></div>
          <?php else: ?>
            <div class="absolute inset-0 bg-gradient-to-br from-slate-900 via-blue-700 to-violet-700"></div>
          <?php endif; ?>

          <a href="index.php?vboardid=<?= $playlistId ?>" class="relative flex h-full min-h-[230px] flex-col justify-between px-5 py-5">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-xs font-black uppercase tracking-[0.22em] text-rose-300">V Board</p>
                <h2 class="mt-2 text-xl font-black tracking-tight"><?= e($playlist['name']) ?></h2>
              </div>
              <?php if ((int) $playlist['is_active'] === 1): ?>
                <span class="rounded-full bg-emerald-400/15 px-3 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-emerald-200">Active</span>
              <?php endif; ?>
            </div>

            <div class="space-y-3">
              <p class="max-w-md text-sm text-slate-200">
                <?= !empty($playlist['description']) ? e($playlist['description']) : 'Build this board with media, item timing, and a display link for your screens.' ?>
              </p>
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/10 px-2.5 py-1 text-[10px] font-semibold text-slate-200">
                  <span class="uppercase tracking-[0.14em] text-slate-400">Items</span>
                  <span class="font-black text-white"><?= (int) $playlist['item_count'] ?></span>
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-slate-900">
                  <i data-lucide="square-pen" class="h-3.5 w-3.5"></i> Edit
                </span>
              </div>
              <?php if ($playlistScreen && !empty($playlistScreen['slug'])): ?>
                <div class="rounded-2xl border border-white/10 bg-black/20 px-4 py-3">
                  <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Short URL</p>
                  <div class="mt-1 flex items-center gap-2">
                    <p class="min-w-0 flex-1 truncate text-[11px] text-rose-200"><?= e($shortBase . rawurlencode($playlistScreen['slug'])) ?></p>
                    <button
                      type="button"
                      class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-white/10 bg-white/10 text-slate-200 hover:bg-white/15"
                      data-copy-url="<?= e($shortBase . rawurlencode($playlistScreen['slug'])) ?>"
                      title="Copy short URL"
                    >
                      <i data-lucide="copy" class="h-3.5 w-3.5"></i>
                    </button>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </a>
        </div>
      </article>
      <?php endif; ?>
    <?php endforeach; ?>

    <article class="overflow-hidden rounded-3xl border border-dashed border-rose-300 bg-white shadow-sm">
      <div class="min-h-[230px]">
        <div class="bg-gradient-to-br from-rose-50 via-white to-purple-50 px-4 py-3">
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="text-xs font-black uppercase tracking-[0.22em] text-rose-400">Create Your First V Board</p>
            </div>
            <button type="button" data-toggle-create class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-rose-600 text-white hover:bg-rose-700 transition-colors">
              <i data-lucide="plus" class="h-4 w-4"></i>
            </button>
          </div>
        </div>

        <div id="createPlaylistPanel" class="hidden px-4 py-3">
          <form method="post" class="flex h-[170px] flex-col justify-between">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_board_card">
            <div class="space-y-2">
              <div>
                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">V Board name</label>
                <input name="name" required placeholder="Weekend specials" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
              </div>
              <div>
                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">Description</label>
                <input name="description" placeholder="Optional" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 pt-1">
              <button class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-rose-700 transition-colors">
                <i data-lucide="sparkles" class="h-4 w-4"></i> Get Started
              </button>
              <button type="button" data-toggle-create class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3.5 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-colors">
                Cancel
              </button>
            </div>
          </form>
        </div>
      </div>
    </article>

  </section>
  <?php endif; ?>

  <?php if ($editing): ?>
    <?php $editingScreen = $playlistScreens[(int) $editing['id']] ?? null; ?>
    <section class="space-y-4">
      <?php
        $editingName = trim((string) ($editing['name'] ?? '')) ?: 'Untitled';
        $editingShortUrl = ($editingScreen && !empty($editingScreen['slug'])) ? ($shortBase . rawurlencode($editingScreen['slug'])) : '';
      ?>
      <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 bg-gradient-to-r from-slate-950 via-slate-900 to-slate-800 px-4 py-3 text-white">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-white/10 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-slate-200">V Board</span>
                <span class="rounded-full bg-white/10 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-slate-300">ID <?= (int) $editing['id'] ?></span>
                <?php if ($isDemoBoard): ?>
                  <span class="rounded-full bg-white/10 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-slate-300">Read only</span>
                <?php else: ?>
                  <span class="rounded-full bg-emerald-400/15 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-emerald-200">Editable</span>
                <?php endif; ?>
              </div>
              <h1 class="mt-2 text-2xl font-black tracking-tight text-white"><?= e($editingName) ?></h1>
              <?php if ($editingShortUrl !== ''): ?>
                <div class="mt-2 flex min-w-0 items-center gap-1.5 text-[11px]">
                  <a href="<?= e($editingShortUrl) ?>" target="_blank" class="min-w-0 truncate text-slate-200 hover:text-white hover:underline"><?= e($editingShortUrl) ?></a>
                  <button
                    type="button"
                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/10 text-slate-100 hover:bg-white/15"
                    data-copy-url="<?= e($editingShortUrl) ?>"
                    title="Copy short URL"
                  >
                    <i data-lucide="copy" class="h-3.5 w-3.5"></i>
                  </button>
                  <a href="<?= e($editingShortUrl) ?>" target="_blank" class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/10 text-slate-100 hover:bg-white/15" title="Open URL">
                    <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
                  </a>
                </div>
              <?php endif; ?>
              <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-slate-200">
                <?php if ($playlistItemCount > 0): ?>
                  <span class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/10 px-2 py-1">
                    <span class="font-black text-white"><?= $playlistItemCount ?></span>
                    <span>media<?= $playlistItemCount === 1 ? '' : 's' ?></span>
                  </span>
                <?php endif; ?>
                <?php if ($playlistDurationSeconds > 0): ?>
                  <span class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/10 px-2 py-1">
                    <span class="font-black text-white"><?= $playlistDurationSeconds ?></span>
                    <span>seconds</span>
                  </span>
                <?php endif; ?>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <a href="index.php" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/10 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-white/15 transition-colors">
                <i data-lucide="chevron-left" class="h-3.5 w-3.5"></i> View all V Boards
              </a>
              <?php if (!$isDemoBoard): ?>
                <button type="button" data-open-delete class="inline-flex items-center gap-1.5 rounded-xl border border-rose-300/30 bg-rose-500/10 px-2.5 py-1.5 text-xs font-semibold text-rose-100 hover:bg-rose-500/20 transition-colors">
                  <i data-lucide="trash-2" class="h-3.5 w-3.5"></i> Delete
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="px-4 py-2.5">
          <div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
            <div class="min-w-0 flex-1">
              <p class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-400">Scheduling</p>
              <?php if ($playlistSchedules): ?>
                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-700">
                  <?php foreach ($playlistSchedules as $schedule): ?>
                    <?php
                      $scheduleBits = [];
                      if (!empty($schedule['name'])) {
                          $scheduleBits[] = trim((string) $schedule['name']);
                      }
                      if (!empty($schedule['start_time']) || !empty($schedule['end_time'])) {
                          $scheduleBits[] = trim((string) ($schedule['start_time'] ?? '')) . ' - ' . trim((string) ($schedule['end_time'] ?? ''));
                      }
                      if (!empty($schedule['start_date']) || !empty($schedule['end_date'])) {
                          $scheduleBits[] = trim((string) ($schedule['start_date'] ?? '')) . ' to ' . trim((string) ($schedule['end_date'] ?? ''));
                      }
                    ?>
                    <span class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] font-medium text-slate-700"><?= e(implode(' | ', array_filter($scheduleBits))) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="mt-1 text-sm text-slate-400">No schedule has been set for this vBoard.</p>
              <?php endif; ?>
            </div>
            <a href="schedule.php?playlist_id=<?= (int) $editing['id'] ?>" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
              <i data-lucide="calendar-pen" class="h-3.5 w-3.5"></i>
              <?= $playlistSchedules ? 'Open Schedule' : 'Set Schedule' ?>
            </a>
          </div>
        </div>
      </div>

      <?php if ($isDemoBoard): ?>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <?php foreach ($playlistItems as $pi): $eff = $pi['duration_override'] ?: $pi['duration_seconds']; ?>
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
              <div class="aspect-video bg-slate-100">
                <?php if ($pi['filename'] && $pi['media_kind'] === 'image'): ?>
                  <img src="<?= thumbnail_url($pi['thumbnail_filename']) ?: media_url($pi['filename']) ?>" alt="" class="h-full w-full object-cover">
                <?php elseif ($pi['filename'] && $pi['media_kind'] === 'video'): ?>
                  <video src="<?= media_url($pi['filename']) ?>" class="h-full w-full object-cover" muted></video>
                <?php else: ?>
                  <div class="flex h-full items-center justify-center text-slate-400"><?= content_type_icon($pi['type'], 'h-6 w-6') ?></div>
                <?php endif; ?>
              </div>
              <div class="p-3">
                <p class="truncate text-base font-bold text-slate-800"><?= e($pi['title']) ?></p>
                <p class="mt-1 text-sm font-medium text-slate-500"><?= (int) $eff ?> sec</p>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="grid gap-4 xl:grid-cols-2">
          <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2.5">
              <div>
                <h2 class="text-sm font-bold text-slate-900">Media Library</h2>
                <p class="text-xs text-slate-500">All uploaded media for this company.</p>
              </div>
              <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-600"><?= count($mediaLibrary) ?></span>
            </div>
            <div class="space-y-2.5 p-3">
              <form method="post" enctype="multipart/form-data" class="space-y-2.5">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_library_media">
                <input type="hidden" name="playlist_id" value="<?= (int) $editing['id'] ?>">
                <label class="flex min-h-[72px] cursor-pointer items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-center hover:border-rose-300 hover:bg-rose-50/40">
                  <span class="space-y-1">
                    <span class="block text-sm font-semibold text-slate-700">Upload or drop media here</span>
                    <span class="block text-xs text-slate-500">Images and videos</span>
                  </span>
                  <input type="file" name="files[]" multiple accept="image/*,video/*" class="sr-only" data-library-file-input>
                </label>
                <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2">
                  <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-800" data-library-file-name>No files selected</p>
                    <p class="text-xs text-slate-500" data-library-file-count>Choose media to add to the library.</p>
                  </div>
                  <button class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-3.5 py-2 text-sm font-bold text-white hover:bg-rose-700 transition-colors">
                    <i data-lucide="upload" class="h-4 w-4"></i> Upload
                  </button>
                </div>
              </form>

              <div class="grid gap-2 sm:grid-cols-3 xl:grid-cols-4">
                <?php if (!$mediaLibrary): ?>
                  <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500 sm:col-span-3 xl:col-span-4">No media uploaded yet.</div>
                <?php endif; ?>
                <?php foreach ($mediaLibrary as $media):
                  $mid = (int) $media['id'];
                  $thumb = thumbnail_url($media['thumbnail_filename']) ?: media_url($media['filename']);
                  $name = pathinfo((string) $media['original_name'], PATHINFO_FILENAME);
                  $durationLabel = $media['kind'] === 'video' ? 'Uses video runtime' : '10 sec';
                ?>
                  <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition-opacity duration-300" data-library-card="<?= $mid ?>">
                    <button type="button" class="block aspect-square w-full overflow-hidden bg-slate-100" data-open-media-preview="<?= $mid ?>">
                      <?php if ($media['kind'] === 'image'): ?>
                        <img src="<?= e($thumb) ?>" alt="" class="h-full w-full object-cover">
                      <?php else: ?>
                        <video src="<?= media_url($media['filename']) ?>" class="h-full w-full object-cover" muted preload="metadata" data-video-duration="<?= $mid ?>"></video>
                      <?php endif; ?>
                    </button>
                    <div class="space-y-1.5 p-2.5">
                      <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                          <p class="truncate text-xs font-semibold text-slate-800"><?= e($name) ?></p>
                          <p class="text-xs text-slate-500"><?= e($durationLabel) ?></p>
                        </div>
                        <div class="flex items-center gap-1">
                          <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="quick_add_media">
                            <input type="hidden" name="playlist_id" value="<?= (int) $editing['id'] ?>">
                            <input type="hidden" name="media_id" value="<?= $mid ?>">
                            <input type="hidden" name="media_duration" value="<?= $media['kind'] === 'image' ? '10' : '0' ?>" data-media-duration-input="<?= $mid ?>">
                            <button class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-rose-600 text-white hover:bg-rose-700" title="Add to vBoard">
                              <i data-lucide="plus" class="h-4 w-4"></i>
                            </button>
                          </form>
                          <form method="post" data-library-delete-form>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_library_media">
                            <input type="hidden" name="playlist_id" value="<?= (int) $editing['id'] ?>">
                            <input type="hidden" name="media_id" value="<?= $mid ?>">
                            <button class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-rose-600" title="Remove from library">
                              <i data-lucide="trash-2" class="h-4 w-4"></i>
                            </button>
                          </form>
                        </div>
                      </div>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
              <div>
                <h2 class="text-sm font-bold text-slate-900">Playlist</h2>
                <p class="text-xs text-slate-500">Use the arrows to arrange the order that will play.</p>
              </div>
            </div>
            <div class="p-4">
              <div class="space-y-3">
                <?php if (!$playlistItems): ?>
                  <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">Add media from the left to start building this vBoard.</div>
                <?php endif; ?>
                <?php foreach ($playlistItems as $index => $pi):
                  $eff = $pi['duration_override'] ?: $pi['duration_seconds'];
                  $thumb = $pi['filename'] ? (thumbnail_url($pi['thumbnail_filename']) ?: media_url($pi['filename'])) : null;
                ?>
                  <article class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500"><?= $index + 1 ?></div>
                    <div class="h-16 w-24 shrink-0 overflow-hidden rounded-xl bg-slate-100">
                      <?php if ($thumb && $pi['media_kind'] === 'image'): ?>
                        <img src="<?= e($thumb) ?>" alt="" class="h-full w-full object-cover">
                      <?php elseif ($thumb && $pi['media_kind'] === 'video'): ?>
                        <video src="<?= media_url($pi['filename']) ?>" class="h-full w-full object-cover" muted></video>
                      <?php else: ?>
                        <div class="flex h-full items-center justify-center text-slate-400"><?= content_type_icon($pi['type'], 'h-5 w-5') ?></div>
                      <?php endif; ?>
                    </div>
                    <div class="min-w-0 flex-1">
                      <p class="truncate text-sm font-semibold text-slate-900"><?= e($pi['title']) ?></p>
                      <p class="mt-1 text-xs text-slate-500"><?= (int) $eff ?> sec</p>
                    </div>
                    <form method="post" class="flex shrink-0 items-center gap-1.5">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="set_duration">
                      <input type="hidden" name="playlist_id" value="<?= (int) $editing['id'] ?>">
                      <input type="hidden" name="item_id" value="<?= (int) $pi['id'] ?>">
                      <input
                        type="number"
                        name="duration_override"
                        min="1"
                        value="<?= (int) $eff ?>"
                        class="w-16 rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-semibold text-slate-700"
                        title="Duration in seconds"
                      >
                      <button class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" title="Save duration">
                        <i data-lucide="clock-3" class="h-3.5 w-3.5"></i>
                        Save
                      </button>
                    </form>
                    <div class="flex shrink-0 items-center gap-1">
                      <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="move">
                        <input type="hidden" name="playlist_id" value="<?= (int) $editing['id'] ?>">
                        <input type="hidden" name="item_id" value="<?= (int) $pi['id'] ?>">
                        <input type="hidden" name="dir" value="up">
                        <button class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-40" title="Move up" <?= $index === 0 ? 'disabled' : '' ?>>
                          <i data-lucide="chevron-up" class="h-4 w-4"></i>
                        </button>
                      </form>
                      <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="move">
                        <input type="hidden" name="playlist_id" value="<?= (int) $editing['id'] ?>">
                        <input type="hidden" name="item_id" value="<?= (int) $pi['id'] ?>">
                        <input type="hidden" name="dir" value="down">
                        <button class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-40" title="Move down" <?= $index === count($playlistItems) - 1 ? 'disabled' : '' ?>>
                          <i data-lucide="chevron-down" class="h-4 w-4"></i>
                        </button>
                      </form>
                    </div>
                    <form method="post" class="shrink-0">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="remove_item">
                      <input type="hidden" name="playlist_id" value="<?= (int) $editing['id'] ?>">
                      <input type="hidden" name="item_id" value="<?= (int) $pi['id'] ?>">
                      <button class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-rose-600" title="Remove from playlist">
                        <i data-lucide="x" class="h-4 w-4"></i>
                      </button>
                    </form>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
          </section>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>

<div id="mediaPreviewModal" class="fixed inset-0 z-[72] hidden">
  <div class="absolute inset-0 bg-slate-950/70" data-close-media-preview></div>
  <div class="relative flex min-h-full items-center justify-center p-4">
    <div class="relative w-full max-w-4xl overflow-hidden rounded-3xl bg-slate-950 shadow-2xl">
      <button type="button" class="absolute right-3 top-3 z-10 inline-flex h-9 w-9 items-center justify-center rounded-xl bg-black/45 text-white hover:bg-black/60" data-close-media-preview>
        <i data-lucide="x" class="h-4 w-4"></i>
      </button>
      <div id="mediaPreviewBody" class="flex min-h-[300px] items-center justify-center bg-slate-950 p-3"></div>
    </div>
  </div>
</div>

<div id="mediaPanel" class="fixed inset-y-0 right-0 z-50 hidden w-full max-w-[1180px] bg-white shadow-2xl ring-1 ring-slate-200">
  <div class="flex h-14 items-center justify-between border-b border-slate-200 px-4">
    <div>
      <h2 class="text-sm font-bold text-slate-800">Media</h2>
      <p class="text-xs text-slate-400">Upload and manage your media library.</p>
    </div>
    <button type="button" data-close-media class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700">
      <i data-lucide="x" class="h-4 w-4"></i>
    </button>
  </div>
  <iframe id="mediaFrame" src="media.php?panel=1" class="h-[calc(100%-56px)] w-full border-0" title="Vision Board media"></iframe>
</div>
<div id="mediaBackdrop" class="fixed inset-0 z-40 hidden bg-slate-900/35"></div>

<div id="schedulePanel" class="fixed inset-y-0 right-0 z-50 hidden w-full max-w-[980px] bg-white shadow-2xl ring-1 ring-slate-200">
  <div class="flex h-14 items-center justify-between border-b border-slate-200 px-4">
    <div>
      <h2 class="text-sm font-bold text-slate-800">Scheduling</h2>
      <p class="text-xs text-slate-400">Set when this board should play.</p>
    </div>
    <button type="button" data-close-schedule class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700">
      <i data-lucide="x" class="h-4 w-4"></i>
    </button>
  </div>
  <iframe id="scheduleFrame" src="schedule.php?panel=1<?= $schedulePanelPlaylistId > 0 ? '&playlist_id=' . $schedulePanelPlaylistId : '' ?>" class="h-[calc(100%-56px)] w-full border-0" title="Vision Board scheduling"></iframe>
</div>
<div id="scheduleBackdrop" class="fixed inset-0 z-40 hidden bg-slate-900/35"></div>

<?php if ($editing && !$isDemoBoard): ?>
<div id="deleteBoardModal" class="fixed inset-0 z-[70] hidden">
  <div class="absolute inset-0 bg-slate-900/45" data-close-delete></div>
  <div class="relative flex min-h-full items-center justify-center p-4">
    <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
      <div class="flex items-start gap-3">
        <div class="mt-0.5 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
          <i data-lucide="trash-2" class="h-5 w-5"></i>
        </div>
        <div class="min-w-0 flex-1">
          <h2 class="text-base font-black text-slate-900">Delete vBoard</h2>
          <p class="mt-1 text-sm text-slate-600">Type <span class="font-bold text-slate-900">Delete</span> to remove this vBoard.</p>
        </div>
      </div>
      <form method="post" class="mt-4 space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_playlist">
        <input type="hidden" name="playlist_id" value="<?= (int) $editing['id'] ?>">
        <div>
          <input
            type="text"
            name="delete_confirm"
            autocomplete="off"
            placeholder="Type Delete"
            class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm"
            data-delete-confirm-input
          >
        </div>
        <div class="flex items-center justify-end gap-2">
          <button type="button" data-close-delete class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-colors">
            Cancel
          </button>
          <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white hover:bg-rose-700 disabled:cursor-not-allowed disabled:bg-rose-300" data-delete-submit disabled>
            <i data-lucide="trash-2" class="h-4 w-4"></i> Delete vBoard
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  const newBoardCard = document.querySelector('.js-new-board-card');
  if (newBoardCard) {
    requestAnimationFrame(() => {
      newBoardCard.classList.add('duration-300', 'ease-out');
      newBoardCard.classList.remove('opacity-0', 'translate-y-2');
    });
  }
})();

(function () {
  const flash = document.querySelector('[data-flash-message]');
  if (flash) {
    window.setTimeout(() => {
      flash.classList.add('opacity-0');
      window.setTimeout(() => flash.remove(), 500);
    }, 5000);
  }
})();

(function () {
  const modal = document.getElementById('deleteBoardModal');
  if (!modal) {
    return;
  }
  const openers = document.querySelectorAll('[data-open-delete], [data-open-delete-card]');
  const closers = modal.querySelectorAll('[data-close-delete]');
  const input = modal.querySelector('[data-delete-confirm-input]');
  const submit = modal.querySelector('[data-delete-submit]');

  const syncDeleteState = () => {
    if (!input || !submit) {
      return;
    }
    submit.disabled = input.value.trim() !== 'Delete';
  };

  const openModal = () => {
    modal.classList.remove('hidden');
    syncDeleteState();
    window.setTimeout(() => {
      input?.focus();
    }, 0);
  };

  const closeModal = () => {
    modal.classList.add('hidden');
    if (input) {
      input.value = '';
    }
    syncDeleteState();
  };

  openers.forEach((btn) => btn.addEventListener('click', openModal));
  closers.forEach((btn) => btn.addEventListener('click', closeModal));
  input?.addEventListener('input', syncDeleteState);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
      closeModal();
    }
  });
})();

(function () {
  document.querySelectorAll('[data-copy-url]').forEach((btn) => {
    btn.addEventListener('click', async (event) => {
      event.preventDefault();
      event.stopPropagation();
      const url = btn.getAttribute('data-copy-url') || '';
      if (!url) {
        return;
      }
      try {
        await navigator.clipboard.writeText(url);
        const oldTitle = btn.title;
        btn.title = 'Copied';
        btn.classList.add('bg-emerald-500/20', 'text-emerald-200');
        setTimeout(() => {
          btn.title = oldTitle;
          btn.classList.remove('bg-emerald-500/20', 'text-emerald-200');
        }, 1200);
      } catch (error) {
        // Ignore clipboard errors in unsupported browsers.
      }
    });
  });
})();

(function () {
  const libraryInput = document.querySelector('[data-library-file-input]');
  const libraryName = document.querySelector('[data-library-file-name]');
  const libraryCount = document.querySelector('[data-library-file-count]');
  if (libraryInput && libraryName && libraryCount) {
    libraryInput.addEventListener('change', () => {
      const files = Array.from(libraryInput.files || []);
      if (!files.length) {
        libraryName.textContent = 'No files selected';
        libraryCount.textContent = 'Choose media to add to the library.';
        return;
      }
      libraryName.textContent = files[0].name;
      libraryCount.textContent = files.length === 1 ? '1 file selected' : `${files.length} files selected`;
    });
  }
})();

(function () {
  const modal = document.getElementById('mediaPreviewModal');
  const body = document.getElementById('mediaPreviewBody');
  const openers = document.querySelectorAll('[data-open-media-preview]');
  const closers = document.querySelectorAll('[data-close-media-preview]');
  if (!modal || !body || !openers.length) {
    return;
  }

  function closePreview() {
    modal.classList.add('hidden');
    body.innerHTML = '';
    document.body.classList.remove('overflow-hidden');
  }

  openers.forEach((btn) => {
    btn.addEventListener('click', () => {
      const card = btn.closest('[data-library-card]');
      if (!card) {
        return;
      }
      const image = card.querySelector('img');
      const video = card.querySelector('video');
      const title = card.querySelector('p');

      if (image) {
        body.innerHTML = `<img src="${image.getAttribute('src') || ''}" alt="" class="max-h-[82vh] w-auto max-w-full rounded-2xl object-contain">`;
      } else if (video) {
        body.innerHTML = `<video src="${video.getAttribute('src') || ''}" class="max-h-[82vh] w-auto max-w-full rounded-2xl bg-black" controls autoplay playsinline></video>`;
      } else {
        body.innerHTML = '<div class="text-sm font-semibold text-slate-300">Preview unavailable.</div>';
      }

      if (title) {
        body.insertAdjacentHTML('beforeend', `<div class="absolute bottom-3 left-3 rounded-xl bg-black/55 px-3 py-1.5 text-xs font-semibold text-white">${title.textContent || ''}</div>`);
      }

      modal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
    });
  });

  closers.forEach((btn) => btn.addEventListener('click', closePreview));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
      closePreview();
    }
  });
})();

(function () {
  document.querySelectorAll('video[data-video-duration]').forEach((video) => {
    const mediaId = video.getAttribute('data-video-duration');
    if (!mediaId) {
      return;
    }
    const input = document.querySelector(`[data-media-duration-input="${mediaId}"]`);
    if (!input) {
      return;
    }
    video.addEventListener('loadedmetadata', () => {
      const duration = Math.max(1, Math.round(video.duration || 0));
      if (duration > 0) {
        input.value = String(duration);
      }
    });
  });
})();

(function () {
  function showLibraryNotice(message, type = 'success') {
    let host = document.getElementById('libraryNoticeHost');
    if (!host) {
      host = document.createElement('div');
      host.id = 'libraryNoticeHost';
      host.className = 'fixed right-4 top-20 z-[80] flex w-full max-w-sm flex-col gap-2';
      document.body.appendChild(host);
    }
    const note = document.createElement('div');
    note.className = `rounded-xl border px-4 py-3 text-sm shadow-lg transition-opacity duration-300 ${
      type === 'error'
        ? 'border-red-200 bg-red-50 text-red-800'
        : 'border-emerald-200 bg-emerald-50 text-emerald-800'
    }`;
    note.textContent = message;
    host.appendChild(note);
    window.setTimeout(() => {
      note.classList.add('opacity-0');
      window.setTimeout(() => note.remove(), 300);
    }, 4500);
  }

  document.querySelectorAll('[data-library-delete-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const article = form.closest('[data-library-card]');
      const button = form.querySelector('button');
      const payload = new FormData(form);
      try {
        button?.setAttribute('disabled', 'disabled');
        const response = await fetch(window.location.href, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: payload,
          credentials: 'same-origin',
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          showLibraryNotice(data.message || 'Could not remove this media.', 'error');
          button?.removeAttribute('disabled');
          return;
        }
        if (article) {
          article.classList.add('opacity-0');
          window.setTimeout(() => article.remove(), 300);
        }
        showLibraryNotice(data.message || 'Media removed from your library.');
      } catch (error) {
        button?.removeAttribute('disabled');
        showLibraryNotice('Could not remove this media.', 'error');
      }
    });
  });
})();

(function () {
  const toggles = document.querySelectorAll('[data-toggle-create]');
  const createPanel = document.getElementById('createPlaylistPanel');
  const shouldOpenOnLoad = <?= empty($playlists) ? 'true' : 'false' ?>;
  if (createPanel) {
    toggles.forEach((btn) => {
      btn.addEventListener('click', () => {
        createPanel.classList.toggle('hidden');
      });
    });
    if (shouldOpenOnLoad) {
      createPanel.classList.remove('hidden');
    }
  }

})();

(function () {
  function wirePanel(panelId, backdropId, openerSelector, closerSelector) {
    const panel = document.getElementById(panelId);
    const backdrop = document.getElementById(backdropId);
    const openers = document.querySelectorAll(openerSelector);
    const closers = document.querySelectorAll(closerSelector);
    if (!panel || !backdrop) {
      return;
    }
    function openPanel() {
      panel.classList.remove('hidden');
      backdrop.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
    }
    function closePanel() {
      panel.classList.add('hidden');
      backdrop.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }
    openers.forEach((btn) => btn.addEventListener('click', openPanel));
    closers.forEach((btn) => btn.addEventListener('click', closePanel));
    backdrop.addEventListener('click', closePanel);
  }

  wirePanel('mediaPanel', 'mediaBackdrop', '[data-open-media]', '[data-close-media]');
})();

(function () {
  const panel = document.getElementById('schedulePanel');
  const backdrop = document.getElementById('scheduleBackdrop');
  const frame = document.getElementById('scheduleFrame');
  const openers = document.querySelectorAll('[data-open-schedule]');
  const closers = document.querySelectorAll('[data-close-schedule]');
  if (!panel || !backdrop || !frame) {
    return;
  }

  function openPanel(playlistId) {
    const url = new URL('schedule.php', window.location.href);
    url.searchParams.set('panel', '1');
    if (playlistId) {
      url.searchParams.set('playlist_id', String(playlistId));
    }
    frame.src = url.pathname + url.search;
    panel.classList.remove('hidden');
    backdrop.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
  }

  function closePanel() {
    panel.classList.add('hidden');
    backdrop.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
  }

  openers.forEach((btn) => {
    btn.addEventListener('click', () => {
      openPanel(btn.getAttribute('data-open-schedule'));
    });
  });
  closers.forEach((btn) => btn.addEventListener('click', closePanel));
  backdrop.addEventListener('click', closePanel);

  if (<?= $openSchedulePanel ? 'true' : 'false' ?>) {
    openPanel(<?= $schedulePanelPlaylistId ?>);
  }
})();

</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
