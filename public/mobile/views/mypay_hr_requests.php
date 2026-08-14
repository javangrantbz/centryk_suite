<div class="px-4 py-4">
  <div class="mb-4 flex items-center justify-between">
    <div>
      <h1 class="text-lg font-black tracking-tight text-slate-900">HR Requests</h1>
      <p class="text-xs font-semibold text-slate-500">Leave requests waiting on your decision</p>
    </div>
    <button id="btnRefreshHr" type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50">
      Refresh
    </button>
  </div>

  <div id="hrError" class="mb-3 hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"></div>
  <div id="hrList" class="space-y-3">
    <div class="py-10 text-center text-sm font-semibold text-slate-400">Loading…</div>
  </div>
</div>

<!-- Decision note sheet -->
<div id="hrNoteOverlay" class="fixed inset-0 z-40 hidden bg-slate-950/50"></div>
<div id="hrNoteSheet" class="safe-bottom fixed inset-x-0 bottom-0 z-50 hidden flex-col rounded-t-3xl bg-white shadow-2xl">
  <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
    <h2 id="hrNoteTitle" class="text-sm font-black text-slate-900">Approve request</h2>
    <button id="btnCloseHrNote" type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">
      <i data-lucide="x" class="h-5 w-5"></i>
    </button>
  </div>
  <div class="space-y-3 px-5 py-4">
    <textarea id="hrNoteInput" rows="3" placeholder="Optional note…" class="w-full rounded-xl border-2 border-slate-200 px-3 py-3 text-sm font-medium outline-none focus:border-blue-500"></textarea>
    <button id="btnConfirmHrAction" type="button" class="flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3.5 text-sm font-black uppercase tracking-widest text-white">
      Confirm
    </button>
  </div>
</div>

<script>
(function () {
  const APP_COLOR = <?= json_encode($appColor) ?>;
  let pendingRequests = [];
  let actionTarget = null; // { id, action }

  function esc(v) {
    return String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
  }

  function fmtDate(v) {
    if (!v) return '';
    const d = new Date(v.replace(' ', 'T'));
    return isNaN(d) ? v : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  async function loadRequests() {
    const list = document.getElementById('hrList');
    const errBox = document.getElementById('hrError');
    errBox.classList.add('hidden');
    list.innerHTML = '<div class="py-10 text-center text-sm font-semibold text-slate-400">Loading…</div>';

    try {
      const data = await fetch('api/mypay/leave_list.php').then(r => r.json());
      if (!data.success) {
        list.innerHTML = '';
        errBox.textContent = data.message || 'Could not load requests.';
        errBox.classList.remove('hidden');
        return;
      }
      pendingRequests = (data.requests || []).filter(r => r.status === 'pending');
      renderList();
    } catch (e) {
      list.innerHTML = '';
      errBox.textContent = 'Network error loading requests.';
      errBox.classList.remove('hidden');
    }
  }

  function renderList() {
    const list = document.getElementById('hrList');
    if (!pendingRequests.length) {
      list.innerHTML = '<div class="rounded-2xl border border-dashed border-slate-200 bg-white py-10 text-center text-sm font-semibold text-slate-400">No pending requests. All caught up.</div>';
      return;
    }

    list.innerHTML = pendingRequests.map(r => `
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <div class="text-sm font-black text-slate-900">${esc(r.employee_name)}</div>
            <div class="text-xs font-bold" style="color:${APP_COLOR}">${esc(r.leave_type || 'Leave')}</div>
          </div>
          <span class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-black uppercase text-amber-700">Pending</span>
        </div>
        <div class="mt-2 text-sm font-semibold text-slate-600">${esc(fmtDate(r.start_date))} &ndash; ${esc(fmtDate(r.end_date))}</div>
        ${r.reason ? `<p class="mt-1.5 text-xs font-medium text-slate-500">${esc(r.reason)}</p>` : ''}
        <div class="mt-3 flex gap-2">
          <button type="button" onclick="window.__hrOpenNote(${r.id}, 'reject')" class="flex-1 rounded-xl border-2 border-rose-200 py-2.5 text-xs font-black uppercase tracking-widest text-rose-600 hover:bg-rose-50">Decline</button>
          <button type="button" onclick="window.__hrOpenNote(${r.id}, 'approve')" class="flex-1 rounded-xl py-2.5 text-xs font-black uppercase tracking-widest text-white" style="background:${APP_COLOR}">Approve</button>
        </div>
      </div>
    `).join('');
  }

  window.__hrOpenNote = function (id, action) {
    actionTarget = { id, action };
    document.getElementById('hrNoteTitle').textContent = action === 'approve' ? 'Approve request' : 'Decline request';
    document.getElementById('hrNoteInput').value = '';
    const btn = document.getElementById('btnConfirmHrAction');
    btn.disabled = false;
    btn.textContent = action === 'approve' ? 'Approve' : 'Decline';
    btn.style.background = action === 'approve' ? APP_COLOR : '#e11d48';
    document.getElementById('hrNoteOverlay').classList.remove('hidden');
    document.getElementById('hrNoteSheet').classList.remove('hidden');
    document.getElementById('hrNoteSheet').classList.add('flex');
  };

  function closeNoteSheet() {
    document.getElementById('hrNoteOverlay').classList.add('hidden');
    document.getElementById('hrNoteSheet').classList.add('hidden');
    document.getElementById('hrNoteSheet').classList.remove('flex');
    actionTarget = null;
  }

  document.getElementById('btnCloseHrNote').addEventListener('click', closeNoteSheet);
  document.getElementById('hrNoteOverlay').addEventListener('click', closeNoteSheet);

  document.getElementById('btnConfirmHrAction').addEventListener('click', async () => {
    if (!actionTarget) return;
    const btn = document.getElementById('btnConfirmHrAction');
    btn.disabled = true;
    try {
      const res = await fetch('api/mypay/leave_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: actionTarget.id,
          action: actionTarget.action,
          notes: document.getElementById('hrNoteInput').value,
        }),
      });
      const data = await res.json();
      if (!data.success) {
        alert(data.message || 'Could not update the request.');
        btn.disabled = false;
        return;
      }
      closeNoteSheet();
      loadRequests();
    } catch (e) {
      alert('Network error.');
      btn.disabled = false;
    }
  });

  document.getElementById('btnRefreshHr').addEventListener('click', loadRequests);

  loadRequests();
})();
</script>
