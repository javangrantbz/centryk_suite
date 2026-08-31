<?php
/**
 * Centryk Business — Accounting: expenses / bills and vendors.
 * Recording an expense posts its journal straight away.
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
$nav = 'expenses';
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Expenses'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php
ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
$pageTitle = 'Expenses'; $headerMaxW = 'max-w-5xl'; $awCurrent = 'centryk';
include __DIR__ . '/partials/account_header.php';
$bizNav = 'accounting';
include __DIR__ . '/partials/business_sidebar.php';
?>

<div class="biz mx-auto max-w-5xl px-4 py-4">
    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">No company you manage is on the Accounting package.</div>
    <?php else: ?>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div><p class="biz-kicker">Centryk Business · Accounting</p><h1 class="mt-0.5">Expenses</h1></div>
        </div>

        <?php require __DIR__ . '/partials/accounting_nav.php'; ?>

        <div id="alert" class="biz-notice hidden mb-3"></div>
        <div id="notReady" class="biz-panel biz-panel-empty hidden">
            Accounting isn't set up for this company yet.
            <a class="biz-t-blue font-semibold" id="setupLink" href="#">Set up the books</a>.
        </div>

        <div id="wrap" class="hidden">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 mt-3 mb-3">
                <div class="biz-tile"><div class="biz-tile-l">Expenses YTD</div><div class="biz-tile-v biz-num" id="t_ytd">—</div></div>
                <div class="biz-tile"><div class="biz-tile-l">Unpaid bills</div><div class="biz-tile-v biz-num" id="t_unpaid">—</div></div>
                <div class="biz-tile"><div class="biz-tile-l">Unpaid count</div><div class="biz-tile-v biz-num" id="t_unpaid_n">—</div></div>
            </div>

            <div class="biz-tabs mb-3" id="subtabs">
                <button class="biz-tab is-active" data-t="list">Expenses</button>
                <button class="biz-tab" data-t="vendors">Vendors</button>
            </div>

            <!-- ── Expenses ─────────────────────────────────────────── -->
            <section data-panel="list">
                <div class="biz-panel mb-3">
                    <div class="biz-panel-head"><span>Record an expense</span></div>
                    <div class="biz-panel-body">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="block"><span class="biz-label">Vendor</span>
                                <input id="e_vendor" class="biz-input" list="vendorList" placeholder="Name — or pick a saved vendor">
                                <datalist id="vendorList"></datalist></label>
                            <label class="block"><span class="biz-label">Date</span>
                                <input type="date" id="e_date" class="biz-input"></label>
                            <label class="block"><span class="biz-label">Charge to account</span>
                                <select id="e_account" class="biz-select"></select></label>
                            <label class="block"><span class="biz-label">Reference (optional)</span>
                                <input id="e_ref" class="biz-input" placeholder="Invoice / receipt no."></label>
                            <label class="block sm:col-span-2"><span class="biz-label">Description</span>
                                <input id="e_desc" class="biz-input"></label>
                            <label class="block"><span class="biz-label">Net amount</span>
                                <input id="e_net" class="biz-input biz-num" inputmode="decimal" oninput="e_recalc()"></label>
                            <label class="block"><span class="biz-label">GST paid <button type="button" class="biz-t-blue" style="font-size:11px" onclick="e_gst125()">12.5%</button></span>
                                <input id="e_tax" class="biz-input biz-num" inputmode="decimal" oninput="e_recalc()"></label>
                        </div>
                        <div class="mt-2 flex flex-wrap items-end gap-3">
                            <label class="flex items-center gap-2" style="font-size:12px">
                                <input type="checkbox" id="e_paid" checked onchange="e_togglePaid()"> Paid now
                            </label>
                            <label class="block" id="e_paidFromWrap">
                                <span class="biz-label">Paid from</span>
                                <select id="e_paidFrom" class="biz-select" style="width:auto"></select></label>
                            <span class="flex-1 biz-num biz-muted" style="font-size:12px">Total <span id="e_total">0.00</span></span>
                            <button class="biz-btn biz-btn-primary" onclick="saveExpense()" id="e_save">Record</button>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-end gap-2 mb-2">
                    <label class="block"><span class="biz-label">From</span><input type="date" id="f_from" class="biz-input" style="width:auto"></label>
                    <label class="block"><span class="biz-label">To</span><input type="date" id="f_to" class="biz-input" style="width:auto"></label>
                    <label class="block"><span class="biz-label">Status</span>
                        <select id="f_status" class="biz-select" style="width:auto">
                            <option value="">All</option><option value="unpaid">Unpaid</option>
                            <option value="paid">Paid</option><option value="void">Void</option>
                        </select></label>
                    <label class="block flex-1" style="min-width:130px"><span class="biz-label">Search</span><input id="f_q" class="biz-input"></label>
                    <button class="biz-btn biz-btn-ghost biz-btn-sm" onclick="loadExpenses()">Apply</button>
                </div>
                <div id="elist" class="biz-panel"><div class="biz-panel-empty">Loading…</div></div>
            </section>

            <!-- ── Vendors ──────────────────────────────────────────── -->
            <section data-panel="vendors" class="hidden">
                <div class="biz-panel mb-3">
                    <div class="biz-panel-head"><span>Add a vendor</span></div>
                    <div class="biz-panel-body grid gap-2 sm:grid-cols-2">
                        <input type="hidden" id="v_id">
                        <label class="block"><span class="biz-label">Name</span><input id="v_name" class="biz-input"></label>
                        <label class="block"><span class="biz-label">Email</span><input id="v_email" class="biz-input"></label>
                        <label class="block"><span class="biz-label">Phone</span><input id="v_phone" class="biz-input"></label>
                        <label class="block"><span class="biz-label">TIN</span><input id="v_tin" class="biz-input"></label>
                        <label class="block"><span class="biz-label">Default account</span>
                            <select id="v_account" class="biz-select"></select></label>
                        <div class="flex items-end"><button class="biz-btn biz-btn-primary" onclick="saveVendor()" id="v_save">Save vendor</button>
                            <button class="biz-btn biz-btn-ghost ml-2" onclick="resetVendorForm()">Clear</button></div>
                    </div>
                </div>
                <div id="vlist" class="biz-panel"><div class="biz-panel-empty">Loading…</div></div>
            </section>
        </div>
    <?php endif; ?>
</div>

<script>
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
let ACCOUNTS = [], VENDORS = [];

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function money(v){ return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
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

function chargeAccounts(){
    const rank = { expense: 0, cogs: 1, asset: 2 };
    return ACCOUNTS.filter(a => a.type in rank && !a.is_control)
        .sort((x, y) => (rank[x.type] - rank[y.type]) || String(x.code).localeCompare(String(y.code)));
}
function bankAccounts(){ return ACCOUNTS.filter(a => a.type === 'asset' && !a.is_control); }

function e_recalc(){
    const net = parseFloat(document.getElementById('e_net').value) || 0;
    const tax = parseFloat(document.getElementById('e_tax').value) || 0;
    document.getElementById('e_total').textContent = money(net + tax);
}
function e_gst125(){
    const net = parseFloat(document.getElementById('e_net').value) || 0;
    document.getElementById('e_tax').value = (net * 0.125).toFixed(2);
    e_recalc();
}
function e_togglePaid(){
    document.getElementById('e_paidFromWrap').style.display = document.getElementById('e_paid').checked ? '' : 'none';
}

async function saveExpense(){
    const btn = document.getElementById('e_save');
    btn.disabled = true;
    try {
        const vname = document.getElementById('e_vendor').value.trim();
        const matched = VENDORS.find(v => v.name.toLowerCase() === vname.toLowerCase());
        const paid = document.getElementById('e_paid').checked;
        await api('expense_save.php', {
            vendor_id: matched ? matched.id : null,
            vendor_name: matched ? null : vname,
            expense_date: document.getElementById('e_date').value,
            account_id: parseInt(document.getElementById('e_account').value, 10),
            description: document.getElementById('e_desc').value.trim(),
            reference: document.getElementById('e_ref').value.trim(),
            net_amount: parseFloat(document.getElementById('e_net').value) || 0,
            tax_amount: parseFloat(document.getElementById('e_tax').value) || 0,
            status: paid ? 'paid' : 'unpaid',
            paid_from_account_id: paid ? parseInt(document.getElementById('e_paidFrom').value, 10) : null,
        });
        showAlert('Expense recorded.', 'ok');
        ['e_vendor','e_desc','e_ref','e_net','e_tax'].forEach(id => document.getElementById(id).value = '');
        e_recalc();
        loadExpenses();
    } catch (e){ showAlert(e.message); }
    btn.disabled = false;
}

async function loadExpenses(){
    const wrap = document.getElementById('elist');
    try {
        const d = await api('expenses.php', {
            from: document.getElementById('f_from').value || null,
            to: document.getElementById('f_to').value || null,
            status: document.getElementById('f_status').value || null,
            q: document.getElementById('f_q').value.trim() || null,
        });
        document.getElementById('t_ytd').textContent = money(d.summary.ytd_total);
        document.getElementById('t_unpaid').textContent = money(d.summary.unpaid_total);
        document.getElementById('t_unpaid_n').textContent = d.summary.unpaid_count;

        if (!d.expenses.length){ wrap.innerHTML = '<div class="biz-panel-empty">No expenses match.</div>'; return; }
        wrap.innerHTML = `<div class="biz-panel-head"><span>${d.expenses.length} expense(s)</span></div>` + d.expenses.map(e => `
            <div class="biz-row" style="align-items:flex-start">
                <span style="width:82px">${esc(e.expense_date)}</span>
                <span class="flex-1 min-w-0">
                    <span class="font-semibold">${esc(e.vendor_display || '—')}</span>
                    ${e.status === 'unpaid' ? '<span class="biz-chip biz-c-amber">unpaid</span>' : ''}
                    ${e.status === 'void' ? '<span class="biz-chip biz-c-slate">void</span>' : ''}
                    <span class="block biz-muted" style="font-size:11px">${esc(e.account_code)} ${esc(e.account_name)}${e.description ? ' · ' + esc(e.description) : ''}</span>
                </span>
                <span class="biz-num text-slate-500" style="width:80px;text-align:right">${money(e.net_amount)}</span>
                <span class="biz-num text-slate-400" style="width:70px;text-align:right">${e.tax_amount ? money(e.tax_amount) : ''}</span>
                <span class="biz-num" style="width:90px;text-align:right;font-weight:600">${money(e.total_amount)}</span>
                <span style="width:96px;text-align:right">
                    ${e.status === 'unpaid' ? `<button class="biz-t-blue" style="font-size:11px" onclick="payExpense(${e.id}, ${e.total_amount})">Pay</button>` : ''}
                    ${e.status !== 'void' ? `<button class="biz-t-red ml-2" style="font-size:11px" onclick="voidExpense(${e.id})">Void</button>` : ''}
                </span>
            </div>`).join('');
    } catch (e){
        if (/set up accounting/i.test(e.message)) return;
        wrap.innerHTML = `<div class="biz-panel-empty biz-t-red">${esc(e.message)}</div>`;
    }
}

async function payExpense(id, total){
    const banks = bankAccounts();
    const pick = window.prompt('Pay ' + money(total) + ' from which account?\n' +
        banks.map((a, i) => (i + 1) + ') ' + a.code + ' ' + a.name).join('\n'), '1');
    const idx = parseInt(pick, 10) - 1;
    if (isNaN(idx) || !banks[idx]) return;
    try {
        await api('expense_pay.php', { expense_id: id, paid_from_account_id: banks[idx].id });
        showAlert('Bill paid.', 'ok');
        loadExpenses();
    } catch (e){ showAlert(e.message); }
}
async function voidExpense(id){
    if (!window.confirm('Void this expense? Its journal entries will be reversed.')) return;
    try {
        await api('expense_void.php', { expense_id: id });
        showAlert('Expense voided.', 'ok');
        loadExpenses();
    } catch (e){ showAlert(e.message); }
}

// ── vendors ──
function resetVendorForm(){
    ['v_id','v_name','v_email','v_phone','v_tin'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('v_account').value = '';
}
async function saveVendor(){
    const btn = document.getElementById('v_save');
    btn.disabled = true;
    try {
        await api('vendor_save.php', {
            id: parseInt(document.getElementById('v_id').value || '0', 10),
            name: document.getElementById('v_name').value.trim(),
            email: document.getElementById('v_email').value.trim(),
            phone: document.getElementById('v_phone').value.trim(),
            tax_number: document.getElementById('v_tin').value.trim(),
            default_expense_account_id: parseInt(document.getElementById('v_account').value || '0', 10) || null,
        });
        showAlert('Vendor saved.', 'ok');
        resetVendorForm();
        await loadVendors();
    } catch (e){ showAlert(e.message); }
    btn.disabled = false;
}
function editVendor(id){
    const v = VENDORS.find(x => x.id === id);
    if (!v) return;
    document.getElementById('v_id').value = v.id;
    document.getElementById('v_name').value = v.name;
    document.getElementById('v_email').value = v.email || '';
    document.getElementById('v_phone').value = v.phone || '';
    document.getElementById('v_tin').value = v.tax_number || '';
    document.getElementById('v_account').value = v.default_expense_account_id || '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
async function loadVendors(){
    const d = await api('vendors.php');
    VENDORS = d.vendors;
    document.getElementById('vendorList').innerHTML = VENDORS.map(v => `<option value="${esc(v.name)}">`).join('');
    const wrap = document.getElementById('vlist');
    wrap.innerHTML = VENDORS.length
        ? `<div class="biz-panel-head"><span>${VENDORS.length} vendor(s)</span></div>` + VENDORS.map(v => `
            <div class="biz-row">
                <span class="flex-1"><span class="font-semibold">${esc(v.name)}</span>
                    <span class="block biz-muted" style="font-size:11px">${esc(v.email || '')} ${esc(v.phone || '')}
                        ${v.default_account_code ? ' · → ' + esc(v.default_account_code) + ' ' + esc(v.default_account_name) : ''}</span></span>
                <button class="biz-t-blue" style="font-size:11px" onclick="editVendor(${v.id})">edit</button>
            </div>`).join('')
        : '<div class="biz-panel-empty">No vendors yet.</div>';
}

async function init(){
    if (CID === null) return;
    try {
        const d = await api('accounts.php', { active_only: true });
        if (!d.activated){
            document.getElementById('notReady').classList.remove('hidden');
            document.getElementById('setupLink').href = 'accounting.php?company_id=' + CID;
            return;
        }
        ACCOUNTS = d.accounts;
        document.getElementById('wrap').classList.remove('hidden');
        document.getElementById('e_date').value = new Date().toISOString().slice(0, 10);
        document.getElementById('e_account').innerHTML = chargeAccounts()
            .map(a => `<option value="${a.id}">${esc(a.code)} · ${esc(a.name)}</option>`).join('');
        const bankOpts = bankAccounts().map(a => `<option value="${a.id}">${esc(a.code)} · ${esc(a.name)}</option>`).join('');
        document.getElementById('e_paidFrom').innerHTML = bankOpts;
        document.getElementById('v_account').innerHTML = '<option value="">—</option>' + chargeAccounts()
            .map(a => `<option value="${a.id}">${esc(a.code)} · ${esc(a.name)}</option>`).join('');
        await loadVendors();
        loadExpenses();
    } catch (e){ showAlert(e.message); }
}
init();
</script>
<?php include __DIR__ . '/partials/business_sidebar_end.php'; ?>
</body>
</html>
