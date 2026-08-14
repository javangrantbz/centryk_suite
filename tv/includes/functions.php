<?php

function tv_config(?string $key = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/config.php';
    }

    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? null;
}

function db(): PDO
{
    return DB::pdo();
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tv_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'item';
}

function centryk_public_url(): string
{
    $appUrl = rtrim((string)($_ENV['APP_URL'] ?? ''), '/');
    if ($appUrl !== '') {
        return $appUrl;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/centryk/public';
}

function tv_base_url(): string
{
    $configured = (string)tv_config('tv_app_url');
    if ($configured !== '') {
        return $configured;
    }

    $appUrl = centryk_public_url();
    if (str_ends_with($appUrl, '/public')) {
        return substr($appUrl, 0, -7) . '/tv';
    }

    return rtrim($appUrl, '/') . '/tv';
}

function tv_url(string $path = ''): string
{
    $base = rtrim(tv_base_url(), '/');
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function tv_current_path(): string
{
    return (string)($_SERVER['REQUEST_URI'] ?? '/');
}

function tv_flash(string $type, string $message): void
{
    $_SESSION['tv_flash'][] = ['type' => $type, 'message' => $message];
}

function tv_take_flashes(): array
{
    $items = $_SESSION['tv_flash'] ?? [];
    unset($_SESSION['tv_flash']);
    return is_array($items) ? $items : [];
}

function tv_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function tv_csrf_token(): string
{
    if (empty($_SESSION['tv_csrf'])) {
        $_SESSION['tv_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['tv_csrf'];
}

function tv_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(tv_csrf_token()) . '">';
}

function tv_verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $provided = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals((string)($_SESSION['tv_csrf'] ?? ''), $provided)) {
        Response::error('Invalid or expired form token.', 419);
    }
}

function tv_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }

    $base = Auth::user();
    if (!$base) {
        return $user = null;
    }

    $base['display_name'] = trim(($base['first_name'] ?? '') . ' ' . ($base['last_name'] ?? ''));
    if ($base['display_name'] === '') {
        $base['display_name'] = $base['email'] ?? 'User';
    }

    return $user = $base;
}

function tv_is_platform_admin(?array $user = null): bool
{
    $user = $user ?: tv_user();
    return !empty($user['is_admin']);
}

function tv_require_login(): array
{
    $user = tv_user();
    if (!$user) {
        $redirect = urlencode(tv_current_path());
        tv_redirect(centryk_public_url() . '/login.php?redirect=' . $redirect);
    }

    return $user;
}

function tv_has_app_access(int $userId): bool
{
    if (tv_is_platform_admin(tv_user())) {
        return true;
    }

    $stmt = db()->prepare(
        'SELECT 1
         FROM user_app_access ua
         JOIN apps a ON a.id = ua.app_id
         WHERE ua.user_id = :user_id AND a.`key` = "tv" AND a.status = "active"
         LIMIT 1'
    );
    $stmt->execute(['user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

function tv_require_app_access(): array
{
    $user = tv_require_login();
    if (!tv_has_app_access((int)$user['id'])) {
        http_response_code(403);
        exit('You do not have access to Centryk TV.');
    }

    return $user;
}

function tv_role_rank(?string $role): int
{
    return match ($role) {
        'viewer' => 10,
        'broadcaster' => 20,
        'admin' => 30,
        'owner' => 40,
        'platform_admin' => 100,
        default => 0,
    };
}

function tv_role_at_least(string $role): bool
{
    return tv_role_rank(tv_active_role()) >= tv_role_rank($role);
}

function tv_active_role(): string
{
    $organization = tv_active_organization();
    return (string)($organization['user_role'] ?? (tv_is_platform_admin() ? 'platform_admin' : 'viewer'));
}

function tv_ensure_user_organizations(int $userId): void
{
    $stmt = db()->prepare(
        'SELECT c.id, c.name
         FROM companies c
         JOIN company_members cm ON cm.company_id = c.id
         WHERE cm.user_id = :user_id AND cm.status = "active" AND c.status = "active"'
    );
    $stmt->execute(['user_id' => $userId]);

    foreach ($stmt->fetchAll() as $company) {
        $companyId = (int)$company['id'];
        $exists = db()->prepare('SELECT id FROM tv_organizations WHERE company_id = :company_id LIMIT 1');
        $exists->execute(['company_id' => $companyId]);
        if ($exists->fetch()) {
            continue;
        }

        $slug = tv_slugify((string)$company['name']) . '-' . $companyId;
        db()->prepare(
            'INSERT INTO tv_organizations (
                company_id, name, slug, status, timezone, created_at, updated_at
             ) VALUES (
                :company_id, :name, :slug, "active", :timezone, NOW(), NOW()
             )'
        )->execute([
            'company_id' => $companyId,
            'name' => (string)$company['name'],
            'slug' => $slug,
            'timezone' => tv_config('timezone'),
        ]);
    }
}

function tv_user_organizations(): array
{
    static $organizations = null;
    if ($organizations !== null) {
        return $organizations;
    }

    $user = tv_user();
    if (!$user) {
        return $organizations = [];
    }

    tv_ensure_user_organizations((int)$user['id']);

    if (tv_is_platform_admin($user)) {
        $stmt = db()->query(
            'SELECT o.*, c.uuid AS company_uuid, c.name AS company_name, "platform_admin" AS user_role
             FROM tv_organizations o
             JOIN companies c ON c.id = o.company_id
             WHERE c.status = "active"
             ORDER BY o.name ASC'
        );
        return $organizations = $stmt->fetchAll();
    }

    $stmt = db()->prepare(
        'SELECT o.*, c.uuid AS company_uuid, c.name AS company_name,
                COALESCE(tou.role,
                    CASE
                        WHEN cm.role = "admin" THEN "owner"
                        WHEN cm.role = "manager" THEN "admin"
                        ELSE "viewer"
                    END
                ) AS user_role
         FROM tv_organizations o
         JOIN companies c ON c.id = o.company_id
         JOIN company_members cm
           ON cm.company_id = c.id
          AND cm.user_id = :user_id
          AND cm.status = "active"
         LEFT JOIN tv_organization_users tou
           ON tou.organization_id = o.id
          AND tou.user_id = :user_id
          AND tou.status = "active"
         WHERE c.status = "active" AND o.status IN ("active", "suspended")
         ORDER BY o.name ASC'
    );
    $stmt->execute(['user_id' => (int)$user['id']]);
    return $organizations = $stmt->fetchAll();
}

function tv_active_organization(): ?array
{
    static $organization = false;
    if ($organization !== false) {
        return $organization;
    }

    $items = tv_user_organizations();
    if ($items === []) {
        return $organization = null;
    }

    $requestedId = isset($_GET['organization_id']) ? (int)$_GET['organization_id'] : 0;
    $requestedSlug = trim((string)($_GET['organization_slug'] ?? ''));
    $sessionId = (int)($_SESSION['tv_organization_id'] ?? 0);

    foreach ($items as $item) {
        if ($requestedId > 0 && (int)$item['id'] === $requestedId) {
            $_SESSION['tv_organization_id'] = (int)$item['id'];
            return $organization = $item;
        }
        if ($requestedSlug !== '' && (string)$item['slug'] === $requestedSlug) {
            $_SESSION['tv_organization_id'] = (int)$item['id'];
            return $organization = $item;
        }
        if ($sessionId > 0 && (int)$item['id'] === $sessionId) {
            return $organization = $item;
        }
    }

    $_SESSION['tv_organization_id'] = (int)$items[0]['id'];
    return $organization = $items[0];
}

function tv_require_organization(): array
{
    $user = tv_require_app_access();
    $organization = tv_active_organization();
    if (!$organization) {
        http_response_code(403);
        exit('You are not assigned to any Centryk TV organization.');
    }

    return $user;
}

function tv_find_public_organization(string $slug): ?array
{
    $stmt = db()->prepare(
        'SELECT o.*, c.name AS company_name
         FROM tv_organizations o
         JOIN companies c ON c.id = o.company_id
         WHERE o.slug = :slug AND o.status = "active"
         LIMIT 1'
    );
    $stmt->execute(['slug' => $slug]);
    return $stmt->fetch() ?: null;
}

function tv_find_event_by_slug(string $slug): ?array
{
    $stmt = db()->prepare(
        'SELECT e.*, o.name AS organization_name, o.slug AS organization_slug, o.logo_path AS organization_logo,
                c.name AS channel_name, c.slug AS channel_slug,
                sk.stream_key_encrypted, sk.stream_key_hash,
                sed.sport, sed.home_team, sed.away_team, sed.venue, sed.competition, sed.round_name,
                sed.home_logo_path, sed.away_logo_path, sed.home_score, sed.away_score
         FROM tv_events e
         JOIN tv_organizations o ON o.id = e.organization_id
         JOIN tv_channels c ON c.id = e.channel_id
         LEFT JOIN tv_stream_keys sk ON sk.id = e.stream_key_id
         LEFT JOIN tv_sports_event_details sed ON sed.event_id = e.id
         WHERE e.slug = :slug
         LIMIT 1'
    );
    $stmt->execute(['slug' => $slug]);
    return $stmt->fetch() ?: null;
}

function tv_user_has_private_event_access(int $eventId, int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM tv_event_access
         WHERE event_id = :event_id
           AND user_id = :user_id
           AND (expires_at IS NULL OR expires_at > NOW())
         LIMIT 1'
    );
    $stmt->execute([
        'event_id' => $eventId,
        'user_id' => $userId,
    ]);

    return (bool)$stmt->fetchColumn();
}

function tv_can_watch_event(array $event, ?array $user = null): bool
{
    $visibility = (string)($event['visibility'] ?? 'public');
    if ($visibility === 'public') {
        return true;
    }

    if (!$user) {
        return false;
    }

    if (tv_is_platform_admin($user)) {
        return true;
    }

    $orgMembership = db()->prepare(
        'SELECT 1
         FROM company_members cm
         JOIN tv_organizations o ON o.company_id = cm.company_id
         WHERE o.id = :organization_id AND cm.user_id = :user_id AND cm.status = "active"
         LIMIT 1'
    );
    $orgMembership->execute([
        'organization_id' => (int)$event['organization_id'],
        'user_id' => (int)$user['id'],
    ]);

    if ($orgMembership->fetchColumn()) {
        return true;
    }

    if ($visibility === 'authenticated') {
        return true;
    }

    return tv_user_has_private_event_access((int)$event['id'], (int)$user['id']);
}

function tv_encrypt_secret(string $plain): string
{
    $key = (string)tv_config('stream_cipher_key');
    if ($key === '') {
        $key = hash('sha256', (string)tv_config('stream_signing_secret'));
    }

    $method = 'AES-256-CBC';
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, $method, hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . ($cipher === false ? '' : $cipher));
}

function tv_decrypt_secret(?string $cipherText): ?string
{
    if (!$cipherText) {
        return null;
    }

    $decoded = base64_decode($cipherText, true);
    if ($decoded === false || strlen($decoded) < 17) {
        return null;
    }

    $key = (string)tv_config('stream_cipher_key');
    if ($key === '') {
        $key = hash('sha256', (string)tv_config('stream_signing_secret'));
    }

    $iv = substr($decoded, 0, 16);
    $payload = substr($decoded, 16);
    $plain = openssl_decrypt($payload, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? null : $plain;
}

function tv_record_audit(?int $organizationId, ?int $userId, string $action, string $entityType, ?int $entityId, array $details = []): void
{
    try {
        db()->prepare(
            'INSERT INTO tv_audit_logs (
                organization_id, user_id, action, entity_type, entity_id, details,
                ip_address, created_at
             ) VALUES (
                :organization_id, :user_id, :action, :entity_type, :entity_id, :details,
                :ip_address, NOW()
             )'
        )->execute([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'action' => substr($action, 0, 120),
            'entity_type' => substr($entityType, 0, 80),
            'entity_id' => $entityId,
            'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    } catch (Throwable $e) {
        // Keep the primary flow working even when audit persistence fails.
    }
}

function tv_json_body(): array
{
    $decoded = json_decode(file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function tv_upload_image(string $field, string $folder): ?string
{
    if (empty($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed.');
    }

    if ((int)$file['size'] > (int)tv_config('upload_max_bytes')) {
        throw new RuntimeException('Uploaded file exceeds the maximum size.');
    }

    $mime = mime_content_type($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Unsupported image format.');
    }

    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $targetDir = __DIR__ . '/../storage/uploads/' . trim($folder, '/');
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }

    $target = $targetDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Unable to store uploaded file.');
    }

    return 'storage/uploads/' . trim($folder, '/') . '/' . $name;
}

function tv_format_datetime(?string $value, string $format = 'M j, Y g:i A'): string
{
    if (!$value) {
        return 'Not set';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date($format, $timestamp) : $value;
}

function tv_status_badge_class(string $status): string
{
    return match ($status) {
        'live' => 'bg-emerald-100 text-emerald-700',
        'scheduled' => 'bg-amber-100 text-amber-700',
        'ended', 'available' => 'bg-slate-100 text-slate-700',
        'cancelled', 'error', 'failed' => 'bg-rose-100 text-rose-700',
        default => 'bg-sky-100 text-sky-700',
    };
}

