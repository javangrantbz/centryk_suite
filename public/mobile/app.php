<?php
require_once __DIR__ . '/../../app/core/Auth.php';
require_once __DIR__ . '/../../app/core/DB.php';
require_once __DIR__ . '/../../app/services/AuthService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: ../login.php');
    exit;
}

// ── App-switch action (header dropdown) ─────────────────────────────────────
$allApps = AuthService::allAppsWithEnrollment((int)$user['id']);
$enrolledApps = array_values(array_filter($allApps, fn($a) => !empty($a['enrolled'])));

if (isset($_GET['switch_app'])) {
    $key = trim((string)$_GET['switch_app']);
    foreach ($enrolledApps as $app) {
        if (($app['key'] ?? '') === $key) {
            $_SESSION['mobile_current_app'] = $key;
            break;
        }
    }
    header('Location: app.php?tab=home');
    exit;
}

$pdo = DB::pdo();
$companiesStmt = $pdo->prepare("
    SELECT c.id, c.uuid, c.name, c.logo, cm.role
    FROM companies c
    JOIN company_members cm ON cm.company_id = c.id
    WHERE cm.user_id = :uid AND cm.status = 'active' AND c.status = 'active'
    ORDER BY c.name
");
$companiesStmt->execute(['uid' => $user['id']]);
$companies = $companiesStmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['switch_company'])) {
    $companyId = (int)$_GET['switch_company'];
    foreach ($companies as $company) {
        if ((int)($company['id'] ?? 0) === $companyId) {
            $_SESSION['mobile_current_company_id'] = $companyId;
            break;
        }
    }
    header('Location: app.php?tab=account');
    exit;
}

if (empty($_SESSION['mobile_current_company_id']) && !empty($companies[0]['id'])) {
    $_SESSION['mobile_current_company_id'] = (int)$companies[0]['id'];
}

if (empty($_SESSION['mobile_current_app']) && !empty($enrolledApps)) {
    $_SESSION['mobile_current_app'] = $enrolledApps[0]['key'];
}
$currentApp = $_SESSION['mobile_current_app'] ?? null;

$tab = in_array($_GET['tab'] ?? 'home', ['home', 'notifications', 'calendar', 'account'], true)
    ? $_GET['tab']
    : 'home';

$appColor = '#2563eb';
$currentAppLabel = 'Centryk';
foreach ($enrolledApps as $app) {
    if (($app['key'] ?? '') === $currentApp) {
        $currentAppLabel = $app['label'] ?? $currentApp;
        $appColor = $app['color'] ?: $appColor;
        break;
    }
}

// ── In-app left-menu pages, per app key (only relevant on the Home tab) ────
$appPages = [
    'mypay' => [
        ['key' => 'hr_requests', 'label' => 'HR Requests', 'icon' => 'clipboard-check'],
    ],
];
$pagesForApp = $appPages[$currentApp] ?? [];
$view = $_GET['view'] ?? ($pagesForApp[0]['key'] ?? '');

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Centryk</title>
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="<?= h($appColor) ?>">
  <link rel="apple-touch-icon" href="assets/icons/icon.png">
  <link rel="icon" href="assets/icons/icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
  <script>
    tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } };
  </script>
  <style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; }
    .safe-bottom { padding-bottom: env(safe-area-inset-bottom, 0); }
    .safe-top { padding-top: env(safe-area-inset-top, 0); }
  </style>
</head>
<body class="flex min-h-screen flex-col bg-slate-50 text-slate-900 antialiased">

  <!-- Header: app dropdown, subtly tinted with the current app's brand color -->
  <header class="safe-top sticky top-0 z-30 border-b" style="background:<?= h($appColor) ?>0d; border-color:<?= h($appColor) ?>33;">
    <div class="flex items-center justify-between gap-3 px-4 py-3">
      <button id="btnOpenMenu" type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-black/5 md:hidden">
        <i data-lucide="menu" class="h-5 w-5"></i>
      </button>

      <div class="relative flex-1">
        <button id="btnAppDropdown" type="button"
          class="flex w-full max-w-[220px] items-center gap-2 rounded-xl border px-3 py-2 text-left"
          style="border-color:<?= h($appColor) ?>40; background:white;">
          <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background:<?= h($appColor) ?>;"></span>
          <span class="min-w-0 flex-1 truncate text-sm font-black" style="color:<?= h($appColor) ?>;"><?= h($currentAppLabel) ?></span>
          <i data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-slate-400"></i>
        </button>
        <div id="appDropdownMenu" class="absolute left-0 top-full z-40 mt-1.5 hidden w-56 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
          <?php foreach ($enrolledApps as $app): ?>
          <a href="app.php?switch_app=<?= urlencode($app['key']) ?>"
             class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-bold hover:bg-slate-50 <?= $app['key'] === $currentApp ? 'bg-slate-50' : '' ?>">
            <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background:<?= h($app['color'] ?: '#64748b') ?>;"></span>
            <?= h($app['label']) ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-black text-white">
        <?= h(strtoupper(substr($user['first_name'] ?? '?', 0, 1))) ?>
      </div>
    </div>
  </header>

  <div class="flex flex-1">
    <!-- Left menu: pages within the currently selected app -->
    <aside id="leftMenu" class="fixed inset-y-0 left-0 z-40 hidden w-64 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 md:sticky md:top-[57px] md:flex md:h-[calc(100vh-57px)] md:w-56 md:translate-x-0">
      <div class="flex items-center justify-between border-b border-slate-100 p-4 md:hidden">
        <span class="text-xs font-black uppercase tracking-widest text-slate-400"><?= h($currentAppLabel) ?></span>
        <button id="btnCloseMenu" type="button" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100">
          <i data-lucide="x" class="h-4 w-4"></i>
        </button>
      </div>
      <nav class="flex-1 space-y-0.5 overflow-y-auto p-3">
        <?php if (!$pagesForApp): ?>
          <p class="px-2 py-3 text-xs font-semibold text-slate-400">Nothing here yet for <?= h($currentAppLabel) ?>.</p>
        <?php else: foreach ($pagesForApp as $page): $isActive = $tab === 'home' && $view === $page['key']; ?>
          <a href="app.php?tab=home&view=<?= urlencode($page['key']) ?>"
             class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-bold transition-colors <?= $isActive ? 'text-white' : 'text-slate-600 hover:bg-slate-50' ?>"
             style="<?= $isActive ? 'background:' . h($appColor) . ';' : '' ?>">
            <i data-lucide="<?= h($page['icon']) ?>" class="h-4 w-4 shrink-0"></i>
            <?= h($page['label']) ?>
          </a>
        <?php endforeach; endif; ?>
      </nav>
    </aside>
    <div id="leftMenuOverlay" class="fixed inset-0 z-30 hidden bg-slate-950/40 md:hidden"></div>

    <!-- Content -->
    <main class="min-w-0 flex-1 pb-24">
      <?php
      if ($tab === 'home') {
          if ($currentApp === 'mypay' && $view === 'hr_requests') {
              require __DIR__ . '/views/mypay_hr_requests.php';
          } else {
              require __DIR__ . '/views/home_placeholder.php';
          }
      } elseif ($tab === 'notifications') {
          require __DIR__ . '/views/notifications.php';
      } elseif ($tab === 'calendar') {
          require __DIR__ . '/views/calendar.php';
      } elseif ($tab === 'account') {
          require __DIR__ . '/views/account.php';
      }
      ?>
    </main>
  </div>

  <!-- Bottom tab bar -->
  <nav class="safe-bottom fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 backdrop-blur-md">
    <div class="mx-auto flex max-w-lg items-stretch justify-around">
      <?php
      $tabs = [
          ['key' => 'home',          'label' => 'Home',          'icon' => 'home'],
          ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell'],
          ['key' => 'calendar',      'label' => 'Calendar',      'icon' => 'calendar'],
          ['key' => 'account',       'label' => 'Account',       'icon' => 'user'],
      ];
      foreach ($tabs as $t): $isActive = $tab === $t['key'];
      ?>
      <a href="app.php?tab=<?= $t['key'] ?>" class="flex flex-1 flex-col items-center gap-1 py-2.5 text-[10px] font-bold uppercase tracking-wide <?= $isActive ? '' : 'text-slate-400' ?>" style="<?= $isActive ? 'color:' . h($appColor) . ';' : '' ?>">
        <i data-lucide="<?= $t['icon'] ?>" class="h-5 w-5"></i>
        <?= $t['label'] ?>
      </a>
      <?php endforeach; ?>
    </div>
  </nav>

  <script>
    lucide.createIcons();

    const dropBtn = document.getElementById('btnAppDropdown');
    const dropMenu = document.getElementById('appDropdownMenu');
    dropBtn?.addEventListener('click', () => dropMenu.classList.toggle('hidden'));
    document.addEventListener('click', (e) => {
      if (!dropBtn.contains(e.target) && !dropMenu.contains(e.target)) dropMenu.classList.add('hidden');
    });

    const leftMenu = document.getElementById('leftMenu');
    const leftMenuOverlay = document.getElementById('leftMenuOverlay');
    function openMenu() {
      leftMenu.classList.remove('hidden', '-translate-x-full');
      leftMenuOverlay.classList.remove('hidden');
    }
    function closeMenu() {
      leftMenu.classList.add('-translate-x-full');
      leftMenuOverlay.classList.add('hidden');
      setTimeout(() => leftMenu.classList.add('hidden'), 200);
    }
    document.getElementById('btnOpenMenu')?.addEventListener('click', openMenu);
    document.getElementById('btnCloseMenu')?.addEventListener('click', closeMenu);
    leftMenuOverlay?.addEventListener('click', closeMenu);

    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('sw.js').catch(() => {});
    }
  </script>
</body>
</html>
