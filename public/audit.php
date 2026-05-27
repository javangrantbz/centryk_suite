<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();

if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    header('Location: index.php');
    exit;
}

$user = $me['user'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Trail - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
</head>
<body class="min-h-screen bg-[#0d1117] text-white font-sans antialiased">
<div class="mx-auto max-w-6xl px-4 py-8">
    <div class="mb-6 flex items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <a href="index.php" class="flex items-center gap-2 text-sm font-semibold text-white/40 transition hover:text-white/80">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                Launcher
            </a>
            <span class="text-white/20">/</span>
            <h1 class="text-xl font-black tracking-tight">Audit Trail</h1>
        </div>
        <div class="text-sm font-semibold text-white/40"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
    </div>

    <div id="statsRow" class="mb-6 grid gap-3 sm:grid-cols-3"></div>
    <div id="pageAlert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

    <div class="overflow-hidden rounded-2xl border border-white/10 bg-[#111827]">
        <div class="flex items-center justify-between border-b border-white/8 px-5 py-4">
            <div>
                <p class="text-sm font-black uppercase tracking-[0.14em] text-white/50">Admin Audit Feed</p>
                <p class="mt-1 text-xs font-semibold text-white/25">Login activity and tracked change events across Centryk.</p>
            </div>
            <button id="refreshBtn" class="text-xs font-semibold text-white/30 transition hover:text-white/70">Refresh</button>
        </div>
        <div id="eventsTable" class="divide-y divide-white/6">
            <div class="px-5 py-8 text-center text-sm text-white/30">Loading...</div>
        </div>
    </div>
</div>

<script>
let allEvents = [];

document.getElementById('refreshBtn').addEventListener('click', loadAudit);

async function loadAudit() {
    try {
        const res = await fetch('api/audit/list.php?limit=150');
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.message || 'Failed to load audit events.');
        }
        allEvents = data.events || [];
        renderStats(data.stats || {});
        renderEvents();
    } catch (error) {
        showAlert(error.message || 'Failed to load audit events.', 'error');
        document.getElementById('eventsTable').innerHTML =
            '<div class="px-5 py-8 text-center text-sm text-red-400">Failed to load audit events.</div>';
    }
}

function renderStats(stats) {
    document.getElementById('statsRow').innerHTML = `
        <div class="rounded-xl border border-white/8 bg-[#111827] px-4 py-3">
            <div class="text-xl font-black">${Number(stats.change_events || 0)}</div>
            <div class="text-xs font-semibold text-white/40">Tracked changes loaded</div>
        </div>
        <div class="rounded-xl border border-white/8 bg-[#111827] px-4 py-3">
            <div class="text-xl font-black">${Number(stats.login_events || 0)}</div>
            <div class="text-xs font-semibold text-white/40">Login events loaded</div>
        </div>
        <div class="rounded-xl border border-white/8 bg-[#111827] px-4 py-3">
            <div class="text-xl font-black">${Number(stats.failed_logins || 0)}</div>
            <div class="text-xs font-semibold text-white/40">Failed logins loaded</div>
        </div>`;
}

function renderEvents() {
    const table = document.getElementById('eventsTable');
    if (!allEvents.length) {
        table.innerHTML = '<div class="px-5 py-12 text-center text-sm text-white/30">No audit events found.</div>';
        return;
    }

    table.innerHTML = allEvents.map((event) => {
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
                        <p class="mt-2 text-sm font-semibold text-white">${escapeHtml(event.summary || '')}</p>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-white/40">
                            <span>Actor: ${actor}</span>
                            ${target ? `<span>Target: ${target}</span>` : ''}
                            ${company ? `<span>Company: ${company}</span>` : ''}
                        </div>
                        ${meta}
                    </div>
                    <div class="shrink-0 text-xs font-semibold text-white/30">${formatDate(event.created_at)}</div>
                </div>
            </div>`;
    }).join('');
}

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
        <span class="rounded-lg bg-white/6 px-2.5 py-1 text-[11px] font-semibold text-white/45">
            ${escapeHtml(labelize(key))}: ${escapeHtml(String(value))}
        </span>`).join('')}</div>`;
}

function badge(label, tone) {
    const styles = {
        slate: 'border-white/10 bg-white/6 text-white/45',
        blue: 'border-blue-400/20 bg-blue-400/10 text-blue-300',
        emerald: 'border-emerald-400/20 bg-emerald-400/10 text-emerald-300',
        red: 'border-red-400/20 bg-red-400/10 text-red-300'
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
        ? 'mb-4 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm font-semibold text-red-300'
        : 'mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm font-semibold text-emerald-300';
    el.classList.remove('hidden');
}

loadAudit();
</script>
</body>
</html>
