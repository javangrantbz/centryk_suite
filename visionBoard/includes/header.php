<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_login();

$me        = current_user();
$CENTRYK   = centryk_public_url();          // /centryk/public
$BASE      = app_base();                     // /centryk/visionBoard
$companies = vb_companies();
$company   = vb_company();
$activeCid = vb_cid();
$activeUuid = (string)($company['uuid'] ?? '');
$activeRole = (string)($company['role'] ?? '');
$active     = $active ?? '';
$selfPage   = basename(parse_url($_SERVER['REQUEST_URI'] ?? 'index.php', PHP_URL_PATH) ?: 'index.php') ?: 'index.php';

// Cross-app query strings so links keep the active company.
$switchQs = $activeUuid !== '' ? '&company_uuid=' . urlencode($activeUuid) : '';
$calQs    = $activeUuid !== '' ? '?company_uuid=' . urlencode($activeUuid) : '';
$centrykHome = $CENTRYK . '/index.php' . ($activeUuid !== '' ? '?company_uuid=' . urlencode($activeUuid) : '');

// The user's enrolled apps (for the waffle switcher).
$hdrApps = [];
if (!empty($me['id'])) {
    $as = db()->prepare("SELECT a.`key`, a.label, a.color, a.icon
                         FROM apps a JOIN user_app_access ua ON ua.app_id = a.id
                         WHERE ua.user_id = :u AND a.status = 'active' ORDER BY a.sort_order");
    $as->execute(['u' => (int)$me['id']]);
    $hdrApps = $as->fetchAll(PDO::FETCH_ASSOC);
}

// Sidebar modules: [active-key, lucide icon, label, admin-only].
$nav = [
    ['index',     'layout-grid',    'V Board',   false],
    ['schedule',  'calendar-clock', 'Scheduling', false],
    ['settings',  'settings',       'Settings',  false],
    ['shares',    'share-2',        'Sharing',   true],
    ['activity',  'scroll-text',    'Activity',  true],
    ['backup',    'database',       'Backup',    true],
];
$amAdmin = is_admin($me);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= e($CENTRYK) ?>/favicon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .dropdown:hover .dropdown-menu, .dropdown:focus-within .dropdown-menu { display: block; }
        .dropdown-menu-bridge { position: absolute; left: 0; right: 0; top: 100%; height: 16px; content: ""; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-900 overflow-hidden font-sans">

<!-- Centryk accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>

<div class="flex overflow-hidden" style="height: calc(100vh - 3px)">
    <!-- App sidebar -->
    <aside class="w-20 lg:w-24 bg-[#1a1a1a] flex flex-col items-center py-4 flex-shrink-0 z-50 overflow-y-auto custom-scrollbar">
        <a href="<?= $BASE ?>/admin/index.php" class="mb-5 text-rose-500 hover:text-rose-400 transition" title="Vision Board">
            <i data-lucide="monitor-play" class="w-7 h-7"></i>
        </a>
        <nav class="flex-1 flex flex-col gap-1.5 w-full px-2">
            <?php foreach ($nav as [$key, $icon, $label, $adminOnly]):
                if ($adminOnly && !$amAdmin) continue;
                $on = ($active === $key); ?>
            <a href="<?= $BASE ?>/admin/<?= $key ?>.php"
               class="flex flex-col items-center gap-1 rounded-2xl py-2.5 transition-all <?= $on ? 'bg-rose-600 text-white shadow-lg shadow-rose-900/40' : 'text-gray-400 hover:text-white hover:bg-white/5' ?>">
                <i data-lucide="<?= $icon ?>" class="w-5 h-5"></i>
                <span class="text-[10px] font-bold tracking-wide"><?= e($label) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 bg-[#f8fafc]">
        <!-- Top bar -->
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-5 lg:px-6 flex-shrink-0 shadow-sm z-40">
            <div class="flex items-center gap-4 min-w-0">
                <a href="<?= $BASE ?>/admin/index.php" class="flex items-center text-[#1a1a1a] hover:text-rose-600 transition group shrink-0">
                    <span class="text-2xl font-black tracking-tighter italic group-hover:scale-105 transition-transform">Vision Board</span>
                </a>
                <a href="<?= e($centrykHome) ?>" class="shrink-0 flex items-center hover:opacity-75 transition-opacity" title="Back to Centryk">
                    <img src="<?= e($CENTRYK) ?>/assets/centryk_logo_c.png" alt="Centryk" class="h-7 w-auto">
                </a>
                <div class="hidden h-5 w-px bg-slate-200 shrink-0 lg:block"></div>
                <?php if (!empty($companies)): ?>
                <div class="relative dropdown group shrink-0">
                    <button class="flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 hover:bg-teal-100 transition">
                        <i data-lucide="building-2" class="h-4 w-4 text-teal-500"></i>
                        <span class="max-w-[160px] truncate"><?= e($company['name'] ?? 'Select company') ?></span>
                        <?php if (count($companies) > 1): ?><i data-lucide="chevron-down" class="h-3.5 w-3.5 text-teal-400"></i><?php endif; ?>
                    </button>
                    <?php if (count($companies) > 1): ?>
                    <div class="dropdown-menu-bridge"></div>
                    <div class="dropdown-menu hidden absolute left-0 top-full z-50 mt-0 w-56 pt-3">
                        <div class="rounded-xl border border-slate-200 bg-white py-1 shadow-2xl">
                        <?php foreach ($companies as $c): $act = ((int)$c['id'] === $activeCid); ?>
                        <a href="<?= e($selfPage) ?>?company_id=<?= (int)$c['id'] ?>"
                           class="flex items-center gap-2.5 px-4 py-2.5 text-sm transition <?= $act ? 'bg-teal-50 font-semibold text-teal-700' : 'text-slate-700 hover:bg-slate-50' ?>">
                            <?php if ($act): ?><i data-lucide="check" class="h-3.5 w-3.5 text-teal-500 shrink-0"></i><?php else: ?><span class="h-3.5 w-3.5 shrink-0"></span><?php endif; ?>
                            <span class="truncate"><?= e($c['name']) ?></span>
                        </a>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-1.5">
                <!-- Cross-app notifications -->
                <div class="relative" id="cxNotifWrap">
                    <button id="cxNotifBtn" title="Notifications"
                            class="relative w-10 h-10 flex items-center justify-center rounded-xl text-slate-500 hover:bg-orange-50 hover:text-orange-600 transition">
                        <i data-lucide="bell" class="w-5 h-5"></i>
                        <span id="cxNotifBadge" class="absolute right-1 top-1 hidden h-[16px] min-w-[16px] inline-flex items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold text-white">0</span>
                    </button>
                    <div id="cxNotifDropdown" class="absolute right-0 top-full z-50 mt-1.5 hidden w-80 rounded-xl border border-slate-200 bg-white shadow-2xl overflow-hidden">
                        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                            <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Notifications</span>
                            <a href="<?= e($CENTRYK) ?>/notifications.php" class="text-[11px] font-bold text-orange-600 hover:text-orange-700">View all →</a>
                        </div>
                        <div id="cxNotifBody" class="max-h-96 overflow-y-auto p-2">
                            <p class="px-3 py-6 text-center text-xs text-slate-400">Loading…</p>
                        </div>
                    </div>
                </div>

                <!-- Calendar -->
                <a href="<?= e($CENTRYK) ?>/calendar.php<?= e($calQs) ?>" title="Calendar"
                   class="w-10 h-10 flex items-center justify-center rounded-xl text-slate-500 hover:bg-teal-50 hover:text-teal-600 transition">
                    <i data-lucide="calendar" class="w-5 h-5"></i>
                </a>

                <!-- Waffle app switcher -->
                <div class="relative dropdown group">
                    <button class="w-10 h-10 flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 transition" title="Switch app">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <rect x="3" y="3" width="4" height="4" rx="1"/><rect x="10" y="3" width="4" height="4" rx="1"/><rect x="17" y="3" width="4" height="4" rx="1"/>
                            <rect x="3" y="10" width="4" height="4" rx="1"/><rect x="10" y="10" width="4" height="4" rx="1"/><rect x="17" y="10" width="4" height="4" rx="1"/>
                            <rect x="3" y="17" width="4" height="4" rx="1"/><rect x="10" y="17" width="4" height="4" rx="1"/><rect x="17" y="17" width="4" height="4" rx="1"/>
                        </svg>
                    </button>
                    <div class="dropdown-menu-bridge"></div>
                    <div class="dropdown-menu hidden absolute right-0 top-full z-50 mt-0 w-72 pt-3">
                        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-2xl">
                        <p class="mb-3 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Switch App</p>
                        <div class="grid grid-cols-3 gap-2">
                            <a href="<?= e($CENTRYK) ?>/profile.php" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white shadow-sm">
                                    <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                                </span>
                                <span class="text-xs font-medium text-slate-700">Account</span>
                            </a>
                            <?php foreach ($hdrApps as $a): $k = (string)$a['key']; if ($k === 'centryk') continue; ?>
                                <?php if ($k === 'visionboard'): ?>
                                <div class="flex flex-col items-center gap-2 rounded-xl p-3 text-center bg-slate-100 ring-1 ring-slate-200 cursor-default">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 ring-1 ring-rose-100">
                                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                            <rect x="2" y="3" width="20" height="14" rx="2"/><path d="m10 8 5 3-5 3V8Z"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                                        </svg>
                                    </span>
                                    <span class="text-xs font-semibold text-rose-700"><?= e($a['label']) ?></span>
                                </div>
                                <?php elseif ($k === 'onepay'): ?>
                                <a href="<?= e($CENTRYK) ?>/switch.php?app=<?= urlencode($k) . $switchQs ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-purple-50 p-1.5 shadow-sm ring-1 ring-purple-100">
                                        <img src="<?= e($CENTRYK) ?>/assets/onepay_logo.png" alt="OnePay" class="h-full w-full object-contain">
                                    </span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php elseif ($k === 'mypay'): ?>
                                <a href="<?= e($CENTRYK) ?>/switch.php?app=<?= urlencode($k) . $switchQs ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-orange-50 p-1.5 shadow-sm ring-1 ring-orange-100">
                                        <img src="<?= e($CENTRYK) ?>/assets/myPay.png" alt="MyPay" class="h-full w-full object-contain">
                                    </span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php elseif ($k === 'invoice'): ?>
                                <a href="<?= e($CENTRYK) ?>/switch.php?app=<?= urlencode($k) . $switchQs ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl shadow-sm bg-emerald-50 ring-1 ring-emerald-100">
                                        <svg class="h-6 w-6 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                            <path d="M8 3h7l5 5v11a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M15 3v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>
                                        </svg>
                                    </span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php elseif ($k === 'calendar'): ?>
                                <a href="<?= e($CENTRYK) ?>/calendar.php<?= e($calQs) ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl shadow-sm bg-teal-50 ring-1 ring-teal-100">
                                        <svg class="h-6 w-6 text-teal-600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                                        </svg>
                                    </span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php else: ?>
                                <a href="<?= e($CENTRYK) ?>/switch.php?app=<?= urlencode($k) . $switchQs ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl text-2xl shadow-sm" style="background:<?= e($a['color']) ?>14; border:1px solid <?= e($a['color']) ?>33"><?= e($a['icon']) ?></span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        </div>
                    </div>
                </div>

                <!-- Account -->
                <div class="relative dropdown group border-l border-slate-200 pl-2 ml-0.5">
                    <button class="flex items-center gap-2.5 hover:bg-gray-50 p-1.5 rounded-xl transition border border-transparent hover:border-gray-100">
                        <div class="w-9 h-9 rounded-lg bg-rose-600 text-white flex items-center justify-center font-black text-sm shadow shadow-rose-100"><?= strtoupper(substr((string)($me['display_name'] ?? 'U'), 0, 1)) ?></div>
                        <div class="text-left hidden sm:block leading-none">
                            <p class="text-sm font-bold text-slate-800 leading-none"><?= e($me['display_name'] ?? '') ?></p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wider text-slate-400 leading-none"><?= e($activeRole ?: 'Member') ?></p>
                        </div>
                        <i data-lucide="chevron-down" class="w-4 h-4 text-gray-300"></i>
                    </button>
                    <div class="dropdown-menu-bridge"></div>
                    <div class="dropdown-menu hidden absolute right-0 top-full z-50 mt-0 w-64 pt-3">
                        <div class="rounded-2xl border border-gray-100 bg-white p-3 shadow-2xl">
                            <div class="px-3 py-3 border-b border-gray-50 mb-2">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-bold text-slate-800 truncate"><?= e($me['display_name'] ?? '') ?></p>
                                    <?php if ($activeRole): ?><span class="shrink-0 rounded-full bg-rose-50 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-rose-600"><?= e($activeRole) ?></span><?php endif; ?>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-400 truncate"><?= e($me['email'] ?? '') ?></p>
                            </div>
                            <a href="<?= e($CENTRYK) ?>/profile.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 transition">
                                <i data-lucide="user-cog" class="w-4 h-4"></i> Manage your Centryk Account
                            </a>
                            <a href="<?= e($CENTRYK) ?>/logout.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold text-red-600 hover:bg-red-50 transition">
                                <i data-lucide="log-out" class="w-4 h-4"></i> Sign out
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main content -->
        <main class="flex-1 overflow-y-auto custom-scrollbar px-3 pb-3 pt-0.5 lg:px-4 lg:pb-4 lg:pt-1">
            <div class="max-w-6xl mx-auto">
                <?php foreach (take_flashes() as $f): ?>
                  <div data-flash-message class="mb-4 rounded-lg px-4 py-3 text-sm transition-opacity duration-500 <?= $f['type'] === 'error' ? 'bg-red-100 text-red-800 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-500/30' ?>">
                    <?= e($f['msg']) ?>
                  </div>
                <?php endforeach; ?>
