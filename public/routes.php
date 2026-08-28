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
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Field Sales &amp; Routes</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Field Sales & Routes'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-6xl px-4 pt-4 pb-14">

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-600">Centryk Business · Field Sales &amp; Routes</p>
            <h1 class="mt-0.5 text-2xl font-black tracking-tight text-slate-950">Routes &amp; settlement</h1>
        </div>
        <?php if (count($companies) > 1): ?>
            <div class="flex flex-wrap items-center gap-2">
                <?php foreach ($companies as $c): ?>
                    <a href="routes.php?company_id=<?= (int)$c['id'] ?>"
                       class="rounded-lg border px-3 py-1.5 text-xs font-bold <?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-slate-200 bg-white text-slate-500 hover:border-violet-200' ?>">
                        <?= htmlspecialchars($c['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$companies): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center">
            <p class="text-sm font-bold text-slate-500">You need to be an admin or manager of a company to use Routes.</p>
        </div>
    <?php elseif ($level === Entitlements::NONE): ?>
        <div class="rounded-2xl border border-violet-200 bg-white px-6 py-12 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                <i data-lucide="truck" class="h-6 w-6"></i>
            </div>
            <h2 class="mt-4 text-lg font-black">Field Sales &amp; Routes is part of Centryk Business</h2>
            <p class="mx-auto mt-1 max-w-md text-sm font-semibold text-slate-500">
                Plan delivery runs, record what each stop pays, and settle every driver's cash at
                the end of the day. Ask a Centryk advisor to switch it on for <?= htmlspecialchars($activeCompany['name']) ?>.
            </p>
            <a href="business.php?company_id=<?= (int)$activeCompany['id'] ?>" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-violet-600 px-5 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white hover:bg-violet-700">
                Explore Centryk Business
            </a>
        </div>
    <?php else: ?>

        <?php if ($level === Entitlements::READ): ?>
            <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800">
                Your Routes subscription is paused — planning and settlement are disabled until billing is resolved.
            </div>
        <?php endif; ?>

        <div id="alert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

        <div id="summaryStrip" class="grid grid-cols-2 gap-3 sm:grid-cols-4"></div>

        <div class="mt-4 grid gap-5 lg:grid-cols-[340px_1fr]">
            <div class="space-y-4">
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    <div class="flex items-center justify-between bg-slate-50 px-4 py-2.5">
                        <span class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Routes</span>
                        <?php if ($level === Entitlements::FULL): ?>
                        <button onclick="routeForm()" class="rounded-lg bg-slate-950 px-2.5 py-1 text-[11px] font-black text-white hover:bg-slate-800">+ New</button>
                        <?php endif; ?>
                    </div>
                    <div id="routeRows" class="divide-y divide-slate-100"><div class="px-4 py-6 text-center text-xs text-slate-400">Loading…</div></div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                    <div class="flex items-center justify-between bg-slate-50 px-4 py-2.5">
                        <span class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Trips</span>
                        <select id="fStatus" onchange="load()" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold">
                            <option value="open">Open</option>
                            <option value="settled">Settled</option>
                            <option value="">All</option>
                        </select>
                    </div>
                    <div id="tripRows" class="max-h-[50vh] divide-y divide-slate-100 overflow-y-auto"></div>
                </div>
            </div>

            <div id="tripPanel" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center text-sm text-slate-400">
                Pick a trip, or start one from a route.
            </div>
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
    const res = await fetch('api/routes/' + path, {
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

async function load(){
    if (CID === null) return;
    try {
        DATA = await api('overview.php', { status: document.getElementById('fStatus').value, route_id: ROUTE_FILTER });
        const s = DATA.summary;
        document.getElementById('summaryStrip').innerHTML =
            tile('On the road', s.out) +
            tile('Settling', s.settling, s.settling ? 'text-amber-600' : '') +
            tile('Cash in transit', money(s.cash_in_transit), s.cash_in_transit > 0 ? 'text-amber-600' : '') +
            tile('Variance flags (30d)', s.variance_flags, s.variance_flags ? 'text-red-600' : '');
        renderRoutes();
        renderTrips();
        if (OPEN_TRIP) openTrip(OPEN_TRIP);
    } catch (e){ showAlert(e.message, 'error'); }
}

function renderRoutes(){
    const el = document.getElementById('routeRows');
    if (!DATA.routes.length){ el.innerHTML = '<div class="px-4 py-6 text-center text-xs text-slate-400">No routes yet.</div>'; return; }
    el.innerHTML = DATA.routes.map(r => `
        <div class="flex items-center gap-2 px-4 py-2.5 ${ROUTE_FILTER === r.id ? 'bg-violet-50/60' : ''}">
            <button onclick="filterRoute(${r.id})" class="min-w-0 flex-1 text-left">
                <p class="truncate text-sm font-bold">${esc(r.name)}</p>
                <p class="text-[11px] font-semibold text-slate-400">${r.default_driver_name ? esc(r.default_driver_name) + ' · ' : ''}${r.open_trips || 0} open</p>
            </button>
            ${CAN_WRITE ? `<button onclick="tripForm(${r.id})" title="Start trip" class="shrink-0 rounded-lg bg-violet-600 px-2 py-1 text-[11px] font-black text-white hover:bg-violet-700">Trip</button>
            <button onclick="routeForm(${r.id})" title="Edit" class="shrink-0 text-slate-300 hover:text-slate-600"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></button>` : ''}
        </div>`).join('');
    if (window.lucide) lucide.createIcons();
}
function filterRoute(id){ ROUTE_FILTER = (ROUTE_FILTER === id ? 0 : id); load(); }

function tripStatusBadge(st){
    const map = { planned: 'bg-slate-100 text-slate-500', out: 'bg-sky-50 text-sky-700', settling: 'bg-amber-50 text-amber-700', settled: 'bg-emerald-50 text-emerald-700' };
    return `<span class="rounded px-1.5 py-0.5 text-[10px] font-black uppercase ${map[st] || ''}">${esc(st)}</span>`;
}

function renderTrips(){
    const el = document.getElementById('tripRows');
    if (!DATA.trips.length){ el.innerHTML = '<div class="px-4 py-6 text-center text-xs text-slate-400">No trips.</div>'; return; }
    el.innerHTML = DATA.trips.map(t => `
        <button onclick="openTrip(${t.id})" class="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-slate-50 ${OPEN_TRIP === t.id ? 'bg-violet-50/60' : ''}">
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold">${esc(t.route_name)} ${tripStatusBadge(t.status)}</p>
                <p class="text-[11px] font-semibold text-slate-400">${fmtDate(t.trip_date)}${t.driver_name ? ' · ' + esc(t.driver_name) : ''} · ${t.done_count}/${t.stop_count} stops</p>
            </div>
            <span class="shrink-0 text-right text-[11px] font-bold text-slate-500">
                ${money(t.cash_expected)}<br><span class="text-slate-300">cash</span>
            </span>
        </button>`).join('');
}

/* ── route + trip forms ────────────────────────────────────────────────── */
function routeForm(id){
    const r = DATA.routes.find(x => x.id === id) || {};
    showPanelForm(`
        <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-500">${id ? 'Edit route' : 'New route'}</p>
        <form onsubmit="saveRoute(event, ${id || 0})" class="mt-3 space-y-3">
            <input name="name" required value="${esc(r.name || '')}" placeholder="Route name" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold">
            <input name="default_driver_name" value="${esc(r.default_driver_name || '')}" placeholder="Default driver (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold">
            <input name="notes" value="${esc(r.notes || '')}" placeholder="Notes (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold">
            <div class="flex gap-2">
                <button class="rounded-xl bg-slate-950 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white">Save</button>
                <button type="button" onclick="clearPanel()" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-slate-400">Cancel</button>
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
        <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-500">Start a trip</p>
        <form onsubmit="createTrip(event, ${routeId})" class="mt-3 space-y-3">
            <label class="block"><span class="text-[11px] font-bold text-slate-500">Date</span>
                <input name="trip_date" type="date" value="${today}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
            <label class="block"><span class="text-[11px] font-bold text-slate-500">Driver</span>
                <input name="driver_name" placeholder="leave blank for the route default" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
            <div class="flex gap-2">
                <button class="rounded-xl bg-violet-600 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white">Create</button>
                <button type="button" onclick="clearPanel()" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-slate-400">Cancel</button>
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
    p.className = 'rounded-2xl border border-slate-200 bg-white p-5';
    p.innerHTML = html;
}
function clearPanel(){
    const p = document.getElementById('tripPanel');
    p.className = 'rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center text-sm text-slate-400';
    p.textContent = 'Pick a trip, or start one from a route.';
    OPEN_TRIP = null;
}

/* ── trip detail ───────────────────────────────────────────────────────── */
async function openTrip(id){
    OPEN_TRIP = id;
    renderTrips();
    try {
        const { trip } = await api('trip.php', { trip_id: id });
        renderTrip(trip);
    } catch (e){ showAlert(e.message, 'error'); }
}

function renderTrip(t){
    const p = document.getElementById('tripPanel');
    p.className = 'rounded-2xl border border-slate-200 bg-white p-5 space-y-4';
    const locked = t.status === 'settled';
    const rw = CAN_WRITE && !locked;

    const nextBtn = (() => {
        if (!rw) return '';
        if (t.status === 'planned') return `<button onclick="tripStatus(${t.id},'out')" class="rounded-xl bg-sky-600 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-sky-700">Send out</button>`;
        if (t.status === 'out') return `<button onclick="tripStatus(${t.id},'settling')" class="rounded-xl bg-amber-500 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-amber-600">Back — start settlement</button>`;
        if (t.status === 'settling') return `<button onclick="tripStatus(${t.id},'out')" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-slate-400">Re-open route</button>`;
        return '';
    })();

    const stops = (t.stops || []).map(s => `
        <div class="border-t border-slate-100 py-2.5 first:border-t-0">
            <div class="flex items-center gap-2">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold">${esc(s.customer_name)}</p>
                    <p class="text-[11px] font-semibold text-slate-400">${STOP_STATUS[s.status] || s.status}${Number(s.amount_collected) > 0 ? ' · ' + money(s.amount_collected) + ' ' + (METHODS[s.method] || s.method) : ''}${s.note ? ' · ' + esc(s.note) : ''}</p>
                </div>
                ${rw ? `<button onclick="stopForm(${s.id})" class="shrink-0 rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-black text-slate-500 hover:border-violet-300 hover:text-violet-700">Record</button>` : ''}
            </div>
            <div id="stopForm-${s.id}"></div>
        </div>`).join('') || '<p class="py-2 text-sm text-slate-400">No stops yet.</p>';

    let settle = '';
    if (t.status === 'out' || t.status === 'settling') {
        settle = `
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-500">Settlement</p>
            <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                <span class="text-slate-400">Cash expected</span><span class="text-right font-black">${money(t.cash_expected)}</span>
                <span class="text-slate-400">Electronic</span><span class="text-right font-bold text-slate-500">${money(t.electronic_total)}</span>
            </div>
            ${rw ? `<form onsubmit="settleTrip(event, ${t.id})" class="mt-3 space-y-2">
                <label class="block"><span class="text-[11px] font-bold text-slate-500">Cash declared by driver</span>
                    <input name="cash_declared" type="number" step="0.01" required value="${Number(t.cash_expected).toFixed(2)}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold"></label>
                <input name="notes" placeholder="note (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold">
                <button class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-emerald-700">Settle &amp; lock</button>
            </form>` : ''}
        </div>`;
    } else if (locked) {
        const v = Number(t.cash_variance || 0);
        settle = `
        <div class="rounded-xl border ${Math.abs(v) > 0.01 ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50'} p-4">
            <p class="text-[11px] font-black uppercase tracking-[0.12em] ${Math.abs(v) > 0.01 ? 'text-red-700' : 'text-emerald-700'}">Settled ${fmtDate(t.settled_at)}</p>
            <div class="mt-2 grid grid-cols-2 gap-1 text-sm">
                <span class="text-slate-500">Expected</span><span class="text-right font-bold">${money(t.cash_expected)}</span>
                <span class="text-slate-500">Declared</span><span class="text-right font-bold">${money(t.cash_declared)}</span>
                <span class="text-slate-500">Variance</span><span class="text-right font-black ${Math.abs(v) > 0.01 ? 'text-red-600' : ''}">${money(v)}</span>
            </div>
            ${t.notes ? `<p class="mt-2 text-[11px] font-semibold text-slate-500">${esc(t.notes)}</p>` : ''}
        </div>`;
    }

    p.innerHTML = `
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-black">${esc(t.route_name)} ${tripStatusBadge(t.status)}</h2>
                <p class="text-xs font-semibold text-slate-400">${fmtDate(t.trip_date)}${t.driver_name ? ' · ' + esc(t.driver_name) : ''}</p>
            </div>
            ${nextBtn}
        </div>
        ${settle}
        <div>
            <div class="flex items-center justify-between">
                <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Stops</p>
                ${rw ? `<button onclick="addStopForm(${t.id})" class="text-[11px] font-black uppercase tracking-[0.1em] text-violet-600 hover:text-violet-800">+ Add stop</button>` : ''}
            </div>
            <div id="addStopBox"></div>
            <div class="mt-1">${stops}</div>
        </div>`;
}

function stopForm(stopId){
    const box = document.getElementById('stopForm-' + stopId);
    if (box.innerHTML) { box.innerHTML = ''; return; }
    box.innerHTML = `
        <form onsubmit="recordStop(event, ${stopId})" class="mt-2 grid gap-2 rounded-lg bg-slate-50 p-3 sm:grid-cols-2">
            <label class="block"><span class="text-[11px] font-bold text-slate-500">Outcome</span>
                <select name="status" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm font-semibold">
                    ${Object.entries(STOP_STATUS).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}
                </select></label>
            <label class="block"><span class="text-[11px] font-bold text-slate-500">Method</span>
                <select name="method" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm font-semibold">
                    ${Object.entries(METHODS).map(([k,v])=>`<option value="${k}">${v}</option>`).join('')}
                </select></label>
            <label class="block"><span class="text-[11px] font-bold text-slate-500">Amount collected</span>
                <input name="amount_collected" type="number" step="0.01" min="0" value="0" class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold"></label>
            <label class="block"><span class="text-[11px] font-bold text-slate-500">Note</span>
                <input name="note" class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold"></label>
            <div class="sm:col-span-2 flex gap-2">
                <button class="rounded-xl bg-violet-600 px-4 py-1.5 text-xs font-black uppercase tracking-[0.1em] text-white">Save</button>
                <button type="button" onclick="document.getElementById('stopForm-${stopId}').innerHTML=''" class="rounded-xl border border-slate-200 px-4 py-1.5 text-xs font-black uppercase tracking-[0.1em] text-slate-400">Cancel</button>
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
        <div class="mt-2 rounded-lg bg-slate-50 p-3">
            <input id="stopSearch" placeholder="Search customers…" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold">
            <div id="stopSearchResults" class="mt-2 space-y-1"></div>
        </div>`;
    document.getElementById('stopSearch').addEventListener('input', (e) => {
        clearTimeout(stopSearchTimer);
        const q = e.target.value.trim();
        stopSearchTimer = setTimeout(async () => {
            try {
                const d = await api('customers.php', { q });
                document.getElementById('stopSearchResults').innerHTML = (d.customers || []).map(c => `
                    <button onclick="addStop(${tripId}, ${c.id})" class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-left text-sm font-semibold hover:border-violet-300">
                        ${esc(c.name)}${c.company ? ' · ' + esc(c.company) : ''}
                    </button>`).join('') || '<p class="text-xs text-slate-400">No matches.</p>';
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
