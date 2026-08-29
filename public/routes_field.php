<?php
/**
 * Field Sales & Routes — driver view (phone-first).
 *
 * A driver (any company role) sees the runs assigned to them, ticks off each
 * stop as delivered / paid / skipped, then hands in the cash. The trip stays
 * 'settling' until a company admin approves it in routes.php.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated']) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'My Runs'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'My Runs'; $headerMaxW = 'max-w-md'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="biz mx-auto max-w-md px-3 py-3">
    <div id="alert" class="biz-notice mb-3 hidden"></div>

    <div id="tripList">
        <p class="biz-kicker">Assigned to you</p>
        <div id="tripRows" class="biz-list mt-1"><div class="biz-panel-empty">Loading…</div></div>
    </div>

    <div id="tripView" class="hidden"></div>
</div>

<script>
const METHODS = { cash: 'Cash', card: 'Card', bank_transfer: 'Transfer', xfer: 'XFER', cheque: 'Cheque', none: '—' };
let CURRENT = null;   // { company_id, trip_id }

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function m(v){ return 'BZD ' + (Number(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(d){ if (!d) return ''; const x = new Date(d + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }); }
function showAlert(msg, kind){
    const el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'biz-notice mb-3 ' + (kind === 'error' ? 'biz-notice-red' : 'biz-notice-green');
    el.classList.remove('hidden');
    if (kind !== 'error') setTimeout(() => el.classList.add('hidden'), 3000);
}
async function api(path, body){
    const r = await fetch('api/routes/' + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {}),
    });
    const d = await r.json();
    if (!d.success) throw new Error(d.message || 'Something went wrong.');
    return d;
}

const STATUS_LABEL = { planned: 'Not started', out: 'On the road', settling: 'Cash submitted', settled: 'Approved' };

async function loadTrips(){
    try {
        const d = await api('my_trips.php');
        const rows = d.trips || [];
        const box = document.getElementById('tripRows');
        if (!rows.length){ box.innerHTML = '<div class="biz-panel-empty">No runs assigned to you.</div>'; return; }
        box.innerHTML = rows.map(t => `
            <button onclick="openTrip(${t.company_id}, ${t.id})" class="biz-row">
                <span class="min-w-0 flex-1">
                    <span class="block truncate" style="font-weight:600">${esc(t.route_name)}</span>
                    <span class="block biz-muted" style="font-size:11px">${esc(t.company_name)} · ${fmtDate(t.trip_date)} · ${STATUS_LABEL[t.status] || t.status}</span>
                </span>
                <span class="shrink-0 text-right biz-muted" style="font-size:11px">${t.done_count}/${t.stop_count} stops</span>
            </button>`).join('');
    } catch (e){ showAlert(e.message, 'error'); }
}

async function openTrip(companyId, tripId){
    CURRENT = { company_id: companyId, trip_id: tripId };
    try {
        const d = await api('field_trip.php', CURRENT);
        renderTrip(d.trip);
    } catch (e){ showAlert(e.message, 'error'); }
}

function backToList(){
    document.getElementById('tripView').classList.add('hidden');
    document.getElementById('tripList').classList.remove('hidden');
    CURRENT = null;
    loadTrips();
}

function renderTrip(t){
    document.getElementById('tripList').classList.add('hidden');
    const v = document.getElementById('tripView');
    v.classList.remove('hidden');

    const locked = t.status === 'settled' || t.settlement_submitted_at;
    const stops = (t.stops || []);
    const done = stops.filter(s => s.status !== 'pending').length;
    const collectedCash = stops.filter(s => s.method === 'cash').reduce((a, s) => a + Number(s.amount_collected || 0), 0);

    const stopHtml = stops.map(s => {
        const paid = Number(s.amount_collected) > 0;
        const chip = s.status === 'paid' ? '<span class="biz-chip biz-c-green">Paid</span>'
            : s.status === 'delivered' ? '<span class="biz-chip biz-c-blue">Delivered</span>'
            : s.status === 'skipped' ? '<span class="biz-chip biz-c-slate">Skipped</span>' : '';
        return `
        <div class="biz-panel mt-2">
            <div class="biz-panel-body">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div style="font-weight:600">${esc(s.customer_name)} ${chip}</div>
                        ${paid ? `<div class="biz-muted" style="font-size:11px">${m(s.amount_collected)} · ${esc(METHODS[s.method] || s.method)}</div>` : ''}
                    </div>
                </div>
                ${locked ? '' : `
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <button onclick='stopQuick(${s.id}, "delivered")' class="biz-btn biz-btn-ghost">Delivered</button>
                    <button onclick='stopQuick(${s.id}, "skipped")' class="biz-btn biz-btn-ghost">Skipped</button>
                </div>
                <div class="mt-2 flex gap-2">
                    <input id="amt-${s.id}" inputmode="decimal" placeholder="Amount" class="biz-input" value="${paid ? Number(s.amount_collected) : ''}">
                    <select id="mtd-${s.id}" class="biz-select">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="bank_transfer">Transfer</option>
                        <option value="xfer">XFER</option>
                        <option value="cheque">Cheque</option>
                    </select>
                    <button onclick="stopPay(${s.id})" class="biz-btn biz-btn-primary shrink-0">Collect</button>
                </div>`}
            </div>
        </div>`;
    }).join('') || '<div class="biz-panel-empty mt-2">No stops on this run.</div>';

    let settleHtml = '';
    if (t.status === 'settled') {
        settleHtml = `<div class="biz-notice biz-notice-green mt-3">Approved. Declared ${m(t.cash_declared)}, variance ${m(t.cash_variance)}.</div>`;
    } else if (t.settlement_submitted_at) {
        settleHtml = `<div class="biz-notice biz-notice-amber mt-3">Cash submitted (${m(t.cash_declared)}). Waiting for an admin to approve.</div>`;
    } else {
        settleHtml = `
        <div class="biz-panel mt-3">
            <div class="biz-panel-head">Hand in cash</div>
            <div class="biz-panel-body">
                <p class="biz-muted" style="font-size:11px">Cash expected from stops: <strong>${m(collectedCash)}</strong></p>
                <div class="mt-2 flex gap-2">
                    <input id="declared" inputmode="decimal" placeholder="Cash counted" class="biz-input" value="${collectedCash.toFixed(2)}">
                    <button onclick="submitCash()" class="biz-btn biz-btn-primary shrink-0">Submit</button>
                </div>
                <textarea id="settleNote" rows="2" placeholder="Note (optional)" class="biz-input mt-2"></textarea>
            </div>
        </div>`;
    }

    v.innerHTML = `
        <button onclick="backToList()" class="biz-btn biz-btn-ghost biz-btn-sm">‹ My runs</button>
        <div class="mt-2">
            <p class="biz-kicker">${esc(t.company_name || '')}</p>
            <h1 style="font-size:16px">${esc(t.route_name)}</h1>
            <p class="biz-muted" style="font-size:11px">${fmtDate(t.trip_date)} · ${done}/${stops.length} stops done · ${STATUS_LABEL[t.status] || t.status}</p>
        </div>
        ${stopHtml}
        ${settleHtml}`;
}

async function stopQuick(stopId, status){
    try {
        const d = await api('field_stop.php', { ...CURRENT, stop_id: stopId, status });
        renderTrip(d.trip);
    } catch (e){ showAlert(e.message, 'error'); }
}
async function stopPay(stopId){
    const amt = parseFloat(document.getElementById('amt-' + stopId).value || '0');
    const method = document.getElementById('mtd-' + stopId).value;
    if (!(amt > 0)){ showAlert('Enter an amount.', 'error'); return; }
    try {
        const d = await api('field_stop.php', { ...CURRENT, stop_id: stopId, status: 'paid', amount_collected: amt, method });
        renderTrip(d.trip);
    } catch (e){ showAlert(e.message, 'error'); }
}
async function submitCash(){
    const declared = parseFloat(document.getElementById('declared').value || '0');
    const notes = document.getElementById('settleNote').value || '';
    if (!confirm('Submit ' + m(declared) + ' as the cash you counted? An admin will approve it.')) return;
    try {
        const d = await api('field_settle.php', { ...CURRENT, cash_declared: declared, notes });
        showAlert('Cash submitted. Thanks!', 'ok');
        renderTrip(d.trip);
    } catch (e){ showAlert(e.message, 'error'); }
}

loadTrips();
</script>
</body>
</html>
