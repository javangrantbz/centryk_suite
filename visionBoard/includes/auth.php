<?php
/**
 * Authentication + company context — bridged to Centryk.
 *
 * Login is Centryk's: unauthenticated users are sent to Centryk's /login.php.
 * The "active company" is resolved exactly like the other in-server apps
 * (calendar.php): ?company_id / ?company_uuid, else remembered in session,
 * else the user's first company. All signage data is scoped to it.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../app/core/Auth.php';
require_once __DIR__ . '/../../app/services/AuthService.php';

Auth::start();

/** URL of Centryk's public root (e.g. /centryk/public) derived from this app's base. */
function centryk_public_url(): string
{
    return str_replace('/visionBoard', '/public', app_base());
}

/**
 * The signed-in Centryk user, augmented with the keys this app expects
 * (username, display_name, role for the active company). Null if not signed in.
 */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $me = AuthService::me();
    if (empty($me['authenticated'])) {
        return $cache = null;
    }
    $u = $me['user'];
    $name = trim((string)(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')));
    $u['display_name'] = $name !== '' ? $name : ($u['email'] ?? 'User');
    $u['username']     = $u['email'] ?? $u['display_name'];
    $u['role']         = vb_company()['role'] ?? null;   // company role, not global
    return $cache = $u;
}

function require_login(): void
{
    if (!current_user()) {
        $return = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . centryk_public_url() . '/login.php?redirect=' . $return);
        exit;
    }
    // Signed in but belongs to no company — nothing to manage.
    if (!vb_cid()) {
        http_response_code(403);
        die('You are not a member of any company. Ask an administrator to add you.');
    }
}

/** All active companies the signed-in user belongs to (id, uuid, name, role). */
function vb_companies(): array
{
    static $rows = null;
    if ($rows !== null) {
        return $rows;
    }
    // Read the uid straight from AuthService to avoid recursing through
    // current_user() (which augments itself with the company role).
    $me = AuthService::me();
    if (empty($me['authenticated'])) {
        return $rows = [];
    }
    $stmt = db()->prepare("
        SELECT c.id, c.uuid, c.name, cm.role
        FROM company_members cm
        JOIN companies c ON c.id = cm.company_id
        WHERE cm.user_id = :uid AND cm.status = 'active' AND c.status = 'active'
        ORDER BY c.name ASC
    ");
    $stmt->execute(['uid' => (int)$me['user']['id']]);
    return $rows = $stmt->fetchAll();
}

/** The active company row for this request, or null. */
function vb_company(): ?array
{
    static $active = false;
    if ($active !== false) {
        return $active;
    }
    $companies = vb_companies();
    if (!$companies) {
        return $active = null;
    }
    $requestedCid  = isset($_GET['company_id'])   ? (int)$_GET['company_id'] : 0;
    $requestedUuid = isset($_GET['company_uuid']) ? trim((string)$_GET['company_uuid']) : '';
    $sessionCid    = (int)($_SESSION['vb_company_id'] ?? 0);

    $picked = null;
    if ($requestedCid) {
        foreach ($companies as $c) if ((int)$c['id'] === $requestedCid) { $picked = $c; break; }
    }
    if (!$picked && $requestedUuid !== '') {
        foreach ($companies as $c) if ((string)($c['uuid'] ?? '') === $requestedUuid) { $picked = $c; break; }
    }
    if (!$picked && $sessionCid) {
        foreach ($companies as $c) if ((int)$c['id'] === $sessionCid) { $picked = $c; break; }
    }
    if (!$picked) {
        $picked = $companies[0];
    }
    $_SESSION['vb_company_id'] = (int)$picked['id'];
    return $active = $picked;
}

/** The active company id (int), or 0 if the user has no company. */
function vb_cid(): int
{
    $c = vb_company();
    return $c ? (int)$c['id'] : 0;
}

function is_admin(?array $user = null): bool
{
    $user = $user ?: current_user();
    return ($user['role'] ?? '') === 'admin';
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die('Admin access required.');
    }
}

/** CSRF helpers (session-based). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function check_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
            http_response_code(419);
            die('Invalid or expired form token. Please go back and try again.');
        }
    }
}
