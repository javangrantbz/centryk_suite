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
    <div class="mx-auto flex <?= htmlspecialchars($_hdrMaxW) ?> items-center gap-4 px-6 py-3">

        <!-- Logo -->
        <a href="index.php" class="flex shrink-0 items-center hover:opacity-80 transition-opacity">
            <img src="../centryk_logo.png" alt="Centryk" class="h-12 w-auto">
        </a>
        <div class="h-5 w-px bg-slate-200 shrink-0"></div>

        <?php if (!empty($headerMiddleHtml)): ?>
            <?= $headerMiddleHtml ?>
        <?php else: ?>
            <span class="text-sm font-bold text-slate-400"><?= htmlspecialchars($pageTitle ?? '') ?></span>
        <?php endif; ?>

        <div class="flex-1"></div>

        <?php if (!empty($headerActionsHtml)): ?><?= $headerActionsHtml ?><?php endif; ?>

        <!-- Calendar preview -->
        <div class="relative shrink-0" id="calPreviewWrap">
            <button id="calPreviewBtn" title="Upcoming events"
                    class="flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-teal-50 hover:text-teal-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
            </button>
            <div id="calPreviewDropdown" class="absolute right-0 top-full z-50 mt-1.5 hidden w-80 rounded-xl border border-slate-200 bg-white shadow-xl overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Upcoming events</span>
                    <a href="calendar.php" class="text-[11px] font-bold text-teal-600 hover:text-teal-700">Open Calendar &rarr;</a>
                </div>
                <div id="calPreviewBody" class="max-h-80 overflow-y-auto p-2">
                    <p class="px-3 py-6 text-center text-xs text-slate-400">Loading&hellip;</p>
                </div>
            </div>
        </div>

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
            window.location.href = 'switch.php?app=' + encodeURIComponent(tile.dataset.app) + (uuid ? '&company_uuid=' + encodeURIComponent(uuid) : '');
        });
    });

    const ub = document.getElementById('userMenuBtn');
    const um = document.getElementById('userMenu');
    if (ub && um) ub.addEventListener('click', e => { e.stopPropagation(); um.classList.toggle('hidden'); });

    // Calendar preview — lazy-loads the user's upcoming events on first open.
    const cb = document.getElementById('calPreviewBtn');
    const cd = document.getElementById('calPreviewDropdown');
    let calLoaded = false;
    if (cb && cd) cb.addEventListener('click', e => {
        e.stopPropagation();
        cd.classList.toggle('hidden');
        if (!cd.classList.contains('hidden')) loadCalPreview();
    });
    function loadCalPreview() {
        if (calLoaded) return;
        calLoaded = true;
        const body = document.getElementById('calPreviewBody');
        fetch('api/calendar/upcoming-mine.php')
            .then(r => r.json())
            .then(d => {
                const evts = (d && d.events) || [];
                body.innerHTML = evts.length
                    ? evts.map(calPreviewRow).join('')
                    : '<p class="px-3 py-6 text-center text-xs text-slate-400">No upcoming events.</p>';
            })
            .catch(() => { calLoaded = false; body.innerHTML = '<p class="px-3 py-6 text-center text-xs text-slate-400">Couldn\'t load events.</p>'; });
    }
    function calPreviewRow(ev) {
        const d   = new Date((ev.event_date || '') + 'T00:00:00');
        const mon = isNaN(d) ? '' : d.toLocaleString('en-US', { month: 'short' });
        const day = isNaN(d) ? '' : d.getDate();
        const esc = s => String(s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        return '<div class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-slate-50">' +
            '<div class="flex flex-col items-center justify-center h-11 w-11 shrink-0 rounded-lg bg-teal-50 text-teal-700">' +
                '<span class="text-[9px] font-black uppercase leading-none">' + mon + '</span>' +
                '<span class="text-base font-black leading-none mt-0.5">' + day + '</span>' +
            '</div>' +
            '<div class="min-w-0">' +
                '<p class="text-sm font-bold text-slate-800 truncate">' + esc(ev.title) + '</p>' +
                '<p class="text-[11px] font-semibold text-slate-400 capitalize">' + esc(ev.event_type || 'event') + '</p>' +
            '</div>' +
        '</div>';
    }

    document.addEventListener('click', () => {
        if (ad) ad.classList.add('hidden');
        if (um) um.classList.add('hidden');
        if (cd) cd.classList.add('hidden');
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
