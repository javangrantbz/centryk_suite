<?php
/**
 * Centryk Business — Accounting: the financial statements.
 * Trial balance, profit & loss, balance sheet, and general-ledger detail.
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
$nav = 'reports';
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Financial Statements'; include __DIR__ . '/partials/business_head.php'; ?>
<style>
  @media print {
    .biz-tabs, .biz-seg, .rp-controls, #site-header, .no-print { display: none !important; }
    body { background: #fff; }
  }
  .rp-line { display: flex; justify-content: space-between; gap: 12px; padding: 2px 0; font-size: 12px; }
  .rp-line.rp-total { font-weight: 700; border-top: 1px solid var(--bz-line); margin-top: 3px; padding-top: 4px; }
  .rp-section-h { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--bz-muted); margin: 10px 0 3px; }
</style>
</head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php
ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
$pageTitle = 'Financial Statements'; $headerMaxW = 'max-w-4xl'; $awCurrent = 'centryk';
include __DIR__ . '/partials/account_header.php';
$bizNav = 'accounting';
include __DIR__ . '/partials/business_sidebar.php';
?>

<div class="biz mx-auto max-w-4xl px-4 py-4">
    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">No company you manage is on the Accounting package.</div>
    <?php else: ?>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div><p class="biz-kicker">Centryk Business · Accounting</p><h1 class="mt-0.5">Financial statements</h1></div>
            <?php if (count($companies) > 1): ?>
            <div class="biz-seg">
                <?php foreach ($companies as $c): ?>
                    <a href="gl_reports.php?company_id=<?= (int)$c['id'] ?>"
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
            <div class="biz-tabs mt-3 mb-3" id="rtabs">
                <button class="biz-tab is-active" data-r="trial_balance">Trial balance</button>
                <button class="biz-tab" data-r="pl">Profit &amp; loss</button>
                <button class="biz-tab" data-r="balance_sheet">Balance sheet</button>
                <button class="biz-tab" data-r="gl">General ledger</button>
            </div>

            <div class="rp-controls flex flex-wrap items-end gap-2 mb-3">
                <label class="block rp-asof"><span class="biz-label">As of</span><input type="date" id="c_asof" class="biz-input" style="width:auto"></label>
                <label class="block rp-range hidden"><span class="biz-label">From</span><input type="date" id="c_from" class="biz-input" style="width:auto"></label>
                <label class="block rp-range hidden"><span class="biz-label">To</span><input type="date" id="c_to" class="biz-input" style="width:auto"></label>
                <label class="block rp-acct hidden"><span class="biz-label">Account</span>
                    <select id="c_acct" class="biz-select" style="width:auto"></select></label>
                <button class="biz-btn biz-btn-primary biz-btn-sm" onclick="run()">Run</button>
                <button class="biz-btn biz-btn-ghost biz-btn-sm no-print" onclick="window.print()">Print</button>
            </div>

            <div id="report" class="biz-panel"><div class="biz-panel-empty">Choose a report.</div></div>
        </div>
    <?php endif; ?>
</div>

<script>
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
const CO_NAME = <?= json_encode($activeCompany['name'] ?? '') ?>;
let TYPE = 'trial_balance';
let ACCOUNTS = [];

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function money(v){ return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function showAlert(msg){
    const el = document.getElementById('alert');
    el.textContent = msg; el.className = 'biz-notice biz-notice-red mb-3'; el.classList.remove('hidden');
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

document.querySelectorAll('#rtabs .biz-tab').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('#rtabs .biz-tab').forEach(x => x.classList.toggle('is-active', x === b));
    TYPE = b.dataset.r;
    const range = TYPE === 'pl' || TYPE === 'gl';
    document.querySelectorAll('.rp-range').forEach(e => e.classList.toggle('hidden', !range));
    document.querySelectorAll('.rp-asof').forEach(e => e.classList.toggle('hidden', range));
    document.querySelector('.rp-acct').classList.toggle('hidden', TYPE !== 'gl');
    run();
}));

function line(label, value, opts){
    opts = opts || {};
    return `<div class="rp-line ${opts.total ? 'rp-total' : ''}">
        <span>${esc(label)}</span><span class="biz-num ${opts.tone || ''}">${value}</span></div>`;
}
function heading(as){
    const sub = TYPE === 'pl' ? `${document.getElementById('c_from').value} to ${document.getElementById('c_to').value}`
        : TYPE === 'gl' ? `${document.getElementById('c_from').value} to ${document.getElementById('c_to').value}`
        : `as at ${document.getElementById('c_asof').value}`;
    return `<div style="text-align:center;margin-bottom:8px">
        <div style="font-weight:700">${esc(CO_NAME)}</div>
        <div style="font-size:12px">${esc(as)}</div>
        <div class="biz-muted" style="font-size:11px">${esc(sub)}</div></div>`;
}

async function run(){
    if (CID === null) return;
    const box = document.getElementById('report');
    box.innerHTML = '<div class="biz-panel-empty">Running…</div>';
    try {
        const body = {
            type: TYPE,
            as_of: document.getElementById('c_asof').value || null,
            from: document.getElementById('c_from').value || null,
            to: document.getElementById('c_to').value || null,
        };
        if (TYPE === 'gl') body.account_id = parseInt(document.getElementById('c_acct').value || '0', 10);
        const { report } = await api('report.php', body);
        box.innerHTML = '<div class="biz-panel-body">' + renderReport(report) + '</div>';
    } catch (e){ box.innerHTML = `<div class="biz-panel-empty biz-t-red">${esc(e.message)}</div>`; }
}

function renderReport(r){
    if (TYPE === 'trial_balance'){
        const rows = r.rows.map(x => `
            <div class="rp-line"><span>${esc(x.code)} · ${esc(x.name)}</span>
                <span class="biz-num" style="width:200px;display:inline-flex;justify-content:space-between">
                    <span>${x.debit ? money(x.debit) : ''}</span><span>${x.credit ? money(x.credit) : ''}</span></span></div>`).join('');
        return heading('Trial balance') + rows +
            `<div class="rp-line rp-total"><span>Totals</span>
                <span class="biz-num" style="width:200px;display:inline-flex;justify-content:space-between">
                    <span>${money(r.total_debit)}</span><span>${money(r.total_credit)}</span></span></div>` +
            `<p class="biz-muted" style="font-size:11px;margin-top:6px">${r.balanced ? '✓ debits equal credits' : '⚠ out of balance'}</p>`;
    }
    if (TYPE === 'pl'){
        const grp = (title, arr) => `<div class="rp-section-h">${title}</div>` +
            (arr.length ? arr.map(x => line(x.code + ' · ' + x.name, money(x.amount))).join('') : '<div class="rp-line biz-muted"><span>—</span><span></span></div>');
        return heading('Profit & loss') +
            grp('Income', r.income) + line('Total income', money(r.total_income), { total: true }) +
            grp('Cost of sales', r.cogs) + line('Gross profit', money(r.gross_profit), { total: true, tone: 'biz-t-blue' }) +
            grp('Expenses', r.expense) + line('Total expenses', money(r.total_expense), { total: true }) +
            line('Net profit', money(r.net_profit), { total: true, tone: r.net_profit < 0 ? 'biz-t-red' : 'biz-t-green' });
    }
    if (TYPE === 'balance_sheet'){
        const grp = (title, arr) => `<div class="rp-section-h">${title}</div>` +
            arr.map(x => line(x.code + ' · ' + x.name, money(x.amount))).join('');
        return heading('Balance sheet') +
            grp('Assets', r.assets) + line('Total assets', money(r.total_assets), { total: true }) +
            grp('Liabilities', r.liabilities) + line('Total liabilities', money(r.total_liabilities), { total: true }) +
            grp('Equity', r.equity) +
            line('Current-year earnings', money(r.current_year_earnings)) +
            line('Total equity', money(r.total_equity), { total: true }) +
            line('Total liabilities + equity', money(r.total_liabilities_equity), { total: true }) +
            `<p class="biz-muted" style="font-size:11px;margin-top:6px">${r.balanced ? '✓ balance sheet balances' : '⚠ assets ≠ liabilities + equity'}</p>`;
    }
    if (TYPE === 'gl'){
        const rows = r.rows.map(x => `
            <div class="rp-line"><span>${esc(x.date)} · J${x.journal_no} · ${esc(x.memo || '')}</span>
                <span class="biz-num" style="width:280px;display:inline-flex;justify-content:space-between">
                    <span>${x.debit ? money(x.debit) : ''}</span><span>${x.credit ? money(x.credit) : ''}</span>
                    <span>${money(x.balance)}</span></span></div>`).join('');
        return heading('General ledger — ' + r.account.code + ' ' + r.account.name) +
            line('Opening balance', money(r.opening_balance), { total: true }) + rows +
            line('Closing balance', money(r.closing_balance), { total: true, tone: 'biz-t-blue' });
    }
    return '';
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
        document.getElementById('c_acct').innerHTML = ACCOUNTS
            .map(a => `<option value="${a.id}">${esc(a.code)} · ${esc(a.name)}</option>`).join('');
        document.getElementById('wrap').classList.remove('hidden');
        const today = new Date().toISOString().slice(0, 10);
        document.getElementById('c_asof').value = today;
        document.getElementById('c_to').value = today;
        document.getElementById('c_from').value = today.slice(0, 4) + '-01-01';
        run();
    } catch (e){ showAlert(e.message); }
}
init();
</script>
<?php include __DIR__ . '/partials/business_sidebar_end.php'; ?>
</body>
</html>
