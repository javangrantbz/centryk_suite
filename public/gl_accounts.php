<?php
/**
 * Centryk Business — Accounting: chart of accounts, CSV import, and the
 * control-account map (which GL account each subledger posts to).
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/core/Entitlements.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated']) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
$user = $me['user'];
$pdo  = DB::pdo();

$coStmt = $pdo->prepare("
    SELECT c.id, c.name
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.user_id = :uid AND cm.status = 'active' AND cm.role IN ('admin','manager') AND c.status = 'active'
    ORDER BY c.name ASC
");
$coStmt->execute(['uid' => (int)$user['id']]);
$all = $coStmt->fetchAll(PDO::FETCH_ASSOC);
$companies = array_values(array_filter(
    $all,
    static fn ($c) => Entitlements::level((int)$c['id'], 'accounting') !== Entitlements::NONE
));

$activeCompany = null;
if ($companies) {
    $reqCid = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    foreach ($companies as $c) {
        if ((int)$c['id'] === $reqCid) { $activeCompany = $c; break; }
    }
    if (!$activeCompany) { $activeCompany = $companies[0]; }
}
$nav = 'accounts';
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Chart of Accounts'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php
ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
$pageTitle = 'Chart of Accounts'; $headerMaxW = 'max-w-5xl'; $awCurrent = 'centryk';
include __DIR__ . '/partials/account_header.php';
$bizNav = 'accounting';
include __DIR__ . '/partials/business_sidebar.php';
?>

<div class="biz mx-auto max-w-5xl px-4 py-4">
    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">No company you manage is on the Accounting package.</div>
    <?php else: ?>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div><p class="biz-kicker">Centryk Business · Accounting</p><h1 class="mt-0.5">Chart of accounts</h1></div>
            <?php if (count($companies) > 1): ?>
            <div class="biz-seg">
                <?php foreach ($companies as $c): ?>
                    <a href="gl_accounts.php?company_id=<?= (int)$c['id'] ?>"
                       class="<?= (int)$c['id'] === (int)$activeCompany['id'] ? 'is-active' : '' ?>"><?= htmlspecialchars($c['name']) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php require __DIR__ . '/partials/accounting_nav.php'; ?>

        <div id="alert" class="biz-notice hidden mb-3"></div>
        <div id="notReady" class="biz-panel biz-panel-empty hidden">
            Accounting isn't set up for this company yet.
            <a class="biz-t-blue font-semibold" id="setupLink" href="#">Set up the books</a>.
        </div>

        <div id="wrap" class="hidden">
            <div class="biz-tabs mt-3 mb-3" id="subtabs">
                <button class="biz-tab is-active" data-t="chart">Accounts</button>
                <button class="biz-tab" data-t="map">Control accounts</button>
                <button class="biz-tab" data-t="import">Import</button>
            </div>

            <!-- ── Chart ─────────────────────────────────────────────── -->
            <section data-panel="chart">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <label class="flex items-center gap-2" style="font-size:12px">
                        <input type="checkbox" id="showInactive"> Show inactive
                    </label>
                    <button class="biz-btn biz-btn-primary biz-btn-sm" onclick="openAccountForm()">New account</button>
                </div>
                <div id="chart" class="biz-panel"><div class="biz-panel-empty">Loading…</div></div>
            </section>

            <!-- ── Control map ───────────────────────────────────────── -->
            <section data-panel="map" class="hidden" id="mapPanel">
                <p class="biz-muted mb-2" style="font-size:12px">
                    The subledgers post to these accounts. Required ones must be set before AR, GST and
                    payroll can post cleanly.
                </p>
                <div id="mapList" class="biz-panel"><div class="biz-panel-empty">Loading…</div></div>
            </section>

            <!-- ── Import ────────────────────────────────────────────── -->
            <section data-panel="import" class="hidden">
                <div class="biz-panel">
                    <div class="biz-panel-head"><span>Import a chart of accounts (CSV)</span></div>
                    <div class="biz-panel-body">
                        <p class="biz-muted mb-2" style="font-size:12px">
                            Header row, any order: <code>code</code>, <code>name</code>, <code>type</code>
                            (asset / liability / equity / income / cogs / expense), and optionally
                            <code>subtype</code>, <code>parent_code</code>, <code>normal_balance</code>.
                            QuickBooks account-list exports work — types are mapped by name. Existing codes
                            are updated; nothing is deleted.
                        </p>
                        <textarea id="csv" class="biz-input" rows="8" placeholder="code,name,type&#10;1000,Cash,asset&#10;4000,Sales,income"></textarea>
                        <div class="mt-2"><button class="biz-btn biz-btn-primary" onclick="doImport()" id="importBtn">Import</button></div>
                        <div id="importResult" class="mt-2" style="font-size:12px"></div>
                    </div>
                </div>
            </section>
        </div>
    <?php endif; ?>
</div>

<!-- account editor -->
<div id="acctModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/40 p-4">
    <div class="biz w-full max-w-md rounded bg-white shadow-xl">
        <div class="biz-panel-head"><span id="acctModalTitle">New account</span>
            <button onclick="closeAccountForm()" class="biz-muted" style="font-size:16px;line-height:1">&times;</button></div>
        <div class="biz-panel-body space-y-2">
            <input type="hidden" id="a_id">
            <div class="grid grid-cols-2 gap-2">
                <label class="block"><span class="biz-label">Code</span><input id="a_code" class="biz-input"></label>
                <label class="block"><span class="biz-label">Type</span>
                    <select id="a_type" class="biz-select" onchange="a_syncNormal()">
                        <option value="asset">Asset</option><option value="liability">Liability</option>
                        <option value="equity">Equity</option><option value="income">Income</option>
                        <option value="cogs">Cost of sales</option><option value="expense">Expense</option>
                    </select></label>
            </div>
            <label class="block"><span class="biz-label">Name</span><input id="a_name" class="biz-input"></label>
            <div class="grid grid-cols-2 gap-2">
                <label class="block"><span class="biz-label">Subtype (optional)</span><input id="a_subtype" class="biz-input"></label>
                <label class="block"><span class="biz-label">Normal balance</span>
                    <select id="a_normal" class="biz-select"><option value="debit">Debit</option><option value="credit">Credit</option></select></label>
            </div>
            <label class="block"><span class="biz-label">Rolls up to (optional)</span>
                <select id="a_parent" class="biz-select"><option value="">—</option></select></label>
            <p id="a_lock" class="biz-muted hidden" style="font-size:11px">This is a system account — code and type are fixed.</p>
        </div>
        <div class="biz-panel-body flex justify-end gap-2" style="border-top:1px solid var(--bz-line)">
            <button onclick="closeAccountForm()" class="biz-btn biz-btn-ghost">Cancel</button>
            <button onclick="saveAccount()" class="biz-btn biz-btn-primary" id="a_save">Save</button>
        </div>
    </div>
</div>

<script>
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
let ACCOUNTS = [], MAP = {}, SLOTS = [];
const TYPE_ORDER = ['asset','liability','equity','income','cogs','expense'];
const TYPE_LABEL = { asset:'Assets', liability:'Liabilities', equity:'Equity', income:'Income', cogs:'Cost of sales', expense:'Expenses' };
const NORMAL_BY_TYPE = { asset:'debit', expense:'debit', cogs:'debit', liability:'credit', equity:'credit', income:'credit' };

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function showAlert(msg, kind){
    const el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'biz-notice mb-3 ' + (kind === 'ok' ? 'biz-notice-green' : 'biz-notice-red');
    el.classList.remove('hidden');
    if (kind === 'ok') setTimeout(() => el.classList.add('hidden'), 4000);
}
async function api(path, body){
    const res = await fetch('api/accounting/' + path, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ company_id: CID }, body || {})),
    });
    const d = await res.json();
    if (!d.success) throw new Error(d.message || 'Request failed.');
    return d;
}

document.querySelectorAll('#subtabs .biz-tab').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('#subtabs .biz-tab').forEach(x => x.classList.toggle('is-active', x === b));
    document.querySelectorAll('[data-panel]').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== b.dataset.t));
}));
document.getElementById('showInactive').addEventListener('change', renderChart);

async function load(){
    if (CID === null) return;
    try {
        const d = await api('accounts.php');
        if (!d.activated){
            document.getElementById('notReady').classList.remove('hidden');
            document.getElementById('wrap').classList.add('hidden');
            document.getElementById('setupLink').href = 'accounting.php?company_id=' + CID;
            return;
        }
        ACCOUNTS = d.accounts; MAP = d.map; SLOTS = d.slots;
        document.getElementById('wrap').classList.remove('hidden');
        document.getElementById('notReady').classList.add('hidden');
        renderChart(); renderMap();
        if (location.hash === '#map') document.querySelector('#subtabs .biz-tab[data-t="map"]').click();
    } catch (e){
        showAlert(e.message);
    }
}

function renderChart(){
    const showInactive = document.getElementById('showInactive').checked;
    const wrap = document.getElementById('chart');
    if (!ACCOUNTS.length){
        wrap.innerHTML = '<div class="biz-panel-empty">No accounts yet. Import a chart, or add accounts one at a time.</div>';
        return;
    }
    let html = '';
    for (const t of TYPE_ORDER){
        const rows = ACCOUNTS.filter(a => a.type === t && (showInactive || a.is_active));
        if (!rows.length) continue;
        html += `<div class="biz-panel-head"><span>${TYPE_LABEL[t]}</span></div>`;
        html += rows.map(a => `
            <div class="biz-row" style="align-items:flex-start">
                <span class="biz-num text-slate-500" style="width:56px">${esc(a.code)}</span>
                <span class="flex-1 min-w-0">
                    ${esc(a.name)} ${a.is_active ? '' : '<span class="biz-chip biz-c-slate">inactive</span>'}
                    ${a.is_control ? '<span class="biz-chip biz-c-slate">control</span>' : ''}
                    ${a.is_system ? '<span class="biz-chip biz-c-blue">system</span>' : ''}
                    ${(a.slots || []).map(s => `<span class="biz-chip biz-c-green">${esc(s)}</span>`).join('')}
                    ${a.parent_code ? `<span class="biz-muted" style="font-size:11px">↳ ${esc(a.parent_code)}</span>` : ''}
                </span>
                <span class="biz-muted" style="font-size:11px;width:44px">${esc(a.normal_balance)}</span>
                <button class="biz-t-blue" style="font-size:11px" onclick='openAccountForm(${a.id})'>edit</button>
                ${a.is_system || !a.is_active ? '' : `<button class="biz-t-red" style="font-size:11px" onclick="archiveAccount(${a.id})">archive</button>`}
            </div>`).join('');
    }
    wrap.innerHTML = html;
}

function renderMap(){
    const wrap = document.getElementById('mapList');
    const postable = ACCOUNTS.filter(a => a.is_active);
    wrap.innerHTML = SLOTS.map(s => {
        const cur = MAP[s.slot] || '';
        const options = ['<option value="">— not set —</option>'].concat(
            postable.map(a => `<option value="${a.id}" ${a.id === cur ? 'selected' : ''}>${esc(a.code)} · ${esc(a.name)}</option>`)
        ).join('');
        return `<div class="biz-row">
            <span class="flex-1">${esc(s.label)} ${s.required ? '<span class="biz-chip biz-c-amber">required</span>' : ''}
                <span class="biz-muted" style="font-size:11px">${esc(s.slot)}</span></span>
            <select class="biz-select" style="width:260px" onchange="setMap('${s.slot}', this.value)">${options}</select>
        </div>`;
    }).join('');
}

async function setMap(slot, accountId){
    if (!accountId) return;
    try {
        await api('map_save.php', { slot, account_id: parseInt(accountId, 10) });
        await load();
        showAlert('Control account set.', 'ok');
    } catch (e){ showAlert(e.message); }
}

// ── account editor ──
function fillParents(exceptId){
    const sel = document.getElementById('a_parent');
    sel.innerHTML = '<option value="">—</option>' + ACCOUNTS
        .filter(a => a.id !== exceptId)
        .map(a => `<option value="${a.id}">${esc(a.code)} · ${esc(a.name)}</option>`).join('');
}
function a_syncNormal(){
    document.getElementById('a_normal').value = NORMAL_BY_TYPE[document.getElementById('a_type').value] || 'debit';
}
function openAccountForm(id){
    const a = id ? ACCOUNTS.find(x => x.id === id) : null;
    document.getElementById('acctModalTitle').textContent = a ? ('Edit ' + a.code) : 'New account';
    document.getElementById('a_id').value = a ? a.id : '';
    document.getElementById('a_code').value = a ? a.code : '';
    document.getElementById('a_name').value = a ? a.name : '';
    document.getElementById('a_type').value = a ? a.type : 'asset';
    document.getElementById('a_subtype').value = a ? (a.subtype || '') : '';
    document.getElementById('a_normal').value = a ? a.normal_balance : 'debit';
    fillParents(a ? a.id : 0);
    document.getElementById('a_parent').value = a && a.parent_id ? a.parent_id : '';
    const locked = !!(a && a.is_system);
    document.getElementById('a_code').disabled = locked;
    document.getElementById('a_type').disabled = locked;
    document.getElementById('a_lock').classList.toggle('hidden', !locked);
    const m = document.getElementById('acctModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeAccountForm(){
    const m = document.getElementById('acctModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
async function saveAccount(){
    const btn = document.getElementById('a_save');
    btn.disabled = true;
    try {
        await api('account_save.php', {
            id: parseInt(document.getElementById('a_id').value || '0', 10),
            code: document.getElementById('a_code').value.trim(),
            name: document.getElementById('a_name').value.trim(),
            type: document.getElementById('a_type').value,
            subtype: document.getElementById('a_subtype').value.trim(),
            normal_balance: document.getElementById('a_normal').value,
            parent_id: parseInt(document.getElementById('a_parent').value || '0', 10) || null,
        });
        closeAccountForm();
        await load();
        showAlert('Account saved.', 'ok');
    } catch (e){ showAlert(e.message); }
    btn.disabled = false;
}
async function archiveAccount(id){
    const a = ACCOUNTS.find(x => x.id === id);
    if (!a || !window.confirm('Make ' + a.code + ' ' + a.name + ' inactive?')) return;
    try {
        await api('account_archive.php', { account_id: id });
        await load();
        showAlert('Account made inactive.', 'ok');
    } catch (e){ showAlert(e.message); }
}

async function doImport(){
    const btn = document.getElementById('importBtn');
    btn.disabled = true;
    document.getElementById('importResult').textContent = '';
    try {
        const { result } = await api('import_accounts.php', { csv: document.getElementById('csv').value });
        const errs = (result.errors || []).map(e => `<li>${esc(e)}</li>`).join('');
        document.getElementById('importResult').innerHTML =
            `<span class="biz-t-green">${result.created} created, ${result.updated} updated</span>` +
            (result.skipped ? ` · <span class="biz-t-red">${result.skipped} skipped</span>` : '') +
            (errs ? `<ul class="mt-1 biz-muted" style="list-style:disc;margin-left:16px">${errs}</ul>` : '');
        await load();
    } catch (e){ document.getElementById('importResult').innerHTML = `<span class="biz-t-red">${esc(e.message)}</span>`; }
    btn.disabled = false;
}

load();
</script>
<?php include __DIR__ . '/partials/business_sidebar_end.php'; ?>
</body>
</html>
