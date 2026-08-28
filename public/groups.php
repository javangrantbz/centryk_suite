<?php
/**
 * Company groups (Centryk Business — Enterprise).
 *
 * A group admin sees a consolidated view across the member companies and
 * manages which companies and people belong to the group. Group-level packages
 * are granted by a Centryk advisor; the members inherit them.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/core/Entitlements.php';
require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/services/GroupsService.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated']) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
$user = $me['user'];

$groups = GroupsService::forUser((int)$user['id']);
$activeGroup = null;
if ($groups) {
    $reqGid = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
    foreach ($groups as $g) {
        if ((int)$g['id'] === $reqGid) { $activeGroup = $g; break; }
    }
    if (!$activeGroup) { $activeGroup = $groups[0]; }
}

$level = $activeGroup ? Entitlements::groupLevel((int)$activeGroup['id'], 'enterprise') : Entitlements::NONE;
$myRole = $activeGroup ? $activeGroup['role'] : null;

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Company Groups</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Company Groups'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-6xl px-4 pt-4 pb-14">

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-600">Centryk Business · Enterprise</p>
            <h1 class="mt-0.5 text-2xl font-black tracking-tight text-slate-950">Company groups</h1>
        </div>
        <?php if (count($groups) > 1): ?>
            <div class="flex flex-wrap items-center gap-2">
                <?php foreach ($groups as $g): ?>
                    <a href="groups.php?group_id=<?= (int)$g['id'] ?>"
                       class="rounded-lg border px-3 py-1.5 text-xs font-bold <?= $activeGroup && (int)$g['id'] === (int)$activeGroup['id'] ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-slate-200 bg-white text-slate-500 hover:border-violet-200' ?>">
                        <?= htmlspecialchars($g['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$groups): ?>
        <div class="rounded-2xl border border-violet-200 bg-white px-6 py-12 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                <i data-lucide="building-2" class="h-6 w-6"></i>
            </div>
            <h2 class="mt-4 text-lg font-black">Company groups are part of Centryk Business</h2>
            <p class="mx-auto mt-1 max-w-md text-sm font-semibold text-slate-500">
                Run several companies as one organisation — a consolidated view of receivables,
                cash on the road and bank reconciliation, and one subscription for all of them.
                Ask a Centryk advisor to set up a group.
            </p>
            <a href="business.php" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-violet-600 px-5 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white hover:bg-violet-700">
                Explore Centryk Business
            </a>
        </div>
    <?php elseif ($level === Entitlements::NONE): ?>
        <div class="rounded-2xl border border-amber-200 bg-white px-6 py-10 text-center">
            <p class="text-sm font-bold text-slate-600"><?= htmlspecialchars($activeGroup['name']) ?> doesn't have an active Enterprise subscription.</p>
            <p class="mt-1 text-xs font-semibold text-slate-400">A Centryk advisor needs to activate it before the group view is available.</p>
        </div>
    <?php else: ?>

        <?php if ($level === Entitlements::READ): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800">
                This group's Enterprise subscription is paused — the view is read-only until billing is resolved.
            </div>
        <?php endif; ?>

        <div id="alert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

        <div id="rollupStrip" class="grid grid-cols-2 gap-3 sm:grid-cols-4"></div>

        <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="bg-slate-50 px-4 py-2.5 text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">By company</div>
            <div id="companyRollup" class="divide-y divide-slate-100"><div class="px-4 py-6 text-center text-xs text-slate-400">Loading…</div></div>
        </div>

        <div class="mt-4 grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Companies</p>
                    <span id="pkgLine" class="text-[11px] font-semibold text-slate-400"></span>
                </div>
                <div id="companyList" class="mt-2 space-y-1"></div>
                <?php if ($myRole === 'group_admin' && $level === Entitlements::FULL): ?>
                <div id="attachBox" class="mt-3"></div>
                <?php endif; ?>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">People</p>
                <div id="memberList" class="mt-2 space-y-1"></div>
                <?php if ($myRole === 'group_admin' && $level === Entitlements::FULL): ?>
                <form id="addMemberForm" class="mt-3 flex gap-2">
                    <input name="email" type="email" placeholder="teammate@email.com" class="min-w-0 flex-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-semibold">
                    <select name="role" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs font-bold">
                        <option value="group_viewer">Viewer</option>
                        <option value="group_admin">Admin</option>
                    </select>
                    <button class="rounded-lg bg-slate-950 px-3 py-1.5 text-xs font-black text-white">Add</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
if (window.lucide) lucide.createIcons();
const GID = <?= $activeGroup ? (int)$activeGroup['id'] : 'null' ?>;
const IS_ADMIN = <?= $myRole === 'group_admin' ? 'true' : 'false' ?>;
const CAN_WRITE = IS_ADMIN && <?= $level === Entitlements::FULL ? 'true' : 'false' ?>;
let STATE = null;

function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function money(v){ return v === null || v === undefined ? '—' : Number(v).toLocaleString('en-BZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

function showAlert(msg, type){
    const el = document.getElementById('alert'); if(!el) return;
    el.textContent = msg;
    el.className = 'mb-4 rounded-xl border p-3 text-sm font-semibold ' + (type==='error'
        ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700');
    el.classList.remove('hidden');
    clearTimeout(showAlert._t); showAlert._t = setTimeout(()=>el.classList.add('hidden'), 5000);
}
async function api(path, body){
    const res = await fetch('api/groups/' + path, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ group_id: GID }, body || {})),
    });
    const data = await res.json().catch(()=>({}));
    if (!res.ok || data.success !== true) throw new Error(data.message || ('Request failed (' + res.status + ')'));
    return data;
}

function tile(label, value, tone){
    return `<div class="rounded-2xl border border-slate-200 bg-white p-3">
        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-400">${esc(label)}</p>
        <p class="mt-1 text-lg font-black ${tone||''}">${value}</p></div>`;
}

async function load(){
    if (GID === null) return;
    try {
        STATE = await api('overview.php');
        render();
    } catch (e){ showAlert(e.message, 'error'); }
}

function render(){
    const c = STATE.consolidated;
    const t = c.totals;
    document.getElementById('rollupStrip').innerHTML =
        tile('AR outstanding', money(t.ar_outstanding)) +
        tile('AR overdue', money(t.ar_overdue), t.ar_overdue > 0 ? 'text-red-600' : '') +
        tile('Cash in transit', money(t.cash_in_transit), t.cash_in_transit > 0 ? 'text-amber-600' : '') +
        tile('Unmatched deposits', (t.unmatched_deposits || 0) + ' · ' + money(t.unmatched_value));

    document.getElementById('companyRollup').innerHTML = c.companies.length ? c.companies.map(co => `
        <div class="grid grid-cols-2 gap-2 px-4 py-2.5 text-sm sm:grid-cols-5">
            <span class="font-bold sm:col-span-2">${esc(co.name)}</span>
            <span class="text-right text-slate-500">AR ${money(co.ar_outstanding)}</span>
            <span class="text-right text-slate-500">${co.cash_in_transit === null ? '' : 'transit ' + money(co.cash_in_transit)}</span>
            <span class="text-right text-slate-500">${co.unmatched_value === null ? '' : 'unmatched ' + money(co.unmatched_value)}</span>
        </div>`).join('') : '<div class="px-4 py-6 text-center text-xs text-slate-400">No companies in this group yet.</div>';

    const g = STATE.group;
    const pkgs = Object.keys(g.entitlements || {});
    document.getElementById('pkgLine').textContent = pkgs.length ? 'group packages: ' + pkgs.join(', ') : 'no group packages';

    document.getElementById('companyList').innerHTML = (g.companies || []).map(co => `
        <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2">
            <div class="min-w-0">
                <p class="truncate text-sm font-bold">${esc(co.name)}</p>
                <p class="text-[11px] font-semibold text-slate-400">${Object.keys(co.entitlements || {}).join(', ') || 'no packages'}</p>
            </div>
            ${CAN_WRITE ? `<button onclick="detachCompany(${co.id})" class="shrink-0 text-[11px] font-black uppercase text-slate-400 hover:text-red-600">Remove</button>` : ''}
        </div>`).join('') || '<p class="text-xs text-slate-400">No companies yet.</p>';

    if (CAN_WRITE) {
        const box = document.getElementById('attachBox');
        if (box) {
            box.innerHTML = (STATE.attachable || []).length
                ? `<div class="flex gap-2"><select id="attachSel" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm font-semibold">
                      ${STATE.attachable.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}
                   </select><button onclick="attachCompany()" class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-black text-white">Add</button></div>`
                : '<p class="text-[11px] text-slate-400">No unassigned companies you admin.</p>';
        }
    }

    document.getElementById('memberList').innerHTML = (g.members || []).map(mem => `
        <div class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2">
            <div class="min-w-0">
                <p class="truncate text-sm font-bold">${esc(mem.name || mem.email)}</p>
                <p class="text-[11px] font-semibold text-slate-400">${esc(mem.email)} · ${mem.role === 'group_admin' ? 'Admin' : 'Viewer'}</p>
            </div>
            ${CAN_WRITE ? `<div class="flex shrink-0 gap-1">
                <button onclick="setMember(${mem.user_id}, '${mem.role === 'group_admin' ? 'group_viewer' : 'group_admin'}')" class="text-[11px] font-black uppercase text-slate-400 hover:text-violet-700">${mem.role === 'group_admin' ? 'Make viewer' : 'Make admin'}</button>
                <button onclick="setMember(${mem.user_id}, 'remove')" class="text-[11px] font-black uppercase text-slate-400 hover:text-red-600">Remove</button>
            </div>` : ''}
        </div>`).join('');
}

async function detachCompany(id){
    try { await api('company.php', { company_id: id, action: 'detach' }); showAlert('Company removed.', 'ok'); load(); }
    catch (e){ showAlert(e.message, 'error'); }
}
async function attachCompany(){
    const id = parseInt(document.getElementById('attachSel').value, 10);
    try { await api('company.php', { company_id: id, action: 'attach' }); showAlert('Company added.', 'ok'); load(); }
    catch (e){ showAlert(e.message, 'error'); }
}
async function setMember(userId, role){
    try { await api('member_set.php', { user_id: userId, role }); showAlert('Updated.', 'ok'); load(); }
    catch (e){ showAlert(e.message, 'error'); }
}
const amf = document.getElementById('addMemberForm');
if (amf) amf.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
        await api('member_set.php', { email: amf.email.value, role: amf.role.value });
        showAlert('Member added.', 'ok'); amf.reset(); load();
    } catch (err){ showAlert(err.message, 'error'); }
});

load();
</script>
</body>
</html>
