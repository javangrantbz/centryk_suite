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
<head><?php $bizTitle = 'Reconciliation'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Reconciliation'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; $bizNav = 'reconciliation'; include __DIR__ . '/partials/business_sidebar.php'; ?>

<div class="biz mx-auto max-w-6xl px-4 py-4">

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Centryk Business · Reconciliation</p>
            <h1 class="mt-0.5">Bank reconciliation</h1>
        </div>
        <?php if (count($companies) > 1): ?>
            <div class="biz-seg">
                <?php foreach ($companies as $c): ?>
                    <a href="reconciliation.php?company_id=<?= (int)$c['id'] ?>"
                       class="<?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'is-active' : '' ?>">
                        <?= htmlspecialchars($c['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">You need to be an admin or manager of a company to use Reconciliation.</div>
    <?php elseif ($level === Entitlements::NONE): ?>
        <div class="biz-panel" style="padding:28px 16px;text-align:center">
            <div style="margin:0 auto;display:flex;height:36px;width:36px;align-items:center;justify-content:center;border-radius:4px;background:#eef2ff;color:#4f46e5">
                <i data-lucide="scale" style="height:18px;width:18px"></i>
            </div>
            <h2 style="margin-top:10px;font-size:15px">Reconciliation is part of Centryk Business</h2>
            <p class="biz-muted" style="margin:4px auto 0;max-width:28rem;font-size:12px">
                Import your bank statement and match each deposit to the right customer invoice in
                minutes instead of by hand. Ask a Centryk advisor to switch it on for <?= htmlspecialchars($activeCompany['name']) ?>.
            </p>
            <a href="business.php?company_id=<?= (int)$activeCompany['id'] ?>" class="biz-btn biz-btn-primary" style="margin-top:12px">Explore Centryk Business</a>
        </div>
    <?php else: ?>

        <?php if ($level === Entitlements::READ): ?>
            <div class="biz-notice biz-notice-amber mb-3">Your Reconciliation subscription is paused — importing and matching are disabled until billing is resolved.</div>
        <?php endif; ?>

        <div id="alert" class="biz-notice mb-3 hidden"></div>

        <div id="summaryStrip" class="grid grid-cols-2 gap-2 sm:grid-cols-4"></div>

        <?php if ($level === Entitlements::FULL): ?>
        <details class="biz-panel mt-3">
            <summary style="cursor:pointer;padding:6px 10px;font-size:12px;font-weight:600;color:#334155">Import a bank statement — CSV, OFX/QFX or MT940</summary>
            <div class="biz-panel-body" style="border-top:1px solid var(--bz-line)">
                <p class="biz-muted" style="font-size:11px">
                    Choose a file or paste it. The format is auto-detected. For CSV a header row is required;
                    date, description and amount columns are auto-detected (or one "Credit" + one "Debit" column),
                    override below if needed.
                </p>
                <input type="file" id="csvFile" accept=".csv,.ofx,.qfx,.sta,.txt,text/csv" class="mt-2 block w-full text-xs">
                <textarea id="csvText" rows="5" placeholder="Date,Description,Amount&#10;2026-08-20,DEPOSIT J BELLS,250.00" class="biz-input mt-2" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px"></textarea>
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                    <input id="mapDate" placeholder="date column" class="biz-input">
                    <input id="mapDesc" placeholder="description column" class="biz-input">
                    <input id="mapAmount" placeholder="amount column" class="biz-input">
                </div>
                <button onclick="doImport()" class="biz-btn biz-btn-primary mt-2">Import</button>
            </div>
        </details>
        <?php endif; ?>

        <details class="biz-panel mt-3" ontoggle="if(this.open) loadRefs()">
            <summary style="cursor:pointer;padding:6px 10px;font-size:12px;font-weight:600;color:#334155">Payment references — what to tell customers to put on a transfer</summary>
            <div id="refRows" class="biz-list" style="max-height:40vh;overflow-y:auto"><div class="biz-panel-empty">Open to load…</div></div>
        </details>

        <details class="biz-panel mt-3" ontoggle="if(this.open) loadRules()">
            <summary style="cursor:pointer;padding:6px 10px;font-size:12px;font-weight:600;color:#334155">Auto-ignore rules — keep recurring noise out of the queue</summary>
            <div id="rulesBox" class="biz-panel-body"><div class="biz-panel-empty">Open to load…</div></div>
        </details>

        <div class="mt-3 grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div class="biz-panel self-start">
                <div class="biz-panel-head">
                    <span>Lines</span>
                    <span class="flex gap-2 items-center">
                        <?php if ($level === Entitlements::FULL): ?>
                        <button onclick="syncOnepay()" class="biz-btn biz-btn-ghost biz-btn-sm">Sync OnePay</button>
                        <button onclick="autoMatch()" class="biz-btn biz-btn-ghost biz-btn-sm">Auto-match</button>
                        <?php endif; ?>
                        <button onclick="exportLines()" class="biz-btn biz-btn-ghost biz-btn-sm">Export CSV</button>
                        <select id="fStatus" onchange="loadTxns()" class="biz-select" style="height:22px;width:auto;font-size:11px">
                            <option value="unmatched">Unmatched</option>
                            <option value="matched">Matched</option>
                            <option value="ignored">Ignored</option>
                        </select>
                        <select id="fDir" onchange="loadTxns()" class="biz-select" style="height:22px;width:auto;font-size:11px">
                            <option value="">In &amp; out</option>
                            <option value="credit">Money in</option>
                            <option value="debit">Money out</option>
                        </select>
                    </span>
                </div>
                <div id="txnRows" class="biz-list max-h-[62vh] overflow-y-auto">
                    <div class="biz-panel-empty">Loading…</div>
                </div>
            </div>

            <div id="matchPanel" class="biz-panel biz-panel-empty self-start">Select an unmatched deposit to see suggested invoices.</div>
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
function fmtDate(s){ if(!s) return '—'; return new Date(String(s).replace(' ','T')).toLocaleDateString('en-BZ',{year:'2-digit',month:'short',day:'numeric'}); }
function fld(label, inner){ return `<label class="block"><span class="biz-label">${label}</span>${inner}</label>`; }

function showAlert(msg, type){
    const el = document.getElementById('alert'); if(!el) return;
    el.textContent = msg;
    el.className = 'biz-notice mb-3 ' + (type === 'error' ? 'biz-notice-red' : 'biz-notice-green');
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
    return `<div class="biz-tile"><div class="biz-tile-l">${esc(label)}</div><div class="biz-tile-v ${tone || ''}">${value}</div></div>`;
}
async function loadSummary(){
    try {
        const d = await api('summary.php');
        const s = d.summary;
        document.getElementById('summaryStrip').innerHTML =
            tile('Unmatched deposits', s.unmatched_credits, s.unmatched_credits ? 'biz-t-amber' : '') +
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
        let msg = `${(r.format || 'file').toUpperCase()}: imported ${r.imported} line(s)` + (r.skipped ? `, skipped ${r.skipped} (duplicate or zero)` : '') + (r.auto_ignored ? `, ${r.auto_ignored} auto-ignored` : '') + '.';
        showAlert(msg + (r.errors.length ? ' Some rows had issues.' : ''), r.errors.length ? 'error' : 'ok');
        if (r.errors.length) console.warn('Import issues:', r.errors);
        document.getElementById('csvText').value = '';
        loadSummary(); loadTxns();
    } catch (e){ showAlert(e.message, 'error'); }
}

let REFS_LOADED = false;
async function loadRefs(){
    if (REFS_LOADED) return;
    REFS_LOADED = true;
    try {
        const d = await api('refs.php');
        const rows = d.invoices || [];
        document.getElementById('refRows').innerHTML = rows.length ? rows.map(r => `
            <div class="biz-row" style="cursor:default;font-size:12px">
                <span class="min-w-0 flex-1 truncate">${esc(r.customer_name)} · ${esc(r.invoice_number)}</span>
                <button onclick="navigator.clipboard && navigator.clipboard.writeText('${r.payment_ref}'); showAlert('${r.payment_ref} copied.', 'ok');"
                        class="biz-btn biz-btn-ghost biz-btn-sm biz-num">${r.payment_ref}</button>
                <span class="shrink-0 biz-num biz-muted" style="width:88px;text-align:right">${m(r.outstanding)}</span>
            </div>`).join('') : '<div class="biz-panel-empty">No open invoices.</div>';
    } catch (e){ REFS_LOADED = false; showAlert(e.message, 'error'); }
}

function exportLines(){
    if (CID === null) return;
    const status = document.getElementById('fStatus').value || 'unmatched';
    window.location = 'api/reconciliation/export.php?company_id=' + CID + '&status=' + encodeURIComponent(status);
}

const DIR_LABEL = { any: 'any direction', credit: 'money in', debit: 'money out' };
async function loadRules(){
    const box = document.getElementById('rulesBox');
    try {
        const d = await api('rules.php');
        renderRules(d.rules || []);
    } catch (e){ box.innerHTML = '<div class="biz-panel-empty">' + esc(e.message) + '</div>'; }
}
function renderRules(rules){
    const active = rules.filter(r => Number(r.active) === 1);
    const rows = active.length ? active.map(r => {
        const conds = [
            r.description_like ? `description ~ "${esc(r.description_like)}"` : '',
            r.reference_like ? `reference ~ "${esc(r.reference_like)}"` : '',
            (r.amount_exact != null && r.amount_exact !== '') ? `amount ${m(r.amount_exact)}` : '',
            r.direction !== 'any' ? DIR_LABEL[r.direction] : '',
        ].filter(Boolean).join(' · ');
        return `<div class="biz-row" style="font-size:12px">
            <span class="min-w-0 flex-1">
                <span style="font-weight:600">${conds || 'match all'}</span>
                <span class="block biz-muted" style="font-size:11px">${r.note ? esc(r.note) + ' · ' : ''}ignored ${r.hits} line${Number(r.hits) === 1 ? '' : 's'}${r.last_hit_at ? ' · last ' + fmtDate(r.last_hit_at) : ''}</span>
            </span>
            ${CAN_WRITE ? `<span class="shrink-0 flex gap-1">
                <button onclick='ruleForm(${JSON.stringify(r)})' class="biz-btn biz-btn-ghost biz-btn-sm">Edit</button>
                <button onclick="removeRule(${r.id})" class="biz-btn biz-btn-ghost biz-btn-sm">Remove</button>
            </span>` : ''}
        </div>`;
    }).join('') : '<div class="biz-panel-empty">No rules yet. Add one to auto-ignore bank charges, interest, transfers…</div>';

    document.getElementById('rulesBox').innerHTML = `
        <div class="flex items-center justify-between">
            <p class="biz-kicker">Active rules</p>
            ${CAN_WRITE ? `<span class="flex gap-1">
                <button onclick="applyRules()" class="biz-btn biz-btn-ghost biz-btn-sm">Apply to backlog</button>
                <button onclick="ruleForm()" class="biz-btn biz-btn-ghost biz-btn-sm">+ Rule</button>
            </span>` : ''}
        </div>
        <div class="biz-list mt-1">${rows}</div>
        <div id="ruleForm" class="mt-2"></div>`;
}
function ruleForm(rule){
    const r = rule || { description_like: '', reference_like: '', amount_exact: '', direction: 'any', note: '' };
    document.getElementById('ruleForm').innerHTML = `
        <form onsubmit="submitRule(event, ${r.id || 0})" style="border:1px solid var(--bz-line);border-radius:4px;background:var(--bz-head);padding:8px">
            <p class="biz-muted" style="font-size:11px">A line is ignored when every condition you set is true. Leave a field blank to skip it.</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                ${fld('Description contains', `<input name="description_like" value="${esc(r.description_like)}" class="biz-input" placeholder="e.g. BANK CHARGE">`)}
                ${fld('Reference contains', `<input name="reference_like" value="${esc(r.reference_like)}" class="biz-input">`)}
                ${fld('Exact amount', `<input name="amount_exact" type="number" step="0.01" min="0" value="${r.amount_exact ?? ''}" class="biz-input" placeholder="any">`)}
                ${fld('Direction', `<select name="direction" class="biz-select">
                    <option value="any" ${r.direction === 'any' ? 'selected' : ''}>Any</option>
                    <option value="credit" ${r.direction === 'credit' ? 'selected' : ''}>Money in</option>
                    <option value="debit" ${r.direction === 'debit' ? 'selected' : ''}>Money out</option></select>`)}
                ${fld('Note', `<input name="note" value="${esc(r.note)}" class="biz-input" placeholder="why this is ignored">`)}
            </div>
            <div class="mt-2 flex gap-2">
                <button class="biz-btn biz-btn-primary biz-btn-sm">Save &amp; apply</button>
                <button type="button" onclick="document.getElementById('ruleForm').innerHTML=''" class="biz-btn biz-btn-ghost biz-btn-sm">Cancel</button>
            </div>
        </form>`;
}
async function submitRule(e, id){
    e.preventDefault();
    const f = e.target;
    try {
        const res = await api('rule_save.php', {
            id, description_like: f.description_like.value, reference_like: f.reference_like.value,
            amount_exact: f.amount_exact.value, direction: f.direction.value, note: f.note.value,
        });
        showAlert(`Rule saved${res.applied ? ` — ${res.applied} line(s) ignored` : ''}.`, 'ok');
        loadRules(); loadSummary(); loadTxns();
    } catch (err){ showAlert(err.message, 'error'); }
}
async function removeRule(id){
    if (!confirm('Remove this rule? Lines it already ignored stay ignored.')) return;
    try { await api('rule_delete.php', { rule_id: id }); showAlert('Rule removed.', 'ok'); loadRules(); }
    catch (err){ showAlert(err.message, 'error'); }
}
async function applyRules(){
    try {
        const r = await api('rules_apply.php');
        showAlert(r.applied ? `${r.applied} line(s) ignored.` : 'Nothing new matched.', 'ok');
        loadRules(); loadSummary(); loadTxns();
    } catch (err){ showAlert(err.message, 'error'); }
}

async function autoMatch(){
    if (!confirm('Auto-match every unmatched deposit that has one clear, exact match?')) return;
    try {
        const r = await api('automatch.php');
        showAlert(`Auto-matched ${r.matched} of ${r.reviewed} deposit(s). The rest need a look.`, 'ok');
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
    if (!rows.length){ el.innerHTML = '<div class="biz-panel-empty">Nothing here.</div>'; return; }
    el.innerHTML = rows.map(t => {
        const credit = t.direction === 'credit';
        return `
        <button onclick="openTxn(${t.id}, ${credit})" class="biz-row ${OPEN_TXN === t.id ? 'is-active' : ''}">
            <span class="min-w-0 flex-1">
                <span class="block truncate" style="font-weight:600">${esc(t.description) || '<span class="biz-muted">(no description)</span>'}</span>
                <span class="block biz-muted" style="font-size:11px">${fmtDate(t.txn_date)}${t.reference ? ' · ' + esc(t.reference) : ''}${t.status === 'matched' ? ' · <span class="biz-t-green">matched</span>' : ''}</span>
            </span>
            <span class="shrink-0 biz-num ${credit ? 'biz-t-green' : 'biz-muted'}" style="font-weight:700">${credit ? '+' : ''}${m(t.amount)}</span>
        </button>`;
    }).join('');
}

async function openTxn(id, credit){
    OPEN_TXN = id;
    const panel = document.getElementById('matchPanel');
    if (!credit){
        panel.className = 'biz-panel self-start';
        panel.innerHTML = `<div class="biz-panel-body"><p class="biz-muted" style="font-size:12px">This is a payment out — nothing to match to a customer invoice.</p>
            ${CAN_WRITE ? `<button onclick="ignoreTxn(${id}, true)" class="biz-btn biz-btn-ghost biz-btn-sm mt-2">Ignore this line</button>` : ''}</div>`;
        loadTxns();
        return;
    }
    try {
        const d = await api('suggestions.php', { txn_id: id });
        let settle = null;
        if (d.transaction.status !== 'matched') {
            try { settle = await api('settlement_suggest.php', { txn_id: id }); } catch (e) {}
        }
        renderMatch(d.transaction, d.invoices || [], settle);
        loadTxns();
    } catch (e){ showAlert(e.message, 'error'); }
}

let SETTLE_SELECT = [];
function renderMatch(txn, invoices, settle){
    const panel = document.getElementById('matchPanel');
    panel.className = 'biz-panel self-start';
    const matched = txn.status === 'matched';
    SETTLE_SELECT = (settle && settle.exact && settle.exact.length) ? settle.exact.slice() : [];

    const list = invoices.length ? invoices.map(iv => `
        <div class="flex items-center gap-3 border-t border-[color:var(--bz-line-soft)] px-2.5 py-2 first:border-t-0">
            <div class="min-w-0 flex-1">
                <p style="font-size:12px;font-weight:600">${esc(iv.invoice_number)} · ${esc(iv.customer_name)} <span class="biz-chip biz-c-slate biz-num">${esc(iv.payment_ref || '')}</span></p>
                <p class="biz-muted" style="font-size:11px">${m(iv.outstanding)} outstanding · ${esc(iv.reasons.join(', '))}</p>
            </div>
            ${CAN_WRITE && !matched ? `<button onclick="doMatch(${txn.id}, ${iv.invoice_id})" class="biz-btn biz-btn-primary biz-btn-sm shrink-0">Match</button>` : ''}
        </div>`).join('') : '<p class="biz-muted px-2.5 py-2" style="font-size:12px">No confident matches. Check the customer has an open invoice, or ignore the line.</p>';

    panel.innerHTML = `
        <div class="biz-panel-body">
            <p class="biz-tile-l">Bank line</p>
            <p style="font-size:13px;font-weight:600;margin-top:2px">${esc(txn.description) || '(no description)'}</p>
            <p class="biz-muted" style="font-size:11px">${fmtDate(txn.txn_date)} · <span class="biz-num biz-t-green" style="font-weight:700">+${m(txn.amount)}</span>${txn.reference ? ' · ' + esc(txn.reference) : ''}</p>
        </div>
        ${matched ? `<div class="biz-notice biz-notice-green" style="margin:0 10px 10px">
            ${txn.match_type === 'settlement' ? 'Settled against a batch of receipts.' : 'Matched.'} ${CAN_WRITE ? `<button onclick="unmatchTxn(${txn.id})" class="ml-1 underline">Undo</button>` : ''}
        </div>` : `
        ${settleBlock(txn, settle)}
        <div class="biz-panel-head" style="border-top:1px solid var(--bz-line)">Suggested invoices</div>
        <div>${list}</div>
        ${CAN_WRITE ? `<div class="biz-panel-body" style="border-top:1px solid var(--bz-line)"><button onclick="ignoreTxn(${txn.id}, true)" class="biz-btn biz-btn-ghost biz-btn-sm">Ignore this line</button></div>` : ''}`}
    `;
}

function settleBlock(txn, settle){
    if (!settle || !settle.pool || !settle.pool.length) return '';
    const exact = settle.exact || [];
    const rows = settle.pool.map(p => `
        <label class="flex items-center gap-2 px-2.5 py-1.5" style="font-size:11px;border-top:1px solid var(--bz-line-soft)">
            <input type="checkbox" ${exact.includes(p.id) ? 'checked' : ''} onchange="toggleSettle(${p.id}, this.checked)">
            <span class="min-w-0 flex-1 truncate">${esc(p.customer_name)} · ${fmtDate(p.received_on)}${p.source === 'onepay' ? ' · OnePay' : ''}</span>
            <span class="biz-num">${m(p.amount)}</span>
        </label>`).join('');
    return `
        <div class="biz-panel-body" style="border-top:1px solid var(--bz-line);background:var(--bz-head)">
            <p class="biz-tile-l">Card / OnePay settlement</p>
            <p class="biz-muted" style="font-size:11px">
                ${exact.length ? `This deposit matches a batch of <strong>${exact.length}</strong> receipt(s).` :
                  `${settle.pool.length} un-reconciled electronic receipt(s) in the window (${m(settle.pool_total)}).`}
            </p>
            <div class="mt-1" style="max-height:22vh;overflow-y:auto;border:1px solid var(--bz-line);border-radius:4px">${rows}</div>
            ${CAN_WRITE ? `<button onclick="matchSettle(${txn.id})" class="biz-btn biz-btn-primary biz-btn-sm mt-2">Match selected as settlement</button>` : ''}
        </div>`;
}
function toggleSettle(id, on){
    SETTLE_SELECT = SETTLE_SELECT.filter(x => x !== id);
    if (on) SETTLE_SELECT.push(id);
}
async function matchSettle(txnId){
    if (!SETTLE_SELECT.length){ showAlert('Tick the receipts in this settlement.', 'error'); return; }
    try {
        const r = await api('settlement_match.php', { txn_id: txnId, payment_ids: SETTLE_SELECT });
        showAlert(`Settled — ${r.receipts} receipt(s), ${m(r.total)}.`, 'ok');
        openTxn(txnId, true); loadSummary();
    } catch (e){ showAlert(e.message, 'error'); }
}
async function syncOnepay(){
    try {
        const r = await api('sync_onepay.php');
        showAlert(r.created ? `${r.created} OnePay payment(s) posted to the ledger (${m(r.amount)}).` : 'OnePay payments already up to date.', 'ok');
        loadSummary(); loadTxns();
    } catch (e){ showAlert(e.message, 'error'); }
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
        const p = document.getElementById('matchPanel');
        p.className = 'biz-panel biz-panel-empty self-start';
        p.textContent = 'Select an unmatched deposit to see suggested invoices.';
        OPEN_TXN = null;
        loadSummary(); loadTxns();
    } catch (e){ showAlert(e.message, 'error'); }
}

loadSummary();
loadTxns();
</script>
<?php include __DIR__ . '/partials/business_sidebar_end.php'; ?>
</body>
</html>
