<?php
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
    <title>All Signups - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'All Signups'; $headerMaxW = 'max-w-5xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-5xl px-4 pt-1 pb-5">

    <div id="pageAlert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-950 px-5 py-5 text-white">
            <div>
                <h1 class="text-xl font-black tracking-tight">All Signups</h1>
                <p class="mt-1 text-xs font-semibold text-white/55">Review Centryk account signups and activate or deactivate user access.</p>
            </div>
            <button onclick="loadUsers()" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/8 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-white/15">Refresh</button>
        </div>
        <div id="statsRow" class="px-5 pt-5 flex flex-wrap gap-3"></div>
        <div id="usersTable" class="divide-y divide-slate-100">
            <div class="px-5 py-8 text-center text-sm text-slate-400">Loading…</div>
        </div>
    </div>

</div>

<script>
let allUsers = [];

async function loadUsers() {
    try {
        const res  = await fetch('api/requests/list.php');
        const data = await res.json();
        allUsers = data.users || [];
        renderStats();
        renderTable();
    } catch (e) {
        document.getElementById('usersTable').innerHTML =
            '<div class="px-5 py-8 text-center text-sm text-red-400">Failed to load users.</div>';
    }
}

function renderStats() {
    const active   = allUsers.filter(u => u.status === 'active').length;
    const inactive = allUsers.filter(u => u.status !== 'active').length;
    document.getElementById('statsRow').innerHTML = `
        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 flex items-center gap-2">
            <span class="text-sm font-black">${allUsers.length}</span>
            <span class="text-xs text-slate-500 font-semibold">Total</span>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
            <span class="text-sm font-black">${active}</span>
            <span class="text-xs text-slate-500 font-semibold">Active</span>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-slate-300"></span>
            <span class="text-sm font-black">${inactive}</span>
            <span class="text-xs text-slate-500 font-semibold">Inactive</span>
        </div>`;
}

function statusBadge(status) {
    if (status === 'active') {
        return '<span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-emerald-700">Active</span>';
    }
    return '<span class="rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-slate-500">Inactive</span>';
}

function initials(first, last) {
    return ((first || '').charAt(0) + (last || '').charAt(0)).toUpperCase() || '?';
}

function renderTable() {
    const tbody = document.getElementById('usersTable');
    if (!allUsers.length) {
        tbody.innerHTML = '<div class="px-5 py-12 text-center text-sm text-slate-400">No users yet.</div>';
        return;
    }
    tbody.innerHTML = allUsers.map(u => {
        const name    = [u.first_name, u.last_name].filter(Boolean).join(' ') || '—';
        const isActive = u.status === 'active';
        return `
        <div class="flex items-center gap-4 px-5 py-3.5 hover:bg-slate-50 transition">
            <div class="h-8 w-8 shrink-0 rounded-full bg-slate-100 flex items-center justify-center text-xs font-black text-slate-500">
                ${esc(initials(u.first_name, u.last_name))}
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-900 truncate"><a href="user-profile.php?id=${u.id}" class="hover:text-violet-700 hover:underline">${esc(name)}</a></p>
                <p class="text-xs text-slate-500 mt-0.5 truncate">${esc(u.email)}</p>
            </div>
            <div class="shrink-0 hidden sm:block text-xs text-slate-400">${fmtDate(u.created_at)}</div>
            <div class="shrink-0">${statusBadge(u.status)}</div>
            <div class="shrink-0">
                <button onclick="toggleUser(${u.id}, '${isActive ? 'inactive' : 'active'}')"
                    class="rounded-lg px-3 py-1.5 text-xs font-black transition ${isActive
                        ? 'bg-slate-100 text-slate-500 hover:bg-red-50 hover:text-red-600'
                        : 'bg-slate-100 text-slate-500 hover:bg-emerald-50 hover:text-emerald-600'}">
                    ${isActive ? 'Deactivate' : 'Activate'}
                </button>
            </div>
        </div>`;
    }).join('');
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(s) {
    if (!s) return '';
    return new Date(s).toLocaleDateString('en-BZ', { month: 'short', day: 'numeric', year: 'numeric' });
}

async function toggleUser(userId, newStatus) {
    const label = newStatus === 'active' ? 'activate' : 'deactivate';
    if (!confirm(`Are you sure you want to ${label} this user?`)) return;

    try {
        const res  = await fetch('api/requests/toggle_user.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ user_id: userId, status: newStatus }),
        });
        const data = await res.json();
        if (data.ok) {
            const u = allUsers.find(x => x.id == userId);
            if (u) u.status = newStatus;
            renderStats();
            renderTable();
        } else {
            showAlert(data.message || 'Failed to update user.', 'error');
        }
    } catch (e) {
        showAlert('Network error.', 'error');
    }
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

loadUsers();
</script>
</body>
</html>



