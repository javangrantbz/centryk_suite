<?php
/**
 * Always-visible conference pill: shows the signed-in user's live / starting /
 * upcoming online conferences (Calendar events hosted on Centryk TV) with a
 * Join link. Self-contained markup + behaviour, polls api/conferences/mine.php.
 *
 * Included by notification_bell.php (so every hub header that has the bell gets
 * it) and by the Centryk TV page header. Safe to include more than once - only
 * the first include renders.
 *
 * Optional: define $conferencePillApi before including to override the API URL
 * (the TV pages do, since they live under /tv/).
 */
if (defined('CENTRYK_CONF_PILL_RENDERED')) {
    return;
}
define('CENTRYK_CONF_PILL_RENDERED', true);

$__confApi = $conferencePillApi ?? 'api/conferences/mine.php';
?>
<!-- Conference pill -->
<div class="relative shrink-0" id="confPillWrap" style="display:none">
    <button id="confPillBtn" type="button" title="Online conferences"
            class="flex items-center gap-1.5 rounded-xl border px-2.5 py-1.5 text-[11px] font-black transition">
        <span id="confPillDot" class="h-2 w-2 shrink-0 rounded-full"></span>
        <span id="confPillText" class="max-w-[10rem] truncate sm:max-w-[16rem]"></span>
    </button>
    <div id="confPillMenu" class="absolute right-0 top-full z-50 mt-1.5 hidden w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
        <div class="border-b border-slate-100 px-4 py-2.5 text-[10px] font-bold uppercase tracking-widest text-slate-500">Online conferences</div>
        <div id="confPillList" class="max-h-80 divide-y divide-slate-100 overflow-y-auto"></div>
    </div>
</div>

<script>
(function () {
    var API = <?= json_encode($__confApi) ?>;
    <?php if (!isset($conferencePillApi)): ?>
    if (window.__NOTIF_CFG && window.__NOTIF_CFG.apiBase) {
        API = window.__NOTIF_CFG.apiBase.replace(/notifications\/?$/, 'conferences') + '/mine.php';
    }
    <?php endif; ?>
    var wrap = document.getElementById('confPillWrap');
    var btn = document.getElementById('confPillBtn');
    var dot = document.getElementById('confPillDot');
    var text = document.getElementById('confPillText');
    var menu = document.getElementById('confPillMenu');
    var list = document.getElementById('confPillList');
    if (!wrap || !btn) return;

    var items = [];
    var skew = 0; // server clock minus browser clock, so countdowns match the server

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function nowTs() { return Math.floor(Date.now() / 1000) + skew; }

    function rel(c) {
        var d = c.start_ts - nowTs();
        if (d <= 0) return 'starting now';
        var m = Math.round(d / 60);
        if (m < 60) return 'in ' + m + ' min';
        var h = Math.floor(m / 60);
        return h < 24 ? 'in ' + h + 'h ' + (m % 60) + 'm' : c.when;
    }

    function label(c) {
        if (c.state === 'active') return 'Live';
        if (c.state === 'due') return c.start_ts <= nowTs() ? 'Starting now' : rel(c);
        return rel(c);
    }

    function render() {
        if (!items.length) { wrap.style.display = 'none'; return; }
        wrap.style.display = '';
        var top = items[0];
        var live = top.state === 'active';
        var soon = !live && top.state === 'due';
        btn.className = 'flex items-center gap-1.5 rounded-xl border px-2.5 py-1.5 text-[11px] font-black transition ' +
            (live ? 'border-rose-300 bg-rose-600 text-white hover:bg-rose-500'
                  : soon ? 'border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100'
                         : 'border-teal-200 bg-teal-50 text-teal-800 hover:bg-teal-100');
        dot.className = 'h-2 w-2 shrink-0 rounded-full ' + (live ? 'bg-white animate-pulse' : soon ? 'bg-amber-500' : 'bg-teal-500');
        text.textContent = label(top) + ' · ' + top.title + (items.length > 1 ? '  +' + (items.length - 1) : '');

        list.innerHTML = items.map(function (c) {
            var isLive = c.state === 'active';
            return '<div class="flex items-center gap-3 px-4 py-3">' +
                '<span class="mt-0.5 h-2 w-2 shrink-0 rounded-full ' + (isLive ? 'bg-rose-500' : c.state === 'due' ? 'bg-amber-500' : 'bg-teal-500') + '"></span>' +
                '<span class="min-w-0 flex-1">' +
                    '<span class="block truncate text-sm font-bold text-slate-900">' + esc(c.title) + '</span>' +
                    '<span class="block truncate text-[11px] font-semibold text-slate-500">' +
                        (isLive ? 'Live now' + (c.in_room ? ' · ' + c.in_room + ' in the room' : '') : esc(c.when) + ' · ' + esc(label(c))) +
                        (c.is_host ? ' · you are hosting' : (c.host_name ? ' · ' + esc(c.host_name) : '')) +
                    '</span>' +
                '</span>' +
                '<a href="' + esc(c.join_url) + '" class="shrink-0 rounded-lg px-3 py-1.5 text-[11px] font-black uppercase tracking-wide ' +
                    (isLive || c.state === 'due' ? 'bg-rose-600 text-white hover:bg-rose-500' : 'border border-slate-200 text-slate-600 hover:bg-slate-50') + '">' +
                    (c.is_host && !isLive ? 'Start' : 'Join') + '</a>' +
            '</div>';
        }).join('');
    }

    function poll() {
        fetch(API, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || !d.success) return;
                skew = (d.server_time || 0) - Math.floor(Date.now() / 1000);
                items = d.conferences || [];
                render();
            })
            .catch(function () {});
    }

    btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('hidden'); });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) menu.classList.add('hidden'); });

    poll();
    setInterval(poll, 30000);
    setInterval(render, 15000); // keep countdown text fresh between polls
})();
</script>
