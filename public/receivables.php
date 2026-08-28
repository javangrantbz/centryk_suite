<?php
/**
 * Receivables — the AR customer ledger (Centryk Business package).
 *
 * Company admins/managers see every trade customer's balance and aging, drill
 * into a statement, record receipts (auto-applied oldest-due-first) and place
 * accounts on credit hold. Gated on the 'receivables' entitlement.
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
$companies = $coStmt->fetchAll(PDO::FETCH_ASSOC);

$activeCompany = null;
if ($companies) {
    $reqCid = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    foreach ($companies as $c) {
        if ((int)$c['id'] === $reqCid) { $activeCompany = $c; break; }
    }
    if (!$activeCompany) { $activeCompany = $companies[0]; }
}

$level = $activeCompany ? Entitlements::level((int)$activeCompany['id'], 'receivables') : Entitlements::NONE;

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
    <title>Receivables</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Receivables'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-6xl px-4 pt-4 pb-14">

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-600">Centryk Business · Receivables</p>
            <h1 class="mt-0.5 text-2xl font-black tracking-tight text-slate-950">Customer ledger</h1>
        </div>
        <?php if (count($companies) > 1): ?>
            <div class="flex flex-wrap items-center gap-2">
                <?php foreach ($companies as $c): ?>
                    <a href="receivables.php?company_id=<?= (int)$c['id'] ?>"
                       class="rounded-lg border px-3 py-1.5 text-xs font-bold <?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-slate-200 bg-white text-slate-500 hover:border-violet-200' ?>">
                        <?= htmlspecialchars($c['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$companies): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center">
            <p class="text-sm font-bold text-slate-500">You need to be an admin or manager of a company to use Receivables.</p>
        </div>
    <?php elseif ($level === Entitlements::NONE): ?>
        <div class="rounded-2xl border border-violet-200 bg-white px-6 py-12 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                <i data-lucide="wallet" class="h-6 w-6"></i>
            </div>
            <h2 class="mt-4 text-lg font-black">Receivables is part of Centryk Business</h2>
            <p class="mx-auto mt-1 max-w-md text-sm font-semibold text-slate-500">
                Track what every customer owes you, age the balances, and record receipts against
                their account. Ask a Centryk advisor to switch it on for <?= htmlspecialchars($activeCompany['name']) ?>.
            </p>
            <a href="business.php?company_id=<?= (int)$activeCompany['id'] ?>" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-violet-600 px-5 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white hover:bg-violet-700">
                Explore Centryk Business
            </a>
        </div>
    <?php else: ?>

        <?php if ($level === Entitlements::READ): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800">
                Your Receivables subscription is paused — the ledger is read-only until billing is resolved.
            </div>
        <?php endif; ?>

        <div id="alert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

        <div id="agingStrip" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"></div>

        <div class="mt-4 grid gap-5 lg:grid-cols-[1fr_1fr]">
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div class="flex items-center justify-between bg-slate-50 px-4 py-2.5">
                    <span class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Customers</span>
                    <?php if ($level === Entitlements::FULL): ?>
                        <button onclick="newCustomer()" class="rounded-lg bg-slate-950 px-2.5 py-1 text-[11px] font-black text-white hover:bg-slate-800">+ New</button>
                    <?php endif; ?>
                </div>
                <div id="customerRows" class="max-h-[60vh] divide-y divide-slate-100 overflow-y-auto">
                    <div class="px-4 py-8 text-center text-sm text-slate-400">Loading…</div>
                </div>
            </div>

            <div id="statementPanel" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center text-sm text-slate-400">
                Select a customer to see their statement.
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
if (window.lucide) lucide.createIcons();
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
const CAN_WRITE = <?= $level === Entitlements::FULL ? 'true' : 'false' ?>;
let PORTFOLIO = { customers: [], totals: {} };
let OPEN_CUSTOMER = null;

const METHODS = { cash: 'Cash', card: 'Card', bank_transfer: 'Bank transfer', xfer: 'XFER', cheque: 'Cheque', other: 'Other' };

function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function m(v){ return Number(v || 0).toLocaleString('en-BZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(s){ if(!s) return '—'; return new Date(String(s).replace(' ','T')).toLocaleDateString('en-BZ',{month:'short',day:'numeric',year:'numeric'}); }

function showAlert(msg, type){
    const el = document.getElementById('alert'); if(!el) return;
    el.textContent = msg;
    el.className = 'mb-4 rounded-xl border p-3 text-sm font-semibold ' + (type==='error'
        ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700');
    el.classList.remove('hidden');
    clearTimeout(showAlert._t); showAlert._t = setTimeout(()=>el.classList.add('hidden'), 4500);
}

async function api(path, body){
    const res = await fetch('api/receivables/' + path, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ company_id: CID }, body || {})),
    });
    const data = await res.json().catch(()=>({}));
    if (!res.ok || data.success !== true) throw new Error(data.message || ('Request failed (' + res.status + ')'));
    return data;
}

async function load(){
    if (CID === null) return;
    try {
        PORTFOLIO = await api('summary.php');
        renderAging();
        renderCustomers();
        if (OPEN_CUSTOMER) openCustomer(OPEN_CUSTOMER);
    } catch (e){ showAlert(e.message, 'error'); }
}

function tile(label, value, tone){
    return `<div class="rounded-2xl border border-slate-200 bg-white p-3">
        <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-400">${esc(label)}</p>
        <p class="mt-1 text-lg font-black ${tone || ''}">${m(value)}</p>
    </div>`;
}
function renderAging(){
    const t = PORTFOLIO.totals || {};
    document.getElementById('agingStrip').innerHTML =
        tile('Outstanding', t.balance) +
        tile('Overdue', t.overdue, (t.overdue > 0 ? 'text-red-600' : '')) +
        tile('1–30', t.b_1_30) +
        tile('31–60', t.b_31_60) +
        tile('61–90', t.b_61_90) +
        tile('90+', t.b_90p, (t.b_90p > 0 ? 'text-red-600' : ''));
}

function renderCustomers(){
    const el = document.getElementById('customerRows');
    const rows = PORTFOLIO.customers || [];
    if (!rows.length){
        el.innerHTML = '<div class="px-4 py-8 text-center text-sm text-slate-400">No customers yet.</div>';
        return;
    }
    el.innerHTML = rows.map(c => `
        <button onclick="openCustomer(${c.id})" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50 ${OPEN_CUSTOMER === c.id ? 'bg-violet-50/60' : ''}">
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold">${esc(c.name)}
                    ${c.on_hold ? '<span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-black uppercase text-red-600">Hold</span>' : ''}
                    ${c.over_limit ? '<span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-black uppercase text-amber-700">Over limit</span>' : ''}
                </p>
                <p class="mt-0.5 text-[11px] font-semibold text-slate-400">
                    ${c.payment_terms_days ? 'net ' + c.payment_terms_days + 'd' : 'no terms'}${c.credit_limit != null ? ' · limit ' + m(c.credit_limit) : ''}
                </p>
            </div>
            <div class="shrink-0 text-right">
                <p class="text-sm font-black ${c.balance > 0 ? '' : 'text-emerald-600'}">${m(c.balance)}</p>
                ${c.overdue > 0 ? `<p class="text-[11px] font-bold text-red-600">${m(c.overdue)} overdue</p>` : ''}
            </div>
        </button>`).join('');
}

async function openCustomer(id){
    OPEN_CUSTOMER = id;
    renderCustomers();
    try {
        const s = await api('customer.php', { customer_id: id });
        renderStatement(s);
    } catch (e){ showAlert(e.message, 'error'); }
}

function invStatusChip(st){
    const map = { paid: 'bg-emerald-50 text-emerald-700', overdue: 'bg-red-50 text-red-600', sent: 'bg-slate-100 text-slate-500', draft: 'bg-slate-100 text-slate-400' };
    return `<span class="rounded px-1.5 py-0.5 text-[10px] font-black uppercase ${map[st] || 'bg-slate-100 text-slate-500'}">${esc(st)}</span>`;
}

function renderStatement(s){
    const c = s.customer;
    const panel = document.getElementById('statementPanel');
    panel.className = 'rounded-2xl border border-slate-200 bg-white p-5 space-y-5';

    const invoices = (s.invoices || []).map(i => `
        <div class="flex items-center gap-3 border-t border-slate-100 py-2 text-sm first:border-t-0">
            <div class="min-w-0 flex-1">
                <span class="font-bold">${esc(i.invoice_number)}</span> ${invStatusChip(i.status)}
                <span class="ml-1 text-[11px] text-slate-400">due ${fmtDate(i.effective_due)}${Number(i.days_overdue) > 0 && i.status !== 'paid' ? ' · ' + i.days_overdue + 'd late' : ''}</span>
            </div>
            <div class="shrink-0 text-right">
                <p class="font-bold">${m(i.total)}</p>
                ${Number(i.outstanding) > 0.004 && i.status !== 'paid' ? `<p class="text-[11px] text-red-600">${m(i.outstanding)} open</p>` : ''}
            </div>
        </div>`).join('') || '<p class="py-2 text-sm text-slate-400">No invoices.</p>';

    const payments = (s.payments || []).map(p => `
        <div class="flex items-center gap-3 border-t border-slate-100 py-2 text-sm first:border-t-0">
            <div class="min-w-0 flex-1">
                <span class="font-bold">${m(p.amount)}</span>
                <span class="text-[11px] text-slate-400">${esc(METHODS[p.method] || p.method)} · ${fmtDate(p.received_on)}${p.reference ? ' · ' + esc(p.reference) : ''}</span>
            </div>
            ${Number(p.amount) - Number(p.allocated) > 0.004 ? `<span class="shrink-0 text-[11px] font-bold text-sky-600">${m(Number(p.amount) - Number(p.allocated))} on account</span>` : ''}
        </div>`).join('') || '<p class="py-2 text-sm text-slate-400">No receipts.</p>';

    panel.innerHTML = `
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-black">${esc(c.name)}${c.on_hold ? ' <span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-black uppercase text-red-600 align-middle">On hold</span>' : ''}</h2>
                <p class="text-xs font-semibold text-slate-400">
                    ${c.email ? esc(c.email) + ' · ' : ''}${c.payment_terms_days ? 'net ' + c.payment_terms_days + ' days' : 'no terms'}${c.credit_limit != null ? ' · limit ' + m(c.credit_limit) : ''}
                </p>
            </div>
            <div class="text-right">
                <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-400">Balance</p>
                <p class="text-xl font-black ${s.balance > 0 ? '' : 'text-emerald-600'}">${m(s.balance)}</p>
                ${s.unallocated_credit > 0.004 ? `<p class="text-[11px] font-bold text-sky-600">incl. ${m(s.unallocated_credit)} on account</p>` : ''}
            </div>
        </div>

        ${CAN_WRITE ? `
        <div class="flex flex-wrap gap-2">
            <button onclick="paymentForm(${c.id})" class="rounded-xl bg-violet-600 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-violet-700">Record payment</button>
            <button onclick="editCustomer(${c.id})" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-slate-500 hover:border-slate-300">Edit</button>
            <button onclick="toggleHold(${c.id}, ${c.on_hold ? 'false' : 'true'})" class="rounded-xl border px-4 py-2 text-xs font-black uppercase tracking-[0.1em] ${c.on_hold ? 'border-emerald-200 text-emerald-700 hover:bg-emerald-50' : 'border-red-200 text-red-600 hover:bg-red-50'}">
                ${c.on_hold ? 'Release hold' : 'Place on hold'}
            </button>
        </div>` : ''}

        <div id="inlineForm"></div>

        <div>
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Invoices</p>
            <div class="mt-1">${invoices}</div>
        </div>
        <div>
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Receipts</p>
            <div class="mt-1">${payments}</div>
        </div>`;
}

/* ── write actions ─────────────────────────────────────────────────────── */
function paymentForm(customerId){
    const today = new Date().toISOString().slice(0,10);
    document.getElementById('inlineForm').innerHTML = `
        <form onsubmit="submitPayment(event, ${customerId})" class="rounded-xl border border-violet-200 bg-violet-50/50 p-4 space-y-3">
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-violet-700">Record payment</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block"><span class="text-[11px] font-bold text-slate-500">Amount</span>
                    <input name="amount" type="number" step="0.01" min="0.01" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
                <label class="block"><span class="text-[11px] font-bold text-slate-500">Method</span>
                    <select name="method" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold">
                        ${Object.entries(METHODS).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}
                    </select></label>
                <label class="block"><span class="text-[11px] font-bold text-slate-500">Received on</span>
                    <input name="received_on" type="date" value="${today}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
                <label class="block"><span class="text-[11px] font-bold text-slate-500">Reference</span>
                    <input name="reference" type="text" placeholder="optional" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
            </div>
            <p class="text-[11px] font-semibold text-slate-400">Applied to open invoices, oldest due first. Any surplus sits on account.</p>
            <div class="flex gap-2">
                <button type="submit" class="rounded-xl bg-violet-600 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-violet-700">Save receipt</button>
                <button type="button" onclick="document.getElementById('inlineForm').innerHTML=''" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-slate-400">Cancel</button>
            </div>
        </form>`;
}
async function submitPayment(e, customerId){
    e.preventDefault();
    const f = e.target;
    try {
        const r = await api('record_payment.php', {
            customer_id: customerId,
            amount: f.amount.value, method: f.method.value,
            received_on: f.received_on.value, reference: f.reference.value,
        });
        showAlert(`Receipt saved — applied ${m(r.allocated)} to ${r.invoices} invoice(s)` + (r.credit > 0.004 ? `, ${m(r.credit)} on account` : '') + '.', 'ok');
        document.getElementById('inlineForm').innerHTML = '';
        load();
    } catch (err){ showAlert(err.message, 'error'); }
}

function customerForm(cust){
    const c = cust || {};
    document.getElementById('inlineForm').innerHTML = `
        <form onsubmit="submitCustomer(event, ${c.id || 0})" class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-500">${c.id ? 'Edit customer' : 'New customer'}</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block"><span class="text-[11px] font-bold text-slate-500">Name</span>
                    <input name="name" required value="${esc(c.name || '')}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
                <label class="block"><span class="text-[11px] font-bold text-slate-500">Email</span>
                    <input name="email" type="email" value="${esc(c.email || '')}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
                <label class="block"><span class="text-[11px] font-bold text-slate-500">Credit limit</span>
                    <input name="credit_limit" type="number" step="0.01" min="0" value="${c.credit_limit ?? ''}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
                <label class="block"><span class="text-[11px] font-bold text-slate-500">Payment terms (days)</span>
                    <input name="payment_terms_days" type="number" min="0" value="${c.payment_terms_days ?? 0}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
                <label class="block"><span class="text-[11px] font-bold text-slate-500">Opening balance</span>
                    <input name="opening_balance" type="number" step="0.01" value="${c.opening_balance ?? 0}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="rounded-xl bg-slate-950 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-slate-800">Save</button>
                <button type="button" onclick="document.getElementById('inlineForm').innerHTML=''" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-slate-400">Cancel</button>
            </div>
        </form>`;
}
function newCustomer(){
    OPEN_CUSTOMER = null;
    document.getElementById('statementPanel').className = 'rounded-2xl border border-slate-200 bg-white p-5';
    document.getElementById('statementPanel').innerHTML = '<div id="inlineForm"></div>';
    customerForm(null);
}
function editCustomer(id){
    const c = (PORTFOLIO.customers || []).find(x => x.id === id);
    customerForm(c);
}
async function submitCustomer(e, id){
    e.preventDefault();
    const f = e.target;
    try {
        await api('save_customer.php', {
            id, name: f.name.value, email: f.email.value,
            credit_limit: f.credit_limit.value, payment_terms_days: f.payment_terms_days.value,
            opening_balance: f.opening_balance.value,
        });
        showAlert('Customer saved.', 'ok');
        load();
    } catch (err){ showAlert(err.message, 'error'); }
}
async function toggleHold(id, on){
    try {
        await api('set_hold.php', { customer_id: id, on_hold: on });
        showAlert(on ? 'Placed on credit hold.' : 'Hold released.', 'ok');
        load();
    } catch (err){ showAlert(err.message, 'error'); }
}

load();
</script>
</body>
</html>
