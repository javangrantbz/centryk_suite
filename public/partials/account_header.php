<?php
/**
 * Shared Centryk account-area header (logo · middle · waffle · account menu).
 * Used by the logged-in account pages (profile, companies, future settings).
 * The dashboard/launcher keeps its own header.
 *
 * Set before include (all optional except where noted):
 *   $pageTitle          — breadcrumb label shown when no middle slot is given
 *   $awCurrent          — current app key for the waffle highlight (default 'centryk')
 *   $headerMaxW         — content max width (default 'max-w-6xl')
 *   $headerMiddleHtml   — HTML injected after the logo (e.g. a company picker)
 *   $headerActionsHtml  — HTML injected before the waffle (e.g. admin links)
 *
 * JS hooks a page may define:
 *   window.centrykAppLaunch(appKey)  — custom waffle-launch handler; if absent,
 *                                      the default navigates to switch.php with
 *                                      window.CENTRYK_ACTIVE_COMPANY_UUID (if set).
 *
 * Requires Auth (the page calls Auth::start()); AuthService is pulled in here.
 */
require_once __DIR__ . '/../../app/services/AuthService.php';

$_hdrUser  = Auth::user() ?? [];
$_hdrName  = htmlspecialchars(trim(($_hdrUser['first_name'] ?? '') . ' ' . ($_hdrUser['last_name'] ?? '')));
$_hdrInit  = strtoupper(substr($_hdrUser['first_name'] ?? '?', 0, 1));
$_hdrEmail = htmlspecialchars($_hdrUser['email'] ?? '');
$_hdrAdmin = !empty($_hdrUser['is_admin']);
$_hdrMaxW  = $headerMaxW ?? 'max-w-6xl';

// Waffle config (consumed by app_switcher.php)
$awAlign   = 'right';
$awMode    = 'launch';
$awCurrent = $awCurrent ?? 'centryk';
?>
<!-- Top accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500 sticky top-0 z-50"></div>

<!-- Header -->
<header class="sticky top-[3px] z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto flex <?= htmlspecialchars($_hdrMaxW) ?> items-center gap-4 px-6 py-2.5">

        <!-- Logo -->
        <a href="index.php" class="flex shrink-0 items-center hover:opacity-80 transition-opacity">
            <img src="assets/centryk_logo.png" alt="Centryk" class="h-12 w-auto">
        </a>
        <div class="h-5 w-px bg-slate-200 shrink-0"></div>

        <?php if (!empty($headerMiddleHtml)): ?>
            <?= $headerMiddleHtml ?>
        <?php else: ?>
            <?php if (($awCurrent ?? '') === 'store'): ?>
                <a href="store.php" class="text-sm font-bold text-slate-500 transition hover:text-slate-900"><?= htmlspecialchars($pageTitle ?? '') ?></a>
            <?php else: ?>
                <span class="text-sm font-bold text-slate-400"><?= htmlspecialchars($pageTitle ?? '') ?></span>
            <?php endif; ?>
        <?php endif; ?>

        <div class="flex-1"></div>

        <?php if (!empty($headerActionsHtml)): ?><?= $headerActionsHtml ?><?php endif; ?>

        <!-- Notifications -->
        <?php include __DIR__ . '/notification_bell.php'; ?>

        <!-- Calendar preview -->
        <?php include __DIR__ . '/calendar_preview.php'; ?>

        <!-- Waffle app switcher -->
        <?php include __DIR__ . '/app_switcher.php'; ?>

        <div class="h-5 w-px bg-slate-200 shrink-0"></div>

        <!-- Account dropdown -->
        <div class="relative shrink-0" id="userMenuWrapper">
            <button id="userMenuBtn" class="flex items-center gap-2.5 rounded-xl px-3 py-2 transition hover:bg-slate-100">
                <div class="js-hdr-initial flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[12px] font-black text-slate-700"><?= $_hdrInit ?></div>
                <div class="text-left hidden sm:block">
                    <p class="js-hdr-name text-sm font-semibold text-slate-800 leading-tight"><?= $_hdrName ?></p>
                    <p class="text-[10px] text-slate-400 leading-tight"><?= $_hdrEmail ?></p>
                </div>
                <i data-lucide="chevron-down" class="h-3.5 w-3.5 text-slate-400 shrink-0"></i>
            </button>
            <div id="userMenu" class="absolute right-0 top-full mt-2 w-60 hidden rounded-2xl border border-slate-200 bg-white shadow-xl z-50 overflow-hidden">
                <div class="px-4 py-3.5 border-b border-slate-100">
                    <div class="flex items-center justify-between gap-2">
                        <p class="js-hdr-name text-sm font-bold text-slate-900 leading-tight truncate"><?= $_hdrName ?></p>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.1em] <?= $_hdrAdmin ? 'bg-violet-100 text-violet-600' : 'bg-slate-100 text-slate-500' ?>"><?= $_hdrAdmin ? 'Admin' : 'Member' ?></span>
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5 truncate"><?= $_hdrEmail ?></p>
                </div>
                <div class="p-2 space-y-0.5">
                    <a href="connections.php" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="handshake" class="h-4 w-4 shrink-0"></i> Centryk Connect
                    </a>
                    <a href="profile.php" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="user-cog" class="h-4 w-4 shrink-0"></i> Manage your Centryk Account
                    </a>
                    <button id="themeToggle" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition text-left">
                        <i data-lucide="sun"  id="themeIconSun"  class="h-4 w-4 shrink-0"></i>
                        <i data-lucide="moon" id="themeIconMoon" class="h-4 w-4 shrink-0 hidden"></i>
                        <span id="themeLabel">Light mode</span>
                    </button>
                    <button id="logoutBtn" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 transition text-left">
                        <i data-lucide="log-out" class="h-4 w-4 shrink-0"></i> Sign out
                    </button>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Calendar drawer (defines window.centrykAppLaunch → routes Calendar here). Kept
     outside <header> — that element's backdrop-blur makes it a containing block for
     position:fixed descendants, which trapped the drawer inside it. -->
<?php include __DIR__ . '/calendar_drawer.php'; ?>

<script>
// ── Shared header behaviour (waffle · account menu · theme · logout) ─────────
(function () {
    const ab = document.getElementById('appSwitcherBtn');
    const ad = document.getElementById('appSwitcherDropdown');
    if (ab && ad) ab.addEventListener('click', e => { e.stopPropagation(); ad.classList.toggle('hidden'); });

    document.querySelectorAll('.aw-app').forEach(tile => {
        tile.addEventListener('click', () => {
            if (ad) ad.classList.add('hidden');
            if (typeof window.centrykAppLaunch === 'function') { window.centrykAppLaunch(tile.dataset.app); return; }
            const uuid = window.CENTRYK_ACTIVE_COMPANY_UUID || '';
            if (tile.dataset.app === 'store') {
                window.location.href = 'store.php';
                return;
            }
            window.location.href = 'switch.php?app=' + encodeURIComponent(tile.dataset.app) + (uuid ? '&company_uuid=' + encodeURIComponent(uuid) : '');
        });
    });

    const ub = document.getElementById('userMenuBtn');
    const um = document.getElementById('userMenu');
    if (ub && um) ub.addEventListener('click', e => { e.stopPropagation(); um.classList.toggle('hidden'); });

    const atb = document.getElementById('adminToolsBtn');
    const atm = document.getElementById('adminToolsMenu');
    if (atb && atm) atb.addEventListener('click', e => { e.stopPropagation(); atm.classList.toggle('hidden'); });

    // Notification bell + calendar preview manage their own open/close — see
    // partials/notification_bell.php and partials/calendar_preview.php.

    document.addEventListener('click', () => {
        if (ad) ad.classList.add('hidden');
        if (um) um.classList.add('hidden');
        if (atm) atm.classList.add('hidden');
    });

    document.getElementById('logoutBtn')?.addEventListener('click', () => {
        fetch('api/auth/logout.php', { method: 'POST' }).finally(() => { window.location.href = 'index.php'; });
    });

    // Theme
    const sun = document.getElementById('themeIconSun');
    const moon = document.getElementById('themeIconMoon');
    const lbl = document.getElementById('themeLabel');
    function applyTheme(theme) {
        document.body.classList.toggle('light', theme === 'light');
        if (sun)  sun.classList.toggle('hidden', theme === 'light');
        if (moon) moon.classList.toggle('hidden', theme !== 'light');
        if (lbl)  lbl.textContent = theme === 'light' ? 'Dark mode' : 'Light mode';
    }
    applyTheme(localStorage.getItem('centrikyTheme') || 'dark');
    document.getElementById('themeToggle')?.addEventListener('click', () => {
        const next = document.body.classList.contains('light') ? 'dark' : 'light';
        localStorage.setItem('centrikyTheme', next);
        applyTheme(next);
        document.dispatchEvent(new CustomEvent('centryk:themechange', { detail: { theme: next } }));
        if (window.lucide) lucide.createIcons();
    });
})();
</script>
