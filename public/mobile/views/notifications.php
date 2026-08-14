<div class="px-4 py-4">
  <div class="mb-4 flex items-center justify-between">
    <div>
      <h1 class="text-lg font-black tracking-tight text-slate-900">Notifications</h1>
      <p class="text-xs font-semibold text-slate-500">Across every app you use</p>
    </div>
    <button id="btnMarkAllRead" type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50">
      Mark all read
    </button>
  </div>

  <div id="notifList" class="space-y-2">
    <div class="py-10 text-center text-sm font-semibold text-slate-400">Loading…</div>
  </div>
</div>

<script>
(function () {
  function esc(v) {
    return String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
  }

  function timeAgo(v) {
    if (!v) return '';
    const d = new Date(v.replace(' ', 'T'));
    if (isNaN(d)) return v;
    const mins = Math.floor((Date.now() - d.getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + 'm ago';
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + 'h ago';
    return Math.floor(hrs / 24) + 'd ago';
  }

  async function loadNotifications() {
    const list = document.getElementById('notifList');
    try {
      const data = await fetch('api/notifications/list.php').then(r => r.json());
      if (!data.success) {
        list.innerHTML = `<div class="py-10 text-center text-sm font-semibold text-rose-500">${esc(data.message || 'Could not load notifications.')}</div>`;
        return;
      }
      const items = data.notifications || [];
      if (!items.length) {
        list.innerHTML = '<div class="rounded-2xl border border-dashed border-slate-200 bg-white py-10 text-center text-sm font-semibold text-slate-400">Nothing yet.</div>';
        return;
      }
      list.innerHTML = items.map(n => {
        const unread = !n.read_at;
        const color = n.color || '#2563eb';
        const body = n.url
          ? `<a href="${esc(n.url)}" target="_blank" rel="noopener" class="block">${renderBody(n, color, unread)}</a>`
          : renderBody(n, color, unread);
        return body;
      }).join('');
    } catch (e) {
      list.innerHTML = '<div class="py-10 text-center text-sm font-semibold text-rose-500">Network error.</div>';
    }
  }

  function renderBody(n, color, unread) {
    return `
      <div class="flex items-start gap-3 rounded-2xl border p-3.5 ${unread ? 'border-slate-200 bg-white shadow-sm' : 'border-slate-100 bg-slate-50/60'}">
        <span class="mt-0.5 h-2 w-2 shrink-0 rounded-full" style="background:${unread ? color : 'transparent'}"></span>
        <div class="min-w-0 flex-1">
          <div class="text-sm font-black text-slate-900">${esc(n.title)}</div>
          ${n.body ? `<p class="mt-0.5 text-xs font-medium text-slate-500">${esc(n.body)}</p>` : ''}
          <div class="mt-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">${esc(n.app_key || '')} &middot; ${esc(timeAgo(n.created_at))}</div>
        </div>
      </div>`;
  }

  document.getElementById('btnMarkAllRead').addEventListener('click', async () => {
    await fetch('api/notifications/mark_read.php', { method: 'POST' });
    loadNotifications();
  });

  loadNotifications();
})();
</script>
