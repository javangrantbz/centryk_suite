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

        <!-- Notifications -->
        <div class="relative shrink-0" id="notifWrap">
            <button id="notifBtn" title="Notifications"
                    class="relative flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-orange-50 hover:text-orange-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
                </svg>
                <span id="notifBadge" class="hidden absolute -top-1 -right-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">0</span>
            </button>
            <div id="notifDropdown" class="absolute right-0 top-full z-50 mt-1.5 hidden w-80 rounded-xl border border-slate-200 bg-white shadow-xl overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Notifications</span>
                    <a href="notifications.php" class="text-[11px] font-bold text-orange-600 hover:text-orange-700">View all &rarr;</a>
                </div>
                <div id="notifBody" class="max-h-96 overflow-y-auto p-2">
                    <p class="px-3 py-6 text-center text-xs text-slate-400">Loading&hellip;</p>
                </div>
            </div>
        </div>

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

    const atb = document.getElementById('adminToolsBtn');
    const atm = document.getElementById('adminToolsMenu');
    if (atb && atm) atb.addEventListener('click', e => { e.stopPropagation(); atm.classList.toggle('hidden'); });

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

<!-- Notification bell behaviour (shared across Centryk apps). -->
<script>
(function () {
    const CFG = window.__NOTIF_CFG || { apiBase: 'api/notifications', pageUrl: 'notifications.php' };
    const btn = document.getElementById('notifBtn');
    const dd = document.getElementById('notifDropdown');
    const badge = document.getElementById('notifBadge');
    const body = document.getElementById('notifBody');
    if (!btn || !dd || !badge || !body) return;

    const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    function setBadge(n) {
        n = parseInt(n, 10) || 0;
        if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.classList.remove('hidden'); }
        else { badge.classList.add('hidden'); }
    }

    function timeAgo(ts) {
        const d = new Date((ts || '').replace(' ', 'T'));
        if (isNaN(d)) return '';
        const s = Math.max(1, Math.floor((Date.now() - d.getTime()) / 1000));
        if (s < 60) return s + 's ago';
        const m = Math.floor(s / 60); if (m < 60) return m + 'm ago';
        const h = Math.floor(m / 60); if (h < 24) return h + 'h ago';
        const dy = Math.floor(h / 24); if (dy < 7) return dy + 'd ago';
        return d.toLocaleDateString();
    }

    function row(n) {
        const unread = !n.read_at;
        const accent = n.color && /^#/.test(n.color) ? n.color : '#f97316';
        const href = n.url ? esc(n.url) : (CFG.pageUrl);
        return '<a href="' + href + '" class="flex gap-3 px-3 py-2.5 rounded-lg hover:bg-slate-50 ' + (unread ? 'bg-orange-50/40' : '') + '">' +
            '<span class="mt-1 h-2 w-2 shrink-0 rounded-full" style="background:' + (unread ? accent : 'transparent') + '"></span>' +
            '<span class="min-w-0 flex-1">' +
                '<span class="block text-sm font-semibold text-slate-800 truncate">' + esc(n.title) + '</span>' +
                (n.body ? '<span class="block text-[11px] text-slate-500 line-clamp-2">' + esc(n.body) + '</span>' : '') +
                '<span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">' + esc(n.app_key || '') + ' · ' + timeAgo(n.created_at) + '</span>' +
            '</span>' +
        '</a>';
    }

    function refreshCount() {
        fetch(CFG.apiBase + '/count.php', { credentials: 'same-origin' })
            .then(r => r.json()).then(d => { if (d && d.success) setBadge(d.unread_count); })
            .catch(() => {});
    }

    function loadList() {
        body.innerHTML = '<p class="px-3 py-6 text-center text-xs text-slate-400">Loading…</p>';
        fetch(CFG.apiBase + '/list.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                const items = (d && d.notifications) || [];
                body.innerHTML = items.length
                    ? items.map(row).join('')
                    : '<p class="px-3 py-6 text-center text-xs text-slate-400">You\'re all caught up.</p>';
                // Opening the panel clears the "new" badge.
                if (d && d.unread_count > 0) {
                    fetch(CFG.apiBase + '/read.php', { method: 'POST', credentials: 'same-origin' })
                        .then(r => r.json()).then(() => setBadge(0)).catch(() => {});
                } else {
                    setBadge(0);
                }
            })
            .catch(() => { body.innerHTML = '<p class="px-3 py-6 text-center text-xs text-slate-400">Couldn\'t load notifications.</p>'; });
    }

    btn.addEventListener('click', e => {
        e.stopPropagation();
        const opening = dd.classList.contains('hidden');
        dd.classList.add('hidden');
        if (opening) { dd.classList.remove('hidden'); loadList(); }
    });
    document.addEventListener('click', () => dd.classList.add('hidden'));

    refreshCount();
    setInterval(refreshCount, 60000);
})();
</script>
