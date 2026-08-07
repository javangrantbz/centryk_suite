<?php
require_once __DIR__ . '/../includes/functions.php';
$base = app_base();

// The TV player is unauthenticated; it identifies itself by a screen token.
$token  = $_GET['screen'] ?? '';
$screen = vb_screen_by_token($token);
if (!$screen) {
    http_response_code(404);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= APP_NAME ?> - Pair this screen</title>
    <style>
      body{font-family:system-ui,sans-serif;background:#0b1220;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:2rem;text-align:center}
      .pair-shell{width:min(100%,34rem)}
      .pair-brand{display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;padding:1rem 1.25rem;border-radius:1.25rem;background:#fff;box-shadow:0 20px 50px rgba(2,6,23,.28)}
      .pair-card{padding:2.25rem 2rem;border:1px solid rgba(244,63,94,.18);border-radius:1.5rem;background:linear-gradient(180deg,rgba(15,23,42,.96),rgba(15,23,42,.88));box-shadow:0 32px 80px rgba(2,6,23,.45)}
      .pair-logo{display:block;height:4.25rem;width:auto;max-width:min(78vw,18rem);object-fit:contain}
      .pair-kicker{margin:0 0 .65rem;color:#fb7185;font-size:.78rem;font-weight:800;letter-spacing:.2em;text-transform:uppercase}
      h1{margin:0;font-size:2rem;line-height:1.05}
      p{margin:1rem 0 0;color:#94a3b8;font-size:1rem;line-height:1.6}
      .pair-app{color:#fb7185;font-weight:800}
      b{color:#f8fafc}
    </style>
    </head>
    <body>
    <div class="pair-shell">
      <div class="pair-brand">
        <img src="<?= $base ?>/../public/assets/centryk_logo.png" alt="Centryk" class="pair-logo">
      </div>
      <div class="pair-card">
        <p class="pair-kicker">Screen Setup</p>
        <h1>This screen isn't paired yet</h1>
        <p>Open <span class="pair-app">Vision Board</span> <b>&rarr; Screens</b> in Centryk, add a screen, and point this display at the URL shown there.</p>
      </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}
$companyId  = (int) $screen['company_id'];
$theme      = get_setting('theme', 'jungle', $companyId);
$transition = get_setting('transition', 'fade', $companyId);
$feedQuery  = '?screen=' . rawurlencode($screen['pair_token']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= APP_NAME ?> - Display</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/display.css">
</head>
<body data-theme="<?= e($theme) ?>" data-api="<?= $base ?>/api/current.php<?= e($feedQuery) ?>" data-heartbeat="<?= $base ?>/api/display-heartbeat.php<?= e($feedQuery) ?>">

  <!-- Idle / empty state -->
  <div id="idle" class="idle">
    <div class="idle-logo">🦙</div>
    <h1>The Belize Zoo</h1>
    <p id="idleMsg">Loading today's program...</p>
  </div>

  <!-- Stage where content is rendered -->
  <div id="stage" class="stage" data-transition="<?= e($transition) ?>"></div>

  <!-- Overlays -->
  <div id="weatherWidget" class="weather-widget" style="display:none">
    <div class="weather-time" id="weatherTime"></div>
    <div class="weather-main">
      <span id="weatherIcon" class="weather-icon">☀</span>
      <span id="weatherTemp" class="weather-temp"></span>
    </div>
    <div class="weather-meta" id="weatherMeta">Belize time</div>
  </div>
  <div id="clock" class="clock"></div>
  <div id="announcement" class="announcement" style="display:none">
    <div class="announcement-inner">
      <div class="announcement-label">Announcement</div>
      <div id="announcementText" class="announcement-text"></div>
    </div>
  </div>

  <!-- Visitor QR code (hidden until enabled in Settings) -->
  <div id="qrbox" class="qrbox" style="display:none">
    <div id="qrcode" class="qrcode"></div>
    <div id="qrcap" class="qrcap"></div>
  </div>

  <div id="marquee" class="marquee"><span id="marqueeText"></span></div>

  <!-- Fullscreen hint (auto-hides) -->
  <button id="fsBtn" class="fs-btn" title="Toggle fullscreen">⛶ Fullscreen</button>

<script src="<?= $base ?>/assets/js/qrcode.min.js"></script>
<script src="<?= $base ?>/assets/js/display.js"></script>
</body>
</html>
