            </div><!-- /max-w -->
        </main>
    </div><!-- /col -->
</div><!-- /flex container -->

<div id="vbToastWrap" class="pointer-events-none fixed right-6 top-6 z-[70] flex max-w-sm flex-col items-end gap-2"></div>

<script>
    if (window.lucide) lucide.createIcons();
</script>
<script>
(function () {
    const wrap = document.getElementById('vbToastWrap');
    if (!wrap) return;

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function showToast(message, type) {
        const toast = document.createElement('div');
        const isErr = type === 'error';
        toast.className = 'pointer-events-auto flex items-center gap-2.5 rounded-xl px-4 py-3 text-sm font-semibold shadow-lg transition-all duration-300 '
            + (isErr ? 'bg-rose-600 text-white' : 'bg-slate-900 text-white');
        toast.innerHTML = (isErr
            ? '<svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>'
            : '<svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>')
            + '<span>' + esc(message) + '</span>';
        wrap.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () { toast.remove(); }, 300);
        }, 4000);
    }

    document.querySelectorAll('[data-flash-toast]').forEach(function (node) {
        const message = node.getAttribute('data-flash-message') || '';
        const type = node.getAttribute('data-flash-type') || 'success';
        if (message) {
            showToast(message, type);
        }
    });
})();
</script>
<script>
// Cross-app (Centryk) notifications dropdown.
(function () {
    const CFG = { apiBase: '<?= e($CENTRYK) ?>/api/notifications', pageUrl: '<?= e($CENTRYK) ?>/notifications.php' };
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
<script src="<?= app_base() ?>/assets/js/admin.js"></script>
</body>
</html>
