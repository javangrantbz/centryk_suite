<?php
/**
 * Field Sales & Routes (Centryk Business package).
 *
 * Plan delivery runs, record what each stop paid, and settle the driver at the
 * end of the day — expected cash vs what was handed in, variance flagged.
 * Per-stop collections post through the Receivables ledger. Gated on 'routes'.
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

$level = $activeCompany ? Entitlements::level((int)$activeCompany['id'], 'routes') : Entitlements::NONE;

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Field Sales & Routes'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Field Sales & Routes'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; $bizNav = 'routes'; include __DIR__ . '/partials/business_sidebar.php'; ?>

<div class="biz mx-auto max-w-6xl px-4 py-4">

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Centryk Business · Field Sales &amp; Routes</p>
            <h1 class="mt-0.5">Routes &amp; settlement</h1>
        </div>
        <a href="routes_field.php" class="biz-btn biz-btn-ghost biz-btn-sm">Driver view ›</a>
        <?php if (count($companies) > 1): ?>
            <div class="biz-seg">
                <?php foreach ($companies as $c): ?>
                    <a href="routes.php?company_id=<?= (int)$c['id'] ?>"
                       class="<?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'is-active' : '' ?>">
                        <?= htmlspecialchars($c['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">You need to be an admin or manager of a company to use Routes.</div>
    <?php elseif ($level === Entitlements::NONE): ?>
        <div class="biz-panel" style="padding:28px 16px;text-align:center">
            <div style="margin:0 auto;display:flex;height:36px;width:36px;align-items:center;justify-content:center;border-radius:4px;background:#eef2ff;color:#4f46e5">
                <i data-lucide="truck" style="height:18px;width:18px"></i>
            </div>
            <h2 style="margin-top:10px;font-size:15px">Field Sales &amp; Routes is part of Centryk Business</h2>
            <p class="biz-muted" style="margin:4px auto 0;max-width:28rem;font-size:12px">
                Plan delivery runs, record what each stop pays, and settle every driver's cash at
                the end of the day. Ask a Centryk advisor to switch it on for <?= htmlspecialchars($activeCompany['name']) ?>.
            </p>
            <a href="business.php?company_id=<?= (int)$activeCompany['id'] ?>" class="biz-btn biz-btn-primary" style="margin-top:12px">Explore Centryk Business</a>
        </div>
    <?php else: ?>

        <?php if ($level === Entitlements::READ): ?>
            <div class="biz-notice biz-notice-amber mb-3">Your Routes subscription is paused — planning and settlement are disabled until billing is resolved.</div>
        <?php endif; ?>

        <div id="alert" class="biz-notice mb-3 hidden"></div>

        <div id="summaryStrip" class="grid grid-cols-2 gap-2 sm:grid-cols-4"></div>

        <details class="biz-panel mt-3" ontoggle="if(this.open) loadDrivers()">
            <summary style="cursor:pointer;padding:6px 10px;font-size:12px;font-weight:600;color:#334155">Driver performance (last 30 days)</summary>
            <div id="driverRows" class="biz-list" style="max-height:40vh;overflow-y:auto"><div class="biz-panel-empty">Open to load…</div></div>
        </details>

        <details class="biz-panel mt-3" ontoggle="if(this.open) loadCommission()">
            <summary style="cursor:pointer;padding:6px 10px;font-size:12px;font-weight:600;color:#334155">Commission</summary>
            <div id="commissionBox" class="biz-panel-body"><div class="biz-panel-empty">Open to load…</div></div>
        </details>

        <div class="mt-3 grid gap-3 lg:grid-cols-[320px_minmax(0,1fr)]">
            <div class="space-y-3">
                <div class="biz-panel">
                    <div class="biz-panel-head">
                        <span>Routes</span>
                        <?php if ($level === Entitlements::FULL): ?>
                        <button onclick="routeForm()" class="biz-btn biz-btn-ghost biz-btn-sm">+ New</button>
                        <?php endif; ?>
                    </div>
                    <div id="routeRows" class="biz-list"><div class="biz-panel-empty">Loading…</div></div>
                </div>

                <div class="biz-panel">
                    <div class="biz-panel-head">
                        <span>Trips</span>
                        <select id="fStatus" onchange="load()" class="biz-select" style="height:22px;width:auto;font-size:11px">
                            <option value="open">Open</option>
                            <option value="settled">Settled</option>
                            <option value="">All</option>
                        </select>
                    </div>
                    <div id="tripRows" class="biz-list max-h-[52vh] overflow-y-auto"></div>
                </div>
            </div>

            <div id="tripPanel" class="biz-panel biz-panel-empty self-start">Pick a trip, or start one from a route.</div>
        </div>

    <?php endif; ?>
</div>

<script>
if (window.lucide) lucide.createIcons();
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
const CAN_WRITE = <?= $level === Entitlements::FULL ? 'true' : 'false' ?>;
const IS_ADMIN = <?= $isCompanyAdmin ? 'true' : 'false' ?>;
let DATA = { summary: {}, routes: [], trips: [] };
let ROUTE_FILTER = 0;
let OPEN_TRIP = null;

const METHODS = { none: '—', cash: 'Cash', card: 'Card', bank_transfer: 'Transfer', xfer: 'XFER', cheque: 'Cheque' };
const STOP_STATUS = { pending: 'Pending', delivered: 'Delivered', paid: 'Paid', skipped: 'Skipped' };

function esc(s){ return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function money(v){ return Number(v || 0).toLocaleString('en-BZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function fmtDate(s){ if(!s) return '—'; return new Date(String(s).replace(' ','T')).toLocaleDateString('en-BZ',{year:'2-digit',month:'short',day:'numeric'}); }

function showAlert(msg, type){
    const el = document.getElementById('alert'); if(!el) return;
    el.textContent = msg;
    el.className = 'biz-notice mb-3 ' + (type === 'error' ? 'biz-notice-red' : 'biz-notice-green');
    el.classList.remove('hidden');
    clearTimeout(showAlert._t); showAlert._t = setTimeout(()=>el.classList.add('hidden'), 5000);
}

async function api(path, body){
    const res = await fetch('api/routes/' + path, {
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

async function load(){
    if (CID === null) return;
    try {
        DATA = await api('overview.php', { status: document.getElementById('fStatus').value, route_id: ROUTE_FILTER });
        const s = DATA.summary;
        document.getElementById('summaryStrip').innerHTML =
            tile('On the road', s.out) +
            tile('Awaiting approval', s.awaiting_approval, s.awaiting_approval ? 'biz-t-amber' : '') +
            tile('Cash in transit', money(s.cash_in_transit), s.cash_in_transit > 0 ? 'biz-t-amber' : '') +
            tile('Variance flags (30d)', s.variance_flags, s.variance_flags ? 'biz-t-red' : '');
        renderRoutes();
        renderTrips();
        if (OPEN_TRIP) openTrip(OPEN_TRIP);
    } catch (e){ showAlert(e.message, 'error'); }
}

function renderRoutes(){
    const el = document.getElementById('routeRows');
    if (!DATA.routes.length){ el.innerHTML = '<div class="biz-panel-empty">No routes yet.</div>'; return; }
    el.innerHTML = DATA.routes.map(r => `
        <div class="biz-row ${ROUTE_FILTER === r.id ? 'is-active' : ''}" style="cursor:default">
            <button onclick="filterRoute(${r.id})" class="min-w-0 flex-1 text-left" style="background:none">
                <span class="block truncate" style="font-weight:600">${esc(r.name)}</span>
                <span class="block biz-muted" style="font-size:11px">${r.default_driver_name ? esc(r.default_driver_name) + ' · ' : ''}${r.open_trips || 0} open</span>
            </button>
            ${CAN_WRITE ? `<button onclick="tripForm(${r.id})" class="biz-btn biz-btn-primary biz-btn-sm shrink-0">Trip</button>
            <button onclick="routeForm(${r.id})" title="Edit" class="shrink-0 biz-muted" style="background:none"><i data-lucide="pencil" style="height:13px;width:13px"></i></button>` : ''}
        </div>`).join('');
    if (window.lucide) lucide.createIcons();
}
function filterRoute(id){ ROUTE_FILTER = (ROUTE_FILTER === id ? 0 : id); load(); }

function tripBadge(st){
    const map = { planned: 'biz-c-slate', out: 'biz-c-blue', settling: 'biz-c-amber', settled: 'biz-c-green' };
    return `<span class="biz-chip ${map[st] || 'biz-c-slate'}">${esc(st)}</span>`;
}

function renderTrips(){
    const el = document.getElementById('tripRows');
    if (!DATA.trips.length){ el.innerHTML = '<div class="biz-panel-empty">No trips.</div>'; return; }
    el.innerHTML = DATA.trips.map(t => `
        <button onclick="openTrip(${t.id})" class="biz-row ${OPEN_TRIP === t.id ? 'is-active' : ''}">
            <span class="min-w-0 flex-1">
                <span class="block truncate" style="font-weight:600">${esc(t.route_name)} ${tripBadge(t.status)}</span>
                <span class="block biz-muted" style="font-size:11px">${fmtDate(t.trip_date)}${t.driver_name ? ' · ' + esc(t.driver_name) : ''} · ${t.done_count}/${t.stop_count} stops</span>
            </span>
            <span class="shrink-0 text-right biz-muted" style="font-size:11px">
                <span class="biz-num" style="font-weight:700;color:var(--bz-fg)">${money(t.cash_expected)}</span><br>cash
            </span>
        </button>`).join('');
}

/* ── route + trip forms ────────────────────────────────────────────────── */
function fld(label, inner){ return `<label class="block"><span class="biz-label">${label}</span>${inner}</label>`; }

function routeForm(id){
    const r = DATA.routes.find(x => x.id === id) || {};
    showPanelForm(`
        <p class="biz-kicker">${id ? 'Edit route' : 'New route'}</p>
        <form onsubmit="saveRoute(event, ${id || 0})" class="mt-2 space-y-2">
            <input name="name" required value="${esc(r.name || '')}" placeholder="Route name" class="biz-input">
            <input name="default_driver_name" value="${esc(r.default_driver_name || '')}" placeholder="Default driver (optional)" class="biz-input">
            <input name="notes" value="${esc(r.notes || '')}" placeholder="Notes (optional)" class="biz-input">
            <div class="flex gap-2">
                <button class="biz-btn biz-btn-primary">Save</button>
                <button type="button" onclick="clearPanel()" class="biz-btn biz-btn-ghost">Cancel</button>
            </div>
        </form>`);
}
async function saveRoute(e, id){
    e.preventDefault();
    const f = e.target;
    try {
        await api('route_save.php', { id, name: f.name.value, default_driver_name: f.default_driver_name.value, notes: f.notes.value });
        showAlert('Route saved.', 'ok'); clearPanel(); load();
    } catch (err){ showAlert(err.message, 'error'); }
}
function tripForm(routeId){
    const today = new Date().toISOString().slice(0,10);
    showPanelForm(`
        <p class="biz-kicker">Start a trip</p>
        <form onsubmit="createTrip(event, ${routeId})" class="mt-2 space-y-2">
            ${fld('Date', `<input name="trip_date" type="date" value="${today}" class="biz-input">`)}
            ${fld('Driver', '<input name="driver_name" placeholder="leave blank for the route default" class="biz-input">')}
            <div class="flex gap-2">
                <button class="biz-btn biz-btn-primary">Create</button>
                <button type="button" onclick="clearPanel()" class="biz-btn biz-btn-ghost">Cancel</button>
            </div>
        </form>`);
}
async function createTrip(e, routeId){
    e.preventDefault();
    const f = e.target;
    try {
        const r = await api('trip_create.php', { route_id: routeId, trip_date: f.trip_date.value, driver_name: f.driver_name.value });
        showAlert('Trip created.', 'ok');
        OPEN_TRIP = r.trip_id;
        await load();
    } catch (err){ showAlert(err.message, 'error'); }
}

function showPanelForm(html){
    const p = document.getElementById('tripPanel');
    p.className = 'biz-panel self-start';
    p.innerHTML = `<div class="biz-panel-body">${html}</div>`;
}
function clearPanel(){
    const p = document.getElementById('tripPanel');
    p.className = 'biz-panel biz-panel-empty self-start';
    p.textContent = 'Pick a trip, or start one from a route.';
    OPEN_TRIP = null;
}

/* ── trip detail ───────────────────────────────────────────────────────── */
async function openTrip(id){
    OPEN_TRIP = id;
    renderTrips();
    try { renderTrip((await api('trip.php', { trip_id: id })).trip); }
    catch (e){ showAlert(e.message, 'error'); }
}

function renderTrip(t){
    const p = document.getElementById('tripPanel');
    p.className = 'biz-panel self-start';
    const locked = t.status === 'settled';
    const submitted = !!t.settlement_submitted_at;
    const pendingApproval = t.status === 'settling' && submitted && !locked;
    const rw = CAN_WRITE && !locked && !submitted;

    const nextBtn = (() => {
        if (!rw) return '';
        if (t.status === 'planned')  return `<button onclick="tripStatus(${t.id},'out')" class="biz-btn biz-btn-primary">Send out</button>`;
        if (t.status === 'out')      return `<button onclick="tripStatus(${t.id},'settling')" class="biz-btn" style="background:#f59e0b;color:#fff">Back — start settlement</button>`;
        if (t.status === 'settling') return `<button onclick="tripStatus(${t.id},'out')" class="biz-btn biz-btn-ghost">Re-open route</button>`;
        return '';
    })();

    const stopList = t.stops || [];
    const stops = stopList.map((s, idx) => `
        <div class="biz-row" style="display:block;cursor:default">
            <div class="flex items-center gap-2">
                ${rw && stopList.length > 1 ? `<span class="shrink-0 flex flex-col" style="line-height:1">
                    <button onclick="moveStop(${t.id}, ${idx}, -1)" ${idx === 0 ? 'disabled' : ''} class="biz-muted" style="background:none;font-size:9px;opacity:${idx === 0 ? '.3' : '1'}">▲</button>
                    <button onclick="moveStop(${t.id}, ${idx}, 1)" ${idx === stopList.length - 1 ? 'disabled' : ''} class="biz-muted" style="background:none;font-size:9px;opacity:${idx === stopList.length - 1 ? '.3' : '1'}">▼</button>
                </span>` : ''}
                <span class="shrink-0 biz-muted biz-num" style="font-size:11px;width:16px">${idx + 1}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate" style="font-size:12px;font-weight:600">${esc(s.customer_name)}</p>
                    <p class="biz-muted" style="font-size:11px">${STOP_STATUS[s.status] || s.status}${Number(s.amount_collected) > 0 ? ' · ' + money(s.amount_collected) + ' ' + (METHODS[s.method] || s.method) : ''}${s.note ? ' · ' + esc(s.note) : ''}</p>
                </div>
                ${rw ? `<button onclick="stopForm(${s.id})" class="biz-btn biz-btn-ghost biz-btn-sm shrink-0">Record</button>` : ''}
            </div>
            <div id="stopForm-${s.id}"></div>
        </div>`).join('') || '<div class="biz-panel-empty">No stops yet.</div>';

    const v = Number(t.cash_variance || 0);
    const off = Math.abs(v) > 0.01;
    const cashRows = `
        <div class="mt-1 grid grid-cols-2 gap-1" style="font-size:12px">
            <span class="biz-muted">Cash expected</span><span class="text-right biz-num" style="font-weight:700">${money(t.cash_expected)}</span>
            <span class="biz-muted">Electronic</span><span class="text-right biz-num biz-muted" style="font-weight:600">${money(t.electronic_total)}</span>
            ${submitted ? `<span class="biz-muted">Cash declared</span><span class="text-right biz-num" style="font-weight:600">${money(t.cash_declared)}</span>
            <span class="biz-muted">Variance</span><span class="text-right biz-num ${off ? 'biz-t-red' : ''}" style="font-weight:700">${money(v)}</span>` : ''}
        </div>`;

    let settle = '';
    if (!submitted && (t.status === 'out' || t.status === 'settling')) {
        settle = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line);background:var(--bz-head)">
            <p class="biz-kicker">Settlement</p>
            ${cashRows}
            ${rw ? `<form onsubmit="settleTrip(event, ${t.id})" class="mt-2 space-y-2">
                ${fld('Cash declared by driver', `<input name="cash_declared" type="number" step="0.01" required value="${Number(t.cash_expected).toFixed(2)}" class="biz-input">`)}
                <input name="notes" placeholder="note (optional)" class="biz-input">
                <button class="biz-btn biz-btn-primary">Submit settlement</button>
            </form>
            <p class="biz-muted mt-1" style="font-size:11px">A company admin approves before it locks.</p>` : ''}
        </div>`;
    } else if (pendingApproval) {
        settle = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line);background:#fffbeb">
            <p class="biz-kicker" style="color:#b45309">Awaiting approval</p>
            ${cashRows}
            <p class="biz-muted" style="font-size:11px;margin-top:6px">Submitted ${fmtDate(t.settlement_submitted_at)}${t.submitted_by_name ? ' by ' + esc(t.submitted_by_name) : ''}.${t.notes ? ' ' + esc(t.notes) : ''}</p>
            ${IS_ADMIN ? `<div class="mt-2 flex gap-2">
                <button onclick="approveSettle(${t.id})" class="biz-btn" style="background:#059669;color:#fff">Approve &amp; lock</button>
                <button onclick="reopenSettle(${t.id})" class="biz-btn biz-btn-ghost">Reopen</button>
            </div>` : '<p class="biz-muted" style="font-size:11px">Only a company admin can approve.</p>'}
        </div>`;
    } else if (locked) {
        settle = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line);background:${off ? '#fef2f2' : '#f0fdf4'}">
            <p class="biz-kicker" style="color:${off ? '#b91c1c' : '#15803d'}">Settled ${fmtDate(t.settled_at)}</p>
            ${cashRows}
            <p class="biz-muted" style="font-size:11px;margin-top:6px">
                ${t.submitted_by_name ? 'Submitted by ' + esc(t.submitted_by_name) + '. ' : ''}${t.approved_by_name ? 'Approved by ' + esc(t.approved_by_name) + '.' : ''}
            </p>
            ${IS_ADMIN ? `<button onclick="reopenSettle(${t.id})" class="biz-btn biz-btn-ghost mt-1">Reopen</button>` : ''}
        </div>`;
    }

    const members = DATA.members || [];
    const driverPicker = (rw && members.length) ? `
        <div class="mt-2 flex items-center gap-2">
            <span class="biz-muted" style="font-size:11px">Driver</span>
            <select onchange="assignDriver(${t.id}, this.value)" class="biz-select" style="height:24px;width:auto;font-size:11px">
                <option value="">— unassigned —</option>
                ${members.map(mem => `<option value="${mem.id}" ${String(t.driver_user_id) === String(mem.id) ? 'selected' : ''}>${esc(mem.name || ('User ' + mem.id))}${mem.role === 'employee' ? '' : ' (' + esc(mem.role) + ')'}</option>`).join('')}
            </select>
            ${t.driver_user_id ? '<span class="biz-muted" style="font-size:11px">has the field link</span>' : ''}
        </div>` : '';

    p.innerHTML = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line)">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2>${esc(t.route_name)} ${tripBadge(t.status)}</h2>
                    <p class="biz-muted" style="font-size:11px;margin-top:2px">${fmtDate(t.trip_date)}${t.driver_name ? ' · ' + esc(t.driver_name) : ''}</p>
                </div>
                ${nextBtn}
            </div>
            ${driverPicker}
        </div>
        ${settle}
        <div class="biz-panel-head">
            <span>Stops</span>
            ${rw ? `<button onclick="addStopForm(${t.id})" class="biz-btn biz-btn-ghost biz-btn-sm">+ Add stop</button>` : ''}
        </div>
        <div id="addStopBox"></div>
        <div class="biz-list">${stops}</div>`;
}

function stopForm(stopId){
    const box = document.getElementById('stopForm-' + stopId);
    if (box.innerHTML) { box.innerHTML = ''; return; }
    box.innerHTML = `
        <form onsubmit="recordStop(event, ${stopId})" class="mt-2 grid gap-2 sm:grid-cols-2" style="border:1px solid var(--bz-line);border-radius:4px;background:var(--bz-head);padding:8px">
            ${fld('Outcome', `<select name="status" class="biz-select">${Object.entries(STOP_STATUS).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}</select>`)}
            ${fld('Method', `<select name="method" class="biz-select">${Object.entries(METHODS).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}</select>`)}
            ${fld('Amount collected', '<input name="amount_collected" type="number" step="0.01" min="0" value="0" class="biz-input">')}
            ${fld('Note', '<input name="note" class="biz-input">')}
            <div class="sm:col-span-2 flex gap-2">
                <button class="biz-btn biz-btn-primary">Save</button>
                <button type="button" onclick="document.getElementById('stopForm-${stopId}').innerHTML=''" class="biz-btn biz-btn-ghost">Cancel</button>
            </div>
        </form>`;
}
async function recordStop(e, stopId){
    e.preventDefault();
    const f = e.target;
    try {
        await api('stop_record.php', {
            stop_id: stopId, status: f.status.value, method: f.method.value,
            amount_collected: f.amount_collected.value, note: f.note.value,
        });
        showAlert('Stop recorded.', 'ok'); openTrip(OPEN_TRIP); load();
    } catch (err){ showAlert(err.message, 'error'); }
}

let stopSearchTimer = null;
function addStopForm(tripId){
    const box = document.getElementById('addStopBox');
    if (box.innerHTML) { box.innerHTML = ''; return; }
    box.innerHTML = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line);background:var(--bz-head)">
            <input id="stopSearch" placeholder="Search customers…" class="biz-input">
            <div id="stopSearchResults" class="mt-1 space-y-1"></div>
        </div>`;
    document.getElementById('stopSearch').addEventListener('input', (e) => {
        clearTimeout(stopSearchTimer);
        const q = e.target.value.trim();
        stopSearchTimer = setTimeout(async () => {
            try {
                const d = await api('customers.php', { q });
                document.getElementById('stopSearchResults').innerHTML = (d.customers || []).map(c => `
                    <button onclick="addStop(${tripId}, ${c.id})" class="block w-full text-left" style="border:1px solid var(--bz-line);border-radius:3px;background:#fff;padding:4px 8px;font-size:12px;font-weight:600">
                        ${esc(c.name)}${c.company ? ' · ' + esc(c.company) : ''}
                    </button>`).join('') || '<p class="biz-muted" style="font-size:11px">No matches.</p>';
            } catch (err){ showAlert(err.message, 'error'); }
        }, 200);
    });
}
async function addStop(tripId, customerId){
    try {
        await api('stop_add.php', { trip_id: tripId, customer_id: customerId });
        showAlert('Stop added.', 'ok'); openTrip(tripId); load();
    } catch (err){ showAlert(err.message, 'error'); }
}
async function moveStop(tripId, idx, dir){
    const { trip } = await api('trip.php', { trip_id: tripId });
    const ids = (trip.stops || []).map(s => s.id);
    const j = idx + dir;
    if (j < 0 || j >= ids.length) return;
    [ids[idx], ids[j]] = [ids[j], ids[idx]];
    try {
        await api('stops_reorder.php', { trip_id: tripId, stop_ids: ids });
        openTrip(tripId);
    } catch (err){ showAlert(err.message, 'error'); }
}

async function tripStatus(tripId, status){
    try {
        await api('trip_status.php', { trip_id: tripId, status });
        openTrip(tripId); load();
    } catch (err){ showAlert(err.message, 'error'); }
}
async function settleTrip(e, tripId){
    e.preventDefault();
    const f = e.target;
    try {
        const r = await api('settle.php', { trip_id: tripId, cash_declared: f.cash_declared.value, notes: f.notes.value });
        showAlert(`Settlement submitted. Variance ${money(r.variance)}${Math.abs(r.variance) > 0.01 ? ' — flagged' : ''}. Awaiting approval.`, Math.abs(r.variance) > 0.01 ? 'error' : 'ok');
        openTrip(tripId); load();
    } catch (err){ showAlert(err.message, 'error'); }
}
async function loadDrivers(){
    const box = document.getElementById('driverRows');
    try {
        const d = await api('drivers.php', { days: 30 });
        const rows = d.drivers || [];
        if (!rows.length){ box.innerHTML = '<div class="biz-panel-empty">No settled trips in the last 30 days.</div>'; return; }
        box.innerHTML = rows.map(r => {
            const off = Math.abs(r.net_variance) > 0.01;
            return `<div class="biz-row" style="display:block">
                <div class="flex items-center justify-between gap-2">
                    <span style="font-weight:600">${esc(r.driver)}</span>
                    <span class="biz-num" style="font-weight:700">${money(r.total_collected)}</span>
                </div>
                <div class="biz-muted" style="font-size:11px">
                    ${r.trips} trip${r.trips === 1 ? '' : 's'} · ${r.stops_done} stops · cash ${money(r.cash_collected)} · electronic ${money(r.electronic_collected)}
                    · <span class="${off ? 'biz-t-red' : ''}">variance ${money(r.net_variance)}</span>${r.flagged ? ` · ${r.flagged} flagged` : ''}${Number(r.commission) > 0 ? ` · <span class="biz-t-green">commission ${money(r.commission)}</span>` : ''}
                </div>
            </div>`;
        }).join('');
    } catch (e){ box.innerHTML = '<div class="biz-panel-empty">' + esc(e.message) + '</div>'; }
}

const COMM_BASIS = {
    collections_total: '% of all collections',
    collections_cash: '% of cash collected',
    collections_electronic: '% of electronic collected',
    stops_delivered: 'flat BZD per delivered stop',
};
let COMMISSION = null;

async function loadCommission(){
    const box = document.getElementById('commissionBox');
    const monthStart = new Date().toISOString().slice(0,8) + '01';
    try {
        COMMISSION = await api('commission.php', { from: monthStart });
        renderCommission();
    } catch (e){ box.innerHTML = '<div class="biz-panel-empty">' + esc(e.message) + '</div>'; }
}

function renderCommission(){
    const box = document.getElementById('commissionBox');
    const d = COMMISSION;
    const rules = (d.rules || []).filter(r => Number(r.active) === 1);
    const st = d.statement || { drivers: [], total: 0, from: '', to: '' };

    const ruleRows = rules.length ? rules.map(r => {
        const target = r.scope === 'driver' ? esc(r.driver_name || ('user ' + r.driver_user_id))
            : r.scope === 'route' ? esc(r.route_name || ('route ' + r.route_id)) : 'Company default';
        const rateTxt = r.basis === 'stops_delivered' ? money(r.rate) + '/stop' : (+r.rate) + '%';
        return `<div class="biz-row" style="font-size:12px">
            <span class="min-w-0 flex-1">
                <span style="font-weight:600">${target}</span>
                <span class="biz-muted" style="font-size:11px">&nbsp;${rateTxt} — ${COMM_BASIS[r.basis] || r.basis}${r.note ? ' · ' + esc(r.note) : ''}</span>
            </span>
            ${IS_ADMIN ? `<span class="shrink-0 flex gap-1">
                <button onclick='commRuleForm(${JSON.stringify(r)})' class="biz-btn biz-btn-ghost biz-btn-sm">Edit</button>
                <button onclick="removeCommRule(${r.id})" class="biz-btn biz-btn-ghost biz-btn-sm">Remove</button>
            </span>` : ''}
        </div>`;
    }).join('') : '<div class="biz-panel-empty">No commission rules — drivers earn nothing until you add one.</div>';

    const earn = st.drivers.length ? st.drivers.map(x => `
        <div class="biz-row" style="font-size:12px">
            <span class="min-w-0 flex-1">
                <span style="font-weight:600">${esc(x.driver)}</span>
                <span class="biz-muted" style="font-size:11px">&nbsp;${x.trips} trip${x.trips === 1 ? '' : 's'} · ${money(x.collections)} collected</span>
            </span>
            <span class="shrink-0 biz-num biz-t-green" style="font-weight:700">${money(x.commission)}</span>
        </div>`).join('') : '<div class="biz-panel-empty">No settled trips this month.</div>';

    box.innerHTML = `
        <div class="flex items-center justify-between">
            <p class="biz-kicker">Rules</p>
            ${IS_ADMIN ? `<button onclick="commRuleForm()" class="biz-btn biz-btn-ghost biz-btn-sm">+ Rule</button>` : ''}
        </div>
        <div class="biz-list mt-1">${ruleRows}</div>
        <div id="commRuleForm" class="mt-2"></div>
        <div class="mt-3 flex items-center justify-between">
            <p class="biz-kicker">This month · earned ${money(st.total)}</p>
            <a href="routes_commission.php?company_id=${CID}&from=${st.from}&to=${st.to}" target="_blank" rel="noopener" class="biz-btn biz-btn-ghost biz-btn-sm">Statement / payroll</a>
        </div>
        <div class="biz-list mt-1">${earn}</div>`;
}

function commRuleForm(rule){
    const r = rule || { scope: 'company', basis: 'collections_total', rate: '', note: '' };
    const routeOpts = (DATA.routes || []).map(x => `<option value="${x.id}" ${String(r.route_id) === String(x.id) ? 'selected' : ''}>${esc(x.name)}</option>`).join('');
    const memberOpts = (DATA.members || []).map(x => `<option value="${x.id}" ${String(r.driver_user_id) === String(x.id) ? 'selected' : ''}>${esc(x.name || ('User ' + x.id))}</option>`).join('');
    document.getElementById('commRuleForm').innerHTML = `
        <form onsubmit="submitCommRule(event, ${r.id || 0})" style="border:1px solid var(--bz-line);border-radius:4px;background:var(--bz-head);padding:8px">
            <div class="grid gap-2 sm:grid-cols-2">
                ${fld('Applies to', `<select name="scope" onchange="commScope(this)" class="biz-select">
                    <option value="company" ${r.scope === 'company' ? 'selected' : ''}>Company default</option>
                    <option value="route" ${r.scope === 'route' ? 'selected' : ''}>A route</option>
                    <option value="driver" ${r.scope === 'driver' ? 'selected' : ''}>A driver</option></select>`)}
                <div data-scope="route" style="${r.scope === 'route' ? '' : 'display:none'}">${fld('Route', `<select name="route_id" class="biz-select">${routeOpts}</select>`)}</div>
                <div data-scope="driver" style="${r.scope === 'driver' ? '' : 'display:none'}">${fld('Driver', `<select name="driver_user_id" class="biz-select">${memberOpts}</select>`)}</div>
                ${fld('Basis', `<select name="basis" class="biz-select">${Object.entries(COMM_BASIS).map(([k,v])=>`<option value="${k}" ${r.basis === k ? 'selected' : ''}>${v}</option>`).join('')}</select>`)}
                ${fld('Rate', `<input name="rate" type="number" step="0.01" min="0.01" value="${r.rate || ''}" required class="biz-input" placeholder="e.g. 2.5">`)}
                ${fld('Note', `<input name="note" value="${esc(r.note || '')}" class="biz-input">`)}
            </div>
            <div class="mt-2 flex gap-2">
                <button class="biz-btn biz-btn-primary biz-btn-sm">Save rule</button>
                <button type="button" onclick="document.getElementById('commRuleForm').innerHTML=''" class="biz-btn biz-btn-ghost biz-btn-sm">Cancel</button>
            </div>
        </form>`;
}
function commScope(sel){
    sel.form.querySelectorAll('[data-scope]').forEach(el => { el.style.display = el.dataset.scope === sel.value ? '' : 'none'; });
}
async function submitCommRule(e, id){
    e.preventDefault();
    const f = e.target;
    const body = { id, scope: f.scope.value, basis: f.basis.value, rate: f.rate.value, note: f.note.value };
    if (f.scope.value === 'route') body.route_id = f.route_id.value;
    if (f.scope.value === 'driver') body.driver_user_id = f.driver_user_id.value;
    try {
        await api('commission_rule_save.php', body);
        showAlert('Commission rule saved.', 'ok');
        loadCommission();
    } catch (err){ showAlert(err.message, 'error'); }
}
async function removeCommRule(id){
    if (!confirm('Remove this commission rule?')) return;
    try {
        await api('commission_rule_delete.php', { rule_id: id });
        showAlert('Rule removed.', 'ok');
        loadCommission();
    } catch (err){ showAlert(err.message, 'error'); }
}

async function assignDriver(tripId, driverUserId){
    try {
        await api('assign_driver.php', { trip_id: tripId, driver_user_id: driverUserId || null });
        showAlert(driverUserId ? 'Driver assigned — they can open it from routes_field.php.' : 'Driver cleared.', 'ok');
        openTrip(tripId); load();
    } catch (err){ showAlert(err.message, 'error'); }
}
async function approveSettle(tripId){
    try { await api('settle_approve.php', { trip_id: tripId }); showAlert('Settlement approved — trip locked.', 'ok'); openTrip(tripId); load(); }
    catch (err){ showAlert(err.message, 'error'); }
}
async function reopenSettle(tripId){
    try { await api('settle_reopen.php', { trip_id: tripId }); showAlert('Settlement reopened.', 'ok'); openTrip(tripId); load(); }
    catch (err){ showAlert(err.message, 'error'); }
}

load();
</script>
<?php include __DIR__ . '/partials/business_sidebar_end.php'; ?>
</body>
</html>
