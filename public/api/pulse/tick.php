<?php
/**
 * Self-priming daily pulse. The dashboard fires this once on load (fire and
 * forget). The first authenticated request of the day runs DailyPulse::run();
 * every request after that is a cheap no-op (the 'pulse:<date>' digest is
 * already claimed).
 *
 * GET — returns { ok, ran } quickly.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
if (!Auth::user()) {
    Response::error('Unauthorized.', 401);
}

$ran = false;
try {
    $stmt = DB::pdo()->prepare("INSERT IGNORE INTO notification_digests (digest_key) VALUES (:k)");
    $stmt->execute(['k' => 'pulse:' . date('Y-m-d')]);
    if ($stmt->rowCount() === 1) {
        require_once __DIR__ . '/../../../app/services/DailyPulse.php';
        DailyPulse::run();
        $ran = true;
    }
} catch (Throwable $e) {
    error_log('[pulse/tick] ' . $e->getMessage());
}

Response::ok(['ran' => $ran]);
