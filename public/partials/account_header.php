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
$_hdrShowBell = !isset($headerShowBell) || $headerShowBell;
$_hdrShowCalendar = !isset($headerShowCalendar) || $headerShowCalendar;

// Waffle config (consumed by app_switcher.php)
$awAlign   = 'right';
$awMode    = 'launch';
$awCurrent = $awCurrent ?? 'centryk';
?>
<style>
    @keyframes centryk-logo-settle {
        0%   { opacity: 0; transform: translateY(-2px) scale(0.965); filter: saturate(0.92); }
        62%  { opacity: 1; transform: translateY(0) scale(1.018);  filter: saturate(1.03); }
        100% { opacity: 1; transform: translateY(0) scale(1);      filter: saturate(1); }
    }
    @keyframes centryk-logo-sheen {
        0%, 14% { opacity: 0; transform: translateX(-135%) skewX(-18deg); }
        32%     { opacity: 0.34; }
        100%    { opacity: 0; transform: translateX(165%) skewX(-18deg); }
    }
    .centryk-logo-lockup {
        position: relative;
        overflow: hidden;
    }
    .centryk-logo-lockup::after {
        content: "";
        position: absolute;
        inset: -10% auto -10% -35%;
        width: 32%;
        background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.72) 50%, transparent 100%);
        opacity: 0;
        pointer-events: none;
        animation: centryk-logo-sheen 900ms cubic-bezier(0.22, 1, 0.36, 1) 420ms 1 both;
    }
    .centryk-logo-mark {
        transform-origin: center left;
        animation: centryk-logo-settle 520ms cubic-bezier(0.22, 1, 0.36, 1) 1 both;
    }
    @media (prefers-reduced-motion: reduce) {
        .centryk-logo-lockup::after,
        .centryk-logo-mark {
            animation: none !important;
        }
    }
</style>
<!-- Top accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500 sticky top-0 z-50"></div>

<!-- Header -->
<header class="sticky top-[3px] z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto flex <?= htmlspecialchars($_hdrMaxW) ?> items-center gap-4 px-6 py-2.5">

        <!-- Logo -->
        <a href="index.php" class="centryk-logo-lockup flex shrink-0 items-center hover:opacity-80 transition-opacity">
            <img src="assets/centryk_logo.png" alt="Centryk" class="centryk-logo-mark h-12 w-auto">
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

        <?php if ($_hdrShowBell): ?>
        <!-- Notifications -->
        <?php include __DIR__ . '/notification_bell.php'; ?>
        <?php endif; ?>

        <?php if ($_hdrShowCalendar): ?>
        <!-- Calendar preview -->
        <?php include __DIR__ . '/calendar_preview.php'; ?>
        <?php endif; ?>

        <!-- Waffle app switcher -->
        <?php include __DIR__ . '/app_switcher.php'; ?>

        <div class="h-5 w-px bg-slate-200 shrink-0"></div>

        <!-- Account dropdown (or a Log in prompt on a public page with no session, e.g. store.php for a visitor) -->
        <?php if (empty($_hdrUser)): ?>
        <a href="login.php" class="shrink-0 rounded-xl bg-slate-950 px-4 py-2 text-sm font-bold text-white transition hover:bg-slate-800">
            Log in
        </a>
        <?php else: ?>
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
                    <a href="business.php" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="briefcase" class="h-4 w-4 shrink-0"></i> Centryk Business
                    </a>
                    <a href="business_fiscal.php" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="file-check-2" class="h-4 w-4 shrink-0"></i> BTS E-Invoicing
                    </a>
                    <a href="profile.php" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="user-cog" class="h-4 w-4 shrink-0"></i> Manage your Centryk Account
                    </a>
                    <button id="logoutBtn" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 transition text-left">
                        <i data-lucide="log-out" class="h-4 w-4 shrink-0"></i> Sign out
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</header>

<!-- Calendar drawer (defines window.centrykAppLaunch → routes Calendar here). Kept
     outside <header> — that element's backdrop-blur makes it a containing block for
     position:fixed descendants, which trapped the drawer inside it. -->
<?php if ($_hdrShowCalendar): ?>
<?php include __DIR__ . '/calendar_drawer.php'; ?>
<?php endif; ?>

<script>
// ── Shared header behaviour (waffle · account menu · theme · logout) ─────────
(function () {
    // The open/close toggle for #appSwitcherBtn is owned by app_switcher.php's
    // own inline script (it's a reusable partial included on many pages, not
    // just this header) - binding it again here double-fired on every click:
    // one listener opened the dropdown, the other immediately closed it right
    // back, so the waffle looked like it did nothing.
    const ad = document.getElementById('appSwitcherDropdown');

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
    if (ub && um) ub.addEventListener('click', e => {
        e.stopPropagation();
        const opening = um.classList.contains('hidden');
        um.classList.toggle('hidden');
        if (opening) document.dispatchEvent(new CustomEvent('centryk:dropdown-open', { detail: { id: um.id } }));
    });

    const atb = document.getElementById('adminToolsBtn');
    const atm = document.getElementById('adminToolsMenu');
    if (atb && atm) atb.addEventListener('click', e => {
        e.stopPropagation();
        const opening = atm.classList.contains('hidden');
        atm.classList.toggle('hidden');
        if (opening) document.dispatchEvent(new CustomEvent('centryk:dropdown-open', { detail: { id: atm.id } }));
    });

    // Notification bell + calendar preview manage their own open/close — see
    // partials/notification_bell.php and partials/calendar_preview.php. Every
    // header dropdown (including those two) fires 'centryk:dropdown-open' the
    // moment it opens, and every dropdown listens for that event to close
    // itself if it wasn't the one that opened - each partial stays
    // self-contained (no hard dependency on the others existing on a given
    // page) while still behaving as one mutually-exclusive group when they
    // do co-occur, here in the shared header.
    document.addEventListener('click', () => {
        if (ad) ad.classList.add('hidden');
        if (um) um.classList.add('hidden');
        if (atm) atm.classList.add('hidden');
    });
    document.addEventListener('centryk:dropdown-open', e => {
        const openedId = e.detail && e.detail.id;
        if (ad && openedId !== ad.id) ad.classList.add('hidden');
        if (um && openedId !== um.id) um.classList.add('hidden');
        if (atm && openedId !== atm.id) atm.classList.add('hidden');
    });

    document.getElementById('logoutBtn')?.addEventListener('click', () => {
        fetch('api/auth/logout.php', { method: 'POST' }).finally(() => { window.location.href = 'index.php'; });
    });

    // Theme — the dark theme is unfinished (chrome goes dark, content stays
    // light), so it's disabled for now: force light and clear any stored 'dark'.
    document.body.classList.add('light');
    document.body.classList.remove('dark');
    try { localStorage.setItem('centrikyTheme', 'light'); } catch (e) {}
})();
</script>
