            </div><!-- /max-w -->
        </main>
    </div><!-- /col -->
</div><!-- /flex container -->

<script>
    if (window.lucide) lucide.createIcons();
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
