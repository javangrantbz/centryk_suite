<?php
/**
 * Centryk Business — admin grant console.
 *
 * Platform admins promote a company onto the paid tier here: grant a package
 * (opens a subscription + activates the entitlement), move subscriptions through
 * their lifecycle (suspend / resume / cancel), and triage inbound requests.
 *
 * Read model:  api/business/overview.php
 * Writes:      api/business/grant.php, subscription_status.php, request_status.php
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
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Business Packages - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Centryk Business'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-6xl px-4 pt-1 pb-10">

    <div id="pageAlert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-950 px-5 py-5 text-white">
            <div>
                <h1 class="text-xl font-black tracking-tight">Business Packages</h1>
                <p class="mt-1 text-xs font-semibold text-white/55">
                    <a href="admin-business-roadmap.php" class="underline decoration-white/30 hover:decoration-white">Centryk Business</a>
                    · grant packages, manage subscriptions, triage requests.
                </p>
            </div>
            <button onclick="loadOverview()" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/8 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-white/15">Refresh</button>
        </div>

        <div class="flex gap-1 border-b border-slate-200 px-5 pt-4">
            <button data-tab="companies" class="tabBtn -mb-px border-b-2 border-violet-600 px-4 py-2.5 text-sm font-black text-violet-700">Companies</button>
            <button data-tab="requests" class="tabBtn -mb-px border-b-2 border-transparent px-4 py-2.5 text-sm font-black text-slate-400 hover:text-slate-700">
                Requests <span id="reqBadge" class="ml-1 hidden rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-black text-amber-700"></span>
            </button>
            <button data-tab="groups" class="tabBtn -mb-px border-b-2 border-transparent px-4 py-2.5 text-sm font-black text-slate-400 hover:text-slate-700">Groups</button>
        </div>

        <!-- ── Companies tab ─────────────────────────────────────────────── -->
        <div id="tab-companies" class="p-5">
            <div class="grid gap-6 lg:grid-cols-[320px_1fr]">
                <div>
                    <label class="block text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Find a company</label>
                    <input id="searchBox" type="text" placeholder="Company name…" autocomplete="off"
                        class="mt-1.5 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold focus:border-violet-400 focus:bg-white focus:outline-none">
                    <div id="searchResults" class="mt-2 space-y-1"></div>

                    <p class="mt-6 text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Business customers</p>
                    <div id="customerList" class="mt-2 space-y-1">
                        <div class="px-1 py-3 text-xs text-slate-400">Loading…</div>
                    </div>
                </div>

                <div id="companyPanel" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center text-sm text-slate-400">
                    Pick a company to view and grant packages.
                </div>
            </div>
        </div>

        <!-- ── Requests tab ─────────────────────────────────────────────── -->
        <div id="tab-requests" class="hidden p-5">
            <div id="requestList" class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200">
                <div class="px-5 py-8 text-center text-sm text-slate-400">Loading…</div>
            </div>
        </div>

        <!-- ── Groups tab ───────────────────────────────────────────────── -->
        <div id="tab-groups" class="hidden p-5">
            <div class="grid gap-6 lg:grid-cols-[300px_1fr]">
                <div>
                    <form id="newGroupForm" class="rounded-xl border border-violet-200 bg-violet-50/50 p-3">
                        <p class="text-[11px] font-black uppercase tracking-[0.12em] text-violet-700">New group</p>
                        <input name="name" required placeholder="Group name" class="mt-2 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold">
                        <input name="admin_email" type="email" placeholder="first group admin (email, optional)" class="mt-2 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold">
                        <button class="mt-2 w-full rounded-lg bg-violet-600 px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-white">Create</button>
                    </form>
                    <div id="groupList" class="mt-3 space-y-1"><div class="px-1 py-3 text-xs text-slate-400">Loading…</div></div>
                </div>
                <div id="groupPanel" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center text-sm text-slate-400">
                    Pick a group to manage its companies, packages and people.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const PACKAGE_STATES = ['trialing', 'active', 'past_due', 'paused', 'canceled'];
let DATA = { catalog: [], customers: [], requests: [] };
let SELECTED = null;          // company_id currently open
let DETAIL = null;            // { company, entitlements, subscriptions }
let searchTimer = null;

function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(s) {
    if (!s) return '—';
    return new Date(s.replace(' ', 'T')).toLocaleDateString('en-BZ', { month: 'short', day: 'numeric', year: 'numeric' });
}
function money(v, cur) {
    const n = Number(v || 0);
    return (cur || 'BZD') + ' ' + n.toLocaleString('en-BZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function pkgLabel(key) {
    const p = DATA.catalog.find(x => x.key === key);
    return p ? p.label : key;
}

function showAlert(msg, type) {
    const el = document.getElementById('pageAlert');
    el.textContent = msg;
    el.className = type === 'error'
        ? 'mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700'
        : 'mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700';
    el.classList.remove('hidden');
    clearTimeout(showAlert._t);
    showAlert._t = setTimeout(() => el.classList.add('hidden'), 4500);
}

async function api(path, body) {
    const res = await fetch('api/business/' + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {}),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success !== true) {
        throw new Error(data.message || ('Request failed (' + res.status + ')'));
    }
    return data;
}

// ── Tabs ────────────────────────────────────────────────────────────────
document.querySelectorAll('.tabBtn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tabBtn').forEach(b => {
            b.classList.toggle('border-violet-600', b === btn);
            b.classList.toggle('text-violet-700', b === btn);
            b.classList.toggle('border-transparent', b !== btn);
            b.classList.toggle('text-slate-400', b !== btn);
        });
        document.getElementById('tab-companies').classList.toggle('hidden', btn.dataset.tab !== 'companies');
        document.getElementById('tab-requests').classList.toggle('hidden', btn.dataset.tab !== 'requests');
        document.getElementById('tab-groups').classList.toggle('hidden', btn.dataset.tab !== 'groups');
        if (btn.dataset.tab === 'groups') loadGroups();
    });
});

/* ── Groups ─────────────────────────────────────────────────────────────── */
let GROUPS = [];
let OPEN_GROUP = null;

async function loadGroups() {
    try {
        const data = await api('group_list.php', {});
        GROUPS = data.groups || [];
        renderGroupList();
        if (OPEN_GROUP) renderGroupPanel(GROUPS.find(g => g.id === OPEN_GROUP));
    } catch (e) { showAlert(e.message, 'error'); }
}
function renderGroupList() {
    const el = document.getElementById('groupList');
    el.innerHTML = GROUPS.length ? GROUPS.map(g => `
        <button onclick="openGroup(${g.id})" class="block w-full rounded-lg border px-3 py-2 text-left hover:border-violet-300 hover:bg-violet-50 ${OPEN_GROUP === g.id ? 'border-violet-300 bg-violet-50' : 'border-slate-200 bg-white'}">
            <span class="block truncate text-sm font-bold">${esc(g.name)}</span>
            <span class="mt-0.5 block text-[11px] font-semibold text-slate-400">${g.company_count} companies · ${Object.keys(g.entitlements || {}).join(', ') || 'no packages'}</span>
        </button>`).join('') : '<p class="px-1 py-2 text-xs text-slate-400">No groups yet.</p>';
}
function openGroup(id) { OPEN_GROUP = id; renderGroupList(); renderGroupPanel(GROUPS.find(g => g.id === id)); }

function renderGroupPanel(g) {
    if (!g) return;
    const packages = DATA.catalog.map(p => {
        const st = (g.entitlements || {})[p.key];
        const btns = st === 'full'
            ? `<button onclick="groupGrant(${g.id},'${p.key}','suspend')" class="rounded bg-amber-100 px-2 py-0.5 text-[10px] font-black text-amber-700">Suspend</button>
               <button onclick="groupGrant(${g.id},'${p.key}','revoke')" class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-black text-slate-500">Revoke</button>`
            : st === 'read'
            ? `<button onclick="groupGrant(${g.id},'${p.key}','resume')" class="rounded bg-emerald-100 px-2 py-0.5 text-[10px] font-black text-emerald-700">Resume</button>`
            : `<button onclick="groupGrant(${g.id},'${p.key}','grant')" class="rounded bg-violet-600 px-2 py-0.5 text-[10px] font-black text-white">Grant</button>`;
        return `<div class="flex items-center justify-between gap-2 border-t border-slate-100 px-3 py-2 first:border-t-0">
            <span class="text-sm font-bold">${esc(p.label)} ${st ? stateBadge(st === 'read' ? 'suspended' : 'active') : ''}</span>
            <span class="flex gap-1">${btns}</span></div>`;
    }).join('');

    const companies = (g.companies || []).map(c => `
        <div class="flex items-center justify-between gap-2 border-t border-slate-100 px-3 py-2 first:border-t-0">
            <span class="text-sm font-bold">${esc(c.name)}</span>
            <button onclick="groupCompany(${g.id}, ${c.id}, 'detach')" class="text-[10px] font-black uppercase text-slate-400 hover:text-red-600">Remove</button>
        </div>`).join('') || '<p class="px-3 py-2 text-sm text-slate-400">No companies.</p>';

    const members = (g.members || []).map(m => `<span class="rounded bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">${esc(m.email)} · ${m.role === 'group_admin' ? 'admin' : 'viewer'}</span>`).join(' ');

    document.getElementById('groupPanel').className = 'space-y-4';
    document.getElementById('groupPanel').innerHTML = `
        <h2 class="text-lg font-black">${esc(g.name)}</h2>
        <div class="overflow-hidden rounded-xl border border-slate-200">
            <div class="bg-slate-50 px-3 py-1.5 text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Group packages (members inherit)</div>
            ${packages}
        </div>
        <div class="overflow-hidden rounded-xl border border-slate-200">
            <div class="flex items-center justify-between bg-slate-50 px-3 py-1.5">
                <span class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Companies</span>
            </div>
            ${companies}
            <div class="border-t border-slate-100 px-3 py-2">
                <input id="grpCoSearch" placeholder="Attach a company by name…" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold">
                <div id="grpCoResults" class="mt-1 space-y-1"></div>
            </div>
        </div>
        <div><p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">People</p><div class="mt-1 flex flex-wrap gap-1">${members || '<span class="text-xs text-slate-400">none</span>'}</div></div>
    `;
    const s = document.getElementById('grpCoSearch');
    let tmr;
    s.addEventListener('input', () => {
        clearTimeout(tmr);
        const q = s.value.trim();
        if (q.length < 2) { document.getElementById('grpCoResults').innerHTML = ''; return; }
        tmr = setTimeout(async () => {
            const d = await api('overview.php', { q });
            document.getElementById('grpCoResults').innerHTML = (d.search_results || []).map(c =>
                `<button onclick="groupCompany(${g.id}, ${c.id}, 'attach')" class="block w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-left text-sm font-semibold hover:border-violet-300">${esc(c.name)}</button>`).join('');
        }, 250);
    });
}
async function groupGrant(gid, key, action) {
    try { await api('group_grant.php', { group_id: gid, package_key: key, action }); showAlert('Done.', 'ok'); loadGroups(); }
    catch (e) { showAlert(e.message, 'error'); }
}
async function groupCompany(gid, cid, action) {
    try { await api('group_company.php', { group_id: gid, company_id: cid, action }); showAlert('Done.', 'ok'); loadGroups(); }
    catch (e) { showAlert(e.message, 'error'); }
}
document.getElementById('newGroupForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target;
    try {
        const r = await api('group_save.php', { name: f.name.value, admin_email: f.admin_email.value });
        showAlert('Group created.', 'ok'); f.reset(); OPEN_GROUP = r.group_id; loadGroups();
    } catch (err) { showAlert(err.message, 'error'); }
});

// ── Load ────────────────────────────────────────────────────────────────
async function loadOverview() {
    try {
        const data = await api('overview.php', SELECTED ? { company_id: SELECTED } : {});
        DATA = { catalog: data.catalog || [], customers: data.customers || [], requests: data.requests || [] };
        renderCustomers();
        renderRequests();
        if (SELECTED && data.company) {
            DETAIL = { company: data.company, entitlements: data.entitlements || [], subscriptions: data.subscriptions || [] };
            renderCompanyPanel();
        }
    } catch (e) {
        showAlert(e.message, 'error');
    }
}

// ── Search ──────────────────────────────────────────────────────────────
document.getElementById('searchBox').addEventListener('input', (e) => {
    const q = e.target.value.trim();
    clearTimeout(searchTimer);
    if (q.length < 2) { document.getElementById('searchResults').innerHTML = ''; return; }
    searchTimer = setTimeout(async () => {
        try {
            const data = await api('overview.php', { q });
            const rows = data.search_results || [];
            document.getElementById('searchResults').innerHTML = rows.length
                ? rows.map(c => `
                    <button onclick="openCompany(${c.id})" class="flex w-full items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-left text-sm font-semibold hover:border-violet-300 hover:bg-violet-50">
                        <span class="truncate">${esc(c.name)}</span>
                        <span class="shrink-0 text-[10px] font-black uppercase ${c.status === 'active' ? 'text-emerald-500' : 'text-slate-300'}">${esc(c.status)}</span>
                    </button>`).join('')
                : '<p class="px-1 py-2 text-xs text-slate-400">No matches.</p>';
        } catch (err) { showAlert(err.message, 'error'); }
    }, 250);
});

function renderCustomers() {
    const el = document.getElementById('customerList');
    if (!DATA.customers.length) {
        el.innerHTML = '<p class="px-1 py-2 text-xs text-slate-400">No companies on Centryk Business yet.</p>';
        return;
    }
    el.innerHTML = DATA.customers.map(c => {
        const pkgs = (c.packages || '').split(',').filter(Boolean);
        const suspended = Number(c.suspended_count || 0);
        return `
        <button onclick="openCompany(${c.id})" class="block w-full rounded-lg border px-3 py-2 text-left hover:border-violet-300 hover:bg-violet-50 ${SELECTED === c.id ? 'border-violet-300 bg-violet-50' : 'border-slate-200 bg-white'}">
            <span class="block truncate text-sm font-bold">${esc(c.name)}</span>
            <span class="mt-0.5 block text-[11px] font-semibold text-slate-400">
                ${pkgs.map(esc).join(' · ')}${suspended ? ` · <span class="text-amber-600">${suspended} suspended</span>` : ''}
            </span>
        </button>`;
    }).join('');
}

// ── Company panel ───────────────────────────────────────────────────────
async function openCompany(id) {
    SELECTED = id;
    try {
        const data = await api('overview.php', { company_id: id });
        DATA.catalog = data.catalog || DATA.catalog;
        DETAIL = { company: data.company, entitlements: data.entitlements || [], subscriptions: data.subscriptions || [] };
        renderCustomers();
        renderCompanyPanel();
    } catch (e) { showAlert(e.message, 'error'); }
}

function stateBadge(state) {
    const map = {
        active:    'border-emerald-200 bg-emerald-50 text-emerald-700',
        suspended: 'border-amber-200 bg-amber-50 text-amber-700',
        revoked:   'border-slate-200 bg-slate-100 text-slate-500',
    };
    return `<span class="rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] ${map[state] || map.revoked}">${esc(state)}</span>`;
}
function subStatusBadge(s) {
    const map = {
        trialing: 'text-sky-600', active: 'text-emerald-600', past_due: 'text-amber-600',
        paused: 'text-amber-600', canceled: 'text-slate-400',
    };
    return `<span class="text-[11px] font-black uppercase ${map[s] || 'text-slate-400'}">${esc(s.replace('_', ' '))}</span>`;
}

function renderCompanyPanel() {
    const c = DETAIL.company;
    const ents = DETAIL.entitlements;
    const subs = DETAIL.subscriptions;
    const heldActive = new Set(subs.filter(s => ['trialing','active','past_due','paused'].includes(s.status)).map(s => s.package_key));
    const grantable = DATA.catalog.filter(p => !heldActive.has(p.key));

    const entRows = ents.length ? ents.map(e => {
        const sub = subs.find(s => s.id === e.subscription_id);
        return `
        <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 px-4 py-3 first:border-t-0">
            <div class="min-w-0 flex-1">
                <span class="text-sm font-bold">${esc(e.label)}</span>
                ${e.notes ? `<span class="ml-2 text-[11px] text-slate-400">${esc(e.notes)}</span>` : ''}
                <span class="mt-0.5 block text-[11px] font-semibold text-slate-400">
                    granted ${fmtDate(e.granted_at)}${sub ? ` · ${money(sub.price, sub.currency)}/${esc(sub.billing_interval)}` : ''}${sub && sub.contract_ref ? ` · ${esc(sub.contract_ref)}` : ''}
                </span>
            </div>
            ${stateBadge(e.state)}
            <div class="flex gap-1">
                ${sub ? subActionButtons(sub) : ''}
            </div>
        </div>`;
    }).join('') : '<p class="px-4 py-4 text-sm text-slate-400">No packages granted yet.</p>';

    const historyRows = subs.length ? subs.map(s => `
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 py-2 text-[11px] first:border-t-0">
            <span class="font-bold text-slate-600">${esc(s.label)}</span>
            <span class="text-slate-400">${money(s.price, s.currency)}/${esc(s.billing_interval)}</span>
            <span>${subStatusBadge(s.status)}</span>
            <span class="text-slate-400">since ${fmtDate(s.started_at)}${s.canceled_at ? ` · ended ${fmtDate(s.canceled_at)}` : ''}</span>
        </div>`).join('') : '';

    document.getElementById('companyPanel').className = 'space-y-5';
    document.getElementById('companyPanel').innerHTML = `
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-black">${esc(c.name)}</h2>
                <p class="text-xs font-semibold text-slate-400">
                    ${esc(c.owner_name || 'No owner')}${c.owner_email ? ` · ${esc(c.owner_email)}` : ''} · company #${c.id} · ${esc(c.status)}
                </p>
            </div>
            <a href="registered-companies.php" class="text-[11px] font-black uppercase tracking-[0.1em] text-slate-400 hover:text-violet-600">Registry ↗</a>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200">
            <div class="bg-slate-50 px-4 py-2 text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Packages</div>
            ${entRows}
        </div>

        ${grantable.length ? `
        <form id="grantForm" class="rounded-xl border border-violet-200 bg-violet-50/50 p-4">
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-violet-700">Grant a package</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="text-[11px] font-bold text-slate-500">Package</span>
                    <select name="package_key" required class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold">
                        ${grantable.map(p => `<option value="${esc(p.key)}">${esc(p.label)}${Number(p.is_app) ? ' (provisions app)' : ''}</option>`).join('')}
                    </select>
                </label>
                <label class="block">
                    <span class="text-[11px] font-bold text-slate-500">Price</span>
                    <div class="mt-1 flex gap-2">
                        <input name="price" type="number" min="0" step="0.01" value="0" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold">
                        <select name="billing_interval" class="rounded-lg border border-slate-200 bg-white px-2 py-2 text-sm font-semibold">
                            <option value="monthly">/mo</option>
                            <option value="annual">/yr</option>
                        </select>
                    </div>
                </label>
                <label class="block">
                    <span class="text-[11px] font-bold text-slate-500">Contract / quote ref</span>
                    <input name="contract_ref" type="text" placeholder="optional" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold">
                </label>
                <label class="block">
                    <span class="text-[11px] font-bold text-slate-500">Internal note</span>
                    <input name="notes" type="text" placeholder="optional" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold">
                </label>
                <label class="flex items-center gap-2 text-sm font-semibold text-slate-600">
                    <input name="trial" type="checkbox" id="trialChk" class="h-4 w-4 rounded border-slate-300">
                    Start as trial
                </label>
                <label class="block">
                    <span class="text-[11px] font-bold text-slate-500">Trial ends</span>
                    <input name="trial_ends_at" type="date" id="trialDate" disabled class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold disabled:opacity-40">
                </label>
            </div>
            <button type="submit" class="mt-4 rounded-xl bg-violet-600 px-5 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white hover:bg-violet-700">Grant package</button>
        </form>` : '<p class="rounded-xl border border-dashed border-slate-200 p-4 text-center text-xs text-slate-400">Every catalog package already has an open subscription for this company.</p>'}

        ${historyRows ? `<div><p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Subscription history</p><div class="mt-1">${historyRows}</div></div>` : ''}
    `;

    const chk = document.getElementById('trialChk');
    if (chk) {
        chk.addEventListener('change', () => {
            const d = document.getElementById('trialDate');
            d.disabled = !chk.checked;
            if (chk.checked && !d.value) {
                const t = new Date(); t.setDate(t.getDate() + 30);
                d.value = t.toISOString().slice(0, 10);
            }
        });
    }
    const form = document.getElementById('grantForm');
    if (form) form.addEventListener('submit', submitGrant);
}

function subActionButtons(sub) {
    const btn = (label, cls, fn) => `<button onclick="${fn}" class="rounded-lg px-2.5 py-1 text-[11px] font-black ${cls}">${label}</button>`;
    const id = sub.id;
    if (['active', 'trialing'].includes(sub.status)) {
        return btn('Suspend', 'bg-amber-100 text-amber-700 hover:bg-amber-200', `setSub(${id},'paused')`)
             + btn('Cancel', 'bg-slate-100 text-slate-500 hover:bg-red-100 hover:text-red-600', `cancelSub(${id})`);
    }
    if (['past_due', 'paused'].includes(sub.status)) {
        return btn('Resume', 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200', `setSub(${id},'active')`)
             + btn('Cancel', 'bg-slate-100 text-slate-500 hover:bg-red-100 hover:text-red-600', `cancelSub(${id})`);
    }
    if (sub.status === 'canceled') {
        return btn('Reopen', 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200', `setSub(${id},'active')`);
    }
    return '';
}

async function submitGrant(e) {
    e.preventDefault();
    const f = e.target;
    const payload = {
        company_id: SELECTED,
        package_key: f.package_key.value,
        price: f.price.value,
        billing_interval: f.billing_interval.value,
        contract_ref: f.contract_ref.value,
        notes: f.notes.value,
        trial: f.trial.checked,
        trial_ends_at: f.trial_ends_at.value,
    };
    try {
        await api('grant.php', payload);
        showAlert('Package granted.', 'ok');
        await openCompany(SELECTED);
        await loadOverview();
    } catch (err) { showAlert(err.message, 'error'); }
}

async function setSub(id, status) {
    try {
        await api('subscription_status.php', { subscription_id: id, status });
        showAlert('Subscription updated.', 'ok');
        await openCompany(SELECTED);
        await loadOverview();
    } catch (err) { showAlert(err.message, 'error'); }
}

async function cancelSub(id) {
    const reason = prompt('Cancel this subscription? Optionally note why:');
    if (reason === null) return;
    try {
        await api('subscription_status.php', { subscription_id: id, status: 'canceled', cancel_reason: reason });
        showAlert('Subscription canceled.', 'ok');
        await openCompany(SELECTED);
        await loadOverview();
    } catch (err) { showAlert(err.message, 'error'); }
}

// ── Requests ────────────────────────────────────────────────────────────
function renderRequests() {
    const pending = DATA.requests.filter(r => r.status === 'pending').length;
    const badge = document.getElementById('reqBadge');
    badge.textContent = pending;
    badge.classList.toggle('hidden', pending === 0);

    const el = document.getElementById('requestList');
    if (!DATA.requests.length) {
        el.innerHTML = '<div class="px-5 py-8 text-center text-sm text-slate-400">No service requests.</div>';
        return;
    }
    el.innerHTML = DATA.requests.map(r => `
        <div class="flex flex-wrap items-center gap-3 px-5 py-3.5">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold">
                    <button onclick="openCompany(${r.company_id}); document.querySelector('.tabBtn[data-tab=companies]').click();" class="hover:text-violet-700 hover:underline">${esc(r.company_name)}</button>
                    <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-black uppercase text-slate-500">${esc(r.package_key || 'general')}</span>
                </p>
                <p class="mt-0.5 text-xs text-slate-500">${r.message ? esc(r.message) + ' · ' : ''}${esc(r.requested_by_name || r.requested_by_email || 'unknown')} · ${fmtDate(r.created_at)}</p>
            </div>
            <select onchange="setRequest(${r.id}, this.value)" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs font-black">
                ${['pending','contacted','converted','declined'].map(s => `<option value="${s}"${s === r.status ? ' selected' : ''}>${s}</option>`).join('')}
            </select>
        </div>`).join('');
}

async function setRequest(id, status) {
    try {
        await api('request_status.php', { request_id: id, status });
        const r = DATA.requests.find(x => x.id === id);
        if (r) r.status = status;
        renderRequests();
    } catch (err) { showAlert(err.message, 'error'); loadOverview(); }
}

loadOverview();
</script>
</body>
</html>
