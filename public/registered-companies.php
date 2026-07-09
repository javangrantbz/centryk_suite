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
    <title>Registered Companies - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Registered Companies'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-6xl px-4 pt-4 pb-8">

    <div id="pageAlert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

    <div class="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-950 px-5 py-5 text-white">
        <div>
            <h1 class="text-xl font-black tracking-tight">Company Registry</h1>
            <p class="mt-1 text-xs font-semibold text-white/55">All companies registered in Centryk and their admin activity.</p>
        </div>
        <button id="refreshBtn" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/8 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-white/15">Refresh</button>
    </div>

    <div id="statsRow" class="grid gap-3 p-5 pb-0 sm:grid-cols-4"></div>
    <div id="companiesGrid" class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white px-5 py-8 text-center text-sm text-slate-400 sm:col-span-2 lg:col-span-3">Loading...</div>
    </div>
    </div>
</div>

<script>
let allCompanies = [];
const canDeleteCompanies = <?= strcasecmp((string)($user['email'] ?? ''), 'webdevelopment@bhilimited.com') === 0 ? 'true' : 'false' ?>;

document.getElementById('refreshBtn').addEventListener('click', loadCompanies);

async function loadCompanies() {
    try {
        const res = await fetch('api/admin/companies.php');
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.message || 'Failed to load companies.');
        }
        allCompanies = data.companies || [];
        renderStats(data.stats || {});
        renderCompanies();
    } catch (error) {
        showAlert(error.message || 'Failed to load companies.', 'error');
        document.getElementById('companiesGrid').innerHTML =
            '<div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-8 text-center text-sm font-semibold text-red-700 sm:col-span-2 lg:col-span-3">Failed to load companies.</div>';
    }
}

function renderStats(stats) {
    document.getElementById('statsRow').innerHTML = `
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div class="text-xl font-black">${Number(stats.total_companies || 0)}</div>
            <div class="text-xs font-semibold text-slate-500">Registered companies</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div class="text-xl font-black">${Number(stats.total_employees || 0)}</div>
            <div class="text-xs font-semibold text-slate-500">Employees</div>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
            <div class="text-xl font-black text-emerald-700">${Number(stats.active_companies || 0)}</div>
            <div class="text-xs font-semibold text-emerald-600">Active</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div class="text-xl font-black text-slate-700">${Number(stats.inactive_companies || 0)}</div>
            <div class="text-xs font-semibold text-slate-500">Inactive</div>
        </div>`;
}

function renderCompanies() {
    const grid = document.getElementById('companiesGrid');
    if (!allCompanies.length) {
        grid.innerHTML = '<div class="rounded-2xl border border-slate-200 bg-white px-5 py-12 text-center text-sm text-slate-400 sm:col-span-2 lg:col-span-3">No registered companies found.</div>';
        return;
    }

    grid.innerHTML = allCompanies.map(companyCard).join('');
}

function companyCard(company) {
    const active = company.activity && company.activity.status === 'active';
    const admin = company.admin || {};
    return `
        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-lg shadow-black/10">
            <div class="flex items-start gap-4 px-5 py-5">
                ${avatar(company)}
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="truncate text-base font-black tracking-tight text-slate-900">${esc(company.name)}</h2>
                        ${statusBadge(active)}
                    </div>
                    <p class="mt-1 text-xs font-semibold text-slate-500">Registered ${fmtDate(company.registered_at)}</p>
                </div>
            </div>
            <div class="space-y-3 border-t border-slate-100 px-5 py-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Admin</p>
                    <p class="mt-1 truncate text-sm font-bold text-slate-800">${esc(admin.name || 'Unnamed admin')}</p>
                    <p class="mt-0.5 truncate text-xs font-semibold text-slate-500">${esc(admin.email || '')}</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                        <p class="text-lg font-black text-slate-900">${Number(company.employee_count || 0)}</p>
                        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Employees</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                        <p class="truncate text-xs font-bold text-slate-700">${fmtDate(admin.last_login_at) || 'Never'}</p>
                        <p class="mt-1 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-400">Last login</p>
                    </div>
                </div>
                ${active ? '' : '<div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-700">No active session for a month</div>'}
                ${canDeleteCompanies ? deleteButton(company) : ''}
            </div>
        </article>`;
}

function deleteButton(company) {
    return `
        <button type="button"
            onclick="deleteCompany(${Number(company.id || 0)})"
            class="w-full rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-red-700 transition hover:bg-red-100 hover:text-red-800">
            Delete Company
        </button>`;
}

async function deleteCompany(companyId) {
    if (!canDeleteCompanies || !companyId) return;
    const company = allCompanies.find(item => Number(item.id) === Number(companyId)) || {};
    const companyName = company.name || 'this company';
    const confirmed = confirm(`Delete ${companyName}? This will remove the company and related Centryk records.`);
    if (!confirmed) return;

    try {
        const res = await fetch('api/admin/delete-company.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ company_id: companyId })
        });
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.message || 'Could not delete company.');
        }
        showAlert(data.message || 'Company deleted.', 'success');
        await loadCompanies();
    } catch (error) {
        showAlert(error.message || 'Could not delete company.', 'error');
    }
}

function avatar(company) {
    const letter = esc((company.name || '?').charAt(0).toUpperCase() || '?');
    if (company.logo) {
        return `
            <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <img src="${escAttr(company.logo)}" alt="" class="h-full w-full object-contain p-1.5"
                    onerror="replaceBrokenLogo(this, '${escAttr(letter)}')">
            </div>`;
    }
    return letterAvatarHtml(letter);
}

function letterAvatarHtml(letter) {
    return `<div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-sky-500 text-xl font-black text-slate-900">${letter}</div>`;
}

function replaceBrokenLogo(img, letter) {
    if (!img || !img.parentElement) return;
    img.parentElement.outerHTML = letterAvatarHtml(esc(letter || '?'));
}

function statusBadge(active) {
    if (active) {
        return '<span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-emerald-700">Active</span>';
    }
    return '<span class="rounded-full border border-slate-200 bg-white/6 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-slate-500">Inactive</span>';
}

function fmtDate(value) {
    if (!value) return '';
    return new Date(String(value).replace(' ', 'T')).toLocaleDateString('en-BZ', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
}

function esc(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function escAttr(value) {
    return esc(value).replace(/'/g, '&#39;');
}

function showAlert(message, type) {
    const el = document.getElementById('pageAlert');
    el.textContent = message;
    el.className = type === 'error'
        ? 'mb-4 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm font-semibold text-red-700'
        : 'mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm font-semibold text-emerald-700';
    el.classList.remove('hidden');
}

loadCompanies();
</script>
</body>
</html>

