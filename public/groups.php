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
<head><?php $bizTitle = 'Company Groups'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Company Groups'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; $bizNav = 'groups'; include __DIR__ . '/partials/business_sidebar.php'; ?>

<div class="biz mx-auto max-w-6xl px-4 py-4">

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Centryk Business · Enterprise</p>
            <h1 class="mt-0.5">Company groups</h1>
        </div>
        <?php if (count($groups) > 1): ?>
            <div class="biz-seg">
                <?php foreach ($groups as $g): ?>
                    <a href="groups.php?group_id=<?= (int)$g['id'] ?>"
                       class="<?= $activeGroup && (int)$g['id'] === (int)$activeGroup['id'] ? 'is-active' : '' ?>">
                        <?= htmlspecialchars($g['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$groups): ?>
        <div class="biz-panel" style="padding:28px 16px;text-align:center">
            <div style="margin:0 auto;display:flex;height:36px;width:36px;align-items:center;justify-content:center;border-radius:4px;background:#eef2ff;color:#4f46e5">
                <i data-lucide="building-2" style="height:18px;width:18px"></i>
            </div>
            <h2 style="margin-top:10px;font-size:15px">Company groups are part of Centryk Business</h2>
            <p class="biz-muted" style="margin:4px auto 0;max-width:30rem;font-size:12px">
                Run several companies as one organisation — a consolidated view of receivables,
                cash on the road and bank reconciliation, and one subscription for all of them.
                Ask a Centryk advisor to set up a group.
            </p>
            <a href="business.php" class="biz-btn biz-btn-primary" style="margin-top:12px">Explore Centryk Business</a>
        </div>
    <?php elseif ($level === Entitlements::NONE): ?>
        <div class="biz-panel biz-panel-empty">
            <?= htmlspecialchars($activeGroup['name']) ?> doesn't have an active Enterprise subscription.
            <span class="block" style="margin-top:3px">A Centryk advisor needs to activate it before the group view is available.</span>
        </div>
    <?php else: ?>

        <?php if ($level === Entitlements::READ): ?>
            <div class="biz-notice biz-notice-amber mb-3">This group's Enterprise subscription is paused — the view is read-only until billing is resolved.</div>
        <?php endif; ?>

        <div id="alert" class="biz-notice mb-3 hidden"></div>

        <div id="rollupStrip" class="grid grid-cols-2 gap-2 sm:grid-cols-4"></div>

        <div class="biz-panel mt-3">
            <div class="biz-panel-head">
                <span>By company</span>
                <?php if ($activeGroup): ?>
                <a href="groups_aging.php?group_id=<?= (int)$activeGroup['id'] ?>" target="_blank" rel="noopener" class="biz-btn biz-btn-ghost biz-btn-sm">Consolidated aging</a>
                <?php endif; ?>
            </div>
            <div id="companyRollup" class="biz-list"><div class="biz-panel-empty">Loading…</div></div>
        </div>

        <details class="biz-panel mt-3" ontoggle="if(this.open) loadActivity()">
            <summary style="cursor:pointer;padding:6px 10px;font-size:12px;font-weight:600;color:#334155">Activity across the group</summary>
            <div id="activityRows" class="biz-list" style="max-height:44vh;overflow-y:auto"><div class="biz-panel-empty">Open to load…</div></div>
        </details>

        <div class="mt-3 grid gap-3 lg:grid-cols-2">
            <div class="biz-panel">
                <div class="biz-panel-head">
                    <span>Companies</span>
                    <span id="pkgLine" style="font-weight:600;text-transform:none;letter-spacing:0"></span>
                </div>
                <div id="companyList" class="biz-list"></div>
                <?php if ($myRole === 'group_admin' && $level === Entitlements::FULL): ?>
                <div id="attachBox" class="biz-panel-body" style="border-top:1px solid var(--bz-line)"></div>
                <?php endif; ?>
            </div>

            <div class="biz-panel">
                <div class="biz-panel-head">People</div>
                <div id="memberList" class="biz-list"></div>
                <?php if ($myRole === 'group_admin' && $level === Entitlements::FULL): ?>
                <form id="addMemberForm" class="biz-panel-body flex gap-2" style="border-top:1px solid var(--bz-line)">
                    <input name="email" type="email" placeholder="teammate@email.com" class="biz-input min-w-0 flex-1">
                    <select name="role" class="biz-select" style="width:auto">
                        <option value="group_viewer">Viewer</option>
                        <option value="group_admin">Admin</option>
                    </select>
                    <button class="biz-btn biz-btn-primary">Add</button>
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
    el.className = 'biz-notice mb-3 ' + (type === 'error' ? 'biz-notice-red' : 'biz-notice-green');
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
    return `<div class="biz-tile"><div class="biz-tile-l">${esc(label)}</div><div class="biz-tile-v ${tone || ''}">${value}</div></div>`;
}

async function load(){
    if (GID === null) return;
    try { STATE = await api('overview.php'); render(); }
    catch (e){ showAlert(e.message, 'error'); }
}

let ACTIVITY_LOADED = false;
async function loadActivity(){
    if (ACTIVITY_LOADED) return;
    ACTIVITY_LOADED = true;
    try {
        const d = await api('activity.php');
        const rows = d.activity || [];
        document.getElementById('activityRows').innerHTML = rows.length ? rows.map(a => `
            <div class="biz-row" style="cursor:default;font-size:12px">
                <span class="min-w-0 flex-1">
                    <span class="block">${esc(a.summary)}</span>
                    <span class="block biz-muted" style="font-size:11px">${a.company_name ? esc(a.company_name) + ' · ' : ''}${a.actor_name ? esc(a.actor_name) + ' · ' : ''}${new Date(String(a.created_at).replace(' ','T')).toLocaleString('en-BZ',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})}</span>
                </span>
            </div>`).join('') : '<div class="biz-panel-empty">No activity yet.</div>';
    } catch (e){ ACTIVITY_LOADED = false; showAlert(e.message, 'error'); }
}

function render(){
    const c = STATE.consolidated, t = c.totals;
    document.getElementById('rollupStrip').innerHTML =
        tile('AR outstanding', money(t.ar_outstanding)) +
        tile('AR overdue', money(t.ar_overdue), t.ar_overdue > 0 ? 'biz-t-red' : '') +
        tile('Cash in transit', money(t.cash_in_transit), t.cash_in_transit > 0 ? 'biz-t-amber' : '') +
        tile('Unmatched deposits', (t.unmatched_deposits || 0) + ' · ' + money(t.unmatched_value));

    document.getElementById('companyRollup').innerHTML = c.companies.length ? c.companies.map(co => `
        <div class="biz-row" style="cursor:default;font-size:12px">
            <span class="min-w-0 flex-1 truncate" style="font-weight:600">${esc(co.name)}</span>
            <span class="biz-muted biz-num">AR ${money(co.ar_outstanding)}</span>
            <span class="biz-muted biz-num">${co.cash_in_transit === null ? '' : 'transit ' + money(co.cash_in_transit)}</span>
            <span class="biz-muted biz-num">${co.unmatched_value === null ? '' : 'unmatched ' + money(co.unmatched_value)}</span>
        </div>`).join('') : '<div class="biz-panel-empty">No companies in this group yet.</div>';

    const g = STATE.group;
    const pkgs = Object.keys(g.entitlements || {});
    document.getElementById('pkgLine').textContent = pkgs.length ? pkgs.join(', ') : 'no group packages';

    document.getElementById('companyList').innerHTML = (g.companies || []).map(co => `
        <div class="biz-row" style="cursor:default">
            <span class="min-w-0 flex-1">
                <span class="block truncate" style="font-weight:600">${esc(co.name)}</span>
                <span class="block biz-muted" style="font-size:11px">${Object.keys(co.entitlements || {}).join(', ') || 'no packages'}</span>
            </span>
            ${CAN_WRITE ? `<button onclick="detachCompany(${co.id})" class="biz-btn biz-btn-ghost biz-btn-sm shrink-0">Remove</button>` : ''}
        </div>`).join('') || '<div class="biz-panel-empty">No companies yet.</div>';

    if (CAN_WRITE) {
        const box = document.getElementById('attachBox');
        if (box) {
            box.innerHTML = (STATE.attachable || []).length
                ? `<div class="flex gap-2"><select id="attachSel" class="biz-select min-w-0 flex-1">
                      ${STATE.attachable.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}
                   </select><button onclick="attachCompany()" class="biz-btn biz-btn-primary">Add</button></div>`
                : '<p class="biz-muted" style="font-size:11px">No unassigned companies you admin.</p>';
        }
    }

    document.getElementById('memberList').innerHTML = (g.members || []).map(mem => `
        <div class="biz-row" style="cursor:default">
            <span class="min-w-0 flex-1">
                <span class="block truncate" style="font-weight:600">${esc(mem.name || mem.email)}</span>
                <span class="block biz-muted" style="font-size:11px">${esc(mem.email)} · ${mem.role === 'group_admin' ? 'Admin' : 'Viewer'}</span>
            </span>
            ${CAN_WRITE ? `<span class="flex shrink-0 gap-1">
                <button onclick="setMember(${mem.user_id}, '${mem.role === 'group_admin' ? 'group_viewer' : 'group_admin'}')" class="biz-btn biz-btn-ghost biz-btn-sm">${mem.role === 'group_admin' ? 'Viewer' : 'Admin'}</button>
                <button onclick="setMember(${mem.user_id}, 'remove')" class="biz-btn biz-btn-danger biz-btn-sm">Remove</button>
            </span>` : ''}
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
<?php include __DIR__ . '/partials/business_sidebar_end.php'; ?>
</body>
</html>
