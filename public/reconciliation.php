<?php
/**
 * Reconciliation workbench (Centryk Business package).
 *
 * Import a bank statement (CSV), then match each deposit to an open invoice —
 * which posts a receipt through the Receivables ledger. Gated on the
 * 'reconciliation' entitlement.
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

$level = $activeCompany ? Entitlements::level((int)$activeCompany['id'], 'reconciliation') : Entitlements::NONE;

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
    <title>Reconciliation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Reconciliation'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-6xl px-4 pt-4 pb-14">

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-600">Centryk Business · Reconciliation</p>
            <h1 class="mt-0.5 text-2xl font-black tracking-tight text-slate-950">Bank reconciliation</h1>
        </div>
        <?php if (count($companies) > 1): ?>
            <div class="flex flex-wrap items-center gap-2">
                <?php foreach ($companies as $c): ?>
                    <a href="reconciliation.php?company_id=<?= (int)$c['id'] ?>"
                       class="rounded-lg border px-3 py-1.5 text-xs font-bold <?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-slate-200 bg-white text-slate-500 hover:border-violet-200' ?>">
                        <?= htmlspecialchars($c['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$companies): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center">
            <p class="text-sm font-bold text-slate-500">You need to be an admin or manager of a company to use Reconciliation.</p>
        </div>
    <?php elseif ($level === Entitlements::NONE): ?>
        <div class="rounded-2xl border border-violet-200 bg-white px-6 py-12 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                <i data-lucide="scale" class="h-6 w-6"></i>
            </div>
            <h2 class="mt-4 text-lg font-black">Reconciliation is part of Centryk Business</h2>
            <p class="mx-auto mt-1 max-w-md text-sm font-semibold text-slate-500">
                Import your bank statement and match each deposit to the right customer invoice in
                minutes instead of by hand. Ask a Centryk advisor to switch it on for <?= htmlspecialchars($activeCompany['name']) ?>.
            </p>
            <a href="business.php?company_id=<?= (int)$activeCompany['id'] ?>" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-violet-600 px-5 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white hover:bg-violet-700">
                Explore Centryk Business
            </a>
        </div>
    <?php else: ?>

        <?php if ($level === Entitlements::READ): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800">
                Your Reconciliation subscription is paused — importing and matching are disabled until billing is resolved.
            </div>
        <?php endif; ?>

        <div id="alert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

        <div id="summaryStrip" class="grid grid-cols-2 gap-3 sm:grid-cols-4"></div>

        <?php if ($level === Entitlements::FULL): ?>
        <details class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <summary class="cursor-pointer px-4 py-3 text-sm font-black text-slate-700">Import a bank statement (CSV)</summary>
            <div class="border-t border-slate-100 p-4 space-y-3">
                <p class="text-xs font-semibold text-slate-500">
                    Paste the CSV or choose a file. A header row is required. Columns for date, description and amount
                    are auto-detected (or one "Credit" + one "Debit" column); override below if needed.
                </p>
                <input type="file" id="csvFile" accept=".csv,text/csv" class="block w-full text-xs">
                <textarea id="csvText" rows="5" placeholder="Date,Description,Amount&#10;2026-08-20,DEPOSIT J BELLS,250.00" class="w-full rounded-lg border border-slate-200 p-2 font-mono text-xs"></textarea>
                <div class="grid gap-2 sm:grid-cols-3">
                    <input id="mapDate" placeholder="date column" class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                    <input id="mapDesc" placeholder="description column" class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                    <input id="mapAmount" placeholder="amount column" class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                </div>
                <button onclick="doImport()" class="rounded-xl bg-slate-950 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-slate-800">Import</button>
            </div>
        </details>
        <?php endif; ?>

        <div class="mt-4 grid gap-5 lg:grid-cols-[1fr_1fr]">
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center gap-2 bg-slate-50 px-4 py-2.5">
                    <span class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Lines</span>
                    <select id="fStatus" onchange="loadTxns()" class="ml-auto rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold">
                        <option value="unmatched">Unmatched</option>
                        <option value="matched">Matched</option>
                        <option value="ignored">Ignored</option>
                    </select>
                    <select id="fDir" onchange="loadTxns()" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold">
                        <option value="">In &amp; out</option>
                        <option value="credit">Money in</option>
                        <option value="debit">Money out</option>
                    </select>
                </div>
                <div id="txnRows" class="max-h-[60vh] divide-y divide-slate-100 overflow-y-auto">
                    <div class="px-4 py-8 text-center text-sm text-slate-400">Loading…</div>
                </div>
            </div>

            <div id="matchPanel" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center text-sm text-slate-400">
                Select an unmatched deposit to see suggested invoices.
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
if (window.lucide) lucide.createIcons();
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
const CAN_WRITE = <?= $level === Entitlements::FULL ? 'true' : 'false' ?>;
let OPEN_TXN = null;

function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function m(v){ return Number(v || 0).toLocaleString('en-BZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(s){ if(!s) return '—'; return new Date(String(s).replace(' ','T')).toLocaleDateString('en-BZ',{month:'short',day:'numeric',year:'numeric'}); }

function showAlert(msg, type){
    const el = document.getElementById('alert'); if(!el) return;
    el.textContent = msg;
    el.className = 'mb-4 rounded-xl border p-3 text-sm font-semibold ' + (type==='error'
        ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700');
    el.classList.remove('hidden');
    clearTimeout(showAlert._t); showAlert._t = setTimeout(()=>el.classList.add('hidden'), 5000);
}

async function api(path, body){
    const res = await fetch('api/reconciliation/' + path, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ company_id: CID }, body || {})),
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
async function loadSummary(){
    try {
        const d = await api('summary.php');
        const s = d.summary;
        document.getElementById('summaryStrip').innerHTML =
            tile('Unmatched deposits', s.unmatched_credits, s.unmatched_credits ? 'text-amber-600' : '') +
            tile('Unmatched value', m(s.unmatched_value)) +
            tile('Matched', s.matched_count + ' · ' + m(s.matched_value)) +
            tile('Ignored', s.ignored_count);
    } catch (e){ showAlert(e.message, 'error'); }
}

const csvFile = document.getElementById('csvFile');
if (csvFile) csvFile.addEventListener('change', () => {
    const f = csvFile.files[0]; if (!f) return;
    const r = new FileReader();
    r.onload = () => { document.getElementById('csvText').value = r.result; };
    r.readAsText(f);
});
async function doImport(){
    const csv = document.getElementById('csvText').value.trim();
    if (!csv) { showAlert('Paste or choose a CSV first.', 'error'); return; }
    const mapping = {};
    if (document.getElementById('mapDate').value.trim()) mapping.date = document.getElementById('mapDate').value.trim();
    if (document.getElementById('mapDesc').value.trim()) mapping.description = document.getElementById('mapDesc').value.trim();
    if (document.getElementById('mapAmount').value.trim()) mapping.amount = document.getElementById('mapAmount').value.trim();
    try {
        const r = await api('import.php', { csv, filename: (csvFile && csvFile.files[0]) ? csvFile.files[0].name : '', mapping });
        let msg = `Imported ${r.imported} line(s)` + (r.skipped ? `, skipped ${r.skipped} (duplicate or zero)` : '') + '.';
        showAlert(msg + (r.errors.length ? ' Some rows had issues.' : ''), r.errors.length ? 'error' : 'ok');
        if (r.errors.length) console.warn('Import issues:', r.errors);
        document.getElementById('csvText').value = '';
        loadSummary(); loadTxns();
    } catch (e){ showAlert(e.message, 'error'); }
}

async function loadTxns(){
    if (CID === null) return;
    try {
        const d = await api('transactions.php', {
            status: document.getElementById('fStatus').value,
            direction: document.getElementById('fDir').value,
        });
        renderTxns(d.transactions || []);
    } catch (e){ showAlert(e.message, 'error'); }
}

function renderTxns(rows){
    const el = document.getElementById('txnRows');
    if (!rows.length){ el.innerHTML = '<div class="px-4 py-8 text-center text-sm text-slate-400">Nothing here.</div>'; return; }
    el.innerHTML = rows.map(t => {
        const credit = t.direction === 'credit';
        return `
        <button onclick="openTxn(${t.id}, ${credit})" class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50 ${OPEN_TXN === t.id ? 'bg-violet-50/60' : ''}">
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold">${esc(t.description) || '<span class="text-slate-400">(no description)</span>'}</p>
                <p class="mt-0.5 text-[11px] font-semibold text-slate-400">${fmtDate(t.txn_date)}${t.reference ? ' · ' + esc(t.reference) : ''}${t.status === 'matched' ? ' · <span class="text-emerald-600">matched</span>' : ''}</p>
            </div>
            <span class="shrink-0 text-sm font-black ${credit ? 'text-emerald-600' : 'text-slate-500'}">${credit ? '+' : ''}${m(t.amount)}</span>
        </button>`;
    }).join('');
}

async function openTxn(id, credit){
    OPEN_TXN = id;
    const panel = document.getElementById('matchPanel');
    if (!credit){
        panel.className = 'rounded-2xl border border-slate-200 bg-white p-5';
        panel.innerHTML = `<p class="text-sm font-semibold text-slate-500">This is a payment out — nothing to match to a customer invoice.</p>
            ${CAN_WRITE ? `<button onclick="ignoreTxn(${id}, true)" class="mt-3 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-black text-slate-500">Ignore this line</button>` : ''}`;
        loadTxns();
        return;
    }
    try {
        const d = await api('suggestions.php', { txn_id: id });
        renderMatch(d.transaction, d.invoices || []);
        loadTxns();
    } catch (e){ showAlert(e.message, 'error'); }
}

function renderMatch(txn, invoices){
    const panel = document.getElementById('matchPanel');
    panel.className = 'rounded-2xl border border-slate-200 bg-white p-5 space-y-4';
    const matched = txn.status === 'matched';

    const list = invoices.length ? invoices.map(iv => `
        <div class="flex items-center gap-3 rounded-xl border border-slate-200 p-3">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold">${esc(iv.invoice_number)} · ${esc(iv.customer_name)}</p>
                <p class="mt-0.5 text-[11px] font-semibold text-slate-400">${m(iv.outstanding)} outstanding · ${esc(iv.reasons.join(', '))}</p>
            </div>
            ${CAN_WRITE && !matched ? `<button onclick="doMatch(${txn.id}, ${iv.invoice_id})" class="shrink-0 rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-violet-700">Match</button>` : ''}
        </div>`).join('') : '<p class="text-sm text-slate-400">No confident matches. Check the customer has an open invoice, or ignore the line.</p>';

    panel.innerHTML = `
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.1em] text-slate-400">Bank line</p>
            <p class="text-sm font-bold">${esc(txn.description) || '(no description)'}</p>
            <p class="text-[11px] font-semibold text-slate-400">${fmtDate(txn.txn_date)} · <span class="text-emerald-600 font-black">+${m(txn.amount)}</span>${txn.reference ? ' · ' + esc(txn.reference) : ''}</p>
        </div>
        ${matched ? `<div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-800">
            Matched. ${CAN_WRITE ? `<button onclick="unmatchTxn(${txn.id})" class="ml-2 underline">Undo</button>` : ''}
        </div>` : `
        <div>
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Suggested invoices</p>
            <div class="mt-2 space-y-2">${list}</div>
        </div>
        ${CAN_WRITE ? `<button onclick="ignoreTxn(${txn.id}, true)" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-black text-slate-500 hover:border-slate-300">Ignore this line</button>` : ''}`}
    `;
}

async function doMatch(txnId, invoiceId){
    try {
        await api('match.php', { txn_id: txnId, type: 'invoice', target_id: invoiceId });
        showAlert('Matched — receipt posted to the customer account.', 'ok');
        openTxn(txnId, true); loadSummary();
    } catch (e){ showAlert(e.message, 'error'); }
}
async function unmatchTxn(txnId){
    try {
        await api('unmatch.php', { txn_id: txnId });
        showAlert('Match undone.', 'ok');
        openTxn(txnId, true); loadSummary();
    } catch (e){ showAlert(e.message, 'error'); }
}
async function ignoreTxn(txnId, ignored){
    try {
        await api('ignore.php', { txn_id: txnId, ignored });
        showAlert(ignored ? 'Line ignored.' : 'Line restored.', 'ok');
        document.getElementById('matchPanel').className = 'rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center text-sm text-slate-400';
        document.getElementById('matchPanel').textContent = 'Select an unmatched deposit to see suggested invoices.';
        OPEN_TXN = null;
        loadSummary(); loadTxns();
    } catch (e){ showAlert(e.message, 'error'); }
}

loadSummary();
loadTxns();
</script>
</body>
</html>
