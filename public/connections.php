<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
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

// Only companies this user administers — connecting is an admin decision.
$coStmt = $pdo->prepare("
    SELECT c.id, c.uuid, c.name
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.user_id = :uid AND cm.status = 'active' AND cm.role = 'admin' AND c.status = 'active'
    ORDER BY c.name ASC
");
$coStmt->execute(['uid' => (int)$user['id']]);
$companies = $coStmt->fetchAll(PDO::FETCH_ASSOC);

$activeCompanyId = null;
if ($companies) {
    $requestedCid = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    $picked = null;
    foreach ($companies as $c) {
        if ((int)$c['id'] === $requestedCid) { $picked = $c; break; }
    }
    if (!$picked) $picked = $companies[0];
    $activeCompanyId = (int)$picked['id'];
}

$connLink = static function (int $companyId): string {
    return 'connections.php?company_id=' . $companyId;
};

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
    <title>Centryk Connect</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Centryk Connect'; $headerMaxW = 'max-w-4xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-4xl px-4 pt-4 pb-8">

    <div class="mb-4">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-600">Centryk Connect</p>
        <h1 class="mt-0.5 text-2xl font-black tracking-tight text-slate-950">Company connections</h1>
        <p class="mt-1 max-w-2xl text-sm font-semibold text-slate-500">Connect with other businesses on Centryk to unlock cross-company features, like sharing Vision Board playlists. Both sides must approve before anything is shared.</p>
    </div>

    <?php if (!$companies): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center">
            <p class="text-sm font-bold text-slate-500">You need to be an admin of a company to manage connections.</p>
        </div>
    <?php else: ?>

    <?php if (count($companies) > 1): ?>
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="text-xs font-bold uppercase tracking-wide text-slate-400">Managing as:</span>
        <?php foreach ($companies as $c): $isActive = ((int)$c['id'] === $activeCompanyId); ?>
        <a href="<?= htmlspecialchars($connLink((int)$c['id'])) ?>"
           class="rounded-full px-3 py-1.5 text-xs font-bold transition <?= $isActive ? 'bg-violet-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
            <?= htmlspecialchars($c['name']) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div id="pageAlert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

    <div class="grid md:grid-cols-3 gap-4">
        <div class="md:col-span-2 space-y-5">
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Requests to you</h2>
                <div id="incomingList" class="space-y-2"></div>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Sent, awaiting response</h2>
                <div id="outgoingList" class="space-y-2"></div>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Connected</h2>
                <div id="connectedList" class="space-y-2"></div>
            </div>
        </div>

        <div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sticky top-4">
                <h2 class="font-bold text-slate-800 mb-3">Connect with a company</h2>
                <select id="targetCompany" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm mb-3">
                    <option value="">Loading companies…</option>
                </select>
                <button id="sendBtn" class="w-full bg-violet-600 hover:bg-violet-700 text-white font-bold rounded-xl py-2 transition-colors">Send request</button>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
const activeCompanyId = <?= (int)($activeCompanyId ?? 0) ?>;
let allCompanies = [];
let state = { incoming: [], outgoing: [], connected: [] };

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showAlert(msg, type) {
    const el = document.getElementById('pageAlert');
    el.textContent = msg;
    el.className = type === 'error'
        ? 'mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700'
        : 'mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700';
    el.classList.remove('hidden');
    setTimeout(() => el.classList.add('hidden'), 4000);
}

async function loadAll() {
    if (!activeCompanyId) return;
    try {
        const connRes = await fetch(`api/connections/list.php?company_id=${activeCompanyId}`);
        const connData = await connRes.json();
        if (!connData.success) throw new Error(connData.message || 'Failed to load.');
        state = connData;
        renderLists();
        await loadDirectory();
    } catch (e) {
        showAlert(e.message || 'Failed to load connections.', 'error');
    }
}

async function loadDirectory() {
    try {
        const res = await fetch(`api/connections/directory.php?company_id=${activeCompanyId}`);
        const data = await res.json();
        allCompanies = data.companies || [];
    } catch (e) {
        allCompanies = [];
    }
    renderPicker();
}

function renderPicker() {
    const busyIds = new Set([
        ...state.incoming.map(r => r.company_id),
        ...state.outgoing.map(r => r.company_id),
        ...state.connected.map(r => r.id),
    ]);
    const sel = document.getElementById('targetCompany');
    const available = allCompanies.filter(c => !busyIds.has(c.id));
    if (!available.length) {
        sel.innerHTML = '<option value="">No more companies available</option>';
        return;
    }
    sel.innerHTML = '<option value="">Choose a company…</option>' +
        available.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
}

function renderLists() {
    const inc = document.getElementById('incomingList');
    inc.innerHTML = state.incoming.length ? state.incoming.map(r => `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center justify-between gap-3">
            <p class="font-semibold text-slate-800">${esc(r.company_name)}</p>
            <div class="flex items-center gap-2 shrink-0">
                <button onclick="respond(${r.id}, true)" class="text-xs bg-violet-600 hover:bg-violet-700 text-white font-bold rounded-lg px-3 py-1.5">Accept</button>
                <button onclick="respond(${r.id}, false)" class="text-xs text-slate-500 hover:underline">Decline</button>
            </div>
        </div>`).join('') : '<p class="text-slate-400 text-sm">No pending requests.</p>';

    const out = document.getElementById('outgoingList');
    out.innerHTML = state.outgoing.length ? state.outgoing.map(r => `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center justify-between gap-3">
            <p class="font-semibold text-slate-800">${esc(r.company_name)} <span class="text-xs font-normal text-amber-600">pending</span></p>
            <button onclick="removeConn(${r.id})" class="text-xs text-red-600 hover:underline shrink-0">Cancel</button>
        </div>`).join('') : '<p class="text-slate-400 text-sm">Nothing sent yet.</p>';

    const conn = document.getElementById('connectedList');
    conn.innerHTML = state.connected.length ? state.connected.map(c => `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center justify-between gap-3">
            <p class="font-semibold text-slate-800">${esc(c.name)}</p>
            <button onclick="removeConn(${c.connection_id})" class="text-xs text-red-600 hover:underline shrink-0">Remove</button>
        </div>`).join('') : '<p class="text-slate-400 text-sm">Not connected with anyone yet.</p>';

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function post(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    return res.json();
}

document.getElementById('sendBtn')?.addEventListener('click', async () => {
    const targetId = parseInt(document.getElementById('targetCompany').value, 10);
    if (!targetId) { showAlert('Choose a company first.', 'error'); return; }
    const data = await post('api/connections/send.php', { company_id: activeCompanyId, target_company_id: targetId });
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
});

window.respond = async function (connectionId, accept) {
    const data = await post('api/connections/respond.php', { company_id: activeCompanyId, connection_id: connectionId, accept });
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
};

window.removeConn = async function (connectionId) {
    if (!confirm('Remove this connection?')) return;
    const data = await post('api/connections/remove.php', { company_id: activeCompanyId, connection_id: connectionId });
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
};

loadAll();
</script>
</body>
</html>
