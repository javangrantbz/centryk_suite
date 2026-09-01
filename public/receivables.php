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
    SELECT c.id, c.name, cm.role
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
$isCompanyAdmin = $activeCompany && ($activeCompany['role'] ?? '') === 'admin';

$level = $activeCompany ? Entitlements::level((int)$activeCompany['id'], 'receivables') : Entitlements::NONE;

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Receivables'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Receivables'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; $bizNav = 'receivables'; include __DIR__ . '/partials/business_sidebar.php'; ?>

<div class="biz mx-auto max-w-6xl px-4 py-4">

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Centryk Business · Receivables</p>
            <h1 class="mt-0.5">Customer ledger</h1>
        </div>
    </div>

    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">You need to be an admin or manager of a company to use Receivables.</div>
    <?php elseif ($level === Entitlements::NONE): ?>
        <div class="biz-panel" style="padding:28px 16px;text-align:center">
            <div style="margin:0 auto;display:flex;height:36px;width:36px;align-items:center;justify-content:center;border-radius:4px;background:#eef2ff;color:#4f46e5">
                <i data-lucide="wallet" style="height:18px;width:18px"></i>
            </div>
            <h2 style="margin-top:10px;font-size:15px">Receivables is part of Centryk Business</h2>
            <p class="biz-muted" style="margin:4px auto 0;max-width:26rem;font-size:12px">
                Track what every customer owes you, age the balances, and record receipts against
                their account. Turn it on for <?= htmlspecialchars($activeCompany['name']) ?> yourself on Centryk
                Business — a Centryk advisor can also help.
            </p>
            <a href="business.php?company_id=<?= (int)$activeCompany['id'] ?>" class="biz-btn biz-btn-primary" style="margin-top:12px">Turn on Receivables</a>
        </div>
    <?php else: ?>

        <?php if ($level === Entitlements::READ): ?>
            <div class="biz-notice biz-notice-amber mb-3">Your Receivables subscription is paused — the ledger is read-only until billing is resolved.</div>
        <?php endif; ?>

        <div id="alert" class="biz-notice mb-3 hidden"></div>

        <div id="agingStrip" class="grid grid-cols-3 gap-2 sm:grid-cols-6"></div>

        <div class="mt-3 grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div class="biz-panel self-start">
                <div class="biz-panel-head">
                    <span class="biz-seg" style="text-transform:none;letter-spacing:0">
                        <button id="viewLedger" class="is-active" onclick="setView('ledger')">Customers</button>
                        <button id="viewCollections" onclick="setView('collections')">Collections</button>
                        <button id="viewCheques" onclick="setView('cheques')">Cheques</button>
                    </span>
                    <span class="flex gap-1">
                        <a href="receivables_aging.php?company_id=<?= (int)$activeCompany['id'] ?>" target="_blank" rel="noopener" class="biz-btn biz-btn-ghost biz-btn-sm">Aging report</a>
                        <?php if ($level === Entitlements::FULL): ?>
                        <button onclick="statementRun()" class="biz-btn biz-btn-ghost biz-btn-sm">Send statements</button>
                        <button onclick="toggleImport()" class="biz-btn biz-btn-ghost biz-btn-sm">Import</button>
                        <button onclick="newCustomer()" class="biz-btn biz-btn-ghost biz-btn-sm">+ New</button>
                        <?php endif; ?>
                    </span>
                </div>
                <div id="importBox" class="biz-panel-body hidden" style="border-bottom:1px solid var(--bz-line);background:var(--bz-head)">
                    <p class="biz-kicker">Import customers</p>
                    <p class="biz-muted" style="font-size:11px;margin-top:2px">
                        CSV with a header row. Columns: <strong>name</strong> (required), company, email, phone,
                        credit_limit, payment_terms_days, opening_balance. Existing accounts (matched by name) are updated.
                    </p>
                    <input type="file" id="importFile" accept=".csv,text/csv" class="biz-input mt-2" style="padding:3px">
                    <textarea id="importText" rows="4" placeholder="…or paste CSV here" class="biz-input mt-2"></textarea>
                    <div class="mt-2 flex gap-2">
                        <button onclick="doImportCustomers()" class="biz-btn biz-btn-primary biz-btn-sm">Import</button>
                        <button onclick="toggleImport()" class="biz-btn biz-btn-ghost biz-btn-sm">Cancel</button>
                    </div>
                </div>
                <div id="customerRows" class="biz-list max-h-[62vh] overflow-y-auto">
                    <div class="biz-panel-empty">Loading…</div>
                </div>
            </div>

            <div id="statementPanel" class="biz-panel biz-panel-empty self-start">Select a customer to see their statement.</div>
        </div>

    <?php endif; ?>
</div>

<script>
if (window.lucide) lucide.createIcons();
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
const CAN_WRITE = <?= $level === Entitlements::FULL ? 'true' : 'false' ?>;
const IS_ADMIN = <?= $isCompanyAdmin ? 'true' : 'false' ?>;
let PORTFOLIO = { customers: [], totals: {} };
let COLLECTIONS = [];
let VIEW = 'ledger';
let OPEN_CUSTOMER = null;

const METHODS = { cash: 'Cash', card: 'Card', bank_transfer: 'Bank transfer', xfer: 'XFER', cheque: 'Cheque', other: 'Other' };

function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function m(v){ return Number(v || 0).toLocaleString('en-BZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(s){ if(!s) return '—'; return new Date(String(s).replace(' ','T')).toLocaleDateString('en-BZ',{year:'2-digit',month:'short',day:'numeric'}); }

function showAlert(msg, type){
    const el = document.getElementById('alert'); if(!el) return;
    el.textContent = msg;
    el.className = 'biz-notice mb-3 ' + (type === 'error' ? 'biz-notice-red' : 'biz-notice-green');
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
        if (VIEW === 'collections') {
            COLLECTIONS = (await api('collections.php')).accounts || [];
            renderCollections();
        } else if (VIEW === 'cheques') {
            await loadCheques();
        } else {
            renderCustomers();
        }
        if (OPEN_CUSTOMER && VIEW !== 'cheques') openCustomer(OPEN_CUSTOMER);
    } catch (e){ showAlert(e.message, 'error'); }
}

function setView(v){
    VIEW = v;
    document.getElementById('viewLedger').classList.toggle('is-active', v === 'ledger');
    document.getElementById('viewCollections').classList.toggle('is-active', v === 'collections');
    document.getElementById('viewCheques').classList.toggle('is-active', v === 'cheques');
    load();
}

let CHEQUE_STATUS = 'pending';
async function loadCheques(){
    const el = document.getElementById('customerRows');
    const d = await api('cheques.php', { status: CHEQUE_STATUS });
    const s = d.summary || {};
    const seg = ['pending', 'cleared', 'bounced'].map(k =>
        `<button onclick="CHEQUE_STATUS='${k}';load()" class="${CHEQUE_STATUS === k ? 'is-active' : ''}">${k[0].toUpperCase() + k.slice(1)}</button>`).join('');
    const head = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line)">
            <div class="grid grid-cols-3 gap-2">
                ${tile('Uncleared', s.pending_value, s.pending_count ? 'biz-t-amber' : '')}
                ${tile('Post-dated', s.postdated_value)}
                ${tile('Bounced (12m)', s.bounced_12m_value, s.bounced_12m_count ? 'biz-t-red' : '')}
            </div>
            <span class="biz-seg mt-2" style="text-transform:none;letter-spacing:0">${seg}</span>
        </div>`;
    const rows = (d.cheques || []).map(c => `
        <div class="biz-row" style="display:block;font-size:12px">
            <div class="flex items-start justify-between gap-2">
                <span class="min-w-0 flex-1">
                    <span style="font-weight:600">${esc(c.customer_name)}</span>
                    ${c.post_dated ? '<span class="biz-chip biz-c-blue">post-dated</span>' : ''}
                    <span class="block biz-muted" style="font-size:11px">
                        cheque ${esc(c.cheque_number || '—')}${c.cheque_bank ? ' · ' + esc(c.cheque_bank) : ''}
                        · received ${fmtDate(c.received_on)}${c.cheque_date ? ' · dated ' + fmtDate(c.cheque_date) : ''}
                        ${c.clearance_status === 'pending' ? ' · held ' + c.days_held + 'd' : ''}
                        ${c.clearance_status === 'cleared' ? ' · cleared ' + fmtDate(c.cleared_on) : ''}
                        ${c.clearance_status === 'bounced' && c.bounce_reason ? ' · ' + esc(c.bounce_reason) : ''}
                    </span>
                </span>
                <span class="shrink-0 biz-num ${c.clearance_status === 'bounced' ? 'biz-t-red' : ''}" style="font-weight:700">${m(c.amount)}</span>
            </div>
            ${CAN_WRITE && c.clearance_status === 'pending' ? `
            <div class="mt-1 flex gap-2">
                <button onclick="clearCheque(${c.id})" class="biz-btn biz-btn-primary biz-btn-sm">Mark cleared</button>
                <button onclick="bounceCheque(${c.id})" class="biz-btn biz-btn-danger biz-btn-sm">Bounced</button>
            </div>` : ''}
        </div>`).join('') || `<div class="biz-panel-empty">No ${CHEQUE_STATUS} cheques.</div>`;
    el.innerHTML = head + rows;
}
async function clearCheque(id){
    const on = prompt('Date the cheque cleared:', new Date().toISOString().slice(0,10));
    if (on === null) return;
    try { await api('cheque_clear.php', { payment_id: id, cleared_on: on }); showAlert('Cheque cleared.', 'ok'); load(); }
    catch (e){ showAlert(e.message, 'error'); }
}
async function bounceCheque(id){
    const reason = prompt('This reverses the receipt and the customer owes again.\nReason the cheque bounced:');
    if (reason === null) return;
    try { await api('cheque_bounce.php', { payment_id: id, reason }); showAlert('Cheque recorded as bounced — customer balance restored.', 'ok'); load(); }
    catch (e){ showAlert(e.message, 'error'); }
}

function renderCollections(){
    const el = document.getElementById('customerRows');
    if (!COLLECTIONS.length){ el.innerHTML = '<div class="biz-panel-empty">Nothing overdue. 🎉</div>'; return; }
    el.innerHTML = COLLECTIONS.map(c => `
        <button onclick="openCustomer(${c.id})" class="biz-row ${OPEN_CUSTOMER === c.id ? 'is-active' : ''}">
            <span class="min-w-0 flex-1">
                <span class="block truncate" style="font-weight:600">${esc(c.name)}
                    ${c.on_hold ? '<span class="biz-chip biz-c-red">Hold</span>' : ''}
                </span>
                <span class="block biz-muted" style="font-size:11px">
                    ${c.oldest_days}d overdue · ${c.overdue_invoices} inv${c.reminder_count ? ` · chased ${fmtDate(c.last_reminder_at)}` : ' · not chased'}
                </span>
            </span>
            <span class="shrink-0 text-right">
                <span class="block biz-num biz-t-red" style="font-weight:700">${m(c.overdue_total)}</span>
                ${c.reminder_count ? `<span class="block biz-muted" style="font-size:11px">${c.reminder_count} reminder${c.reminder_count > 1 ? 's' : ''}</span>` : ''}
            </span>
        </button>`).join('');
}

function tile(label, value, tone){
    return `<div class="biz-tile"><div class="biz-tile-l">${esc(label)}</div><div class="biz-tile-v ${tone || ''}">${m(value)}</div></div>`;
}
function renderAging(){
    const t = PORTFOLIO.totals || {};
    document.getElementById('agingStrip').innerHTML =
        tile('Outstanding', t.balance) +
        tile('Overdue', t.overdue, (t.overdue > 0 ? 'biz-t-red' : '')) +
        tile('1–30', t.b_1_30) +
        tile('31–60', t.b_31_60) +
        tile('61–90', t.b_61_90) +
        tile('90+', t.b_90p, (t.b_90p > 0 ? 'biz-t-red' : ''));
}

function renderCustomers(){
    const el = document.getElementById('customerRows');
    const rows = PORTFOLIO.customers || [];
    if (!rows.length){ el.innerHTML = '<div class="biz-panel-empty">No customers yet.</div>'; return; }
    el.innerHTML = rows.map(c => `
        <button onclick="openCustomer(${c.id})" class="biz-row ${OPEN_CUSTOMER === c.id ? 'is-active' : ''}">
            <span class="min-w-0 flex-1">
                <span class="block truncate" style="font-weight:600">${esc(c.name)}
                    ${c.on_hold ? '<span class="biz-chip biz-c-red">Hold</span>' : ''}
                    ${c.over_limit ? '<span class="biz-chip biz-c-amber">Over limit</span>' : ''}
                </span>
                <span class="block biz-muted" style="font-size:11px">
                    ${c.payment_terms_days ? 'net ' + c.payment_terms_days + 'd' : 'no terms'}${c.credit_limit != null ? ' · limit ' + m(c.credit_limit) : ''}
                </span>
            </span>
            <span class="shrink-0 text-right">
                <span class="block biz-num ${c.balance > 0 ? '' : 'biz-t-green'}" style="font-weight:700">${m(c.balance)}</span>
                ${c.overdue > 0 ? `<span class="block biz-num biz-t-red" style="font-size:11px;font-weight:600">${m(c.overdue)} overdue</span>` : ''}
            </span>
        </button>`).join('');
}

async function openCustomer(id){
    OPEN_CUSTOMER = id;
    renderCustomers();
    try { renderStatement(await api('customer.php', { customer_id: id })); }
    catch (e){ showAlert(e.message, 'error'); }
}

function invChip(st){
    const map = { paid: 'biz-c-green', overdue: 'biz-c-red', sent: 'biz-c-slate', draft: 'biz-c-slate', written_off: 'biz-c-amber' };
    const label = st === 'written_off' ? 'written off' : st;
    return `<span class="biz-chip ${map[st] || 'biz-c-slate'}">${esc(label)}</span>`;
}
const WO_KIND = { bad_debt: 'Bad debt', damaged_goods: 'Damaged / expired goods', price_adjustment: 'Price adjustment', other: 'Other' };

function renderStatement(s){
    const c = s.customer;
    const panel = document.getElementById('statementPanel');
    panel.className = 'biz-panel self-start';

    const invoices = (s.invoices || []).map(i => {
        const open = ['sent', 'overdue'].includes(i.status);
        return `
        <div class="biz-row" style="font-size:12px;display:block">
          <div class="flex items-start justify-between gap-2">
            <span class="min-w-0 flex-1">
                <span style="font-weight:600${i.status === 'written_off' ? ';text-decoration:line-through;opacity:.7' : ''}">${esc(i.invoice_number)}</span> ${invChip(i.status)}
                <span class="biz-muted" style="font-size:11px">&nbsp;due ${fmtDate(i.effective_due)}${Number(i.days_overdue) > 0 && open ? ' · ' + i.days_overdue + 'd late' : ''}</span>
            </span>
            <span class="shrink-0 text-right">
                <span class="block biz-num" style="font-weight:600">${m(i.total)}</span>
                ${Number(i.outstanding) > 0.004 && i.status !== 'paid' && i.status !== 'written_off' ? `<span class="block biz-num biz-t-red" style="font-size:11px">${m(i.outstanding)} open</span>` : ''}
            </span>
          </div>
          ${CAN_WRITE && open && Number(i.outstanding) > 0.004
            ? `<button onclick='writeoffForm(${i.id}, ${JSON.stringify(i.invoice_number)}, ${Number(i.outstanding)})' class="biz-btn biz-btn-ghost biz-btn-sm mt-1">Write off</button>
               <div id="woForm-${i.id}"></div>` : ''}
        </div>`;
    }).join('') || '<div class="biz-panel-empty">No invoices.</div>';

    const payments = (s.payments || []).map(p => {
        const chip = p.clearance_status === 'pending' ? '<span class="biz-chip biz-c-amber">uncleared</span>'
            : p.clearance_status === 'bounced' ? '<span class="biz-chip biz-c-red">bounced</span>' : '';
        return `
        <div class="biz-row" style="font-size:12px">
            <span class="min-w-0 flex-1">
                <span class="biz-num" style="font-weight:600;${p.clearance_status === 'bounced' ? 'text-decoration:line-through;opacity:.6' : ''}">${m(p.amount)}</span> ${chip}
                <span class="biz-muted" style="font-size:11px">&nbsp;${esc(METHODS[p.method] || p.method)}${p.cheque_number ? ' ' + esc(p.cheque_number) : ''} · ${fmtDate(p.received_on)}${p.reference ? ' · ' + esc(p.reference) : ''}</span>
            </span>
            ${p.clearance_status !== 'bounced' && Number(p.amount) - Number(p.allocated) > 0.004 ? `<span class="shrink-0 biz-num biz-t-blue" style="font-size:11px;font-weight:600">${m(Number(p.amount) - Number(p.allocated))} on acct</span>` : ''}
        </div>`;
    }).join('') || '<div class="biz-panel-empty">No receipts.</div>';

    const KIND = { statement: 'Statement', due_soon: 'Due soon', overdue: 'Overdue', final_notice: 'Final notice' };
    const CHAN = { email: 'Email', phone: 'Phone', in_person: 'In person', other: 'Other' };
    const reminders = (s.reminders || []).map(r => `
        <div class="biz-row" style="font-size:12px">
            <span class="min-w-0 flex-1">
                <span style="font-weight:600">${KIND[r.kind] || r.kind}</span>
                <span class="biz-chip ${r.sent_at ? 'biz-c-green' : 'biz-c-slate'}">${r.sent_at ? 'sent' : 'drafted'}</span>
                <span class="biz-muted" style="font-size:11px">&nbsp;${CHAN[r.channel] || r.channel} · ${fmtDate(r.created_at)}${r.by_name ? ' · ' + esc(r.by_name) : ''}</span>
            </span>
        </div>`).join('') || '<div class="biz-panel-empty">Not chased yet.</div>';

    panel.innerHTML = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="truncate">${esc(c.name)}${c.on_hold ? ' <span class="biz-chip biz-c-red">On hold</span>' : ''}</h2>
                    <p class="biz-muted" style="font-size:11px;margin-top:2px">
                        ${c.email ? esc(c.email) + ' · ' : ''}${c.payment_terms_days ? 'net ' + c.payment_terms_days + ' days' : 'no terms'}${c.credit_limit != null ? ' · limit ' + m(c.credit_limit) : ''}
                    </p>
                </div>
                <div class="shrink-0 text-right">
                    <div class="biz-tile-l">Balance</div>
                    <div class="biz-num" style="font-size:18px;font-weight:700;${s.balance > 0 ? '' : 'color:#059669'}">${m(s.balance)}</div>
                    ${s.unallocated_credit > 0.004 ? `<div class="biz-num biz-t-blue" style="font-size:11px;font-weight:600">incl. ${m(s.unallocated_credit)} on acct</div>` : ''}
                </div>
            </div>
            <div class="mt-2 flex flex-wrap gap-2">
                <a href="receivables_statement.php?company_id=${CID}&customer_id=${c.id}" target="_blank" rel="noopener" class="biz-btn biz-btn-ghost">Statement</a>
                ${CAN_WRITE ? `
                <button onclick="paymentForm(${c.id})" class="biz-btn biz-btn-primary">Record payment</button>
                <button onclick="editCustomer(${c.id})" class="biz-btn biz-btn-ghost">Edit</button>
                <button onclick="reminderForm(${c.id})" class="biz-btn biz-btn-ghost">Draft reminder</button>
                ${c.email ? `<button onclick="emailStatement(${c.id})" class="biz-btn biz-btn-ghost">Email statement</button>` : ''}
                <button onclick="toggleHold(${c.id}, ${c.on_hold ? 'false' : 'true'})" class="biz-btn ${c.on_hold ? 'biz-btn-ghost' : 'biz-btn-danger'}">
                    ${c.on_hold ? 'Release hold' : 'Place on hold'}
                </button>` : ''}
            </div>
            <div id="inlineForm" class="mt-2"></div>
        </div>
        <div class="biz-panel-head">Invoices</div>
        <div class="biz-list">${invoices}</div>
        <div class="biz-panel-head" style="border-top:1px solid var(--bz-line)">Receipts</div>
        <div class="biz-list">${payments}</div>
        ${writeoffsSection(s.writeoffs || [])}
        <div class="biz-panel-head" style="border-top:1px solid var(--bz-line)">Reminders</div>
        <div class="biz-list">${reminders}</div>`;
}

function writeoffsSection(rows){
    if (!rows.length) return '';
    const badge = { pending: 'biz-c-amber', approved: 'biz-c-green', rejected: 'biz-c-slate' };
    const body = rows.map(w => `
        <div class="biz-row" style="font-size:12px;display:block">
          <div class="flex items-start justify-between gap-2">
            <span class="min-w-0 flex-1">
                <span style="font-weight:600">${esc(w.invoice_number)}</span>
                <span class="biz-chip ${badge[w.status] || 'biz-c-slate'}">${w.status}</span>
                <span class="biz-muted" style="font-size:11px">&nbsp;${WO_KIND[w.kind] || w.kind}${w.reason ? ' · ' + esc(w.reason) : ''}</span>
                <span class="block biz-muted" style="font-size:11px">
                    proposed ${fmtDate(w.proposed_at)}${w.proposed_by_name ? ' by ' + esc(w.proposed_by_name) : ''}${w.decided_at ? ` · ${w.status} ${fmtDate(w.decided_at)}${w.approved_by_name ? ' by ' + esc(w.approved_by_name) : ''}` : ''}
                </span>
            </span>
            <span class="shrink-0 biz-num biz-t-amber" style="font-weight:700">${m(w.amount)}</span>
          </div>
          ${IS_ADMIN && w.status === 'pending'
            ? `<div class="mt-1 flex gap-2">
                 <button onclick="decideWriteoff(${w.id}, 'approve')" class="biz-btn biz-btn-primary biz-btn-sm">Approve</button>
                 <button onclick="decideWriteoff(${w.id}, 'reject')" class="biz-btn biz-btn-ghost biz-btn-sm">Reject</button>
               </div>` : ''}
          ${IS_ADMIN && w.status === 'approved'
            ? `<button onclick="decideWriteoff(${w.id}, 'reverse')" class="biz-btn biz-btn-ghost biz-btn-sm mt-1">Reverse</button>` : ''}
        </div>`).join('');
    return `<div class="biz-panel-head" style="border-top:1px solid var(--bz-line)">Write-offs</div><div class="biz-list">${body}</div>`;
}

function writeoffForm(invoiceId, number, outstanding){
    const box = document.getElementById('woForm-' + invoiceId);
    if (box.innerHTML){ box.innerHTML = ''; return; }
    box.innerHTML = `
        <form onsubmit="submitWriteoff(event, ${invoiceId})" class="mt-2" style="border:1px solid var(--bz-line);border-radius:4px;background:var(--bz-head);padding:8px">
            <p class="biz-kicker" style="color:var(--bz-accent-d)">Write off ${esc(number)} — ${m(outstanding)} outstanding</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                ${fld('Amount', `<input name="amount" type="number" step="0.01" min="0.01" max="${outstanding}" value="${Number(outstanding).toFixed(2)}" required class="biz-input">`)}
                ${fld('Reason', `<select name="kind" class="biz-select">${Object.entries(WO_KIND).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}</select>`)}
            </div>
            ${fld('Note', '<input name="reason" class="biz-input mt-1" placeholder="e.g. crates of milk expired in transit">')}
            <p class="biz-muted mt-2" style="font-size:11px">Creates a proposal. A company admin approves it before the balance changes.</p>
            <div class="mt-2 flex gap-2">
                <button type="submit" class="biz-btn biz-btn-primary biz-btn-sm">Propose write-off</button>
                <button type="button" onclick="document.getElementById('woForm-${invoiceId}').innerHTML=''" class="biz-btn biz-btn-ghost biz-btn-sm">Cancel</button>
            </div>
        </form>`;
}
async function submitWriteoff(e, invoiceId){
    e.preventDefault();
    const f = e.target;
    try {
        await api('writeoff_propose.php', { invoice_id: invoiceId, amount: f.amount.value, kind: f.kind.value, reason: f.reason.value });
        showAlert('Write-off proposed — waiting for an admin to approve.', 'ok');
        openCustomer(OPEN_CUSTOMER);
        load();
    } catch (err){ showAlert(err.message, 'error'); }
}
async function decideWriteoff(id, action){
    const verb = { approve: 'Approve', reject: 'Reject', reverse: 'Reverse' }[action];
    let note = '';
    if (action !== 'approve'){
        note = prompt(verb + ' — reason (optional):') || '';
        if (note === null) return;
    } else if (!confirm('Approve this write-off? The customer balance drops now.')) {
        return;
    }
    try {
        await api('writeoff_decide.php', { writeoff_id: id, action, note });
        showAlert('Write-off ' + action + 'd.', 'ok');
        openCustomer(OPEN_CUSTOMER);
        load();
    } catch (err){ showAlert(err.message, 'error'); }
}

/* ── write actions ─────────────────────────────────────────────────────── */
function fld(label, inner){ return `<label class="block"><span class="biz-label">${label}</span>${inner}</label>`; }

function paymentForm(customerId){
    const today = new Date().toISOString().slice(0,10);
    document.getElementById('inlineForm').innerHTML = `
        <form onsubmit="submitPayment(event, ${customerId})" class="biz-panel" style="background:var(--bz-head);padding:10px">
            <p class="biz-kicker" style="color:var(--bz-accent-d)">Record payment</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                ${fld('Amount', '<input name="amount" type="number" step="0.01" min="0.01" required class="biz-input">')}
                ${fld('Method', `<select name="method" class="biz-select" onchange="document.getElementById('chequeFields').style.display = this.value === 'cheque' ? '' : 'none'">${Object.entries(METHODS).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}</select>`)}
                ${fld('Received on', `<input name="received_on" type="date" value="${today}" class="biz-input">`)}
                ${fld('Reference', '<input name="reference" type="text" placeholder="optional" class="biz-input">')}
            </div>
            <div id="chequeFields" class="mt-2 grid gap-2 sm:grid-cols-3" style="display:none">
                ${fld('Cheque no.', '<input name="cheque_number" class="biz-input">')}
                ${fld('Drawee bank', '<input name="cheque_bank" class="biz-input" placeholder="e.g. Atlantic Bank">')}
                ${fld('Cheque date', `<input name="cheque_date" type="date" value="${today}" class="biz-input">`)}
            </div>
            <p class="biz-muted mt-2" style="font-size:11px">Applied to open invoices, oldest due first. Any surplus sits on account. A cheque is held as <strong>uncleared</strong> until you confirm it in the Cheques tab.</p>
            <div class="mt-2 flex gap-2">
                <button type="submit" class="biz-btn biz-btn-primary">Save receipt</button>
                <button type="button" onclick="document.getElementById('inlineForm').innerHTML=''" class="biz-btn biz-btn-ghost">Cancel</button>
            </div>
        </form>`;
}
async function submitPayment(e, customerId){
    e.preventDefault();
    const f = e.target;
    try {
        const body = {
            customer_id: customerId, amount: f.amount.value, method: f.method.value,
            received_on: f.received_on.value, reference: f.reference.value,
        };
        if (f.method.value === 'cheque') {
            body.cheque_number = f.cheque_number.value;
            body.cheque_bank = f.cheque_bank.value;
            body.cheque_date = f.cheque_date.value;
        }
        const r = await api('record_payment.php', body);
        showAlert(`Receipt saved — applied ${m(r.allocated)} to ${r.invoices} invoice(s)` + (r.credit > 0.004 ? `, ${m(r.credit)} on account` : '') + '.', 'ok');
        document.getElementById('inlineForm').innerHTML = '';
        load();
    } catch (err){ showAlert(err.message, 'error'); }
}

function customerForm(cust){
    const c = cust || {};
    document.getElementById('inlineForm').innerHTML = `
        <form onsubmit="submitCustomer(event, ${c.id || 0})" class="biz-panel" style="background:var(--bz-head);padding:10px">
            <p class="biz-kicker">${c.id ? 'Edit customer' : 'New customer'}</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                ${fld('Name', `<input name="name" required value="${esc(c.name || '')}" class="biz-input">`)}
                ${fld('Email', `<input name="email" type="email" value="${esc(c.email || '')}" class="biz-input">`)}
                ${fld('Credit limit', `<input name="credit_limit" type="number" step="0.01" min="0" value="${c.credit_limit ?? ''}" class="biz-input">`)}
                ${fld('Payment terms (days)', `<input name="payment_terms_days" type="number" min="0" value="${c.payment_terms_days ?? 0}" class="biz-input">`)}
                ${fld('Opening balance', `<input name="opening_balance" type="number" step="0.01" value="${c.opening_balance ?? 0}" class="biz-input">`)}
            </div>
            <div class="mt-2 flex gap-2">
                <button type="submit" class="biz-btn biz-btn-primary">Save</button>
                <button type="button" onclick="document.getElementById('inlineForm').innerHTML=''" class="biz-btn biz-btn-ghost">Cancel</button>
            </div>
        </form>`;
}
function newCustomer(){
    OPEN_CUSTOMER = null;
    const p = document.getElementById('statementPanel');
    p.className = 'biz-panel';
    p.innerHTML = '<div class="biz-panel-body"><div id="inlineForm"></div></div>';
    customerForm(null);
}
function editCustomer(id){ customerForm((PORTFOLIO.customers || []).find(x => x.id === id)); }
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

async function reminderForm(customerId){
    const box = document.getElementById('inlineForm');
    box.innerHTML = '<p class="biz-muted" style="font-size:11px">Preparing draft…</p>';
    let d;
    try { d = await api('reminder_draft.php', { customer_id: customerId }); }
    catch (e){ showAlert(e.message, 'error'); box.innerHTML = ''; return; }
    box.innerHTML = `
        <form onsubmit="submitReminder(event, ${customerId})" class="biz-panel" style="background:var(--bz-head);padding:10px">
            <p class="biz-kicker" style="color:var(--bz-accent-d)">Draft reminder · BZD ${m(d.overdue)} overdue</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                ${fld('Kind', `<select name="kind" class="biz-select">
                    <option value="overdue" selected>Overdue</option>
                    <option value="due_soon">Due soon</option>
                    <option value="statement">Statement</option>
                    <option value="final_notice">Final notice</option></select>`)}
                ${fld('Channel', `<select name="channel" class="biz-select">
                    <option value="email" selected>Email</option>
                    <option value="phone">Phone</option>
                    <option value="in_person">In person</option>
                    <option value="other">Other</option></select>`)}
            </div>
            ${fld('Subject', `<input name="subject" class="biz-input mt-1" value="${esc(d.subject)}">`)}
            ${fld('Message', `<textarea name="body" class="biz-input mt-1" rows="7">${esc(d.body)}</textarea>`)}
            <p class="biz-muted mt-2" style="font-size:11px">${d.to_email
                ? `Emails this customer at <strong>${esc(d.to_email)}</strong>. Or copy the text and log it yourself.`
                : `No email on file for this customer — copy the text into your own email, then log it here.`}</p>
            <div class="mt-2 flex flex-wrap gap-2">
                ${d.to_email ? `<button type="button" onclick="sendReminder(${customerId}, this.form)" class="biz-btn biz-btn-primary">Send by email</button>` : ''}
                <button type="submit" name="act" value="sent" class="biz-btn ${d.to_email ? 'biz-btn-ghost' : 'biz-btn-primary'}">Log as sent</button>
                <button type="submit" name="act" value="draft" class="biz-btn biz-btn-ghost">Save draft</button>
                <button type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(this.form.body.value); showAlert('Message copied.', 'ok');" class="biz-btn biz-btn-ghost">Copy</button>
                <button type="button" onclick="document.getElementById('inlineForm').innerHTML=''" class="biz-btn biz-btn-ghost">Cancel</button>
            </div>
        </form>`;
}
function toggleImport(){
    document.getElementById('importBox').classList.toggle('hidden');
}
async function doImportCustomers(){
    const file = document.getElementById('importFile').files[0];
    let csv = document.getElementById('importText').value.trim();
    if (file) { csv = await file.text(); }
    if (!csv){ showAlert('Choose a file or paste CSV first.', 'error'); return; }
    try {
        const r = await api('import_customers.php', { csv });
        let msg = `${r.created} added, ${r.updated} updated`;
        if (r.skipped) msg += `, ${r.skipped} skipped`;
        showAlert(msg, r.skipped ? 'error' : 'ok');
        if (r.errors && r.errors.length) console.warn('Customer import issues:', r.errors);
        document.getElementById('importText').value = '';
        document.getElementById('importFile').value = '';
        toggleImport();
        load();
    } catch (e){ showAlert(e.message, 'error'); }
}
async function statementRun(){
    const n = (PORTFOLIO.customers || []).filter(c => Math.abs(c.balance) > 0.004 && c.email).length;
    if (!confirm(`Email a statement of account to ${n} customer${n === 1 ? '' : 's'} with an outstanding balance now?`)) return;
    try {
        showAlert('Sending statements…', 'ok');
        const r = await api('statement_run.php', { mode: 'all' });
        let msg = `${r.sent} statement(s) sent`;
        if (r.skipped_no_email) msg += ` · ${r.skipped_no_email} skipped (no email)`;
        if (r.failed) msg += ` · ${r.failed} failed`;
        showAlert(msg, r.failed ? 'error' : 'ok');
        load();
    } catch (e){ showAlert(e.message, 'error'); }
}
async function emailStatement(customerId){
    if (!confirm('Email this customer their full statement of account now?')) return;
    try {
        const r = await api('statement_send.php', { customer_id: customerId });
        showAlert(r.delivery === 'sent' ? 'Statement emailed.' : ('Recorded — ' + (r.note || 'email not sent from this environment')), 'ok');
        openCustomer(customerId);
    } catch (err){ showAlert(err.message, 'error'); }
}
async function sendReminder(customerId, f){
    if (!confirm('Email this reminder to the customer now?')) return;
    try {
        const r = await api('reminder_send.php', {
            customer_id: customerId, kind: f.kind.value,
            subject: f.subject.value, body: f.body.value,
        });
        showAlert(r.delivery === 'sent' ? 'Reminder emailed.' : ('Recorded — ' + (r.note || 'email not sent from this environment')), 'ok');
        document.getElementById('inlineForm').innerHTML = '';
        openCustomer(customerId);
        load();
    } catch (err){ showAlert(err.message, 'error'); }
}
async function submitReminder(e, customerId){
    e.preventDefault();
    const f = e.target;
    const markSent = (e.submitter && e.submitter.value === 'sent');
    try {
        await api('reminder_log.php', {
            customer_id: customerId, kind: f.kind.value, channel: f.channel.value,
            subject: f.subject.value, body: f.body.value, mark_sent: markSent,
        });
        showAlert(markSent ? 'Reminder logged as sent.' : 'Draft saved.', 'ok');
        document.getElementById('inlineForm').innerHTML = '';
        openCustomer(customerId);
        load();
    } catch (err){ showAlert(err.message, 'error'); }
}

load();
</script>
<?php include __DIR__ . '/partials/business_sidebar_end.php'; ?>
</body>
</html>
