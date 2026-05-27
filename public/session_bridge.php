<?php
/**
 * Session bridge — called by OnePay (and MyPay) after a direct email/password login.
 * Validates the one-time bridge token, creates a Centryk session cookie, then
 * redirects to the app URL stored in the token (OnePay's sso.php).
 *
 * GET ?bridge=<token>
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';

Auth::start();

$bridgeToken = trim($_GET['bridge'] ?? '');

if ($bridgeToken === '') {
    header('Location: /');
    exit;
}

$pdo  = DB::pdo();
$stmt = $pdo->prepare('
    SELECT user_id, used, expires_at, redirect_url
    FROM sso_tokens
    WHERE token = :tok AND app_key = "centryk_bridge"
    LIMIT 1
');
$stmt->execute(['tok' => $bridgeToken]);
$row = $stmt->fetch();

if (!$row || $row['used'] || strtotime($row['expires_at']) < time() || $row['redirect_url'] === null) {
    // Invalid / expired / already used — send to Centryk home
    header('Location: /');
    exit;
}

// One-time use
$pdo->prepare('UPDATE sso_tokens SET used = 1 WHERE token = :tok')
    ->execute(['tok' => $bridgeToken]);

// Create the Centryk session — this sets the cookie on the browser
Auth::login((int)$row['user_id']);

// Forward to OnePay (sso.php?sso_token=...)
header('Location: ' . $row['redirect_url']);
exit;
