<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';
Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: index.php');
    exit;
}
$userApps = AuthService::allAppsWithEnrollment((int)$user['id']);
$fullName = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
$initial  = strtoupper(substr($user['first_name'], 0, 1));
$email    = htmlspecialchars($user['email']);
$requestedCompanyUuid = trim($_GET['company_uuid'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Companies — Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>
        [data-lucide] { display: inline-block; }
        .modal-backdrop { background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); }

        /* ── Light theme ─────────────────────────────────────────────────── */
        body.light { background-color: #f1f5f9 !important; color: #0f172a; }

        body.light header { background-color: #ffffff !important; border-bottom-color: #e2e8f0 !important; }

        body.light .bg-\[\#0d1117\],
        body.light .bg-\[\#111827\],
        body.light .bg-\[\#0d1420\] { background-color: #ffffff; }
        body.light .bg-\[\#161f2e\] { background-color: #f8fafc; }
        body.light .bg-white\/10,
        body.light .bg-white\/8    { background-color: #f1f5f9; }
        body.light .bg-white\/6    { background-color: #f1f5f9; }
        body.light .bg-white\/4    { background-color: #f8fafc; }
        body.light .bg-slate-700   { background-color: #e2e8f0; }

        body.light .border-white\/10,
        body.light .border-white\/8,
        body.light .border-white\/6 { border-color: #e2e8f0; }
        body.light .divide-white\/6 > :not([hidden]) ~ :not([hidden]) { border-color: #e2e8f0; }

        body.light .text-white      { color: #0f172a; }
        body.light .text-white\/80  { color: #334155; }
        body.light .text-white\/60  { color: #64748b; }
        body.light .text-white\/45  { color: #64748b; }
        body.light .text-white\/40  { color: #94a3b8; }
        body.light .text-white\/35  { color: #94a3b8; }
        body.light .text-white\/30  { color: #94a3b8; }
        body.light .text-white\/25  { color: #cbd5e1; }
        body.light .text-white\/20  { color: #cbd5e1; }

        /* Role badge text — readable on light backgrounds */
        body.light .text-purple-300 { color: #7e22ce; }
        body.light .text-blue-300   { color: #1d4ed8; }
        body.light .text-slate-300  { color: #475569; }

        /* Preserve white text on solid coloured buttons */
        body.light .bg-blue-600.text-white,
        body.light .bg-blue-500.text-white { color: #ffffff; }
        body.light .text-white\/60           { color: #475569; }
        body.light .hover\:bg-white\/8:hover { background-color: #f1f5f9; }

        body.light .hover\:bg-\[\#161f2e\]:hover  { background-color: #f1f5f9; }
        body.light .hover\:border-white\/20:hover { border-color: #cbd5e1; }
        body.light .hover\:bg-white\/15:hover     { background-color: #e2e8f0; }
        body.light .hover\:text-white\/80:hover   { color: #334155; }

        body.light input,
        body.light select { background-color: #f8fafc; border-color: #e2e8f0; color: #0f172a; }
        body.light input::placeholder { color: #94a3b8; }
        body.light input:focus,
        body.light select:focus { background-color: #f1f5f9; border-color: #3b82f6; }
        body.light .focus\:bg-white\/8:focus { background-color: #f1f5f9; }

        body.light .modal-backdrop { background: rgba(0,0,0,0.4); }
    </style>
</head>
<body class="min-h-screen bg-[#0d1117] font-sans antialiased text-white">
<script>var _ct=localStorage.getItem('centrikyTheme');if(_ct==='light'){document.body.classList.add('light');}if(_ct==='dark'){document.body.classList.add('dark');}</script>

<!-- Top accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>

<!-- Header -->
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white shadow-sm">
    <div class="mx-auto flex max-w-5xl items-center gap-3 px-6 py-3">

        <!-- Logo -->
        <a href="index.php" class="flex shrink-0 items-center hover:opacity-80 transition-opacity">
            <img src="../centryk_logo.png" alt="Centryk" class="h-12 w-auto">
        </a>

        <!-- Divider -->
        <div class="h-5 w-px bg-slate-200 shrink-0"></div>

        <!-- Company selector -->
        <div class="relative flex-1 max-w-xs" id="companyPickerWrapper">
            <button id="companyPickerBtn"
                class="flex w-full items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm font-semibold text-slate-800 transition hover:bg-white hover:border-slate-300 focus:outline-none">
                <i data-lucide="building-2" class="h-4 w-4 shrink-0 text-slate-400"></i>
                <span id="companyPickerLabel" class="flex-1 truncate text-slate-400">Loading…</span>
                <i data-lucide="chevrons-up-down" class="h-3.5 w-3.5 shrink-0 text-slate-400"></i>
            </button>
            <div id="companyDropdown" class="absolute left-0 top-full mt-1.5 hidden w-72 rounded-2xl border border-slate-200 bg-white shadow-xl z-50 overflow-hidden">
                <div class="px-3 py-2 border-b border-slate-100">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Your Companies</p>
                </div>
                <div id="companyDropdownList" class="max-h-64 overflow-y-auto py-1.5"></div>
            </div>
        </div>

        <!-- Manage Companies — active state since we're already on this page -->
        <span class="shrink-0 flex items-center gap-1.5 rounded-xl border border-blue-200 bg-blue-50 px-2.5 py-2 text-xs font-black uppercase tracking-[0.12em] text-blue-600 select-none">
            <i data-lucide="settings-2" class="h-3.5 w-3.5"></i>
            <span class="hidden sm:inline">Manage Companies</span>
        </span>

        <!-- App switcher (waffle dots) -->
        <div class="relative shrink-0" id="appSwitcherWrapper">
            <button id="appSwitcherBtn" title="Launch app"
                class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-500 transition hover:bg-white hover:border-slate-300 hover:text-slate-700">
                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                    <circle cx="4"  cy="4"  r="1.5"/><circle cx="10" cy="4"  r="1.5"/><circle cx="16" cy="4"  r="1.5"/>
                    <circle cx="4"  cy="10" r="1.5"/><circle cx="10" cy="10" r="1.5"/><circle cx="16" cy="10" r="1.5"/>
                    <circle cx="4"  cy="16" r="1.5"/><circle cx="10" cy="16" r="1.5"/><circle cx="16" cy="16" r="1.5"/>
                </svg>
            </button>
            <div id="appSwitcherPanel" class="absolute right-0 top-full mt-1.5 hidden w-56 rounded-2xl border border-slate-200 bg-white shadow-xl z-50 overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-100">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Switch to</p>
                </div>
                <div class="p-2 space-y-1">
                    <?php foreach ($userApps as $app): $enrolled = !empty($app['enrolled']); ?>
                    <button class="switcher-app-btn w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left transition <?= $enrolled ? 'hover:bg-slate-50 text-slate-700' : 'opacity-40 cursor-not-allowed' ?>"
                            data-app-key="<?= htmlspecialchars($app['key']) ?>"
                            <?= $enrolled ? '' : 'disabled' ?>>
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-white text-xs font-black"
                              style="background:<?= htmlspecialchars($app['color']) ?>">
                            <?= strtoupper(substr($app['label'], 0, 1)) ?>
                        </span>
                        <span class="text-sm font-bold"><?= htmlspecialchars($app['label']) ?></span>
                        <?php if (!$enrolled): ?>
                        <span class="ml-auto text-[9px] font-black uppercase tracking-wide text-slate-300">No access</span>
                        <?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Spacer -->
        <div class="flex-1"></div>

        <!-- Admin links -->
        <?php if (!empty($user['is_admin'])): ?>
        <a href="requests.php" class="hidden sm:flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
            <i data-lucide="users" class="h-3.5 w-3.5"></i>
            New Users
        </a>
        <a href="audit.php" class="hidden sm:flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
            <i data-lucide="history" class="h-3.5 w-3.5"></i>
            Audit Trail
        </a>
        <?php endif; ?>

        <!-- User dropdown -->
        <div class="relative" id="userMenuWrapper">
            <button id="userMenuBtn" class="flex items-center gap-2.5 rounded-xl px-3 py-2 transition hover:bg-slate-100">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[12px] font-black text-slate-700">
                    <?= $initial ?>
                </div>
                <div class="text-left hidden sm:block">
                    <p class="text-sm font-semibold text-slate-800 leading-tight"><?= $fullName ?></p>
                    <p class="text-[10px] text-slate-400 leading-tight"><?= $email ?></p>
                </div>
                <i data-lucide="chevron-down" class="h-3.5 w-3.5 text-slate-400 shrink-0"></i>
            </button>

            <div id="userMenu" class="absolute right-0 top-full mt-2 w-60 hidden rounded-2xl border border-slate-200 bg-white shadow-xl z-50 overflow-hidden">
                <div class="px-4 py-3.5 border-b border-slate-100">
                    <p class="text-sm font-bold text-slate-900 leading-tight"><?= $fullName ?></p>
                    <p class="text-xs text-slate-400 mt-0.5"><?= $email ?></p>
                </div>
                <div class="p-2 space-y-0.5">
                    <button id="themeToggle" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition text-left">
                        <i data-lucide="sun"  id="themeIconSun"  class="h-4 w-4 shrink-0"></i>
                        <i data-lucide="moon" id="themeIconMoon" class="h-4 w-4 shrink-0 hidden"></i>
                        <span id="themeLabel">Light mode</span>
                    </button>
                    <a href="profile.php" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="user-cog" class="h-4 w-4 shrink-0"></i>
                        Manage Profile
                    </a>
                    <button id="logoutBtn" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 transition text-left">
                        <i data-lucide="log-out" class="h-4 w-4 shrink-0"></i>
                        Sign out
                    </button>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Company List View -->
<div id="listView" class="mx-auto max-w-5xl px-6 py-10">
    <div class="mb-2">
        <a href="index.php" class="inline-flex items-center gap-1.5 text-xs font-black uppercase tracking-[0.12em] text-white/35 transition hover:text-white/80">
            <i data-lucide="arrow-left" class="h-3.5 w-3.5"></i>
            Back to Dashboard
        </a>
    </div>
    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black tracking-tight text-white">Companies</h1>
            <p class="mt-1 text-sm font-semibold text-white/40">Manage your companies and their members across all apps.</p>
        </div>
        <button id="newCompanyBtn"
            class="flex items-center gap-2 rounded-xl bg-white/10 px-4 py-2.5 text-sm font-black text-white transition hover:bg-white/15">
            <i data-lucide="plus" class="h-4 w-4"></i>
            New Company
        </button>
    </div>

    <!-- Companies grid -->
    <div id="companiesGrid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"></div>

    <!-- Empty state -->
    <div id="emptyState" class="hidden rounded-2xl border border-white/8 bg-white/4 p-14 text-center">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-white/8 text-white/30">
            <i data-lucide="building-2" class="h-6 w-6"></i>
        </div>
        <p class="text-sm font-bold text-white/40">No companies yet.</p>
        <p class="mt-1 text-xs text-white/25">Create a company to start adding employees.</p>
    </div>
</div>

<!-- Company Detail View (hidden initially) -->
<div id="detailView" class="hidden mx-auto max-w-5xl px-6 py-10">
    <div class="mb-6 flex items-center gap-3">
        <button id="backBtn" class="flex items-center gap-1.5 text-xs font-black uppercase tracking-[0.12em] text-white/35 transition hover:text-white/80">
            <i data-lucide="arrow-left" class="h-3.5 w-3.5"></i>
            Back
        </button>
    </div>

    <div class="overflow-hidden rounded-[24px] border border-white/10 bg-[#111827]">
        <!-- Detail header -->
        <div class="flex items-center justify-between border-b border-white/8 px-6 py-5">
            <div>
                <h2 id="detailName" class="text-xl font-black tracking-tight text-white"></h2>
                <div class="mt-1 flex items-center gap-2">
                    <span id="detailRoleBadge" class="rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-[0.12em]"></span>
                    <span id="detailMemberCount" class="text-xs text-white/40"></span>
                </div>
            </div>
            <button id="addMemberBtn" class="hidden flex items-center gap-2 rounded-xl bg-white/10 px-4 py-2.5 text-sm font-black text-white transition hover:bg-white/15">
                <i data-lucide="user-plus" class="h-4 w-4"></i>
                Add Member
            </button>
        </div>

        <!-- Members list -->
        <div id="membersList" class="divide-y divide-white/6"></div>

        <!-- Members loading -->
        <div id="membersLoading" class="p-10 text-center text-sm text-white/30">
            Loading members...
        </div>
    </div>
</div>

<!-- ─── New Company Modal ─── -->
<div id="newCompanyModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="w-full max-w-md rounded-[20px] border border-white/10 bg-[#111827] p-6 shadow-2xl">
        <div class="mb-5 flex items-center justify-between">
            <h3 class="text-lg font-black text-white">New Company</h3>
            <button class="modal-close text-white/30 transition hover:text-white/70">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>
        <div id="newCompanyError" class="mb-3 hidden rounded-xl bg-red-500/15 px-4 py-2.5 text-sm font-semibold text-red-400"></div>
        <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Company Name</label>
        <input id="newCompanyName" type="text" placeholder="e.g. BHI Limited"
            class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
        <div class="mt-5 flex gap-3 justify-end">
            <button class="modal-close rounded-xl px-4 py-2.5 text-sm font-bold text-white/40 transition hover:text-white/70">Cancel</button>
            <button id="createCompanyBtn" class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-black text-white transition hover:bg-blue-500">
                Create Company
            </button>
        </div>
    </div>
</div>

<!-- ─── Remove Member Modal ─── -->
<div id="removeMemberModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="w-full max-w-sm rounded-[20px] border border-white/10 bg-[#111827] p-6 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-lg font-black text-white">Remove Member</h3>
            <button class="modal-close text-white/30 transition hover:text-white/70">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>
        <p class="mb-5 text-sm font-semibold text-white/60">
            Remove <span id="removeMemberName" class="font-black text-white"></span> from this company?
            Their Centryk account will remain active unless they have no other memberships.
        </p>
        <!-- Revoke app access toggle -->
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/8 bg-white/4 px-4 py-3 transition hover:bg-white/6">
            <div class="mt-0.5 shrink-0">
                <input id="revokeAppAccessToggle" type="checkbox" class="h-4 w-4 accent-red-500 cursor-pointer">
            </div>
            <div>
                <p class="text-sm font-black text-white">Also revoke app access</p>
                <p class="mt-0.5 text-xs font-semibold text-white/40">Deactivates their OnePay store memberships and removes their MyPay company assignment.</p>
            </div>
        </label>
        <div class="mt-5 flex gap-3 justify-end">
            <button class="modal-close rounded-xl px-4 py-2.5 text-sm font-bold text-white/40 transition hover:text-white/70">Cancel</button>
            <button id="confirmRemoveMemberBtn" class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-black text-white transition hover:bg-red-500">
                Remove
            </button>
        </div>
    </div>
</div>

<!-- ─── Add Member Modal ─── -->
<div id="addMemberModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="w-full max-w-md rounded-[20px] border border-white/10 bg-[#111827] p-6 shadow-2xl">
        <div class="mb-5 flex items-center justify-between">
            <h3 class="text-lg font-black text-white">Add Member</h3>
            <button class="modal-close text-white/30 transition hover:text-white/70">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>
        <div id="addMemberError" class="mb-3 hidden rounded-xl bg-red-500/15 px-4 py-2.5 text-sm font-semibold text-red-400"></div>

        <div class="space-y-3">
            <div>
                <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Email Address</label>
                <input id="inviteEmail" type="email" placeholder="employee@example.com"
                    class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
            </div>

            <p class="text-[11px] text-white/30 font-semibold">If this person doesn't have a Centryk account yet, fill in their details below.</p>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">First Name</label>
                    <input id="inviteFirstName" type="text" placeholder="John"
                        class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
                </div>
                <div>
                    <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Last Name</label>
                    <input id="inviteLastName" type="text" placeholder="Doe"
                        class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
                </div>
            </div>

            <div>
                <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Temp Password <span class="text-white/20">(new accounts only)</span></label>
                <input id="invitePassword" type="password" placeholder="Set a temporary password"
                    class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
            </div>

            <div>
                <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Role</label>
                <select id="inviteRole"
                    class="w-full rounded-xl border border-white/10 bg-[#111827] px-4 py-3 text-sm font-semibold text-white outline-none focus:border-blue-500/50">
                    <option value="employee">Employee</option>
                    <option value="manager">Manager</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
        </div>

        <div class="mt-5 flex gap-3 justify-end">
            <button class="modal-close rounded-xl px-4 py-2.5 text-sm font-bold text-white/40 transition hover:text-white/70">Cancel</button>
            <button id="inviteSubmitBtn" class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-black text-white transition hover:bg-blue-500">
                Add Member
            </button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
(function () {
    if (window.lucide) { lucide.createIcons(); }

    var currentCompany  = null;
    var pendingRemove   = { companyId: null, userId: null };
    var selectedUuid    = null;
    var requestedCompanyUuid = <?= json_encode($requestedCompanyUuid, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var membersRequestSeq = 0;

    // ── User menu ─────────────────────────────────────────────────────────────
    var userMenuBtn = document.getElementById('userMenuBtn');
    var userMenu    = document.getElementById('userMenu');

    userMenuBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        userMenu.classList.toggle('hidden');
        if (window.lucide) { lucide.createIcons(); }
    });

    document.addEventListener('click', function () {
        userMenu.classList.add('hidden');
        document.getElementById('companyDropdown').classList.add('hidden');
        document.getElementById('appSwitcherPanel').classList.add('hidden');
    });

    // ── Header company picker ─────────────────────────────────────────────────
    document.getElementById('companyPickerBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        document.getElementById('companyDropdown').classList.toggle('hidden');
        document.getElementById('appSwitcherPanel').classList.add('hidden');
    });

    // ── App switcher (waffle) ─────────────────────────────────────────────────
    document.getElementById('appSwitcherBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        document.getElementById('appSwitcherPanel').classList.toggle('hidden');
        document.getElementById('companyDropdown').classList.add('hidden');
    });

    document.querySelectorAll('.switcher-app-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            document.getElementById('appSwitcherPanel').classList.add('hidden');
            if (!selectedUuid) {
                showToast('Select a company from the dropdown first.', 'error');
                document.getElementById('companyDropdown').classList.remove('hidden');
                return;
            }
            window.location.href = 'switch.php?app=' + encodeURIComponent(btn.dataset.appKey) + '&company_uuid=' + encodeURIComponent(selectedUuid);
        });
    });

    // ── Logout ────────────────────────────────────────────────────────────────
    document.getElementById('logoutBtn').addEventListener('click', function () {
        fetch('api/auth/logout.php', { method: 'POST' }).then(function () {
            window.location.href = 'index.php';
        });
    });

    // ── Theme ─────────────────────────────────────────────────────────────────
    function applyThemeUI() {
        var isLight = document.body.classList.contains('light');
        document.getElementById('themeIconSun').classList.toggle('hidden', isLight);
        document.getElementById('themeIconMoon').classList.toggle('hidden', !isLight);
        document.getElementById('themeLabel').textContent = isLight ? 'Dark mode' : 'Light mode';
    }

    document.getElementById('themeToggle').addEventListener('click', function (e) {
        e.stopPropagation();
        var isLight = document.body.classList.toggle('light');
        localStorage.setItem('centrikyTheme', isLight ? 'light' : 'dark');
        applyThemeUI();
        loadCompanies();
        if (currentCompany) { loadMembers(currentCompany.id); }
        if (window.lucide) { lucide.createIcons(); }
    });

    applyThemeUI();

    // ── View helpers ──────────────────────────────────────────────────────────
    function showListView() {
        document.getElementById('listView').classList.remove('hidden');
        document.getElementById('detailView').classList.add('hidden');
        currentCompany = null;
    }

    function showDetailView(company) {
        if (currentCompany && String(currentCompany.id) === String(company.id)) {
            return;
        }
        currentCompany = company;
        document.getElementById('listView').classList.add('hidden');
        document.getElementById('detailView').classList.remove('hidden');
        document.getElementById('detailName').textContent = company.name;

        var badge = document.getElementById('detailRoleBadge');
        badge.textContent = company.role.charAt(0).toUpperCase() + company.role.slice(1);
        var colors = { admin: 'bg-purple-500/20 text-purple-300', manager: 'bg-blue-500/20 text-blue-300', employee: 'bg-slate-500/20 text-slate-300' };
        badge.className = 'rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-[0.12em] ' + (colors[company.role] || colors.employee);

        if (company.role === 'admin') {
            document.getElementById('addMemberBtn').classList.remove('hidden');
        } else {
            document.getElementById('addMemberBtn').classList.add('hidden');
        }

        loadMembers(company.id);
        if (window.lucide) { lucide.createIcons(); }
    }

    // ── Modal helpers ─────────────────────────────────────────────────────────
    function openModal(id) {
        var m = document.getElementById(id);
        m.classList.remove('hidden');
        m.classList.add('flex');
    }

    function closeModal(id) {
        var m = document.getElementById(id);
        m.classList.add('hidden');
        m.classList.remove('flex');
    }

    document.querySelectorAll('.modal-close').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[id$="Modal"]').forEach(function (m) {
                m.classList.add('hidden');
                m.classList.remove('flex');
            });
        });
    });

    // ── Header company picker ─────────────────────────────────────────────────
    function buildHeaderPicker(list) {
        var pickerLabel  = document.getElementById('companyPickerLabel');
        var dropdownList = document.getElementById('companyDropdownList');
        var roleColors   = { admin: '#7c3aed', manager: '#2563eb', employee: '#475569' };

        if (!list || !list.length) {
            pickerLabel.textContent = 'No companies';
            dropdownList.innerHTML  = '<p class="px-4 py-3 text-xs text-slate-400">No companies found.</p>';
            return;
        }

        dropdownList.innerHTML = list.map(function (c) {
            var color   = roleColors[c.role] || roleColors.employee;
            var initial = (c.name || '?').charAt(0).toUpperCase();
            return '<button class="hdr-co-opt w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-slate-50 transition"' +
                ' data-id="' + c.id + '" data-uuid="' + escHtml(c.uuid || '') + '" data-name="' + escHtml(c.name) + '">' +
                '<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-black" style="background:' + color + '18;color:' + color + '">' + escHtml(initial) + '</span>' +
                '<div class="min-w-0"><div class="text-sm font-bold text-slate-800 truncate">' + escHtml(c.name) + '</div>' +
                '<div class="text-[10px] font-semibold capitalize" style="color:' + color + '">' + escHtml(c.role) + '</div></div>' +
                '</button>';
        }).join('');

        dropdownList.querySelectorAll('.hdr-co-opt').forEach(function (btn, i) {
            btn.addEventListener('click', function () {
                selectedUuid = btn.dataset.uuid || null;
                pickerLabel.textContent = btn.dataset.name;
                pickerLabel.classList.remove('text-slate-400');
                pickerLabel.classList.add('text-slate-800');
                document.getElementById('companyDropdown').classList.add('hidden');
                showDetailView(list[i]);
            });
        });

        if (list.length === 1) {
            selectedUuid = list[0].uuid || null;
            pickerLabel.textContent = list[0].name;
            pickerLabel.classList.remove('text-slate-400');
            pickerLabel.classList.add('text-slate-800');
            showDetailView(list[0]);
        } else {
            pickerLabel.textContent = 'Select a company…';
        }
        if (window.lucide) { lucide.createIcons(); }
    }

    // ── Load companies ────────────────────────────────────────────────────────
    function loadCompanies() {
        fetch('api/companies/list.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var grid  = document.getElementById('companiesGrid');
                var empty = document.getElementById('emptyState');
                grid.innerHTML = '';
                buildHeaderPicker((data.success && data.companies) ? data.companies : []);
                if (!data.success || !data.companies || data.companies.length === 0) {
                    empty.classList.remove('hidden');
                    return;
                }
                empty.classList.add('hidden');
                var matchedCompany = null;
                data.companies.forEach(function (c) {
                    var card = document.createElement('button');
                    card.className = 'group flex flex-col rounded-2xl border border-white/10 bg-[#111827] p-5 text-left transition hover:border-white/20 hover:bg-[#161f2e] active:scale-[0.98]';
                    var roleColors = { admin: '#7c3aed', manager: '#2563eb', employee: '#475569' };
                    var color = roleColors[c.role] || roleColors.employee;
                    card.innerHTML = '<div class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl text-white" style="background:' + color + '22">' +
                        '<i data-lucide="building-2" class="h-5 w-5" style="color:' + color + '"></i></div>' +
                        '<div class="text-base font-black tracking-tight text-white">' + escHtml(c.name) + '</div>' +
                        '<div class="mt-1 flex items-center gap-2">' +
                        '<span class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em]" style="background:' + color + '22;color:' + color + '">' + escHtml(c.role) + '</span>' +
                        '<span class="text-xs text-white/30">' + c.member_count + ' member' + (c.member_count == 1 ? '' : 's') + '</span>' +
                        '</div>';
                    card.addEventListener('click', function () { showDetailView(c); });
                    grid.appendChild(card);
                    if (requestedCompanyUuid && c.uuid === requestedCompanyUuid) {
                        matchedCompany = c;
                    }
                });
                if (matchedCompany) {
                    selectedUuid = matchedCompany.uuid || null;
                    document.getElementById('companyPickerLabel').textContent = matchedCompany.name;
                    document.getElementById('companyPickerLabel').classList.remove('text-slate-400');
                    document.getElementById('companyPickerLabel').classList.add('text-slate-800');
                    requestedCompanyUuid = null;
                    if (!currentCompany || String(currentCompany.id) !== String(matchedCompany.id)) {
                        showDetailView(matchedCompany);
                    }
                }
                if (window.lucide) { lucide.createIcons(); }
            });
    }

    // ── Load members ──────────────────────────────────────────────────────────
    function loadMembers(companyId) {
        var list    = document.getElementById('membersList');
        var loading = document.getElementById('membersLoading');
        var requestId = ++membersRequestSeq;
        list.innerHTML = '';
        loading.classList.remove('hidden');

        fetch('api/companies/members.php?company_id=' + companyId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (requestId !== membersRequestSeq || !currentCompany || String(currentCompany.id) !== String(companyId)) {
                    return;
                }
                loading.classList.add('hidden');
                if (!data.success) { return; }
                document.getElementById('detailMemberCount').textContent = data.members.length + ' member' + (data.members.length === 1 ? '' : 's');

                data.members.forEach(function (m) {
                    var row = document.createElement('div');
                    row.className = 'flex items-center justify-between gap-4 px-6 py-4';
                    var initial = (m.first_name || '?').charAt(0).toUpperCase();
                    var roleColors = { admin: 'bg-purple-500/20 text-purple-300', manager: 'bg-blue-500/20 text-blue-300', employee: 'bg-slate-500/20 text-slate-300' };
                    var roleClass = roleColors[m.role] || roleColors.employee;
                    var isMe = (m.id == <?= (int)$user['id'] ?>);
                    var isAdmin = (currentCompany && currentCompany.role === 'admin');

                    // App access pills
                    var appsHtml = '';
                    if ((isAdmin || isMe) && m.apps && m.apps.length) {
                        appsHtml = '<div class="flex items-center gap-1.5">';
                        m.apps.forEach(function (app) {
                            var enrolledCls = app.enrolled
                                ? 'border-emerald-500/40 bg-emerald-500/15 text-emerald-400'
                                : 'border-white/10 bg-white/5 text-white/25';
                            var checkIcon = app.enrolled
                                ? '<svg class="h-2.5 w-2.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>'
                                : '<svg class="h-2.5 w-2.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>';
                            var clickable = isAdmin && !isMe;
                            appsHtml += '<button class="app-access-pill flex items-center gap-1 rounded-full border px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.08em] transition'
                                + (clickable ? ' cursor-pointer hover:opacity-70' : ' cursor-default')
                                + ' ' + enrolledCls + '"'
                                + ' data-uid="' + m.id + '"'
                                + ' data-app="' + escHtml(app.key) + '"'
                                + ' data-enrolled="' + (app.enrolled ? '1' : '0') + '"'
                                + (clickable ? '' : ' disabled') + '>'
                                + checkIcon + ' ' + escHtml(app.label)
                                + '</button>';
                        });
                        appsHtml += '</div>';
                    }

                    row.innerHTML = '<div class="flex items-center gap-3 min-w-0">' +
                        '<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-700 text-sm font-black text-white">' + escHtml(initial) + '</div>' +
                        '<div class="min-w-0">' +
                        '<div class="text-sm font-bold text-white truncate">' + escHtml(m.first_name + ' ' + m.last_name) + (isMe ? ' <span class="text-white/30 text-xs">(you)</span>' : '') + '</div>' +
                        '<div class="text-xs text-white/40 truncate">' + escHtml(m.email) + '</div>' +
                        '</div></div>' +
                        '<div class="flex items-center gap-3 shrink-0">' +
                        appsHtml +
                        '<span class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] ' + roleClass + '">' + escHtml(m.role) + '</span>' +
                        (isAdmin && !isMe ? '<button class="remove-member text-white/20 transition hover:text-red-400" data-uid="' + m.id + '" data-name="' + escHtml(m.first_name + ' ' + m.last_name) + '" title="Remove member"><i data-lucide="user-minus" class="h-4 w-4"></i></button>' : '') +
                        '</div>';

                    list.appendChild(row);
                });

                // App access toggle
                list.querySelectorAll('.app-access-pill:not([disabled])').forEach(function (pill) {
                    pill.addEventListener('click', function () {
                        var uid      = parseInt(pill.dataset.uid, 10);
                        var appKey   = pill.dataset.app;
                        var enrolled = pill.dataset.enrolled === '1';
                        var grant    = !enrolled;

                        pill.style.opacity = '0.4';
                        fetch('api/apps/toggle-access.php', {
                            method:  'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body:    JSON.stringify({ user_id: uid, app_key: appKey, grant: grant }),
                        })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            pill.style.opacity = '';
                            if (data.success) {
                                // Reload the member list to reflect the change
                                loadMembers(companyId);
                            } else {
                                showToast(data.message || 'Could not update access.', 'error');
                            }
                        })
                        .catch(function () {
                            pill.style.opacity = '';
                            showToast('Network error.', 'error');
                        });
                    });
                });

                list.querySelectorAll('.remove-member').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        pendingRemove.companyId = companyId;
                        pendingRemove.userId    = parseInt(btn.dataset.uid, 10);
                        document.getElementById('removeMemberName').textContent = btn.dataset.name || 'this member';
                        document.getElementById('revokeAppAccessToggle').checked = false;
                        openModal('removeMemberModal');
                    });
                });

                if (window.lucide) { lucide.createIcons(); }
            })
            .catch(function () {
                if (requestId !== membersRequestSeq) {
                    return;
                }
                loading.classList.add('hidden');
                list.innerHTML = '<div class="px-6 py-6 text-sm font-semibold text-red-400">Could not load members.</div>';
            });
    }

    // ── Create company ────────────────────────────────────────────────────────
    document.getElementById('newCompanyBtn').addEventListener('click', function () {
        document.getElementById('newCompanyName').value = '';
        document.getElementById('newCompanyError').classList.add('hidden');
        openModal('newCompanyModal');
    });

    document.getElementById('createCompanyBtn').addEventListener('click', function () {
        var name = document.getElementById('newCompanyName').value.trim();
        var errEl = document.getElementById('newCompanyError');
        if (!name) { errEl.textContent = 'Company name is required.'; errEl.classList.remove('hidden'); return; }

        var btn = document.getElementById('createCompanyBtn');
        btn.textContent = 'Creating...';
        btn.disabled = true;

        fetch('api/companies/create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: name })
        }).then(function (r) { return r.json(); })
        .then(function (data) {
            btn.textContent = 'Create Company';
            btn.disabled = false;
            if (!data.success) { errEl.textContent = data.message; errEl.classList.remove('hidden'); return; }
            closeModal('newCompanyModal');
            loadCompanies();
        });
    });

    // ── Add member ────────────────────────────────────────────────────────────
    document.getElementById('addMemberBtn').addEventListener('click', function () {
        ['inviteEmail','inviteFirstName','inviteLastName','invitePassword'].forEach(function (id) {
            document.getElementById(id).value = '';
        });
        document.getElementById('inviteRole').value = 'employee';
        document.getElementById('addMemberError').classList.add('hidden');
        openModal('addMemberModal');
    });

    document.getElementById('inviteSubmitBtn').addEventListener('click', function () {
        var errEl = document.getElementById('addMemberError');
        var payload = {
            company_id: currentCompany.id,
            email:      document.getElementById('inviteEmail').value.trim(),
            first_name: document.getElementById('inviteFirstName').value.trim(),
            last_name:  document.getElementById('inviteLastName').value.trim(),
            password:   document.getElementById('invitePassword').value,
            role:       document.getElementById('inviteRole').value
        };

        if (!payload.email) { errEl.textContent = 'Email is required.'; errEl.classList.remove('hidden'); return; }

        var btn = document.getElementById('inviteSubmitBtn');
        btn.textContent = 'Adding...';
        btn.disabled = true;

        fetch('api/companies/invite.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); })
        .then(function (data) {
            btn.textContent = 'Add Member';
            btn.disabled = false;
            if (!data.success) { errEl.textContent = data.message; errEl.classList.remove('hidden'); return; }
            closeModal('addMemberModal');
            loadMembers(currentCompany.id);
        });
    });

    // ── Remove member ─────────────────────────────────────────────────────────
    document.getElementById('confirmRemoveMemberBtn').addEventListener('click', function () {
        var btn    = this;
        var cid    = pendingRemove.companyId;
        var uid    = pendingRemove.userId;
        var revoke = document.getElementById('revokeAppAccessToggle').checked;
        if (!cid || !uid) { return; }
        btn.textContent = 'Removing...';
        btn.disabled = true;
        fetch('api/companies/remove-member.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ company_id: cid, user_id: uid, revoke_app_access: revoke })
        }).then(function (r) { return r.json(); })
        .then(function (data) {
            btn.textContent = 'Remove';
            btn.disabled = false;
            closeModal('removeMemberModal');
            if (!data.success) { alert(data.message); return; }
            loadMembers(cid);
        });
    });

    // ── Back button ───────────────────────────────────────────────────────────
    document.getElementById('backBtn').addEventListener('click', function () {
        showListView();
        loadCompanies();
    });

    // ── Escape key closes modals ──────────────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('[id$="Modal"]').forEach(function (m) {
                m.classList.add('hidden');
                m.classList.remove('flex');
            });
        }
    });

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function showToast(msg, type) {
        var wrap  = document.getElementById('toastWrap');
        var toast = document.createElement('div');
        var isErr = type === 'error';
        toast.className = 'pointer-events-auto flex items-center gap-2.5 rounded-xl px-4 py-3 text-sm font-semibold shadow-lg '
            + (isErr ? 'bg-rose-600 text-white' : 'bg-slate-900 text-white');
        toast.innerHTML = (isErr
            ? '<svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>'
            : '<svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>')
            + '<span>' + escHtml(msg) + '</span>';
        wrap.appendChild(toast);
        setTimeout(function () { toast.style.opacity = '0'; setTimeout(function () { toast.remove(); }, 300); }, 3500);
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    loadCompanies();
})();
</script>
<div id="toastWrap" class="pointer-events-none fixed bottom-6 left-1/2 z-[60] flex -translate-x-1/2 flex-col items-center gap-2"></div>
</body>
</html>
