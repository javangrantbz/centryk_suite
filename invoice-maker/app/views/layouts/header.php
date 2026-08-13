<?php
// Suite header data: the user's enrolled apps (for the waffle) + active company uuid.
$invHdrApps = [];
$invHdrUuid = '';
$__hdrUid = (int)($_SESSION['user']['id'] ?? 0);
if ($__hdrUid) {
    $__hs = $pdo->prepare("SELECT a.`key`, a.label, a.color, a.icon
                           FROM apps a JOIN user_app_access ua ON ua.app_id = a.id
                           WHERE ua.user_id = :u AND a.status = 'active' ORDER BY a.sort_order");
    $__hs->execute(['u' => $__hdrUid]);
    $invHdrApps = $__hs->fetchAll(PDO::FETCH_ASSOC);
}
// The user's companies (for the Centryk company picker) + active company/role.
$invHdrCompanies = [];
if ($__hdrUid) {
    $__ccs = $pdo->prepare("SELECT c.id, c.uuid, c.name, cm.role
                            FROM company_members cm JOIN companies c ON c.id = cm.company_id
                            WHERE cm.user_id = :u AND cm.status = 'active' AND c.status = 'active'
                            ORDER BY c.name");
    $__ccs->execute(['u' => $__hdrUid]);
    $invHdrCompanies = $__ccs->fetchAll(PDO::FETCH_ASSOC);
}
$invActiveCid  = function_exists('current_company_id') ? current_company_id() : 0;
$invActiveName = '';
$invActiveRole = '';
foreach ($invHdrCompanies as $c) {
    if ((int)$c['id'] === $invActiveCid) { $invActiveName = $c['name']; $invActiveRole = $c['role']; $invHdrUuid = (string)$c['uuid']; }
}
$invSwitchQs = $invHdrUuid !== '' ? '&company_uuid=' . urlencode($invHdrUuid) : '';
$invCalQs    = $invHdrUuid !== '' ? '?company_uuid=' . urlencode($invHdrUuid) : '';
$invCentrykHomeUrl = rtrim(CENTRYK_BASE, '/') . '/';
if ($invHdrUuid !== '') {
    $invCentrykHomeUrl .= '?company_uuid=' . urlencode($invHdrUuid);
}
?>
<?php $invPage = $_GET['page'] ?? 'dashboard'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <link rel="icon" type="image/svg+xml" href="<?= CENTRYK_BASE ?>/favicon.svg">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .dropdown:hover .dropdown-menu,
        .dropdown:focus-within .dropdown-menu { display: block; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
        .dropdown-menu-bridge {
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            height: 16px;
            content: "";
        }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-900 overflow-hidden font-sans">

<!-- Centryk accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>

<div class="flex overflow-hidden" style="height: calc(100vh - 3px)">
    <!-- Sidebar Navigation (labelled) -->
    <aside class="w-20 lg:w-24 bg-[#1a1a1a] flex flex-col items-center py-4 flex-shrink-0 z-50">
        <a href="<?= BASE_URL ?>/?page=dashboard" class="mb-5 text-emerald-500 hover:text-emerald-400 transition" title="Dashboard">
            <i data-lucide="layout-grid" class="w-7 h-7"></i>
        </a>

        <nav class="flex-1 flex flex-col gap-2 w-full px-2">
            <?php
            $invCur = $_GET['page'] ?? 'dashboard';
            $invNav = [
                ['invoices',  'receipt',        'Invoices', ['invoices', 'quotes']],
                ['receipts',  'shopping-cart',  'POS Receipts', ['receipts']],
                ['customers', 'users',          'Clients',  ['customers']],
                ['documents', 'folder',         'Files',    ['documents']],
                ['settings',  'panel-top',      'Header',   ['settings']],
            ];
            foreach ($invNav as [$pg, $ic, $lbl, $matches]):
                $on = false;
                foreach ($matches as $m) { if ($invCur === $m || str_starts_with($invCur, $m . '-')) { $on = true; break; } }
            ?>
            <a href="<?= BASE_URL ?>/?page=<?= $pg ?>"
               class="flex flex-col items-center gap-1 rounded-2xl py-2.5 transition-all <?= $on ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-900/40' : 'text-gray-400 hover:text-white hover:bg-white/5' ?>">
                <i data-lucide="<?= $ic ?>" class="w-5 h-5"></i>
                <span class="text-[10px] font-bold tracking-wide"><?= $lbl ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 bg-[#f8fafc]">
        <!-- Top Bar (Made Taller - "The Car") -->
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-5 lg:px-6 flex-shrink-0 shadow-sm z-40">
            <!-- Brand + Centryk company picker -->
            <div class="flex items-center gap-4 min-w-0">
                <a href="<?= BASE_URL ?>/?page=dashboard" class="flex items-center text-[#1a1a1a] hover:text-emerald-600 transition-all group shrink-0">
                    <span class="text-2xl font-black tracking-tighter italic group-hover:scale-105 transition-transform"><?= APP_NAME ?></span>
                </a>
                <a href="<?= $invCentrykHomeUrl ?>" class="shrink-0 flex items-center hover:opacity-75 transition-opacity" title="Back to Centryk">
                    <img src="<?= rtrim(CENTRYK_BASE, '/') ?>/assets/centryk_logo_c.png" alt="Centryk" class="h-7 w-auto">
                </a>
                <div class="hidden h-5 w-px bg-slate-200 shrink-0 lg:block"></div>
                <?php if (!empty($invHdrCompanies)): ?>
                <div class="relative dropdown group shrink-0">
                    <button class="flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 hover:bg-teal-100 transition">
                        <i data-lucide="building-2" class="h-4 w-4 text-teal-500"></i>
                        <span class="max-w-[160px] truncate"><?= e($invActiveName ?: 'Select company') ?></span>
                        <i data-lucide="chevron-down" class="h-3.5 w-3.5 text-teal-400"></i>
                    </button>
                    <div class="dropdown-menu-bridge"></div>
                    <div class="dropdown-menu hidden absolute left-0 top-full z-50 mt-0 w-56 pt-3">
                        <div class="rounded-xl border border-slate-200 bg-white py-1 shadow-2xl">
                        <?php foreach ($invHdrCompanies as $c): $act = ((int)$c['id'] === $invActiveCid); ?>
                        <a href="<?= BASE_URL ?>/?page=<?= e($invPage) ?>&company_id=<?= (int)$c['id'] ?>"
                           class="flex items-center gap-2.5 px-4 py-2.5 text-sm transition <?= $act ? 'bg-teal-50 font-semibold text-teal-700' : 'text-slate-700 hover:bg-slate-50' ?>">
                            <?php if ($act): ?>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="h-3.5 w-3.5 text-teal-500 shrink-0"><path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd"/></svg>
                            <?php else: ?>
                            <span class="h-3.5 w-3.5 shrink-0"></span>
                            <?php endif; ?>
                            <span class="truncate"><?= e($c['name']) ?></span>
                        </a>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Suite cluster: calendar · waffle · account -->
            <div class="flex items-center gap-1.5">

                <!-- Cross-app notifications (Centryk) -->
                <div class="relative" id="cxNotifWrap">
                    <button id="cxNotifBtn" title="Notifications"
                            class="relative w-10 h-10 flex items-center justify-center rounded-xl text-slate-500 hover:bg-orange-50 hover:text-orange-600 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                        <span id="cxNotifBadge" class="absolute right-1 top-1 hidden h-[16px] min-w-[16px] inline-flex items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-bold text-white">0</span>
                    </button>
                    <div id="cxNotifDropdown" class="absolute right-0 top-full z-50 mt-1.5 hidden w-80 rounded-xl border border-slate-200 bg-white shadow-2xl overflow-hidden">
                        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                            <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Notifications</span>
                            <a href="<?= CENTRYK_BASE ?>/notifications.php" class="text-[11px] font-bold text-orange-600 hover:text-orange-700">View all →</a>
                        </div>
                        <div id="cxNotifBody" class="max-h-96 overflow-y-auto p-2">
                            <p class="px-3 py-6 text-center text-xs text-slate-400">Loading…</p>
                        </div>
                    </div>
                </div>
                <script>
                (function () {
                    const CFG = { apiBase: '<?= CENTRYK_BASE ?>/api/notifications', pageUrl: '<?= CENTRYK_BASE ?>/notifications.php' };
                    const btn = document.getElementById('cxNotifBtn');
                    const dd = document.getElementById('cxNotifDropdown');
                    const badge = document.getElementById('cxNotifBadge');
                    const body = document.getElementById('cxNotifBody');
                    if (!btn || !dd || !badge || !body) return;
                    const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
                    function setBadge(n) { n = parseInt(n, 10) || 0; if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.classList.remove('hidden'); } else { badge.classList.add('hidden'); } }
                    function timeAgo(ts) { const d = new Date(String(ts || '').replace(' ', 'T')); if (isNaN(d)) return ''; const s = Math.max(1, Math.floor((Date.now() - d.getTime()) / 1000)); if (s < 60) return s + 's ago'; const m = Math.floor(s / 60); if (m < 60) return m + 'm ago'; const h = Math.floor(m / 60); if (h < 24) return h + 'h ago'; const dy = Math.floor(h / 24); if (dy < 7) return dy + 'd ago'; return d.toLocaleDateString(); }
                    function row(n) { const unread = !n.read_at; const accent = n.color && /^#/.test(n.color) ? n.color : '#f97316'; const href = n.url ? esc(n.url) : CFG.pageUrl; return '<a href="' + href + '" class="flex gap-3 px-3 py-2.5 rounded-lg hover:bg-slate-50 ' + (unread ? 'bg-orange-50/40' : '') + '">' + '<span class="mt-1 h-2 w-2 shrink-0 rounded-full" style="background:' + (unread ? accent : 'transparent') + '"></span>' + '<span class="min-w-0 flex-1">' + '<span class="block text-sm font-semibold text-slate-800 truncate">' + esc(n.title) + '</span>' + (n.body ? '<span class="block text-[11px] text-slate-500 line-clamp-2">' + esc(n.body) + '</span>' : '') + '<span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">' + esc(n.app_key || '') + ' · ' + timeAgo(n.created_at) + '</span>' + '</span>' + '</a>'; }
                    function refreshCount() { fetch(CFG.apiBase + '/count.php', { credentials: 'same-origin' }).then(r => r.json()).then(d => { if (d && d.success) setBadge(d.unread_count); }).catch(() => {}); }
                    function loadList() { body.innerHTML = '<p class="px-3 py-6 text-center text-xs text-slate-400">Loading…</p>'; fetch(CFG.apiBase + '/list.php', { credentials: 'same-origin' }).then(r => r.json()).then(d => { const items = (d && d.notifications) || []; body.innerHTML = items.length ? items.map(row).join('') : '<p class="px-3 py-6 text-center text-xs text-slate-400">You\'re all caught up.</p>'; if (d && d.unread_count > 0) { fetch(CFG.apiBase + '/read.php', { method: 'POST', credentials: 'same-origin' }).then(r => r.json()).then(() => setBadge(0)).catch(() => {}); } else { setBadge(0); } }).catch(() => { body.innerHTML = '<p class="px-3 py-6 text-center text-xs text-slate-400">Couldn\'t load notifications.</p>'; }); }
                    btn.addEventListener('click', e => { e.stopPropagation(); const opening = dd.classList.contains('hidden'); dd.classList.add('hidden'); if (opening) { dd.classList.remove('hidden'); loadList(); } });
                    document.addEventListener('click', () => dd.classList.add('hidden'));
                    refreshCount(); setInterval(refreshCount, 60000);
                })();
                </script>

                <!-- Calendar -->
                <a href="<?= CENTRYK_BASE ?>/calendar.php<?= $invCalQs ?>" title="Calendar"
                   onclick="if (window.centrykOpenCalendarDrawer) { event.preventDefault(); window.centrykOpenCalendarDrawer('<?= htmlspecialchars($invHdrUuid, ENT_QUOTES) ?>'); }"
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
                            <!-- Account -->
                            <a href="<?= CENTRYK_BASE ?>/profile.php" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white shadow-sm">
                                    <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                                </span>
                                <span class="text-xs font-medium text-slate-700">Account</span>
                            </a>
                            <?php foreach ($invHdrApps as $a): $k = (string)$a['key']; if ($k === 'centryk') continue; ?>
                                <?php if ($k === 'invoice'): ?>
                                <div class="flex flex-col items-center gap-2 rounded-xl p-3 text-center bg-slate-100 ring-1 ring-slate-200 cursor-default">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl shadow-sm" style="background:<?= e($a['color'] ?: '#10b981') ?>">
                                        <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                            <path d="M8 3h7l5 5v11a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>
                                            <path d="M15 3v6h6"/>
                                            <path d="M9 13h6"/>
                                            <path d="M9 17h6"/>
                                        </svg>
                                    </span>
                                    <span class="text-xs font-semibold text-slate-700"><?= e($a['label']) ?></span>
                                </div>
                                <?php elseif ($k === 'onepay'): ?>
                                <a href="<?= CENTRYK_BASE ?>/switch.php?app=<?= urlencode($k) . $invSwitchQs ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-purple-50 p-1.5 shadow-sm ring-1 ring-purple-100">
                                        <img src="<?= rtrim(CENTRYK_BASE, '/') ?>/assets/onepay_logo.png" alt="OnePay" class="h-full w-full object-contain">
                                    </span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php elseif ($k === 'mypay'): ?>
                                <a href="<?= CENTRYK_BASE ?>/switch.php?app=<?= urlencode($k) . $invSwitchQs ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-orange-50 p-1.5 shadow-sm ring-1 ring-orange-100">
                                        <img src="<?= rtrim(CENTRYK_BASE, '/') ?>/assets/myPay.png" alt="MyPay" class="h-full w-full object-contain">
                                    </span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php elseif ($k === 'calendar'): ?>
                                <a href="<?= CENTRYK_BASE ?>/calendar.php<?= $invCalQs ?>"
                                   onclick="if (window.centrykOpenCalendarDrawer) { event.preventDefault(); window.centrykOpenCalendarDrawer('<?= htmlspecialchars($invHdrUuid, ENT_QUOTES) ?>'); }"
                                   class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl shadow-sm" style="background:<?= e($a['color'] ?: '#14b8a6') ?>">
                                        <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                            <line x1="16" y1="2" x2="16" y2="6"/>
                                            <line x1="8" y1="2" x2="8" y2="6"/>
                                            <line x1="3" y1="10" x2="21" y2="10"/>
                                        </svg>
                                    </span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php elseif ($k === 'tv'): ?>
                                <a href="<?= CENTRYK_BASE ?>/switch.php?app=<?= urlencode($k) . $invSwitchQs ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl shadow-sm" style="background:#0f766e">
                                        <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="2" y="5" width="20" height="14" rx="2"/><path d="m10 9 5 3-5 3V9Z"/><path d="M8 21h8"/>
                                        </svg>
                                    </span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php elseif ($k === 'visionboard'): ?>
                                <a href="<?= CENTRYK_BASE ?>/switch.php?app=<?= urlencode($k) . $invSwitchQs ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl shadow-sm" style="background:<?= e($a['color'] ?: '#f43f5e') ?>">
                                        <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                                            <rect x="2" y="3" width="20" height="14" rx="2"/><path d="m10 8 5 3-5 3V8Z"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                                        </svg>
                                    </span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php else: ?>
                                <a href="<?= CENTRYK_BASE ?>/switch.php?app=<?= urlencode($k) . $invSwitchQs ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl text-lg font-black text-white shadow-sm" style="background:<?= e($a['color'] ?: '#475569') ?>"><?= e(strtoupper(substr($a['label'] ?: $k, 0, 1))) ?></span>
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
                        <div class="w-9 h-9 rounded-lg bg-emerald-600 text-white flex items-center justify-center font-black text-sm shadow shadow-emerald-100"><?= strtoupper(substr(current_user()['name'], 0, 1)) ?></div>
                        <div class="text-left hidden sm:block leading-none">
                            <p class="text-sm font-bold text-slate-800 leading-none"><?= e(current_user()['name']) ?></p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-wider text-slate-400 leading-none"><?= e($invActiveRole ?: 'Member') ?></p>
                        </div>
                        <i data-lucide="chevron-down" class="w-4 h-4 text-gray-300"></i>
                    </button>
                    <div class="dropdown-menu-bridge"></div>
                    <div class="dropdown-menu hidden absolute right-0 top-full z-50 mt-0 w-64 pt-3">
                        <div class="rounded-2xl border border-gray-100 bg-white p-3 shadow-2xl">
                            <div class="px-3 py-3 border-b border-gray-50 mb-2">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-bold text-slate-800 truncate"><?= e(current_user()['name']) ?></p>
                                    <?php if ($invActiveRole): ?>
                                    <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-emerald-600"><?= e($invActiveRole) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="mt-0.5 text-xs text-gray-400 truncate"><?= e(current_user()['email']) ?></p>
                            </div>
                            <a href="<?= CENTRYK_BASE ?>/profile.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 transition">
                                <i data-lucide="user-cog" class="w-4 h-4"></i> Manage your Centryk Account
                            </a>
                            <a href="<?= BASE_URL ?>/logout.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold text-red-600 hover:bg-red-50 transition">
                                <i data-lucide="log-out" class="w-4 h-4"></i> Sign out
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Calendar drawer (same Centryk session — no SSO token needed). Set
             CENTRYK_BASE_URL first since this page lives under /invoice-maker/,
             deeper than Centryk's own web root. -->
        <script>window.CENTRYK_BASE_URL = <?= json_encode(rtrim(CENTRYK_BASE, '/') . '/') ?>;</script>
        <?php include __DIR__ . '/../../../../public/partials/calendar_drawer.php'; ?>

        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto custom-scrollbar px-4 pb-4 pt-1 lg:px-5 lg:pb-5 lg:pt-1">
            <div class="max-w-7xl mx-auto h-full">
