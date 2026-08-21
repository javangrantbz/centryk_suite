<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';

/**
 * MyPay's "Use different account" link sends its own login page URL here as
 * ?redirect=, expecting to land back there after logging out of Centryk -
 * but this went unread until now, always dropping the visitor on Centryk's
 * own homepage instead (the same class of lost-return-path bug switch.php
 * had). Unlike login.php/switch.php's same-site relative redirect, this one
 * is legitimately cross-origin (a different app's domain), so it's checked
 * against the registered app origins (apps.url_local/url_production) rather
 * than restricted to a relative path - an unchecked absolute URL here would
 * be an open redirect.
 */
function centryk_safe_logout_redirect(string $candidate, string $fallback): string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return $fallback . '/';
    }

    $parts = parse_url($candidate);
    if (!isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
        return $fallback . '/';
    }
    $candidateOrigin = strtolower($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));

    $allowedOrigins = [];
    $fallbackParts  = parse_url($fallback);
    if (isset($fallbackParts['scheme'], $fallbackParts['host'])) {
        $allowedOrigins[] = strtolower($fallbackParts['scheme'] . '://' . $fallbackParts['host'] . (isset($fallbackParts['port']) ? ':' . $fallbackParts['port'] : ''));
    }
    try {
        $rows = DB::pdo()->query('SELECT url_local, url_production FROM apps')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            foreach (['url_local', 'url_production'] as $col) {
                $u = trim((string)($row[$col] ?? ''));
                $p = $u !== '' ? parse_url($u) : false;
                if ($p && isset($p['scheme'], $p['host'])) {
                    $allowedOrigins[] = strtolower($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
                }
            }
        }
    } catch (Throwable $e) {
        // Registry unreadable - fall through with just Centryk's own origin allowed.
    }

    return in_array($candidateOrigin, $allowedOrigins, true) ? $candidate : $fallback . '/';
}

Auth::logout();

$appUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost/centryk/public', '/');
header('Location: ' . centryk_safe_logout_redirect((string)($_GET['redirect'] ?? ''), $appUrl));
exit;
