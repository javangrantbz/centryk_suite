<?php
/**
 * Centryk Business — Accounting desk.
 *
 * Setup wizard when the books aren't activated; otherwise the finance-lead
 * home: YTD P&L, current period, drafts, and links into the chart of accounts,
 * journal entry and the financial statements. Gated on the 'accounting'
 * entitlement; admin/manager only.
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

$nav = 'home';
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Accounting'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php
ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
$pageTitle = 'Accounting'; $headerMaxW = 'max-w-5xl'; $awCurrent = 'centryk';
include __DIR__ . '/partials/account_header.php';
$bizNav = 'accounting';
include __DIR__ . '/partials/business_sidebar.php';
?>

<div class="biz mx-auto max-w-5xl px-4 py-4">
    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">
            No company you manage is on the Accounting package.
            <a class="biz-t-blue font-semibold" href="business.php">See Centryk Business</a>.
        </div>
    <?php else: ?>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="biz-kicker">Centryk Business</p>
                <h1 class="mt-0.5">Accounting</h1>
            </div>
        </div>

        <?php require __DIR__ . '/partials/accounting_nav.php'; ?>

        <div id="alert" class="biz-notice hidden mb-3"></div>
        <div id="view" class="mt-3"><div class="biz-panel biz-panel-empty">Loading…</div></div>
    <?php endif; ?>
</div>

<script>
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function money(v){ return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function showAlert(msg, kind){
    const el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'biz-notice mb-3 ' + (kind === 'ok' ? 'biz-notice-green' : 'biz-notice-red');
    el.classList.remove('hidden');
}
function clearAlert(){ document.getElementById('alert').classList.add('hidden'); }

async function api(path, body){
    const res = await fetch('api/accounting/' + path, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ company_id: CID }, body || {})),
    });
    const d = await res.json();
    if (!d.success) throw new Error(d.message || 'Request failed.');
    return d;
}

async function load(){
    if (CID === null) return;
    try {
        const { summary } = await api('summary.php');
        summary.activated ? renderDesk(summary) : renderSetup(summary);
    } catch (e){ showAlert(e.message); }
}

function renderSetup(s){
    const opts = MONTHS.map((m, i) => `<option value="${i+1}">${m}</option>`).join('');
    const rows = (s.template || []).map(a => `
        <tr class="border-t border-slate-100">
            <td class="py-1 pr-3 biz-num text-slate-500">${esc(a.code)}</td>
            <td class="py-1 pr-3">${esc(a.name)}</td>
            <td class="py-1 pr-3 biz-muted">${esc(a.type)}</td>
            <td class="py-1 text-right">${a.control ? '<span class="biz-chip biz-c-slate">control</span>' : (a.system ? '<span class="biz-chip biz-c-blue">system</span>' : '')}</td>
        </tr>`).join('');

    document.getElementById('view').innerHTML = `
        <div class="biz-panel">
            <div class="biz-panel-head"><span>Set up the general ledger</span></div>
            <div class="biz-panel-body">
                <p class="biz-muted mb-3" style="font-size:12px">
                    This creates the company's books: a chart of accounts, the accounting periods for the
                    current year, and the control accounts the subledgers post to. You can edit everything after.
                </p>
                <div class="flex flex-wrap items-end gap-3 mb-3">
                    <label class="block">
                        <span class="biz-label">Financial year starts in</span>
                        <select id="fyStart" class="biz-select" style="width:auto">${opts}</select>
                    </label>
                    <label class="block">
                        <span class="biz-label">Base currency</span>
                        <input id="cur" class="biz-input" style="width:90px" value="BZD" maxlength="3">
                    </label>
                    <label class="flex items-center gap-2" style="font-size:12px">
                        <input type="checkbox" id="useTpl" checked> Start from the Belize starter chart
                    </label>
                </div>
                <button onclick="doActivate()" class="biz-btn biz-btn-primary" id="setupBtn">Set up the books</button>
                <details class="mt-4">
                    <summary style="font-size:12px;cursor:pointer" class="biz-muted">Preview the starter chart (${(s.template || []).length} accounts)</summary>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full" style="font-size:12px"><tbody>${rows}</tbody></table>
                    </div>
                </details>
            </div>
        </div>`;
}

async function doActivate(){
    clearAlert();
    const btn = document.getElementById('setupBtn');
    btn.disabled = true;
    try {
        await api('activate.php', {
            fiscal_year_start_month: parseInt(document.getElementById('fyStart').value, 10),
            base_currency: document.getElementById('cur').value.trim() || 'BZD',
            use_template: document.getElementById('useTpl').checked,
        });
        showAlert('Books are set up. Next: check the control accounts under Chart of accounts.', 'ok');
        load();
    } catch (e){ showAlert(e.message); btn.disabled = false; }
}

function renderDesk(s){
    const p = s.current_period;
    const unmapped = Object.entries(s.unmapped_slots || {});
    const periodLabel = p
        ? `${p.fiscal_year}-${String(p.period_no).padStart(2,'0')} · ${esc(p.status)}`
        : 'no open period for today';

    document.getElementById('view').innerHTML = `
        ${unmapped.length ? `
        <div class="biz-notice biz-notice-amber mb-3">
            ${unmapped.length} control account${unmapped.length === 1 ? '' : 's'} not yet set
            (${unmapped.map(([k,v]) => esc(v)).join(', ')}).
            <a class="font-bold underline" href="gl_accounts.php?company_id=${CID}#map">Set them now</a> so auto-posting works.
        </div>` : ''}

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
            <div class="biz-tile"><div class="biz-tile-l">Income YTD</div><div class="biz-tile-v biz-num">${money(s.ytd.income)}</div></div>
            <div class="biz-tile"><div class="biz-tile-l">Gross profit YTD</div><div class="biz-tile-v biz-num">${money(s.ytd.gross_profit)}</div></div>
            <div class="biz-tile"><div class="biz-tile-l">Net profit YTD</div><div class="biz-tile-v biz-num ${s.ytd.net_profit < 0 ? 'biz-t-red' : 'biz-t-green'}">${money(s.ytd.net_profit)}</div></div>
            <div class="biz-tile"><div class="biz-tile-l">Current period</div><div class="biz-tile-v" style="font-size:12px">${periodLabel}</div></div>
        </div>

        <div class="biz-panel mb-3">
            <div class="biz-panel-head"><span>Do</span></div>
            <div class="biz-panel-body flex flex-wrap gap-2">
                <a class="biz-btn biz-btn-primary" href="gl_journal.php?company_id=${CID}">New journal entry</a>
                <a class="biz-btn biz-btn-ghost" href="expenses.php?company_id=${CID}">Record an expense</a>
                <a class="biz-btn biz-btn-ghost" href="gl_reports.php?company_id=${CID}">Financial statements</a>
                <a class="biz-btn biz-btn-ghost" href="gl_accounts.php?company_id=${CID}">Chart of accounts</a>
                <a class="biz-btn biz-btn-ghost" href="business_tax.php?company_id=${CID}">GST summary</a>
                ${s.draft_journals ? `<a class="biz-btn biz-btn-ghost" href="gl_journal.php?company_id=${CID}#drafts">${s.draft_journals} draft${s.draft_journals === 1 ? '' : 's'}</a>` : ''}
                ${s.expenses && s.expenses.unpaid_count ? `<a class="biz-btn biz-btn-ghost" href="expenses.php?company_id=${CID}">${s.expenses.unpaid_count} unpaid bill${s.expenses.unpaid_count === 1 ? '' : 's'}</a>` : ''}
            </div>
        </div>

        <div class="biz-panel mb-3">
            <div class="biz-panel-head"><span>Receivables → ledger</span></div>
            <div class="biz-panel-body" id="arBox">${renderArBox(s)}</div>
        </div>

        <div class="biz-panel">
            <div class="biz-panel-head"><span>Books</span></div>
            <div class="biz-panel-body" style="font-size:12px">
                <div class="biz-row" style="padding-left:0;padding-right:0"><span class="flex-1">Base currency</span><span class="biz-num">${esc(s.base_currency)}</span></div>
                <div class="biz-row" style="padding-left:0;padding-right:0"><span class="flex-1">Financial year starts</span><span>${esc(MONTHS[(s.fiscal_year_start_month || 1) - 1])}</span></div>
                <div class="biz-row" style="padding-left:0;padding-right:0"><span class="flex-1">Hard lock (nothing posts on/before)</span><span>${s.lock_before ? esc(s.lock_before) : '—'}</span></div>
                <div class="biz-row" style="padding-left:0;padding-right:0"><span class="flex-1">Last entry posted</span><span>${s.last_journal ? esc(s.last_journal.entry_date) + ' · J' + s.last_journal.journal_no : '—'}</span></div>
            </div>
        </div>`;
}

function renderArBox(s){
    const ar = s.ar || {};
    if (!ar.started_on){
        return `
            <p class="biz-muted mb-2" style="font-size:12px">
                Not on yet. Turn it on to auto-post every invoice, receipt and write-off to the ledger.
                Everything dated before the start date is taken as one opening balance.
            </p>
            <div class="flex flex-wrap items-end gap-2">
                <label class="block"><span class="biz-label">Start date</span>
                    <input type="date" id="arStart" class="biz-input" style="width:auto" value="${new Date().toISOString().slice(0,10)}"></label>
                <button class="biz-btn biz-btn-primary" id="arEnableBtn" onclick="enableAr()">Turn on AR posting</button>
            </div>`;
    }
    return `
        <div class="flex flex-wrap items-center gap-3">
            <span class="biz-chip biz-c-green">on since ${esc(ar.started_on)}</span>
            ${ar.pending ? `<span class="biz-chip biz-c-amber">${ar.pending} not yet posted</span>` : '<span class="biz-muted" style="font-size:12px">ledger is up to date</span>'}
            <span class="flex-1"></span>
            <button class="biz-btn biz-btn-ghost biz-btn-sm" onclick="syncAr()">Sync now</button>
        </div>`;
}

async function enableAr(){
    clearAlert();
    const btn = document.getElementById('arEnableBtn');
    btn.disabled = true;
    try {
        const { result } = await api('ar_enable.php', { opening_date: document.getElementById('arStart').value });
        showAlert(`AR posting is on. Opening balance ${money(result.opening_total)} across ${result.customers} customer(s).`, 'ok');
        load();
    } catch (e){ showAlert(e.message); btn.disabled = false; }
}

async function syncAr(){
    clearAlert();
    try {
        const { result } = await api('ar_sync.php');
        const parts = [];
        if (result.invoices) parts.push(`${result.invoices} invoice(s)`);
        if (result.receipts) parts.push(`${result.receipts} receipt(s)`);
        if (result.writeoffs) parts.push(`${result.writeoffs} write-off(s)`);
        if (result.bounced) parts.push(`${result.bounced} bounced cheque(s)`);
        showAlert(parts.length ? 'Posted ' + parts.join(', ') + '.' : 'Ledger was already up to date.', 'ok');
        if ((result.errors || []).length) showAlert(result.errors.join(' · '));
        load();
    } catch (e){ showAlert(e.message); }
}

load();
</script>
<?php include __DIR__ . '/partials/business_sidebar_end.php'; ?>
</body>
</html>
