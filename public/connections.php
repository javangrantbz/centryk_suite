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
        <p class="mt-1 max-w-2xl text-sm font-semibold text-slate-500">Connect with other businesses on Centryk to unlock cross-company features, then manage the relationship with notes and permission scopes. Both sides must approve before anything is shared.</p>
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
                <p class="mb-2 text-xs font-semibold text-slate-400">Set the relationship type and what this connection can do.</p>
                <div id="connectedList" class="space-y-2"></div>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Partner requests to you</h2>
                <div id="incomingRequestList" class="space-y-2"></div>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Partner requests you've sent</h2>
                <div id="outgoingRequestList" class="space-y-2"></div>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Shared events to you</h2>
                <div id="incomingEventShareList" class="space-y-2"></div>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Shared events you've sent</h2>
                <div id="outgoingEventShareList" class="space-y-2"></div>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Shared campaigns to you</h2>
                <div id="incomingCampaignShareList" class="space-y-2"></div>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Shared campaigns you've sent</h2>
                <div id="outgoingCampaignShareList" class="space-y-2"></div>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Partner messages</h2>
                <div id="messageList" class="space-y-2"></div>
            </div>
            <div>
                <h2 class="text-sm font-black uppercase tracking-wide text-slate-500 mb-2">Connect activity</h2>
                <div id="activityList" class="space-y-2"></div>
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
let requestState = { incoming: [], outgoing: [] };
let eventShareState = { incoming: [], outgoing: [] };
let campaignShareState = { incoming: [], outgoing: [] };
let messageState = { messages: [] };
let activityState = { items: [] };

const requestTypeLabels = {
    asset: 'Asset request',
    campaign: 'Campaign request',
    event: 'Event request',
    promotion: 'Promotion request',
    general: 'General request',
};

const businessTypeLabels = {
    school: 'School / Education',
    gym: 'Gym / Fitness',
    clinic: 'Clinic / Health',
    salon: 'Salon / Spa',
    grocery: 'Groceries',
    retail: 'Retail / Shop',
    restaurant: 'Restaurant / Food',
    ice_cream: 'Ice Cream / Dessert Shop',
    meat_shop: 'Butcher / Meat Shop',
    cafeteria: 'Cafeteria / Food Service',
    auto_sales: 'Auto Sales',
    auto_rental: 'Auto Rental',
    services: 'Services',
    property: 'Property / Rentals',
    other: 'Business',
};

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function businessTypeLabel(type) {
    const key = String(type || '').trim();
    return businessTypeLabels[key] || (key ? key.replace(/_/g, ' ') : '');
}

function partnerInitial(name) {
    return esc(String(name || '?').trim().charAt(0).toUpperCase() || '?');
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
        const [connRes, reqRes, evtRes, campRes, msgRes, actRes] = await Promise.all([
            fetch(`api/connections/list.php?company_id=${activeCompanyId}`),
            fetch(`api/connections/request_list.php?company_id=${activeCompanyId}`),
            fetch(`api/connections/event_share_list.php?company_id=${activeCompanyId}`),
            fetch(`api/connections/campaign_share_list.php?company_id=${activeCompanyId}`),
            fetch(`api/connections/message_list.php?company_id=${activeCompanyId}`),
            fetch(`api/connections/activity.php?company_id=${activeCompanyId}`)
        ]);
        const connData = await connRes.json();
        const reqData = await reqRes.json();
        const evtData = await evtRes.json();
        const campData = await campRes.json();
        const msgData = await msgRes.json();
        const actData = await actRes.json();
        if (!connData.success) throw new Error(connData.message || 'Failed to load.');
        if (!reqData.success) throw new Error(reqData.message || 'Failed to load requests.');
        if (!evtData.success) throw new Error(evtData.message || 'Failed to load shared events.');
        if (!campData.success) throw new Error(campData.message || 'Failed to load shared campaigns.');
        if (!msgData.success) throw new Error(msgData.message || 'Failed to load partner messages.');
        if (!actData.success) throw new Error(actData.message || 'Failed to load activity.');
        state = connData;
        requestState = reqData;
        eventShareState = evtData;
        campaignShareState = campData;
        messageState = msgData;
        activityState = actData;
        renderLists();
        renderRequests();
        renderEventShares();
        renderCampaignShares();
        renderMessages();
        renderActivity();
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

function requestStatusBadge(status) {
    if (status === 'fulfilled') return 'bg-emerald-100 text-emerald-800';
    if (status === 'declined') return 'bg-slate-100 text-slate-500';
    return 'bg-amber-100 text-amber-800';
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
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-800">${esc(c.name)}</p>
                    <p class="mt-0.5 text-xs font-semibold uppercase tracking-wide text-slate-400">Connected ${c.responded_at ? '• ' + esc(String(c.responded_at).slice(0, 10)) : ''}</p>
                </div>
                <button onclick="removeConn(${c.connection_id})" class="text-xs text-red-600 hover:underline shrink-0">Remove</button>
            </div>
            <div class="mt-3 grid gap-3 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                    <div class="flex items-start gap-3">
                        <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-white text-lg font-black text-slate-600">
                            ${c.logo ? `<img src="${esc(c.logo)}" alt="${esc(c.name)}" class="h-full w-full object-contain">` : partnerInitial(c.name)}
                        </div>
                        <div class="min-w-0">
                            ${businessTypeLabel(c.business_type) ? `<p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">${esc(businessTypeLabel(c.business_type))}</p>` : ''}
                            <div class="mt-1 flex flex-wrap gap-2 text-[11px] font-semibold text-slate-500">
                                ${c.email ? `<span class="rounded-full bg-white px-2.5 py-1">${esc(c.email)}</span>` : ''}
                                ${c.phone ? `<span class="rounded-full bg-white px-2.5 py-1">${esc(c.phone)}</span>` : ''}
                            </div>
                            ${c.address ? `<p class="mt-2 text-xs font-semibold text-slate-500">${esc(c.address)}</p>` : ''}
                            ${c.opening_hours ? `<p class="mt-2 text-xs font-semibold text-slate-500 whitespace-pre-wrap">${esc(c.opening_hours)}</p>` : ''}
                        </div>
                    </div>
                    <div class="mt-3 grid gap-2 sm:grid-cols-4">
                        <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">Campaigns</p>
                            <p class="mt-1 text-sm font-black text-slate-900">${esc(c.campaign_share_count || 0)}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">Accepted</p>
                            <p class="mt-1 text-sm font-black text-pink-700">${esc(c.accepted_campaign_count || 0)}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">Open requests</p>
                            <p class="mt-1 text-sm font-black text-slate-900">${esc(c.open_request_count || 0)}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-white px-3 py-2">
                            <p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">Messages</p>
                            <p class="mt-1 text-sm font-black text-slate-900">${esc(c.message_count || 0)}</p>
                        </div>
                    </div>
                    ${c.relationship_note ? `<div class="mt-3 rounded-xl border border-violet-100 bg-violet-50 px-3 py-2.5"><p class="text-[10px] font-black uppercase tracking-[0.14em] text-violet-500">Collaboration notes</p><p class="mt-1 text-sm font-semibold text-violet-900 whitespace-pre-wrap">${esc(c.relationship_note)}</p></div>` : ''}
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-black uppercase tracking-wide text-slate-400">Relationship type</label>
                    <select id="relationshipType-${c.connection_id}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        ${[
                            ['partner', 'Partner'],
                            ['vendor', 'Vendor'],
                            ['client', 'Client'],
                            ['sister_brand', 'Sister brand'],
                            ['event_sponsor', 'Event sponsor'],
                            ['campaign_partner', 'Campaign partner'],
                            ['other', 'Other'],
                        ].map(([value, label]) => `<option value="${value}" ${c.relationship_type === value ? 'selected' : ''}>${label}</option>`).join('')}
                    </select>
                    <label class="mb-1 mt-3 block text-[11px] font-black uppercase tracking-wide text-slate-400">Permission scopes</label>
                    <div class="space-y-1 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm">
                        <label class="flex items-center gap-2"><input id="canShareSignage-${c.connection_id}" type="checkbox" ${Number(c.can_share_signage) ? 'checked' : ''}> Share signage and playlists</label>
                        <label class="flex items-center gap-2"><input id="canShareEvents-${c.connection_id}" type="checkbox" ${Number(c.can_share_events) ? 'checked' : ''}> Share calendar events</label>
                        <label class="flex items-center gap-2"><input id="canShareCampaigns-${c.connection_id}" type="checkbox" ${Number(c.can_share_campaigns) ? 'checked' : ''}> Share campaign bundles</label>
                        <label class="flex items-center gap-2"><input id="canRequestAssets-${c.connection_id}" type="checkbox" ${Number(c.can_request_assets) ? 'checked' : ''}> Request assets later</label>
                        <label class="flex items-center gap-2"><input id="canMessageAdmins-${c.connection_id}" type="checkbox" ${Number(c.can_message_admins) ? 'checked' : ''}> Message company admins later</label>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <label class="mb-1 block text-[11px] font-black uppercase tracking-wide text-slate-400">Admin notes</label>
                <textarea id="relationshipNote-${c.connection_id}" rows="3" maxlength="1000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="What kind of relationship is this? What do we collaborate on?">${esc(c.relationship_note || '')}</textarea>
            </div>
            <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-slate-400">Quick partner request</p>
                <div class="grid gap-2 md:grid-cols-[12rem_minmax(0,1fr)]">
                    <select id="requestType-${c.connection_id}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="asset">Asset request</option>
                        <option value="campaign">Campaign request</option>
                        <option value="event">Event request</option>
                        <option value="promotion">Promotion request</option>
                        <option value="general">General request</option>
                    </select>
                    <input id="requestSubject-${c.connection_id}" type="text" maxlength="160" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Short subject">
                </div>
                <textarea id="requestDetails-${c.connection_id}" rows="2" maxlength="2000" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="What do you need from this company?"></textarea>
                <div class="mt-2 flex justify-end">
                    <button onclick="createPartnerRequest(${c.id}, ${c.connection_id})" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-slate-800">Send partner request</button>
                </div>
                ${Number(c.can_request_assets) ? '' : '<p class="mt-2 text-xs font-semibold text-amber-700">This connection currently has partner requests turned off. You can enable it above.</p>'}
            </div>
            <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-slate-400">Share an event</p>
                <div class="grid gap-2 md:grid-cols-[minmax(0,1fr)_10rem]">
                    <input id="eventTitle-${c.connection_id}" type="text" maxlength="180" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Event title">
                    <input id="eventDate-${c.connection_id}" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="mt-2 grid gap-2 md:grid-cols-2">
                    <select id="eventType-${c.connection_id}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="meeting">Meeting</option>
                        <option value="holiday">Holiday</option>
                        <option value="deadline">Deadline</option>
                        <option value="training">Training</option>
                        <option value="other" selected>Other</option>
                    </select>
                    <select id="eventColor-${c.connection_id}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="slate">Slate</option>
                        <option value="blue">Blue</option>
                        <option value="teal">Teal</option>
                        <option value="green">Green</option>
                        <option value="amber">Amber</option>
                        <option value="red">Red</option>
                        <option value="purple">Purple</option>
                    </select>
                </div>
                <textarea id="eventDescription-${c.connection_id}" rows="2" maxlength="2000" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Optional details for the receiving business"></textarea>
                <div class="mt-2 flex justify-end">
                    <button onclick="createEventShare(${c.id}, ${c.connection_id})" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-emerald-700">Send shared event</button>
                </div>
                ${Number(c.can_share_events) ? '' : '<p class="mt-2 text-xs font-semibold text-amber-700">This connection currently has event sharing turned off. You can enable it above.</p>'}
            </div>
            <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-slate-400">Share a campaign</p>
                <input id="campaignTitle-${c.connection_id}" type="text" maxlength="180" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Campaign title">
                <textarea id="campaignSummary-${c.connection_id}" rows="2" maxlength="3000" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="What is this campaign about?"></textarea>
                <div class="mt-2 grid gap-2 md:grid-cols-2">
                    <input id="campaignOffer-${c.connection_id}" type="text" maxlength="255" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Offer or headline">
                    <input id="campaignCtaLabel-${c.connection_id}" type="text" maxlength="80" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="CTA label">
                </div>
                <input id="campaignCtaUrl-${c.connection_id}" type="url" maxlength="500" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="https://example.com">
                <div class="mt-2 grid gap-2 md:grid-cols-2">
                    <input id="campaignStartsOn-${c.connection_id}" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <input id="campaignEndsOn-${c.connection_id}" type="date" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <textarea id="campaignAudienceNotes-${c.connection_id}" rows="2" maxlength="1000" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Who is this for? Where should it be used?"></textarea>
                <textarea id="campaignRecipientNotes-${c.connection_id}" rows="2" maxlength="1000" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Notes for the receiving business"></textarea>
                <div class="mt-2 flex justify-end">
                    <button onclick="createCampaignShare(${c.id}, ${c.connection_id})" class="rounded-xl bg-pink-600 px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-pink-700">Send shared campaign</button>
                </div>
                ${Number(c.can_share_campaigns) ? '' : '<p class="mt-2 text-xs font-semibold text-amber-700">This connection currently has campaign sharing turned off. You can enable it above.</p>'}
            </div>
            <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="mb-2 text-[11px] font-black uppercase tracking-wide text-slate-400">Admin message</p>
                <textarea id="messageBody-${c.connection_id}" rows="2" maxlength="2000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Send a quick admin-to-admin message"></textarea>
                <div class="mt-2 flex justify-end">
                    <button onclick="createPartnerMessage(${c.id}, ${c.connection_id})" class="rounded-xl bg-amber-500 px-4 py-2 text-sm font-bold text-slate-950 transition-colors hover:bg-amber-400">Send message</button>
                </div>
                ${Number(c.can_message_admins) ? '' : '<p class="mt-2 text-xs font-semibold text-amber-700">This connection currently has admin messaging turned off. You can enable it above.</p>'}
            </div>
            <div class="mt-3 flex justify-end">
                <button onclick="saveConnection(${c.connection_id})" class="rounded-xl bg-violet-600 px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-violet-700">Save connection settings</button>
            </div>
        </div>`).join('') : '<p class="text-slate-400 text-sm">Not connected with anyone yet.</p>';

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function renderRequests() {
    const incoming = document.getElementById('incomingRequestList');
    incoming.innerHTML = requestState.incoming.length ? requestState.incoming.map(r => `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-800">${esc(r.requester_company_name)} <span class="text-slate-400 font-normal">• ${esc(requestTypeLabels[r.request_type] || 'Request')}</span></p>
                    <p class="mt-0.5 text-sm font-semibold text-slate-700">${esc(r.subject)}</p>
                    ${r.details ? `<p class="mt-1 text-sm text-slate-500 whitespace-pre-wrap">${esc(r.details)}</p>` : ''}
                    <p class="mt-2 text-xs text-slate-400">${esc(String(r.created_at).slice(0, 16).replace('T',' '))}</p>
                </div>
                <span class="inline-flex rounded-full px-2 py-1 text-[10px] font-bold uppercase ${requestStatusBadge(r.status)}">${esc(r.status)}</span>
            </div>
            ${r.status === 'open' ? `
            <div class="mt-3 flex items-center justify-end gap-2">
                <button onclick="updatePartnerRequest(${r.id}, 'declined')" class="text-xs font-bold text-slate-500 hover:underline">Decline</button>
                <button onclick="updatePartnerRequest(${r.id}, 'fulfilled')" class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-violet-700">Mark fulfilled</button>
            </div>` : ''}
        </div>`).join('') : '<p class="text-slate-400 text-sm">No partner requests yet.</p>';

    const outgoing = document.getElementById('outgoingRequestList');
    outgoing.innerHTML = requestState.outgoing.length ? requestState.outgoing.map(r => `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-800">${esc(r.recipient_company_name)} <span class="text-slate-400 font-normal">• ${esc(requestTypeLabels[r.request_type] || 'Request')}</span></p>
                    <p class="mt-0.5 text-sm font-semibold text-slate-700">${esc(r.subject)}</p>
                    ${r.details ? `<p class="mt-1 text-sm text-slate-500 whitespace-pre-wrap">${esc(r.details)}</p>` : ''}
                    <p class="mt-2 text-xs text-slate-400">${esc(String(r.created_at).slice(0, 16).replace('T',' '))}</p>
                </div>
                <span class="inline-flex rounded-full px-2 py-1 text-[10px] font-bold uppercase ${requestStatusBadge(r.status)}">${esc(r.status)}</span>
            </div>
        </div>`).join('') : '<p class="text-slate-400 text-sm">You have not sent any partner requests yet.</p>';
}

function renderEventShares() {
    const incomingHost = document.getElementById('incomingEventShareList');
    const outgoingHost = document.getElementById('outgoingEventShareList');
    if (!incomingHost || !outgoingHost) {
        return;
    }

    incomingHost.innerHTML = eventShareState.incoming.length ? eventShareState.incoming.map(s => `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-800">${esc(s.owner_company_name)}</p>
                    <p class="mt-0.5 text-sm font-semibold text-slate-700">${esc(s.title)}</p>
                    <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">${esc(s.event_date)} • ${esc(s.event_type)} • ${esc(s.color)}</p>
                    ${s.description ? `<p class="mt-1 text-sm text-slate-500 whitespace-pre-wrap">${esc(s.description)}</p>` : ''}
                </div>
                <span class="inline-flex rounded-full px-2 py-1 text-[10px] font-bold uppercase ${requestStatusBadge(s.status === 'accepted' ? 'fulfilled' : s.status)}">${esc(s.status)}</span>
            </div>
            ${s.status === 'pending' ? `
            <div class="mt-3 flex items-center justify-end gap-2">
                <button onclick="updateEventShare(${s.id}, 'decline')" class="text-xs font-bold text-slate-500 hover:underline">Decline</button>
                <button onclick="updateEventShare(${s.id}, 'accept')" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-emerald-700">Add to calendar</button>
            </div>` : ''}
        </div>`).join('') : '<p class="text-slate-400 text-sm">No shared events yet.</p>';

    outgoingHost.innerHTML = eventShareState.outgoing.length ? eventShareState.outgoing.map(s => `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-800">${esc(s.recipient_company_name)}</p>
                    <p class="mt-0.5 text-sm font-semibold text-slate-700">${esc(s.title)}</p>
                    <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">${esc(s.event_date)} • ${esc(s.event_type)} • ${esc(s.color)}</p>
                    ${s.description ? `<p class="mt-1 text-sm text-slate-500 whitespace-pre-wrap">${esc(s.description)}</p>` : ''}
                </div>
                <span class="inline-flex rounded-full px-2 py-1 text-[10px] font-bold uppercase ${requestStatusBadge(s.status === 'accepted' ? 'fulfilled' : s.status)}">${esc(s.status)}</span>
            </div>
            ${['pending','accepted'].includes(String(s.status)) ? `
            <div class="mt-3 flex justify-end">
                <button onclick="updateEventShare(${s.id}, 'revoke')" class="text-xs font-bold text-red-600 hover:underline">Revoke</button>
            </div>` : ''}
        </div>`).join('') : '<p class="text-slate-400 text-sm">You have not shared any events yet.</p>';
}

function renderCampaignShares() {
    const incomingHost = document.getElementById('incomingCampaignShareList');
    const outgoingHost = document.getElementById('outgoingCampaignShareList');
    if (!incomingHost || !outgoingHost) {
        return;
    }

    const statusClass = (status) => requestStatusBadge(status === 'accepted' ? 'fulfilled' : status);
    const dateLine = (share) => {
        if (!share.starts_on && !share.ends_on) return '';
        return `${share.starts_on || '...'} to ${share.ends_on || '...'}`;
    };

    incomingHost.innerHTML = campaignShareState.incoming.length ? campaignShareState.incoming.map(s => `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-800">${esc(s.owner_company_name)}</p>
                    <p class="mt-0.5 text-sm font-semibold text-slate-700">${esc(s.title)}</p>
                    ${s.offer_text ? `<p class="mt-1 text-sm font-semibold text-pink-700">${esc(s.offer_text)}</p>` : ''}
                    ${s.summary ? `<p class="mt-1 text-sm text-slate-500 whitespace-pre-wrap">${esc(s.summary)}</p>` : ''}
                    ${dateLine(s) ? `<p class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">${esc(dateLine(s))}</p>` : ''}
                    ${s.cta_url ? `<p class="mt-1 text-xs text-slate-500">${esc(s.cta_label || 'CTA')}: ${esc(s.cta_url)}</p>` : ''}
                    ${s.audience_notes ? `<p class="mt-1 text-xs text-slate-500">Audience: ${esc(s.audience_notes)}</p>` : ''}
                    ${s.recipient_notes ? `<p class="mt-1 text-xs text-slate-500">Notes: ${esc(s.recipient_notes)}</p>` : ''}
                </div>
                <span class="inline-flex rounded-full px-2 py-1 text-[10px] font-bold uppercase ${statusClass(s.status)}">${esc(s.status)}</span>
            </div>
            ${s.status === 'pending' ? `
            <div class="mt-3 flex items-center justify-end gap-2">
                <button onclick="updateCampaignShare(${s.id}, 'decline')" class="text-xs font-bold text-slate-500 hover:underline">Decline</button>
                <button onclick="updateCampaignShare(${s.id}, 'accept')" class="rounded-lg bg-pink-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-pink-700">Accept</button>
            </div>` : ''}
        </div>`).join('') : '<p class="text-slate-400 text-sm">No shared campaigns yet.</p>';

    outgoingHost.innerHTML = campaignShareState.outgoing.length ? campaignShareState.outgoing.map(s => `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-800">${esc(s.recipient_company_name)}</p>
                    <p class="mt-0.5 text-sm font-semibold text-slate-700">${esc(s.title)}</p>
                    ${s.offer_text ? `<p class="mt-1 text-sm font-semibold text-pink-700">${esc(s.offer_text)}</p>` : ''}
                    ${s.summary ? `<p class="mt-1 text-sm text-slate-500 whitespace-pre-wrap">${esc(s.summary)}</p>` : ''}
                    ${dateLine(s) ? `<p class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">${esc(dateLine(s))}</p>` : ''}
                    ${s.cta_url ? `<p class="mt-1 text-xs text-slate-500">${esc(s.cta_label || 'CTA')}: ${esc(s.cta_url)}</p>` : ''}
                </div>
                <span class="inline-flex rounded-full px-2 py-1 text-[10px] font-bold uppercase ${statusClass(s.status)}">${esc(s.status)}</span>
            </div>
            ${['pending','accepted'].includes(String(s.status)) ? `
            <div class="mt-3 flex justify-end">
                <button onclick="updateCampaignShare(${s.id}, 'revoke')" class="text-xs font-bold text-red-600 hover:underline">Revoke</button>
            </div>` : ''}
        </div>`).join('') : '<p class="text-slate-400 text-sm">You have not shared any campaigns yet.</p>';
}

function renderMessages() {
    const box = document.getElementById('messageList');
    const items = messageState.messages || [];
    box.innerHTML = items.length ? items.map(m => {
        const incoming = Number(m.recipient_company_id) === Number(activeCompanyId);
        const companyLabel = incoming ? m.sender_company_name : m.recipient_company_name;
        const senderLabel = m.sender_name ? m.sender_name : m.sender_company_name;
        return `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <p class="font-semibold text-slate-800">${esc(companyLabel)} <span class="text-slate-400 font-normal">${incoming ? '• incoming' : '• sent'}</span></p>
            <p class="mt-1 text-sm text-slate-700 whitespace-pre-wrap">${esc(m.message)}</p>
            <p class="mt-2 text-xs text-slate-400">${esc(senderLabel)} • ${esc(String(m.created_at).slice(0, 16).replace('T', ' '))}</p>
        </div>`;
    }).join('') : '<p class="text-slate-400 text-sm">No partner messages yet.</p>';
}

function renderActivity() {
    const box = document.getElementById('activityList');
    const items = activityState.items || [];
    box.innerHTML = items.length ? items.map(item => `
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-start gap-3">
                <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" style="background:${esc(item.color || '#7c3aed')}"></span>
                <div class="min-w-0">
                    <p class="font-semibold text-slate-800">${esc(item.title || 'Activity')}</p>
                    <p class="mt-0.5 text-sm text-slate-500">${esc(item.body || '')}</p>
                    <p class="mt-2 text-xs text-slate-400">${esc(String(item.created_at || '').slice(0, 16).replace('T', ' '))}</p>
                </div>
            </div>
        </div>`).join('') : '<p class="text-slate-400 text-sm">No recent connect activity yet.</p>';
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

window.saveConnection = async function (connectionId) {
    const payload = {
        company_id: activeCompanyId,
        connection_id: connectionId,
        relationship_type: document.getElementById(`relationshipType-${connectionId}`)?.value || 'partner',
        relationship_note: document.getElementById(`relationshipNote-${connectionId}`)?.value || '',
        can_share_signage: document.getElementById(`canShareSignage-${connectionId}`)?.checked ? 1 : 0,
        can_share_events: document.getElementById(`canShareEvents-${connectionId}`)?.checked ? 1 : 0,
        can_share_campaigns: document.getElementById(`canShareCampaigns-${connectionId}`)?.checked ? 1 : 0,
        can_request_assets: document.getElementById(`canRequestAssets-${connectionId}`)?.checked ? 1 : 0,
        can_message_admins: document.getElementById(`canMessageAdmins-${connectionId}`)?.checked ? 1 : 0,
    };
    const data = await post('api/connections/update.php', payload);
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
};

window.createPartnerRequest = async function (targetCompanyId, connectionId) {
    const payload = {
        company_id: activeCompanyId,
        target_company_id: targetCompanyId,
        request_type: document.getElementById(`requestType-${connectionId}`)?.value || 'general',
        subject: document.getElementById(`requestSubject-${connectionId}`)?.value || '',
        details: document.getElementById(`requestDetails-${connectionId}`)?.value || '',
    };
    const data = await post('api/connections/request_create.php', payload);
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
};

window.updatePartnerRequest = async function (requestId, status) {
    const data = await post('api/connections/request_update.php', {
        company_id: activeCompanyId,
        request_id: requestId,
        status
    });
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
};

window.createEventShare = async function (targetCompanyId, connectionId) {
    const payload = {
        company_id: activeCompanyId,
        target_company_id: targetCompanyId,
        connection_id: connectionId,
        title: document.getElementById(`eventTitle-${connectionId}`)?.value || '',
        description: document.getElementById(`eventDescription-${connectionId}`)?.value || '',
        event_date: document.getElementById(`eventDate-${connectionId}`)?.value || '',
        event_type: document.getElementById(`eventType-${connectionId}`)?.value || 'other',
        color: document.getElementById(`eventColor-${connectionId}`)?.value || 'slate',
    };
    const data = await post('api/connections/event_share_create.php', payload);
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
};

window.createCampaignShare = async function (targetCompanyId, connectionId) {
    const payload = {
        company_id: activeCompanyId,
        target_company_id: targetCompanyId,
        connection_id: connectionId,
        title: document.getElementById(`campaignTitle-${connectionId}`)?.value || '',
        summary: document.getElementById(`campaignSummary-${connectionId}`)?.value || '',
        offer_text: document.getElementById(`campaignOffer-${connectionId}`)?.value || '',
        cta_label: document.getElementById(`campaignCtaLabel-${connectionId}`)?.value || '',
        cta_url: document.getElementById(`campaignCtaUrl-${connectionId}`)?.value || '',
        starts_on: document.getElementById(`campaignStartsOn-${connectionId}`)?.value || '',
        ends_on: document.getElementById(`campaignEndsOn-${connectionId}`)?.value || '',
        audience_notes: document.getElementById(`campaignAudienceNotes-${connectionId}`)?.value || '',
        recipient_notes: document.getElementById(`campaignRecipientNotes-${connectionId}`)?.value || '',
    };
    const data = await post('api/connections/campaign_share_create.php', payload);
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
};

window.updateEventShare = async function (shareId, action) {
    const data = await post('api/connections/event_share_update.php', {
        company_id: activeCompanyId,
        share_id: shareId,
        action
    });
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
};

window.updateCampaignShare = async function (shareId, action) {
    const data = await post('api/connections/campaign_share_update.php', {
        company_id: activeCompanyId,
        share_id: shareId,
        action
    });
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
};

window.createPartnerMessage = async function (targetCompanyId, connectionId) {
    const payload = {
        company_id: activeCompanyId,
        target_company_id: targetCompanyId,
        connection_id: connectionId,
        message: document.getElementById(`messageBody-${connectionId}`)?.value || '',
    };
    const data = await post('api/connections/message_create.php', payload);
    if (data.success) { showAlert(data.message); loadAll(); } else { showAlert(data.message || 'Failed.', 'error'); }
};

loadAll();
</script>
</body>
</html>
