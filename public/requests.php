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
    <title>New Users — Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
</head>
<body class="min-h-screen bg-[#0d1117] text-white font-sans antialiased">

<div class="mx-auto max-w-5xl px-4 py-8">

    <!-- Header -->
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="index.php" class="flex items-center gap-2 text-white/40 hover:text-white/80 transition text-sm font-semibold">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                Launcher
            </a>
            <span class="text-white/20">/</span>
            <h1 class="text-xl font-black tracking-tight">New Users</h1>
        </div>
        <div class="text-sm text-white/40 font-semibold"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></div>
    </div>

    <!-- Stats row -->
    <div id="statsRow" class="mb-6 flex gap-3"></div>

    <!-- Alert -->
    <div id="pageAlert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

    <!-- Table -->
    <div class="overflow-hidden rounded-2xl border border-white/10 bg-[#111827]">
        <div class="flex items-center justify-between border-b border-white/8 px-5 py-4">
            <span class="text-sm font-black uppercase tracking-[0.14em] text-white/50">All Signups</span>
            <button onclick="loadUsers()" class="text-xs font-semibold text-white/30 hover:text-white/70 transition">Refresh</button>
        </div>
        <div id="usersTable" class="divide-y divide-white/6">
            <div class="px-5 py-8 text-center text-sm text-white/30">Loading…</div>
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
        <div class="rounded-xl border border-white/8 bg-[#111827] px-4 py-3 flex items-center gap-2">
            <span class="text-sm font-black">${allUsers.length}</span>
            <span class="text-xs text-white/40 font-semibold">Total</span>
        </div>
        <div class="rounded-xl border border-white/8 bg-[#111827] px-4 py-3 flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
            <span class="text-sm font-black">${active}</span>
            <span class="text-xs text-white/40 font-semibold">Active</span>
        </div>
        <div class="rounded-xl border border-white/8 bg-[#111827] px-4 py-3 flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-white/20"></span>
            <span class="text-sm font-black">${inactive}</span>
            <span class="text-xs text-white/40 font-semibold">Inactive</span>
        </div>`;
}

function statusBadge(status) {
    if (status === 'active') {
        return '<span class="rounded-full border border-emerald-400/30 bg-emerald-400/15 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-emerald-300">Active</span>';
    }
    return '<span class="rounded-full border border-white/10 bg-white/6 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-white/30">Inactive</span>';
}

function initials(first, last) {
    return ((first || '').charAt(0) + (last || '').charAt(0)).toUpperCase() || '?';
}

function renderTable() {
    const tbody = document.getElementById('usersTable');
    if (!allUsers.length) {
        tbody.innerHTML = '<div class="px-5 py-12 text-center text-sm text-white/30">No users yet.</div>';
        return;
    }
    tbody.innerHTML = allUsers.map(u => {
        const name    = [u.first_name, u.last_name].filter(Boolean).join(' ') || '—';
        const isActive = u.status === 'active';
        return `
        <div class="flex items-center gap-4 px-5 py-3.5 hover:bg-white/3 transition">
            <div class="h-8 w-8 shrink-0 rounded-full bg-white/10 flex items-center justify-center text-xs font-black text-white/60">
                ${esc(initials(u.first_name, u.last_name))}
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold truncate">${esc(name)}</p>
                <p class="text-xs text-white/40 mt-0.5 truncate">${esc(u.email)}</p>
            </div>
            <div class="shrink-0 hidden sm:block text-xs text-white/30">${fmtDate(u.created_at)}</div>
            <div class="shrink-0">${statusBadge(u.status)}</div>
            <div class="shrink-0">
                <button onclick="toggleUser(${u.id}, '${isActive ? 'inactive' : 'active'}')"
                    class="rounded-lg px-3 py-1.5 text-xs font-black transition ${isActive
                        ? 'bg-white/8 text-white/50 hover:bg-red-500/15 hover:text-red-300'
                        : 'bg-white/8 text-white/50 hover:bg-emerald-500/15 hover:text-emerald-300'}">
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
        ? 'mb-4 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm font-semibold text-red-300'
        : 'mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm font-semibold text-emerald-300';
    el.classList.remove('hidden');
    setTimeout(() => el.classList.add('hidden'), 4000);
}

loadUsers();
</script>
</body>
</html>
