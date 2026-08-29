<?php
/**
 * Centryk Business — subscription billing.
 *
 * Platform admins run the monthly charge cycle and clear each charge
 * (paid / waived / void). MRR, billed, outstanding at a glance.
 *
 * Read: api/business/billing_summary.php
 * Write: api/business/billing_run.php, billing_charge.php
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
$user = $me['user'];

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Business Billing'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Centryk Business'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="biz mx-auto max-w-6xl px-4 py-4">

    <div id="alert" class="biz-notice mb-3 hidden"></div>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker"><a href="admin-business-roadmap.php" class="underline">Centryk Business</a> · internal</p>
            <h1 class="mt-0.5">Subscription billing</h1>
        </div>
        <div class="flex gap-2">
            <a href="admin-business-packages.php" class="biz-btn biz-btn-ghost">Grant console</a>
            <button onclick="runCycle()" class="biz-btn biz-btn-primary">Run this month</button>
        </div>
    </div>

    <div id="summaryStrip" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6"></div>

    <div class="biz-panel mt-3">
        <div class="biz-panel-head">
            <span>Charges</span>
            <select id="fStatus" onchange="load()" class="biz-select" style="height:22px;width:auto;font-size:11px">
                <option value="due">Due</option>
                <option value="paid">Paid</option>
                <option value="waived">Waived</option>
                <option value="void">Void</option>
            </select>
        </div>
        <div id="chargeRows" class="biz-list"><div class="biz-panel-empty">Loading…</div></div>
    </div>

    <p class="biz-muted mt-2" style="font-size:11px">
        All subscriptions bill monthly. An "annual" term charges price ÷ 12 each month.
        "Run this month" is safe to click repeatedly — it only adds charges that don't exist yet.
        Wire it to a monthly cron for hands-off billing.
    </p>
</div>

<script>
function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function m(v){ return Number(v || 0).toLocaleString('en-BZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(s){ if(!s) return '—'; return new Date(String(s).replace(' ','T')).toLocaleDateString('en-BZ',{year:'2-digit',month:'short',day:'numeric'}); }
function fmtMonth(s){ if(!s) return ''; return new Date(String(s).replace(' ','T')).toLocaleDateString('en-BZ',{year:'numeric',month:'short'}); }

function showAlert(msg, type){
    const el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'biz-notice mb-3 ' + (type === 'error' ? 'biz-notice-red' : 'biz-notice-green');
    el.classList.remove('hidden');
    clearTimeout(showAlert._t); showAlert._t = setTimeout(()=>el.classList.add('hidden'), 5000);
}

async function api(path, body){
    const res = await fetch('api/business/' + path, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}),
    });
    const data = await res.json().catch(()=>({}));
    if (!res.ok || data.success !== true) throw new Error(data.message || ('Request failed (' + res.status + ')'));
    return data;
}

function tile(label, value, tone){
    return `<div class="biz-tile"><div class="biz-tile-l">${esc(label)}</div><div class="biz-tile-v ${tone || ''}">${value}</div></div>`;
}

async function load(){
    try {
        const d = await api('billing_summary.php', { status: document.getElementById('fStatus').value });
        const s = d.summary;
        document.getElementById('summaryStrip').innerHTML =
            tile('MRR', m(s.mrr)) +
            tile('Billed ' + fmtMonth(s.current_month), m(s.billed_this_month)) +
            tile('Collected ' + fmtMonth(s.current_month), m(s.collected_this_month), 'biz-t-green') +
            tile('Outstanding', m(s.outstanding), s.outstanding > 0 ? 'biz-t-amber' : '') +
            tile('Due', s.due_count) +
            tile('Overdue', s.overdue_count, s.overdue_count ? 'biz-t-red' : '');
        renderCharges(d.charges || []);
    } catch (e){ showAlert(e.message, 'error'); }
}

function renderCharges(rows){
    const el = document.getElementById('chargeRows');
    if (!rows.length){ el.innerHTML = '<div class="biz-panel-empty">Nothing here.</div>'; return; }
    el.innerHTML = rows.map(c => {
        const acts = c.status === 'due'
            ? `<button onclick="mark(${c.id},'paid')" class="biz-btn biz-btn-primary biz-btn-sm">Mark paid</button>
               <button onclick="mark(${c.id},'waive')" class="biz-btn biz-btn-ghost biz-btn-sm">Waive</button>
               <button onclick="mark(${c.id},'void')" class="biz-btn biz-btn-danger biz-btn-sm">Void</button>`
            : `<button onclick="mark(${c.id},'reopen')" class="biz-btn biz-btn-ghost biz-btn-sm">Reopen</button>`;
        return `
        <div class="biz-row" style="cursor:default;flex-wrap:wrap">
            <span class="min-w-0 flex-1">
                <span class="block truncate" style="font-weight:600">${esc(c.company_name)}
                    <span class="biz-chip biz-c-slate">${esc(c.package_label || c.package_key)}</span>
                    ${Number(c.overdue) ? '<span class="biz-chip biz-c-red">overdue</span>' : ''}
                </span>
                <span class="block biz-muted" style="font-size:11px">
                    ${fmtMonth(c.period_start)} · due ${fmtDate(c.due_on)}${c.paid_on ? ' · paid ' + fmtDate(c.paid_on) + (c.paid_method ? ' (' + esc(c.paid_method) + ')' : '') : ''}${c.invoice_ref ? ' · ' + esc(c.invoice_ref) : ''}
                </span>
            </span>
            <span class="shrink-0 biz-num" style="font-weight:700">${esc(c.currency)} ${m(c.amount)}</span>
            <span class="flex gap-1">${acts}</span>
        </div>`;
    }).join('');
}

async function runCycle(){
    try {
        const r = await api('billing_run.php', {});
        showAlert(`Billing run: ${r.created} charge(s) created for ${fmtMonth(r.month)}.`, 'ok');
        load();
    } catch (e){ showAlert(e.message, 'error'); }
}

async function mark(id, action){
    let body = { charge_id: id, action };
    if (action === 'paid') {
        const method = prompt('Payment method (bank transfer / cash / card / …):', 'bank transfer');
        if (method === null) return;
        body.method = method;
    }
    if (action === 'void' || action === 'waive') {
        const note = prompt((action === 'void' ? 'Void' : 'Waive') + ' — reason (optional):');
        if (note === null) return;
        body.note = note;
    }
    try { await api('billing_charge.php', body); showAlert('Charge updated.', 'ok'); load(); }
    catch (e){ showAlert(e.message, 'error'); }
}

load();
</script>
</body>
</html>
