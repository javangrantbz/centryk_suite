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
<head><?php $bizTitle = 'Business Packages'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Centryk Business'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="biz mx-auto max-w-6xl px-4 py-4">

    <div id="pageAlert" class="biz-notice mb-3 hidden"></div>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker"><a href="admin-business-roadmap.php" class="underline">Centryk Business</a> · internal</p>
            <h1 class="mt-0.5">Business packages</h1>
        </div>
        <span class="flex gap-2">
            <a href="admin-business-billing.php" class="biz-btn biz-btn-ghost">Billing</a>
            <button onclick="loadOverview()" class="biz-btn biz-btn-ghost">Refresh</button>
        </span>
    </div>

    <div class="biz-tabs mb-3">
        <button data-tab="companies" class="tabBtn is-active">Companies</button>
        <button data-tab="requests" class="tabBtn">Requests <span id="reqBadge" class="biz-chip biz-c-amber ml-1 hidden"></span></button>
        <button data-tab="groups" class="tabBtn">Groups</button>
    </div>

    <!-- ── Companies tab ─────────────────────────────────────────────── -->
    <div id="tab-companies">
        <div class="grid gap-3 lg:grid-cols-[300px_minmax(0,1fr)]">
            <div class="space-y-3">
                <div>
                    <label class="biz-label">Find a company</label>
                    <input id="searchBox" type="text" placeholder="Company name…" autocomplete="off" class="biz-input">
                    <div id="searchResults" class="mt-1 space-y-1"></div>
                </div>
                <div class="biz-panel">
                    <div class="biz-panel-head">Business customers</div>
                    <div id="customerList" class="biz-list"><div class="biz-panel-empty">Loading…</div></div>
                </div>
            </div>
            <div id="companyPanel" class="biz-panel biz-panel-empty self-start">Pick a company to view and grant packages.</div>
        </div>
    </div>

    <!-- ── Requests tab ─────────────────────────────────────────────── -->
    <div id="tab-requests" class="hidden">
        <div class="biz-panel">
            <div class="biz-panel-head">Service requests</div>
            <div id="requestList" class="biz-list"><div class="biz-panel-empty">Loading…</div></div>
        </div>
    </div>

    <!-- ── Groups tab ───────────────────────────────────────────────── -->
    <div id="tab-groups" class="hidden">
        <div class="grid gap-3 lg:grid-cols-[280px_minmax(0,1fr)]">
            <div class="space-y-3">
                <form id="newGroupForm" class="biz-panel biz-panel-body" style="background:var(--bz-head)">
                    <p class="biz-kicker">New group</p>
                    <input name="name" required placeholder="Group name" class="biz-input mt-2">
                    <input name="admin_email" type="email" placeholder="first group admin (email, optional)" class="biz-input mt-2">
                    <button class="biz-btn biz-btn-primary mt-2 w-full">Create</button>
                </form>
                <div class="biz-panel">
                    <div class="biz-panel-head">Groups</div>
                    <div id="groupList" class="biz-list"><div class="biz-panel-empty">Loading…</div></div>
                </div>
            </div>
            <div id="groupPanel" class="biz-panel biz-panel-empty self-start">Pick a group to manage its companies, packages and people.</div>
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
    return new Date(s.replace(' ', 'T')).toLocaleDateString('en-BZ', { year: '2-digit', month: 'short', day: 'numeric' });
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
    el.className = 'biz-notice mb-3 ' + (type === 'error' ? 'biz-notice-red' : 'biz-notice-green');
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
        document.querySelectorAll('.tabBtn').forEach(b => b.classList.toggle('is-active', b === btn));
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
        <button onclick="openGroup(${g.id})" class="biz-row ${OPEN_GROUP === g.id ? 'is-active' : ''}">
            <span class="min-w-0 flex-1">
                <span class="block truncate" style="font-weight:600">${esc(g.name)}</span>
                <span class="block biz-muted" style="font-size:11px">${g.company_count} companies · ${Object.keys(g.entitlements || {}).join(', ') || 'no packages'}</span>
            </span>
        </button>`).join('') : '<div class="biz-panel-empty">No groups yet.</div>';
}
function openGroup(id) { OPEN_GROUP = id; renderGroupList(); renderGroupPanel(GROUPS.find(g => g.id === id)); }

function renderGroupPanel(g) {
    if (!g) return;
    const packages = DATA.catalog.map(p => {
        const st = (g.entitlements || {})[p.key];
        const btns = st === 'full'
            ? `<button onclick="groupGrant(${g.id},'${p.key}','suspend')" class="biz-btn biz-btn-ghost biz-btn-sm">Suspend</button>
               <button onclick="groupGrant(${g.id},'${p.key}','revoke')" class="biz-btn biz-btn-danger biz-btn-sm">Revoke</button>`
            : st === 'read'
            ? `<button onclick="groupGrant(${g.id},'${p.key}','resume')" class="biz-btn biz-btn-ghost biz-btn-sm">Resume</button>`
            : `<button onclick="groupGrant(${g.id},'${p.key}','grant')" class="biz-btn biz-btn-primary biz-btn-sm">Grant</button>`;
        return `<div class="biz-row" style="cursor:default">
            <span class="flex-1" style="font-weight:600">${esc(p.label)} ${st ? stateBadge(st === 'read' ? 'suspended' : 'active') : ''}</span>
            <span class="flex gap-1">${btns}</span></div>`;
    }).join('');

    const companies = (g.companies || []).map(c => `
        <div class="biz-row" style="cursor:default">
            <span class="flex-1" style="font-weight:600">${esc(c.name)}</span>
            <button onclick="groupCompany(${g.id}, ${c.id}, 'detach')" class="biz-btn biz-btn-ghost biz-btn-sm">Remove</button>
        </div>`).join('') || '<div class="biz-panel-empty">No companies.</div>';

    const members = (g.members || []).map(m => `<span class="biz-chip biz-c-slate">${esc(m.email)} · ${m.role === 'group_admin' ? 'admin' : 'viewer'}</span>`).join(' ');

    document.getElementById('groupPanel').className = 'biz-panel self-start';
    document.getElementById('groupPanel').innerHTML = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line)"><h2>${esc(g.name)}</h2></div>
        <div class="biz-panel-head">Group packages (members inherit)</div>
        <div class="biz-list">${packages}</div>
        <div class="biz-panel-head" style="border-top:1px solid var(--bz-line)">Companies</div>
        <div class="biz-list">${companies}</div>
        <div class="biz-panel-body" style="border-top:1px solid var(--bz-line)">
            <input id="grpCoSearch" placeholder="Attach a company by name…" class="biz-input">
            <div id="grpCoResults" class="mt-1 space-y-1"></div>
        </div>
        <div class="biz-panel-body" style="border-top:1px solid var(--bz-line)">
            <p class="biz-tile-l">People</p>
            <div class="mt-1 flex flex-wrap gap-1">${members || '<span class="biz-muted" style="font-size:11px">none</span>'}</div>
        </div>`;
    const s = document.getElementById('grpCoSearch');
    let tmr;
    s.addEventListener('input', () => {
        clearTimeout(tmr);
        const q = s.value.trim();
        if (q.length < 2) { document.getElementById('grpCoResults').innerHTML = ''; return; }
        tmr = setTimeout(async () => {
            const d = await api('overview.php', { q });
            document.getElementById('grpCoResults').innerHTML = (d.search_results || []).map(c =>
                `<button onclick="groupCompany(${g.id}, ${c.id}, 'attach')" class="block w-full text-left" style="border:1px solid var(--bz-line);border-radius:3px;background:#fff;padding:4px 8px;font-size:12px;font-weight:600">${esc(c.name)}</button>`).join('');
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
                    <button onclick="openCompany(${c.id})" class="flex w-full items-center justify-between gap-2 text-left" style="border:1px solid var(--bz-line);border-radius:3px;background:#fff;padding:4px 8px;font-size:12px;font-weight:600">
                        <span class="truncate">${esc(c.name)}</span>
                        <span class="biz-chip ${c.status === 'active' ? 'biz-c-green' : 'biz-c-slate'}">${esc(c.status)}</span>
                    </button>`).join('')
                : '<p class="biz-muted" style="font-size:11px;padding:4px 2px">No matches.</p>';
        } catch (err) { showAlert(err.message, 'error'); }
    }, 250);
});

function renderCustomers() {
    const el = document.getElementById('customerList');
    if (!DATA.customers.length) {
        el.innerHTML = '<div class="biz-panel-empty">No companies on Centryk Business yet.</div>';
        return;
    }
    el.innerHTML = DATA.customers.map(c => {
        const pkgs = (c.packages || '').split(',').filter(Boolean);
        const suspended = Number(c.suspended_count || 0);
        return `
        <button onclick="openCompany(${c.id})" class="biz-row ${SELECTED === c.id ? 'is-active' : ''}">
            <span class="min-w-0 flex-1">
                <span class="block truncate" style="font-weight:600">${esc(c.name)}</span>
                <span class="block biz-muted" style="font-size:11px">
                    ${pkgs.map(esc).join(' · ')}${suspended ? ` · <span class="biz-t-amber">${suspended} suspended</span>` : ''}
                </span>
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
    const map = { active: 'biz-c-green', suspended: 'biz-c-amber', revoked: 'biz-c-slate' };
    return `<span class="biz-chip ${map[state] || 'biz-c-slate'}">${esc(state)}</span>`;
}
function subStatusBadge(s) {
    const map = { trialing: 'biz-t-blue', active: 'biz-t-green', past_due: 'biz-t-amber', paused: 'biz-t-amber', canceled: 'biz-muted' };
    return `<span class="${map[s] || 'biz-muted'}" style="font-size:11px;font-weight:700;text-transform:uppercase">${esc(s.replace('_', ' '))}</span>`;
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
        <div class="biz-row" style="cursor:default;flex-wrap:wrap">
            <div class="min-w-0 flex-1">
                <span style="font-weight:600">${esc(e.label)}</span>
                ${e.notes ? `<span class="biz-muted" style="font-size:11px">&nbsp;${esc(e.notes)}</span>` : ''}
                <span class="block biz-muted" style="font-size:11px">
                    granted ${fmtDate(e.granted_at)}${sub ? ` · ${money(sub.price, sub.currency)}/${esc(sub.billing_interval)}` : ''}${sub && sub.contract_ref ? ` · ${esc(sub.contract_ref)}` : ''}
                </span>
            </div>
            ${stateBadge(e.state)}
            <div class="flex gap-1">${sub ? subActionButtons(sub) : ''}</div>
        </div>`;
    }).join('') : '<div class="biz-panel-empty">No packages granted yet.</div>';

    const historyRows = subs.length ? subs.map(s => `
        <div class="biz-row" style="cursor:default;font-size:11px;flex-wrap:wrap;gap:6px">
            <span style="font-weight:600">${esc(s.label)}</span>
            <span class="biz-muted biz-num">${money(s.price, s.currency)}/${esc(s.billing_interval)}</span>
            <span>${subStatusBadge(s.status)}</span>
            <span class="biz-muted">since ${fmtDate(s.started_at)}${s.canceled_at ? ` · ended ${fmtDate(s.canceled_at)}` : ''}</span>
        </div>`).join('') : '';

    document.getElementById('companyPanel').className = 'biz-panel self-start';
    document.getElementById('companyPanel').innerHTML = `
        <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line)">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2>${esc(c.name)}</h2>
                    <p class="biz-muted" style="font-size:11px;margin-top:2px">
                        ${esc(c.owner_name || 'No owner')}${c.owner_email ? ` · ${esc(c.owner_email)}` : ''} · company #${c.id} · ${esc(c.status)}
                    </p>
                </div>
                <a href="registered-companies.php" class="biz-kicker" style="text-decoration:none">Registry ↗</a>
            </div>
        </div>

        <div class="biz-panel-head">Packages</div>
        <div class="biz-list">${entRows}</div>

        ${grantable.length ? `
        <form id="grantForm" class="biz-panel-body" style="border-top:1px solid var(--bz-line);background:var(--bz-head)">
            <p class="biz-kicker" style="color:var(--bz-accent-d)">Grant a package</p>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                <label class="block"><span class="biz-label">Package</span>
                    <select name="package_key" required class="biz-select">
                        ${grantable.map(p => `<option value="${esc(p.key)}">${esc(p.label)}${Number(p.is_app) ? ' (provisions app)' : ''}</option>`).join('')}
                    </select></label>
                <label class="block"><span class="biz-label">Price</span>
                    <div class="flex gap-2">
                        <input name="price" type="number" min="0" step="0.01" value="0" class="biz-input">
                        <select name="billing_interval" class="biz-select" style="width:auto">
                            <option value="monthly">/mo</option>
                            <option value="annual">/yr</option>
                        </select>
                    </div></label>
                <label class="block"><span class="biz-label">Contract / quote ref</span>
                    <input name="contract_ref" type="text" placeholder="optional" class="biz-input"></label>
                <label class="block"><span class="biz-label">Internal note</span>
                    <input name="notes" type="text" placeholder="optional" class="biz-input"></label>
                <label class="flex items-center gap-2" style="font-size:12px;font-weight:500;color:var(--bz-fg)">
                    <input name="trial" type="checkbox" id="trialChk"> Start as trial
                </label>
                <label class="block"><span class="biz-label">Trial ends</span>
                    <input name="trial_ends_at" type="date" id="trialDate" disabled class="biz-input"></label>
            </div>
            <button type="submit" class="biz-btn biz-btn-primary mt-3">Grant package</button>
        </form>` : '<div class="biz-panel-empty">Every catalog package already has an open subscription for this company.</div>'}

        ${historyRows ? `<div class="biz-panel-head" style="border-top:1px solid var(--bz-line)">Subscription history</div><div class="biz-list">${historyRows}</div>` : ''}
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
    const btn = (label, cls, fn) => `<button onclick="${fn}" class="biz-btn ${cls} biz-btn-sm">${label}</button>`;
    const id = sub.id;
    if (['active', 'trialing'].includes(sub.status)) {
        return btn('Suspend', 'biz-btn-ghost', `setSub(${id},'paused')`) + btn('Cancel', 'biz-btn-danger', `cancelSub(${id})`);
    }
    if (['past_due', 'paused'].includes(sub.status)) {
        return btn('Resume', 'biz-btn-primary', `setSub(${id},'active')`) + btn('Cancel', 'biz-btn-danger', `cancelSub(${id})`);
    }
    if (sub.status === 'canceled') {
        return btn('Reopen', 'biz-btn-primary', `setSub(${id},'active')`);
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
        el.innerHTML = '<div class="biz-panel-empty">No service requests.</div>';
        return;
    }
    el.innerHTML = DATA.requests.map(r => `
        <div class="biz-row" style="cursor:default;flex-wrap:wrap">
            <div class="min-w-0 flex-1">
                <p style="font-size:12px;font-weight:600">
                    <button onclick="openCompany(${r.company_id}); document.querySelector('.tabBtn[data-tab=companies]').click();" class="underline" style="background:none">${esc(r.company_name)}</button>
                    <span class="biz-chip biz-c-slate ml-1">${esc(r.package_key || 'general')}</span>
                </p>
                <p class="biz-muted" style="font-size:11px">${r.message ? esc(r.message) + ' · ' : ''}${esc(r.requested_by_name || r.requested_by_email || 'unknown')} · ${fmtDate(r.created_at)}</p>
            </div>
            <select onchange="setRequest(${r.id}, this.value)" class="biz-select" style="width:auto">
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
