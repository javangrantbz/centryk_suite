<?php
/**
 * Shared Centryk notification bell — badge · dropdown · 60s unread poll.
 *
 * Self-contained: emits its own markup AND behaviour, so a header only needs a
 * single include wherever the bell should sit. Reads the cross-app notifications
 * table (centryk_core) for the logged-in user via api/notifications/{count,list,read}.php,
 * so every app's notifications (onepay, mypay, calendar, invoice, …) surface here.
 *
 * Optional: define window.__NOTIF_CFG before this include to point the bell at a
 * different API base / "view all" page. Defaults suit the hub public root:
 *   window.__NOTIF_CFG = { apiBase: 'api/notifications', pageUrl: 'notifications.php' };
 */
?>
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
        if (opening) {
            dd.classList.remove('hidden');
            loadList();
            document.dispatchEvent(new CustomEvent('centryk:dropdown-open', { detail: { id: dd.id } }));
        }
    });
    document.addEventListener('click', () => dd.classList.add('hidden'));
    // Close if a different header dropdown (waffle, calendar preview, user
    // menu, ...) just opened - see account_header.php for the shared convention.
    document.addEventListener('centryk:dropdown-open', e => {
        if (e.detail && e.detail.id !== dd.id) dd.classList.add('hidden');
    });

    refreshCount();
    setInterval(refreshCount, 60000);
})();
</script>
