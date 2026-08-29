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

$level = $activeCompany ? Entitlements::level((int)$activeCompany['id'], 'routes') : Entitlements::NONE;

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Field Sales & Routes'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Field Sales & Routes'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="biz mx-auto max-w-6xl px-4 py-4">

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Centryk Business · Field Sales &amp; Routes</p>
            <h1 class="mt-0.5">Routes &amp; settlement</h1>
        </div>
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
            tile('Settling', s.settling, s.settling ? 'biz-t-amber' : '') +
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
    const rw = CAN_WRITE && !locked;

    const nextBtn = (() => {
        if (!rw) return '';
        if (t.status === 'planned')  return `<button onclick="tripStatus(${t.id},'out')" class="biz-btn biz-btn-primary">Send out</button>`;
        if (t.status === 'out')      return `<button onclick="tripStatus(${t.id},'settling')" class="biz-btn" style="background:#f59e0b;color:#fff">Back — start settlement</button>`;
        if (t.status === 'settling') return `<button onclick="tripStatus(${t.id},'out')" class="biz-btn biz-btn-ghost">Re-open route</button>`;
        return '';
    })();

    const stops = (t.stops || []).map(s => `
        <div class="biz-row" style="display:block;cursor:default">
            <div class="flex items-center gap-2">
                <div class="min-w-0 flex-1">
                    <p class="truncate" style="font-size:12px;font-weight:600">${esc(s.customer_name)}</p>
                    <p class="biz-muted" style="font-size:11px">${STOP_STATUS[s.status] || s.status}${Number(s.amount_collected) > 0 ? ' · ' + money(s.amount_collected) + ' ' + (METHODS[s.method] || s.method) : ''}${s.note ? ' · ' + esc(s.note) : ''}</p>
                </div>
                ${rw ? `<button onclick="stopForm(${s.id})" class="biz-btn biz-btn-ghost biz-btn-sm shrink-0">Record</button>` : ''}
            </div>
            <div id="stopForm-${s.id}"></div>
        </div>`).join('') || '<div class="biz-panel-empty">No stops yet.</div>';

    let settle = '';
    if (t.status === 'out' || t.status === 'settling') {
        settle = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line);background:var(--bz-head)">
            <p class="biz-kicker">Settlement</p>
            <div class="mt-1 grid grid-cols-2 gap-1" style="font-size:12px">
                <span class="biz-muted">Cash expected</span><span class="text-right biz-num" style="font-weight:700">${money(t.cash_expected)}</span>
                <span class="biz-muted">Electronic</span><span class="text-right biz-num biz-muted" style="font-weight:600">${money(t.electronic_total)}</span>
            </div>
            ${rw ? `<form onsubmit="settleTrip(event, ${t.id})" class="mt-2 space-y-2">
                ${fld('Cash declared by driver', `<input name="cash_declared" type="number" step="0.01" required value="${Number(t.cash_expected).toFixed(2)}" class="biz-input">`)}
                <input name="notes" placeholder="note (optional)" class="biz-input">
                <button class="biz-btn" style="background:#059669;color:#fff">Settle &amp; lock</button>
            </form>` : ''}
        </div>`;
    } else if (locked) {
        const v = Number(t.cash_variance || 0);
        const off = Math.abs(v) > 0.01;
        settle = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line);background:${off ? '#fef2f2' : '#f0fdf4'}">
            <p class="biz-kicker" style="color:${off ? '#b91c1c' : '#15803d'}">Settled ${fmtDate(t.settled_at)}</p>
            <div class="mt-1 grid grid-cols-2 gap-1" style="font-size:12px">
                <span class="biz-muted">Expected</span><span class="text-right biz-num" style="font-weight:600">${money(t.cash_expected)}</span>
                <span class="biz-muted">Declared</span><span class="text-right biz-num" style="font-weight:600">${money(t.cash_declared)}</span>
                <span class="biz-muted">Variance</span><span class="text-right biz-num ${off ? 'biz-t-red' : ''}" style="font-weight:700">${money(v)}</span>
            </div>
            ${t.notes ? `<p class="biz-muted" style="font-size:11px;margin-top:6px">${esc(t.notes)}</p>` : ''}
        </div>`;
    }

    p.innerHTML = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line)">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2>${esc(t.route_name)} ${tripBadge(t.status)}</h2>
                    <p class="biz-muted" style="font-size:11px;margin-top:2px">${fmtDate(t.trip_date)}${t.driver_name ? ' · ' + esc(t.driver_name) : ''}</p>
                </div>
                ${nextBtn}
            </div>
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
        showAlert(`Settled. Variance ${money(r.variance)}${Math.abs(r.variance) > 0.01 ? ' — flagged' : ''}.`, Math.abs(r.variance) > 0.01 ? 'error' : 'ok');
        openTrip(tripId); load();
    } catch (err){ showAlert(err.message, 'error'); }
}

load();
</script>
</body>
</html>
