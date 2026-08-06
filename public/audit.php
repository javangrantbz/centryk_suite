<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();

if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    header('Location: login.php');
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
    <title>Audit Trail - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Audit Trail'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-6xl px-4 pt-1 pb-5">

    <div id="pageAlert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-950 px-5 py-5 text-white">
            <div>
                <h1 class="text-xl font-black tracking-tight">Admin Audit Feed</h1>
                <p class="mt-1 text-xs font-semibold text-white/55">Login activity and tracked change events across Centryk.</p>
            </div>
            <button id="refreshBtn" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/8 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-white/15">Refresh</button>
        </div>
        <div class="grid gap-3 p-5 pb-0 lg:grid-cols-[1fr_1fr_1.5fr_auto]">
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">From</label>
                <input id="auditFromDate" type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">To</label>
                <input id="auditToDate" type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Search</label>
                <input id="auditSearch" type="search" placeholder="Search name, email, company, event..."
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none placeholder:text-slate-400 focus:border-cyan-500 focus:bg-white">
            </div>
            <div class="flex items-end">
                <button id="auditClearFilters" type="button" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-50">Clear</button>
            </div>
        </div>
        <div id="statsRow" class="grid gap-3 p-5 pb-0 sm:grid-cols-3"></div>
        <div id="eventsTable" class="divide-y divide-slate-100">
            <div class="px-5 py-8 text-center text-sm text-slate-400">Loading...</div>
        </div>
    </div>
</div>

<script>
let allEvents = [];
let filteredEvents = [];

const auditFromDate = document.getElementById('auditFromDate');
const auditToDate = document.getElementById('auditToDate');
const auditSearch = document.getElementById('auditSearch');
const auditClearFilters = document.getElementById('auditClearFilters');

document.getElementById('refreshBtn').addEventListener('click', loadAudit);

async function loadAudit() {
    try {
        const res = await fetch('api/audit/list.php?limit=250');
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.message || 'Failed to load audit events.');
        }
        allEvents = data.events || [];
        applyAuditFilters();
    } catch (error) {
        showAlert(error.message || 'Failed to load audit events.', 'error');
        document.getElementById('eventsTable').innerHTML =
            '<div class="px-5 py-8 text-center text-sm text-red-400">Failed to load audit events.</div>';
    }
}

function setCurrentMonthRange() {
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth(), 1);
    const last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    auditFromDate.value = toDateInputValue(first);
    auditToDate.value = toDateInputValue(last);
}

function toDateInputValue(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function eventDateValue(event) {
    return String(event.created_at || '').slice(0, 10);
}

function eventSearchText(event) {
    return [
        event.source,
        event.event_type,
        event.summary,
        event.actor && event.actor.name,
        event.actor && event.actor.email,
        event.target && event.target.name,
        event.target && event.target.email,
        event.company && event.company.name,
        ...Object.entries(event.metadata || {}).flat()
    ].filter(Boolean).join(' ').toLowerCase();
}

function applyAuditFilters() {
    const from = auditFromDate.value || '';
    const to = auditToDate.value || '';
    const query = auditSearch.value.trim().toLowerCase();

    filteredEvents = allEvents.filter((event) => {
        const eventDate = eventDateValue(event);
        const dateMatches = (!from || eventDate >= from) && (!to || eventDate <= to);
        const searchMatches = !query || eventSearchText(event).includes(query);
        return dateMatches && searchMatches;
    });

    renderStats(filteredEvents);
    renderEvents();
}

function renderStats(events) {
    const changeEvents = events.filter(event => event.source === 'change').length;
    const loginEvents = events.filter(event => event.source === 'login').length;
    const failedLogins = events.filter(event => event.source === 'login' && String(event.event_type || '').indexOf('failed') !== -1).length;
    document.getElementById('statsRow').innerHTML = `
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div class="text-xl font-black">${changeEvents}</div>
            <div class="text-xs font-semibold text-slate-500">Tracked changes shown</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div class="text-xl font-black">${loginEvents}</div>
            <div class="text-xs font-semibold text-slate-500">Login events shown</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div class="text-xl font-black">${failedLogins}</div>
            <div class="text-xs font-semibold text-slate-500">Failed logins shown</div>
        </div>`;
}

function renderEvents() {
    const table = document.getElementById('eventsTable');
    if (!filteredEvents.length) {
        table.innerHTML = '<div class="px-5 py-12 text-center text-sm text-slate-400">No audit events found.</div>';
        return;
    }

    table.innerHTML = filteredEvents.map((event) => {
        const actor = personLabel(event.actor);
        const target = personLabel(event.target);
        const company = event.company && event.company.name ? escapeHtml(event.company.name) : '';
        const meta = renderMetadata(event.metadata || {});
        return `
            <div class="px-5 py-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            ${badge(event.source === 'login' ? 'Login' : 'Change', event.source === 'login' ? 'slate' : 'blue')}
                            ${badge(escapeHtml(event.event_type || 'unknown'), event.source === 'login' && event.event_type.indexOf('failed') !== -1 ? 'red' : 'emerald')}
                        </div>
                        <p class="mt-2 text-sm font-semibold text-slate-900">${escapeHtml(event.summary || '')}</p>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span>Actor: ${actor}</span>
                            ${target ? `<span>Target: ${target}</span>` : ''}
                            ${company ? `<span>Company: ${company}</span>` : ''}
                        </div>
                        ${meta}
                    </div>
                    <div class="shrink-0 text-xs font-semibold text-slate-400">${formatDate(event.created_at)}</div>
                </div>
            </div>`;
    }).join('');
}

auditFromDate.addEventListener('input', applyAuditFilters);
auditToDate.addEventListener('input', applyAuditFilters);
auditSearch.addEventListener('input', applyAuditFilters);
auditClearFilters.addEventListener('click', function () {
    setCurrentMonthRange();
    auditSearch.value = '';
    applyAuditFilters();
});

function personLabel(person) {
    if (!person) return '';
    const name = person.name ? escapeHtml(person.name) : '';
    const email = person.email ? escapeHtml(person.email) : '';
    if (name && email) return `${name} (${email})`;
    return name || email || 'System';
}

function renderMetadata(metadata) {
    const entries = Object.entries(metadata || {}).filter(([, value]) => value !== null && value !== '');
    if (!entries.length) return '';
    return `<div class="mt-2 flex flex-wrap gap-2">${entries.map(([key, value]) => `
        <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600">
            ${escapeHtml(labelize(key))}: ${escapeHtml(String(value))}
        </span>`).join('')}</div>`;
}

function badge(label, tone) {
    const styles = {
        slate: 'border-slate-200 bg-slate-100 text-slate-600',
        blue: 'border-blue-200 bg-blue-50 text-blue-700',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        red: 'border-red-200 bg-red-50 text-red-700'
    };
    return `<span class="rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] ${styles[tone] || styles.slate}">${label}</span>`;
}

function labelize(key) {
    return String(key || '').replace(/_/g, ' ');
}

function formatDate(value) {
    if (!value) return '';
    return new Date(value).toLocaleString('en-BZ', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function showAlert(message, type) {
    const el = document.getElementById('pageAlert');
    el.textContent = message;
    el.className = type === 'error'
        ? 'mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700'
        : 'mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700';
    el.classList.remove('hidden');
}

setCurrentMonthRange();
loadAudit();
</script>
</body>
</html>

