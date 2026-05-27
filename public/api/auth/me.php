<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/AuthService.php';

// CORS: allow credentialed requests from registered app origins (for login-page session detection)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $allowed = false;
    try {
        $rows = DB::pdo()->query("SELECT sso_url FROM apps WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $url) {
            $p = parse_url($url);
            if (!$p) continue;
            $appOrigin = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '');
            if (isset($p['port'])) $appOrigin .= ':' . $p['port'];
            if ($appOrigin === $origin) { $allowed = true; break; }
        }
    } catch (Throwable $e) {}

    $host = parse_url($origin, PHP_URL_HOST) ?? '';
    if (in_array($host, ['localhost', '127.0.0.1'], true)) {
        $allowed = true;
    }

    if ($allowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Vary: Origin');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

Auth::start();
Response::ok(AuthService::me());
