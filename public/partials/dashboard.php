<?php
// Authenticated dashboard page — included by index.php when a user is logged in.
// Expects in scope: $user, $apps, $showOnboarding, $hasDefaultPassword
if (!isset($user) || !isset($apps)) { header('Location: ../index.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Centryk — Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } }
        }
    </script>
    <style>
        @keyframes spin { to { transform: rotate(360deg); } }
        .animate-spin  { animation: spin 1s linear infinite; }
        [data-lucide]  { display: inline-block; }

        @keyframes dash-fade-up {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .dash-fade {
            opacity: 0;
            animation: dash-fade-up 0.55s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: calc(var(--i, 0) * 70ms + 100ms);
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased">

<!-- ── Dashboard (authenticated) ── -->
<div class="min-h-screen bg-slate-100">

<!-- Top accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500 sticky top-0 z-50"></div>

<!-- Header -->
<header class="sticky top-[3px] z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-3">

        <!-- Logo -->
        <a href="index.php" class="flex shrink-0 items-center">
            <img src="../centryk_logo.png" alt="Centryk" class="h-14 w-auto">
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

            <!-- Dropdown -->
            <div id="companyDropdown" class="absolute left-0 top-full mt-1.5 hidden w-72 rounded-2xl border border-slate-200 bg-white shadow-xl z-50 overflow-hidden">
                <div class="px-3 py-2 border-b border-slate-100">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Your Companies</p>
                </div>
                <div id="companyDropdownList" class="max-h-64 overflow-y-auto py-1.5"></div>
                <div class="border-t border-slate-100">
                    <button id="btnCreateCompanyFromDropdown" class="w-full flex items-center gap-2.5 px-3 py-2.5 text-left transition hover:bg-indigo-50 group">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 group-hover:bg-indigo-100 transition">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        </span>
                        <span class="text-sm font-bold text-indigo-600 group-hover:text-indigo-700">Create a new Company Profile</span>
                    </button>
                    <a href="profile.php#companies" class="flex items-center justify-between px-3 py-2 text-[10px] font-bold text-slate-400 hover:text-slate-600 transition border-t border-slate-50">
                        <span>Manage all companies</span>
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>
            </div>
        </div>

        <!-- Spacer -->
        <div class="flex-1"></div>

        <!-- Admin tools -->
        <?php include __DIR__ . '/admin_tools_dropdown.php'; ?>

        <!-- Waffle app switcher -->
        <?php $awAlign = 'right'; $awMode = 'launch'; include __DIR__ . '/app_switcher.php'; ?>

        <!-- Divider -->
        <div class="h-5 w-px bg-slate-200 shrink-0"></div>

        <!-- User dropdown -->
        <div class="relative shrink-0" id="userMenuWrapper">
            <button id="userMenuBtn" class="flex items-center gap-2.5 rounded-xl px-3 py-2 transition hover:bg-slate-100">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[12px] font-black text-slate-700">
                    <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
                </div>
                <div class="hidden text-left sm:block">
                    <p class="text-sm font-semibold text-slate-800 leading-tight"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></p>
                    <p class="text-[10px] text-slate-400 leading-tight"><?= htmlspecialchars($user['email']) ?></p>
                </div>
                <i data-lucide="chevron-down" class="h-3.5 w-3.5 text-slate-400 shrink-0"></i>
            </button>

            <div id="userMenu" class="absolute right-0 top-full mt-2 w-60 hidden rounded-2xl border border-slate-200 bg-white shadow-xl z-50 overflow-hidden">
                <div class="px-4 py-3.5 border-b border-slate-100">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-bold text-slate-900 leading-tight truncate"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></p>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.1em] <?= !empty($user['is_admin']) ? 'bg-violet-100 text-violet-600' : 'bg-slate-100 text-slate-500' ?>">
                            <?= !empty($user['is_admin']) ? 'Admin' : 'Member' ?>
                        </span>
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5 truncate"><?= htmlspecialchars($user['email']) ?></p>
                </div>
                <div class="p-2 space-y-0.5">
                    <a href="profile.php" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="user-cog" class="h-4 w-4 shrink-0"></i>
                        Manage your Centryk Account
                    </a>
                    <?php if (!empty($user['is_admin'])): ?>
                    <a href="profile.php#companies" class="flex sm:hidden items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="building-2" class="h-4 w-4 shrink-0"></i>
                        Companies
                    </a>
                    <a href="requests.php" class="flex sm:hidden items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="users" class="h-4 w-4 shrink-0"></i>
                        New Users
                    </a>
                    <a href="registered-companies.php" class="flex sm:hidden items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="building-2" class="h-4 w-4 shrink-0"></i>
                        Registered Companies
                    </a>
                    <a href="onelink-api-accounts.php" class="flex sm:hidden items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="credit-card" class="h-4 w-4 shrink-0"></i>
                        OneLink API Accounts
                    </a>
                    <a href="audit.php" class="flex sm:hidden items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="history" class="h-4 w-4 shrink-0"></i>
                        Audit Trail
                    </a>
                    <?php endif; ?>
                    <button id="logoutBtn" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 transition text-left">
                        <i data-lucide="log-out" class="h-4 w-4 shrink-0"></i>
                        Sign out
                    </button>
                </div>
            </div>
        </div>

    </div>
</header>

<!-- Main -->
<main class="mx-auto max-w-6xl px-6 py-10">

    <!-- Company profile card -->
    <div style="--i:0" class="dash-fade mb-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

        <!-- Empty state (no company selected) -->
        <div id="coCardEmpty" class="px-6 py-5">
            <h1 class="text-2xl font-black tracking-tight text-slate-900">
                Welcome back, <?= htmlspecialchars($user['first_name']) ?>
            </h1>
            <p id="companyContext" class="mt-1 text-sm font-semibold text-slate-400">Select a company above, then open an app.</p>
        </div>

        <!-- Filled state (company selected) -->
        <div id="coCardFilled" class="hidden">

            <!-- Main row -->
            <div class="flex flex-wrap items-center gap-4 px-6 py-5">

                <!-- Avatar -->
                <div id="coAvatar" class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl text-xl font-black text-white select-none">?</div>

                <!-- Name + context -->
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span id="coName" class="text-xl font-black tracking-tight text-slate-900 truncate">—</span>
                        <span id="coRoleBadge" class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em]">—</span>
                    </div>
                    <p class="mt-0.5 text-sm font-semibold text-slate-400">
                        Welcome back, <?= htmlspecialchars($user['first_name']) ?>
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <button id="coInviteBtn" type="button"
                       class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-xs font-black text-slate-600 transition hover:bg-slate-50 hover:border-slate-300">
                        <i data-lucide="user-plus" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">Invite Member</span>
                        <span class="rounded-full bg-slate-50 px-1.5 py-0.5 text-[10px] text-slate-500 ring-1 ring-slate-200">
                            <span id="coMemberCount">0</span> members
                        </span>
                    </button>
                    <a id="coMemberLink" href="profile.php#companies"
                       class="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-black text-slate-600 transition hover:bg-white hover:border-slate-300 hover:text-slate-900">
                        <i data-lucide="building-2" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">Manage Company Profile</span>
                    </a>
                    <a id="coOnelinkPaymentsBtn" href="onelink-payments.php"
                       class="flex items-center gap-1.5 rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2 text-xs font-black text-cyan-700 transition hover:bg-cyan-100 hover:border-cyan-300">
                        <i data-lucide="credit-card" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">OneLink Payments</span>
                    </a>
                    <?php if (strcasecmp((string)($user['email'] ?? ''), 'webdevelopment@bhilimited.com') === 0): ?>
                    <a id="coAdvertiseBtn" href="advertise.php"
                       class="flex items-center gap-1.5 rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-black text-violet-700 transition hover:bg-violet-100 hover:border-violet-300">
                        <i data-lucide="share-2" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">Advertise</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Setup progress (hidden once complete) -->
            <div id="setupProgressWrap" class="hidden border-t border-slate-100 px-6 py-3.5">
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Getting Started</p>
                    <p id="setupProgressLabel" class="text-[10px] font-bold text-slate-400">0 of 3 complete</p>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div id="setupProgressBar" class="h-1.5 rounded-full bg-violet-500 transition-all duration-500" style="width:0%"></div>
                </div>
                <div class="mt-2.5 flex flex-wrap items-center gap-x-5 gap-y-1">
                    <span id="setupStep1" class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-400">
                        <span class="h-2 w-2 rounded-full bg-slate-200"></span>Company created
                    </span>
                    <span id="setupStep2" class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-400">
                        <span class="h-2 w-2 rounded-full bg-slate-200"></span>Team added
                    </span>
                    <span id="setupStep3" class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-400">
                        <span class="h-2 w-2 rounded-full bg-slate-200"></span>Apps active
                    </span>
                </div>
            </div>

            <!-- Inline invite form (toggled by coInviteBtn) -->
            <div id="inlineInviteForm" class="hidden border-t border-slate-100 bg-slate-50/60 px-6 py-5">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Add a member</p>
                        <h3 class="mt-0.5 text-sm font-black text-slate-800">Invite someone to this company</h3>
                    </div>
                    <button id="closeInlineInviteBtn" type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-200 hover:text-slate-600">
                        <i data-lucide="x" class="h-4 w-4"></i>
                    </button>
                </div>

                <div id="inviteAlert" class="hidden mb-3 rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600"></div>
                <div id="inviteSuccess" class="hidden mb-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-700"></div>

                <form id="inviteForm" class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">First Name</label>
                        <input id="invFirst" type="text" required placeholder="John" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-violet-500 focus:ring-4 focus:ring-violet-100">
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Last Name</label>
                        <input id="invLast" type="text" required placeholder="Doe" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-violet-500 focus:ring-4 focus:ring-violet-100">
                    </div>
                    <div id="invEmailWrap" class="sm:col-span-2">
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Email Address</label>
                        <input id="invEmail" type="email" placeholder="john@company.com" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-violet-500 focus:ring-4 focus:ring-violet-100">
                        <button type="button" id="invNoEmailToggle" class="mt-1.5 text-[11px] font-bold text-violet-600 transition hover:text-violet-700">
                            No email address? Create with username instead →
                        </button>
                    </div>
                    <div id="invUsernameWrap" class="hidden sm:col-span-2">
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Username</label>
                        <input id="invUsername" type="text" placeholder="john.doe" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-violet-500 focus:ring-4 focus:ring-violet-100">
                        <p class="mt-1 text-[11px] font-semibold text-slate-400">Login: <span id="invUsernamePreview" class="font-mono text-slate-600">username@centryk.com</span></p>
                        <button type="button" id="invBackToEmailBtn" class="mt-1.5 text-[11px] font-bold text-violet-600 transition hover:text-violet-700">
                            ← Use an email instead
                        </button>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Temporary Password</label>
                        <input id="invPassword" type="password" required minlength="8" placeholder="At least 8 characters" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-violet-500 focus:ring-4 focus:ring-violet-100">
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Role</label>
                        <select id="invRole" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-violet-500 focus:ring-4 focus:ring-violet-100">
                            <option value="employee">Employee</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2 mt-1 flex items-center justify-end gap-2">
                        <button type="button" id="cancelInviteBtn" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-black text-slate-600 transition hover:bg-slate-50">Cancel</button>
                        <button type="submit" id="submitInviteBtn" class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-black uppercase tracking-[0.1em] text-white transition hover:bg-slate-700">Add Member</button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <?php
    $_comingSoonAppKeys = ['store' => true, 'visionboard' => true];
    $_enrolledAppCount = 0;
    $_otherAppCount = 0;
    foreach ($apps as $_app) {
        if (isset($_comingSoonAppKeys[(string)($_app['key'] ?? '')])) {
            continue;
        }
        if (!empty($_app['enrolled'])) {
            $_enrolledAppCount++;
        } else {
            $_otherAppCount++;
        }
    }
    ?>

    <div class="mb-4 flex items-end justify-between gap-4">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Launcher</p>
            <h2 class="text-xl font-black tracking-tight text-slate-900">Your apps</h2>
        </div>
        <span class="rounded-full bg-white px-3 py-1 text-[11px] font-black text-slate-500 shadow-sm ring-1 ring-slate-200">
            <?= $_enrolledAppCount ?> active
        </span>
    </div>

    <?php if ($_enrolledAppCount === 0): ?>
    <div class="mb-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 text-sm font-semibold text-slate-500 shadow-sm">
        You are not enrolled in any apps yet. Available apps are listed below.
    </div>
    <?php endif; ?>

    <!-- Apps grid -->
    <div id="appsGrid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php $_appIdx = 0; foreach ($apps as $app):
            if (isset($_comingSoonAppKeys[(string)($app['key'] ?? '')])) {
                continue;
            }
            $_appIdx++;
            $enrolled = !empty($app['enrolled']);
            $optIn    = !empty($app['opt_in']);
        ?>
        <button style="--i:<?= $_appIdx ?>" class="dash-fade app-card group <?= $enrolled ? 'order-1' : 'order-3' ?> flex flex-col overflow-hidden rounded-2xl border text-left shadow-sm transition
                    <?= $enrolled
                        ? 'border-slate-200 bg-white hover:shadow-md hover:-translate-y-0.5 active:scale-[0.98] disabled:opacity-40 disabled:cursor-not-allowed disabled:translate-y-0 disabled:shadow-sm'
                        : ($optIn
                            ? 'border-dashed border-slate-300 bg-white hover:shadow-md hover:-translate-y-0.5 active:scale-[0.98]'
                            : 'border-slate-200/50 bg-slate-50 opacity-50 cursor-not-allowed') ?>"
                data-app="<?= htmlspecialchars($app['key']) ?>"
                data-enrolled="<?= $enrolled ? '1' : '0' ?>"
                data-opt-in="<?= $optIn ? '1' : '0' ?>"
                <?= ($enrolled || $optIn) ? '' : 'disabled' ?>>
            <div class="h-1.5 w-full" style="background:<?= htmlspecialchars($app['color']) . ($enrolled ? '' : ';opacity:.4') ?>"></div>
            <div class="flex flex-1 flex-col p-4">
                <div class="flex items-center gap-3">
                    <?php if ($app['key'] === 'onepay'): ?>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full shadow-sm"
                          style="background:<?= htmlspecialchars($app['color']) ?>">
                        <svg viewBox="0 0 24 24" fill="white" class="h-5 w-5">
                            <path d="M12 2.5c.72 5.08 2.42 6.78 7.5 7.5-5.08.72-6.78 2.42-7.5 7.5-.72-5.08-2.42-6.78-7.5-7.5 5.08-.72 6.78-2.42 7.5-7.5Z"/>
                        </svg>
                    </span>
                    <?php elseif ($app['key'] === 'mypay'): ?>
                    <img src="../myPay.png" alt="MyPay" class="h-10 w-10 rounded-xl object-contain shadow-sm">
                    <?php elseif ($app['key'] === 'invoice'): ?>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100">
                        <svg class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z"/>
                        </svg>
                    </span>
                    <?php elseif ($app['key'] === 'calendar'): ?>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl shadow-sm" style="background:<?= htmlspecialchars($app['color']) ?>">
                        <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                    </span>
                    <?php else: ?>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-xl"
                          style="background:<?= htmlspecialchars($app['color']) ?>18">
                        <?= htmlspecialchars($app['icon'] ?? '') ?>
                    </span>
                    <?php endif; ?>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">
                            <?php
                            if ($app['key'] === 'onepay')      echo 'Inventory &amp; POS';
                            elseif ($app['key'] === 'mypay')   echo 'HR &amp; Payroll';
                            elseif ($app['key'] === 'invoice') echo 'Quotes &amp; Invoicing';
                            else                               echo htmlspecialchars($app['label']);
                            ?>
                        </div>
                        <div class="text-base font-black tracking-tight text-slate-900"><?= htmlspecialchars($app['label']) ?></div>
                    </div>
                </div>
                <p class="mt-3 text-xs font-semibold leading-relaxed text-slate-500">
                    <?= htmlspecialchars($app['description']) ?>
                </p>
                <?php if ($enrolled): ?>
                <div id="app-count-<?= htmlspecialchars($app['key']) ?>" class="app-count-badge mt-3 flex items-center gap-1.5">
                    <span class="app-count-dot inline-block h-1.5 w-1.5 rounded-full bg-slate-300"></span>
                    <span class="app-count-num text-[11px] font-bold text-slate-400">0 active users</span>
                </div>
                <?php elseif ($optIn): ?>
                <div class="mt-3 flex items-center gap-1.5">
                    <span class="inline-block h-1.5 w-1.5 rounded-full" style="background:<?= htmlspecialchars($app['color']) ?>"></span>
                    <span class="text-[11px] font-bold" style="color:<?= htmlspecialchars($app['color']) ?>">Available — tap to enable</span>
                </div>
                <?php else: ?>
                <div class="mt-4 flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-[0.14em] bg-slate-200 text-slate-400">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                    Not enrolled
                </div>
                <?php endif; ?>
            </div>
            <?php if ($enrolled): ?>
            <div class="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs font-bold text-slate-500 transition-colors group-hover:text-slate-800">
                <span>Launch <?= htmlspecialchars($app['label']) ?></span>
                <i data-lucide="arrow-right" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"></i>
            </div>
            <?php elseif ($optIn): ?>
            <div class="flex items-center justify-between border-t border-dashed border-slate-200 px-4 py-3 text-xs font-bold text-slate-600 transition-colors group-hover:text-slate-900">
                <span>Enable <?= htmlspecialchars($app['label']) ?></span>
                <i data-lucide="plus" class="h-3.5 w-3.5"></i>
            </div>
            <?php endif; ?>
        </button>
        <?php endforeach; ?>

        <button type="button" style="--i:<?= ($_appIdx ?? 0) + 1 ?>" id="onelinkPaymentsCard"
                class="dash-fade order-1 group flex flex-col overflow-hidden rounded-2xl border border-cyan-200 bg-white text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md active:scale-[0.98]">
            <div class="h-1.5 w-full bg-cyan-500"></div>
            <div class="flex flex-1 flex-col p-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-cyan-100 text-cyan-700">
                        <i data-lucide="credit-card" class="h-5 w-5"></i>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-600/80">Collections</div>
                        <div class="text-base font-black tracking-tight text-slate-900">OneLink Payments</div>
                    </div>
                </div>
                <p class="mt-3 text-xs font-semibold leading-relaxed text-slate-500">
                    View POS, invoice, and payment-form collections for the selected company.
                </p>
                <div class="mt-3 flex items-center gap-1.5">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-cyan-500"></span>
                    <span class="text-[11px] font-bold text-cyan-700">Company-scoped ledger</span>
                </div>
            </div>
            <div class="flex items-center justify-between border-t border-cyan-100 px-4 py-3 text-xs font-bold text-cyan-700 transition-colors group-hover:text-cyan-900">
                <span>View payments</span>
                <i data-lucide="arrow-right" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"></i>
            </div>
        </button>

        <div style="--i:<?= ($_appIdx ?? 0) + 3 ?>" class="dash-fade order-2 sm:col-span-2 lg:col-span-4 mt-4 border-t border-slate-200 pt-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Access</p>
                    <h2 class="text-lg font-black tracking-tight text-slate-800">Other available apps</h2>
                </div>
                <p class="text-xs font-semibold text-slate-400">Contact an admin for access to apps marked not enrolled.</p>
            </div>
        </div>

        <!-- Store — coming soon (static, not launchable) -->
        <div style="--i:<?= ($_appIdx ?? 0) + 4 ?>" class="dash-fade order-3 flex flex-col overflow-hidden rounded-2xl border border-violet-200/70 bg-violet-50/40 text-left shadow-sm opacity-75 cursor-not-allowed select-none">
            <div class="h-1.5 w-full bg-violet-500/50"></div>
            <div class="flex flex-1 flex-col p-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-700">
                        <i data-lucide="store" class="h-5 w-5"></i>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-600/80">Marketplace</div>
                        <div class="text-base font-black tracking-tight text-slate-800">Store</div>
                    </div>
                </div>
                <p class="mt-3 text-xs font-semibold leading-relaxed text-slate-500">
                    Browse employee offers and Centryk Market listings from participating companies.
                </p>
                <div class="mt-4 flex items-center justify-center gap-1.5 rounded-xl border border-violet-200 bg-violet-100 px-3 py-2 text-[10px] font-black uppercase tracking-[0.14em] text-violet-700">
                    <i data-lucide="clock-3" class="h-3 w-3"></i>
                    Coming Soon
                </div>
            </div>
        </div>

        <!-- Vision Board — coming soon (static, not launchable) -->
        <div style="--i:<?= ($_appIdx ?? 0) + 5 ?>" class="dash-fade order-3 flex flex-col overflow-hidden rounded-2xl border border-rose-200/70 bg-rose-50/40 text-left shadow-sm opacity-75 cursor-not-allowed select-none">
            <div class="h-1.5 w-full bg-rose-500/50"></div>
            <div class="flex flex-1 flex-col p-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-700">
                        <i data-lucide="monitor-play" class="h-5 w-5"></i>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-rose-600/80">Digital Signage</div>
                        <div class="text-base font-black tracking-tight text-slate-800">Vision Board</div>
                    </div>
                </div>
                <p class="mt-3 text-xs font-semibold leading-relaxed text-slate-500">
                    Create and schedule branded content for on-site TV screens and displays.
                </p>
                <div class="mt-4 flex items-center justify-center gap-1.5 rounded-xl border border-rose-200 bg-rose-100 px-3 py-2 text-[10px] font-black uppercase tracking-[0.14em] text-rose-700">
                    <i data-lucide="clock-3" class="h-3 w-3"></i>
                    Coming Soon
                </div>
            </div>
        </div>

        <!-- Case Management — coming soon (static, not in DB) -->
        <div style="--i:<?= ($_appIdx ?? 0) + 6 ?>" class="dash-fade order-3 flex flex-col overflow-hidden rounded-2xl border border-blue-200/70 bg-blue-50/40 text-left shadow-sm opacity-75 cursor-not-allowed select-none">
            <div class="h-1.5 w-full bg-blue-500/50"></div>
            <div class="flex flex-1 flex-col p-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100">
                        <svg class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.073a2.25 2.25 0 01-2.25 2.25H5.904a2.25 2.25 0 01-2.25-2.25V14.15M16.5 6.75V5.625a2.25 2.25 0 00-2.25-2.25h-2.25a2.25 2.25 0 00-2.25 2.25V6.75M3.375 6.75h17.25a1.125 1.125 0 011.125 1.125v3.026a48.34 48.34 0 01-10.5 1.299 48.34 48.34 0 01-10.5-1.299V7.875A1.125 1.125 0 013.375 6.75z"/>
                        </svg>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-blue-600/80">Cases &amp; Workflows</div>
                        <div class="text-base font-black tracking-tight text-slate-800">Case Management</div>
                    </div>
                </div>
                <p class="mt-3 text-xs font-semibold leading-relaxed text-slate-500">
                    Track and resolve cases across your team — from intake to outcome — all in one place.
                </p>
                <div class="mt-4 flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-[0.14em] bg-blue-100 text-blue-700 border border-blue-200">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z"/></svg>
                    Coming Soon
                </div>
            </div>
        </div>
    </div>

    <!-- No-company notice (shown when no companies exist) -->
    <div id="noCompanyNotice" class="hidden mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
        You're not part of any company yet. Contact your admin to be added.
    </div>


</main>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<!-- Toast -->
<div id="toastWrap" class="pointer-events-none fixed bottom-6 left-1/2 z-[60] flex -translate-x-1/2 flex-col items-center gap-2"></div>

<?php if ($showOnboarding): ?>
<!-- ── Onboarding modal ── -->
<div id="onboardingModal" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="relative w-full max-w-lg rounded-3xl bg-white shadow-2xl overflow-hidden">

        <!-- Top gradient bar -->
        <div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>

        <div class="px-8 py-8">
            <!-- Header -->
            <div class="mb-6 text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-900">
                    <svg viewBox="0 0 24 24" fill="white" class="h-7 w-7">
                        <rect x="3" y="3" width="7" height="7" rx="1.5"/>
                        <rect x="14" y="3" width="7" height="7" rx="1.5"/>
                        <rect x="3" y="14" width="7" height="7" rx="1.5"/>
                        <rect x="14" y="14" width="7" height="7" rx="1.5"/>
                    </svg>
                </div>
                <h2 class="text-2xl font-black tracking-tight text-slate-900">Welcome to Centryk</h2>
                <?php if ($hasDefaultPassword): ?>
                <p class="mt-1.5 text-sm font-semibold text-slate-500">Your account is ready — finish setting it up below.</p>
                <?php else: ?>
                <p class="mt-1.5 text-sm font-semibold text-slate-500">Let's get you set up in a few quick steps.</p>
                <?php endif; ?>
            </div>

            <?php if ($hasDefaultPassword): ?>
            <!-- Default password warning -->
            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3.5">
                <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <div>
                    <p class="text-xs font-black text-amber-800">Your password is still set to the default.</p>
                    <p class="mt-0.5 text-xs font-semibold text-amber-700">Please change it now to keep your account secure.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Steps -->
            <div class="mb-6 space-y-4">
                <!-- Step 1 -->
                <div class="flex items-start gap-4">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-900 text-sm font-black text-white">1</div>
                    <div class="flex-1 pt-1">
                        <p class="text-sm font-black text-slate-900">Create Your Account</p>
                        <p class="mt-0.5 text-xs font-semibold text-slate-500">Your account has already been created — welcome aboard!</p>
                    </div>
                    <svg class="mt-1 h-5 w-5 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <!-- Step 2 -->
                <div class="flex items-start gap-4">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-600 text-sm font-black text-white">2</div>
                    <div class="flex-1 pt-1">
                        <p class="text-sm font-black text-slate-900">Set Up Your Business</p>
                        <p class="mt-0.5 text-xs font-semibold text-slate-500">Add your stores, products, employees, or payroll information depending on the apps you'll use.</p>
                    </div>
                </div>
                <!-- Step 3 -->
                <div class="flex items-start gap-4">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 border-slate-200 text-sm font-black text-slate-400">3</div>
                    <div class="flex-1 pt-1">
                        <p class="text-sm font-black text-slate-400">Start Using Your Tools</p>
                        <p class="mt-0.5 text-xs font-semibold text-slate-400">Access OnePay for inventory &amp; POS or MyPay for payroll and HR management.</p>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-col gap-2.5">
                <?php if ($hasDefaultPassword): ?>
                <a href="profile.php" id="onboardingChangePwBtn"
                   class="flex items-center justify-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-black text-white transition hover:bg-slate-800">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                    Change My Password
                </a>
                <button id="onboardingDismissBtn"
                        class="rounded-2xl border border-slate-200 px-5 py-3 text-sm font-bold text-slate-500 transition hover:bg-slate-50">
                    Remind me later
                </button>
                <?php else: ?>
                <button id="onboardingDismissBtn"
                        class="flex items-center justify-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-black text-white transition hover:bg-slate-800">
                    Got it — let's go
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12"/>
                    </svg>
                </button>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>

<!-- ── Choose company modal (first visit, multiple companies, no prior choice) ── -->
<div id="chooseCompanyModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="relative w-full max-w-md rounded-3xl bg-white shadow-2xl overflow-hidden">
        <div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>
        <div class="px-7 py-7">
            <div class="mb-5 text-center">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white">
                    <i data-lucide="building-2" class="h-6 w-6"></i>
                </div>
                <h2 class="text-xl font-black tracking-tight text-slate-900">Choose a company</h2>
                <p class="mt-1 text-sm font-semibold text-slate-400">You belong to more than one company. Pick which one you'd like to work in.</p>
            </div>
            <div id="chooseCompanyList" class="max-h-72 space-y-2 overflow-y-auto"></div>
        </div>
    </div>
</div>

<!-- Launch overlay -->
<div id="launchOverlay" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">
    <div class="flex items-center gap-3 rounded-2xl bg-white px-8 py-5 shadow-2xl">
        <svg class="h-5 w-5 animate-spin text-slate-500" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
        </svg>
        <span class="text-sm font-black text-slate-800">Launching app&hellip;</span>
    </div>
</div>

<script>
(function () {
    var companies    = [];
    var selectedId   = null;
    var selectedUuid = null;

    var pickerBtn    = document.getElementById('companyPickerBtn');
    var pickerLabel  = document.getElementById('companyPickerLabel');
    var dropdown     = document.getElementById('companyDropdown');
    var dropdownList = document.getElementById('companyDropdownList');
    var ctxText      = document.getElementById('companyContext');
    var noCompNotice = document.getElementById('noCompanyNotice');

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function showToast(msg, type) {
        var wrap  = document.getElementById('toastWrap');
        var toast = document.createElement('div');
        var isErr = type === 'error';
        toast.className = 'pointer-events-auto flex items-center gap-2.5 rounded-xl px-4 py-3 text-sm font-semibold shadow-lg transition-all duration-300 '
            + (isErr ? 'bg-rose-600 text-white' : 'bg-slate-900 text-white');
        toast.innerHTML = (isErr
            ? '<svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>'
            : '<svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>')
            + '<span>' + esc(msg) + '</span>';
        wrap.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () { toast.remove(); }, 300);
        }, 4000);
    }

    // ── Company picker ────────────────────────────────────────────────────────
    function selectCompany(id) {
        selectedId   = id;
        var c = companies.find(function (x) { return x.id == id; });
        selectedUuid = c ? (c.uuid || null) : null;
        // Remember the choice so it carries over when returning from another app.
        if (selectedUuid) { try { localStorage.setItem('centryk_company_uuid', selectedUuid); } catch (e) {} }
        markActiveCompany();
        if (c) {
            pickerLabel.textContent = c.name;
            pickerLabel.classList.remove('text-slate-400');
            pickerLabel.classList.add('text-slate-800');
            ctxText.textContent = 'Launching as ' + c.name + ' (' + c.role + ')';
            // ── Company profile card ──────────────────────────────────────
            var roleColors = { admin: '#7c3aed', manager: '#2563eb', employee: '#475569', owner: '#0ea5e9' };
            var rColor = roleColors[c.role] || roleColors.employee;

            var coEmpty  = document.getElementById('coCardEmpty');
            var coFilled = document.getElementById('coCardFilled');
            if (coEmpty)  { coEmpty.classList.add('hidden'); }
            if (coFilled) { coFilled.classList.remove('hidden'); }

            var coAvatar = document.getElementById('coAvatar');
            if (coAvatar) {
                var coLetter = (c.name || '?').charAt(0).toUpperCase();
                if (c.logo) {
                    // Show the business profile image instead of the letter.
                    // c.logo is relative to the Centryk public root, which is also
                    // where this dashboard is served from, so it resolves directly.
                    coAvatar.textContent = '';
                    coAvatar.style.background = '#fff';
                    var coImg = document.createElement('img');
                    coImg.src = c.logo;
                    coImg.alt = c.name || '';
                    coImg.className = 'h-full w-full rounded-2xl object-cover';
                    // Fall back to the letter badge if the image fails to load.
                    coImg.onerror = function () {
                        coAvatar.textContent = coLetter;
                        coAvatar.style.background = rColor;
                    };
                    coAvatar.appendChild(coImg);
                } else {
                    coAvatar.textContent = coLetter;
                    coAvatar.style.background = rColor;
                }
            }
            var coNameEl = document.getElementById('coName');
            if (coNameEl) { coNameEl.textContent = c.name || ''; }

            var coRoleBadge = document.getElementById('coRoleBadge');
            if (coRoleBadge) {
                coRoleBadge.textContent = c.role || '';
                coRoleBadge.style.background = rColor + '22';
                coRoleBadge.style.color = rColor;
            }

            var n = Number(c.member_count) || 0;
            var coMemberCount = document.getElementById('coMemberCount');
            if (coMemberCount) { coMemberCount.textContent = n; }

            var companiesUrl = 'profile.php' + (selectedUuid ? ('?company_uuid=' + encodeURIComponent(selectedUuid)) : '') + '#companies';
            var coMemberLink = document.getElementById('coMemberLink');
            if (coMemberLink) { coMemberLink.href = companiesUrl; }

            var onelinkUrl = 'onelink-payments.php' + (selectedUuid ? ('?company_uuid=' + encodeURIComponent(selectedUuid)) : '');
            var coOnelinkBtn = document.getElementById('coOnelinkPaymentsBtn');
            if (coOnelinkBtn) { coOnelinkBtn.href = onelinkUrl; }

            var advertiseUrl = 'advertise.php' + (selectedUuid ? ('?company_uuid=' + encodeURIComponent(selectedUuid)) : '');
            var coAdvertiseBtn = document.getElementById('coAdvertiseBtn');
            if (coAdvertiseBtn) { coAdvertiseBtn.href = advertiseUrl; }

            // ── Setup progress ────────────────────────────────────────────
            var enrolledCount = document.querySelectorAll('.app-card[data-enrolled="1"]').length;
            var step1Done = true;
            var step2Done = n > 1;
            var step3Done = enrolledCount > 0;
            var stepsComplete = (step1Done ? 1 : 0) + (step2Done ? 1 : 0) + (step3Done ? 1 : 0);

            var progressWrap = document.getElementById('setupProgressWrap');
            if (progressWrap) {
                if (stepsComplete < 3) {
                    progressWrap.classList.remove('hidden');
                    var pct = Math.round((stepsComplete / 3) * 100);
                    var bar = document.getElementById('setupProgressBar');
                    var lbl = document.getElementById('setupProgressLabel');
                    if (bar) { bar.style.width = pct + '%'; }
                    if (lbl) { lbl.textContent = stepsComplete + ' of 3 complete'; }

                    function markStep(id, done) {
                        var el = document.getElementById(id);
                        if (!el) { return; }
                        var dot = el.querySelector('span');
                        if (done) {
                            el.style.color = '#10b981';
                            if (dot) { dot.style.background = '#10b981'; }
                        } else {
                            el.style.color = '';
                            if (dot) { dot.style.background = ''; }
                        }
                    }
                    markStep('setupStep1', step1Done);
                    markStep('setupStep2', step2Done);
                    markStep('setupStep3', step3Done);
                } else {
                    progressWrap.classList.add('hidden');
                }
            }

            // Update per-app employee counts on each card
            var counts = (c.app_counts && typeof c.app_counts === 'object') ? c.app_counts : {};
            document.querySelectorAll('.app-count-badge').forEach(function (badge) {
                var appKey = badge.id.replace('app-count-', '');
                var n = counts[appKey];
                var count = (n !== undefined && n !== null) ? parseInt(n, 10) : 0;
                var numEl = badge.querySelector('.app-count-num');
                var dotEl = badge.querySelector('.app-count-dot');
                if (numEl) {
                    numEl.textContent = count === 1 ? '1 active user' : count + ' active users';
                }
                if (dotEl) {
                    if (count > 0) {
                        dotEl.className = 'app-count-dot inline-block h-1.5 w-1.5 rounded-full bg-emerald-400';
                        numEl && (numEl.className = 'app-count-num text-[11px] font-bold text-emerald-600');
                    } else {
                        dotEl.className = 'app-count-dot inline-block h-1.5 w-1.5 rounded-full bg-slate-300';
                        numEl && (numEl.className = 'app-count-num text-[11px] font-bold text-slate-400');
                    }
                }
            });
        }
        dropdown.classList.add('hidden');
        // Re-enable enrolled app cards only
        document.querySelectorAll('.app-card[data-enrolled="1"]').forEach(function (card) {
            card.disabled = false;
            card.classList.remove('opacity-40', 'cursor-not-allowed');
        });
    }

    // Show the checkmark + highlight on the currently selected company row.
    function markActiveCompany() {
        dropdownList.querySelectorAll('.company-option').forEach(function (btn) {
            var active = String(btn.dataset.id) === String(selectedId);
            var chk = btn.querySelector('.company-check');
            if (chk) { chk.classList.toggle('hidden', !active); }
            btn.classList.toggle('bg-slate-50', active);
        });
    }

    function buildDropdown() {
        if (!companies.length) {
            dropdownList.innerHTML = '<p class="px-4 py-3 text-xs text-slate-400">No companies found.</p>';
            noCompanyNotice.classList.remove('hidden');
            pickerLabel.textContent = 'No companies';
            return;
        }

        var _rc = { admin: '#7c3aed', manager: '#2563eb', employee: '#475569', owner: '#0ea5e9' };
        dropdownList.innerHTML = companies.map(function (c) {
            var color   = _rc[c.role] || _rc.employee;
            var initial = (c.name || '?').charAt(0).toUpperCase();
            return '<button class="company-option w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-slate-50 transition"' +
                ' data-id="' + c.id + '">' +
                '<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-black" style="background:' + color + '18;color:' + color + '">' + esc(initial) + '</span>' +
                '<div class="min-w-0">' +
                    '<div class="text-sm font-bold text-slate-800 truncate">' + esc(c.name) + '</div>' +
                    '<div class="text-[10px] font-semibold capitalize" style="color:' + color + '">' + esc(c.role) + '</div>' +
                '</div>' +
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="company-check ml-auto h-4 w-4 shrink-0 text-slate-900 hidden">' +
                    '<path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd"/>' +
                '</svg>' +
                '</button>';
        }).join('');

        dropdownList.querySelectorAll('.company-option').forEach(function (btn) {
            btn.addEventListener('click', function () {
                selectCompany(btn.dataset.id);
            });
        });

        // Carry over a company selected elsewhere: prefer ?company_uuid= in the
        // URL (passed by OnePay/MyPay when switching back), then the last choice
        // remembered in localStorage. Fall back to auto-select when there's one.
        var preferredId = preferredCompanyId();
        if (preferredId != null) {
            selectCompany(preferredId);
        } else if (companies.length === 1) {
            selectCompany(companies[0].id);
        } else {
            pickerLabel.textContent = 'Select a company…';
            // Dim enrolled app cards until a company is chosen
            document.querySelectorAll('.app-card[data-enrolled="1"]').forEach(function (card) {
                card.disabled = true;
            });
            // Prompt explicitly so apps never launch under an arbitrary company.
            openChooseCompanyModal();
        }
    }

    // Resolve the company to preselect from the URL or remembered choice.
    function preferredCompanyId() {
        var uuid = '';
        try { uuid = new URL(window.location.href).searchParams.get('company_uuid') || ''; } catch (e) {}
        if (!uuid) {
            try { uuid = localStorage.getItem('centryk_company_uuid') || ''; } catch (e) {}
        }
        if (!uuid) return null;
        var match = companies.find(function (c) { return c.uuid === uuid; });
        return match ? match.id : null;
    }

    // ── First-visit company chooser ───────────────────────────────────────────
    // Shown only when the user has multiple companies and no remembered choice,
    // so an app is never launched under an arbitrary "first" company. Selecting
    // one persists via selectCompany(), so this never reappears on this device.
    var chooseModal = document.getElementById('chooseCompanyModal');
    var chooseList  = document.getElementById('chooseCompanyList');

    function openChooseCompanyModal() {
        if (!chooseModal || !chooseList) { return; }
        var _rc = { admin: '#7c3aed', manager: '#2563eb', employee: '#475569', owner: '#0ea5e9' };
        chooseList.innerHTML = companies.map(function (c) {
            var color   = _rc[c.role] || _rc.employee;
            var initial = (c.name || '?').charAt(0).toUpperCase();
            var n       = Number(c.member_count) || 0;
            return '<button class="choose-co-opt w-full flex items-center gap-3 rounded-2xl border border-slate-200 px-4 py-3 text-left transition hover:border-slate-300 hover:bg-slate-50 active:scale-[0.98]"' +
                ' data-id="' + c.id + '">' +
                '<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-base font-black" style="background:' + color + '18;color:' + color + '">' + esc(initial) + '</span>' +
                '<div class="min-w-0 flex-1">' +
                    '<div class="text-sm font-black text-slate-900 truncate">' + esc(c.name) + '</div>' +
                    '<div class="mt-0.5 flex items-center gap-2">' +
                        '<span class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] capitalize" style="background:' + color + '22;color:' + color + '">' + esc(c.role) + '</span>' +
                        '<span class="text-[11px] font-semibold text-slate-400">' + n + ' member' + (n === 1 ? '' : 's') + '</span>' +
                    '</div>' +
                '</div>' +
                '<i data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-slate-300"></i>' +
                '</button>';
        }).join('');

        chooseList.querySelectorAll('.choose-co-opt').forEach(function (btn) {
            btn.addEventListener('click', function () {
                selectCompany(btn.dataset.id);
                closeChooseCompanyModal();
            });
        });

        chooseModal.classList.remove('hidden');
        chooseModal.classList.add('flex');
        if (window.lucide) { lucide.createIcons(); }
    }

    function closeChooseCompanyModal() {
        if (!chooseModal) { return; }
        chooseModal.classList.add('hidden');
        chooseModal.classList.remove('flex');
    }

    // Dismissable: clicking the backdrop or pressing Escape falls back to the
    // header dropdown's "Select a company…" state rather than trapping the user.
    if (chooseModal) {
        chooseModal.addEventListener('click', function (e) {
            if (e.target === chooseModal) { closeChooseCompanyModal(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeChooseCompanyModal(); }
        });
    }

    function loadCompanies() {
        fetch('api/companies/list.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                companies = (data.success && data.companies) ? data.companies : [];
                buildDropdown();
                if (window.lucide) { lucide.createIcons(); }
            })
            .catch(function () {
                pickerLabel.textContent = 'Could not load companies';
            });
    }

    pickerBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.classList.toggle('hidden');
    });

    document.addEventListener('click', function () {
        dropdown.classList.add('hidden');
    });

    var appLabels = { onepay: 'OnePay', mypay: 'MyPay', centryk: 'Centryk' };
    var adminToolsBtn = document.getElementById('adminToolsBtn');
    var adminToolsMenu = document.getElementById('adminToolsMenu');
    if (adminToolsBtn && adminToolsMenu) {
        adminToolsBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            adminToolsMenu.classList.toggle('hidden');
            if (window.lucide) { lucide.createIcons(); }
        });
    }

    // ── App launch ────────────────────────────────────────────────────────────
    function launchApp(appKey) {
        var overlay = document.getElementById('launchOverlay');
        overlay.style.display = 'flex';

        // Open the target tab synchronously, inside the click gesture, so popup
        // blockers don't kill it. The real URL is set once the API responds; if
        // the browser blocked it (newTab === null) we fall back to same-window.
        var newTab = window.open('', '_blank');

        fetch('api/auth/launch.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ app_key: appKey, company_uuid: selectedUuid || '' }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            overlay.style.display = 'none';
            if (data.success && data.redirect_url) {
                if (newTab) {
                    newTab.location.href = data.redirect_url;
                } else {
                    window.location.href = data.redirect_url;
                }
            } else {
                if (newTab) { newTab.close(); }
                var appLabel = appLabels[appKey] || appKey;
                var co = companies.find(function (c) { return String(c.id) === String(selectedId); });
                var msg = co
                    ? 'You don\'t have access to ' + appLabel + ' for ' + co.name + '.'
                    : 'You don\'t have access to ' + appLabel + '.';
                showToast(msg, 'error');
            }
        })
        .catch(function () {
            overlay.style.display = 'none';
            if (newTab) { newTab.close(); }
            showToast('Network error. Please try again.', 'error');
        });
    }

    document.querySelectorAll('.app-card').forEach(function (card) {
        card.addEventListener('click', function () {
            var enrolled = card.dataset.enrolled === '1';
            var optIn    = card.dataset.optIn    === '1';

            // Opt-in app the user hasn't enabled yet → self-enable then reload
            if (!enrolled && optIn) {
                card.style.opacity = '0.6';
                fetch('api/apps/enable.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ app_key: card.dataset.app })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        card.style.opacity = '';
                        showToast(data.message || 'Could not enable app.', 'error');
                    }
                })
                .catch(function () {
                    card.style.opacity = '';
                    showToast('Network error. Please try again.', 'error');
                });
                return;
            }

            if (!enrolled) { return; }
            if (!selectedId && companies.length > 1) {
                pickerBtn.focus();
                dropdown.classList.remove('hidden');
                return;
            }
            launchApp(card.dataset.app);
        });
    });

    var onelinkPaymentsCard = document.getElementById('onelinkPaymentsCard');
    if (onelinkPaymentsCard) {
        onelinkPaymentsCard.addEventListener('click', function () {
            if (!selectedId && companies.length > 1) {
                pickerBtn.focus();
                dropdown.classList.remove('hidden');
                return;
            }
            var url = 'onelink-payments.php' + (selectedUuid ? ('?company_uuid=' + encodeURIComponent(selectedUuid)) : '');
            window.location.href = url;
        });
    }

    // Waffle app tiles launch the same way as the app cards
    document.querySelectorAll('.aw-app').forEach(function (tile) {
        tile.addEventListener('click', function () {
            var awDropdown = document.getElementById('appSwitcherDropdown');
            if (awDropdown) { awDropdown.classList.add('hidden'); }
            if (!selectedId && companies.length > 1) {
                pickerBtn.focus();
                dropdown.classList.remove('hidden');
                return;
            }
            launchApp(tile.dataset.app);
        });
    });

    // ── User menu ─────────────────────────────────────────────────────────────
    document.getElementById('userMenuBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        document.getElementById('userMenu').classList.toggle('hidden');
        if (window.lucide) { lucide.createIcons(); }
    });
    document.addEventListener('click', function () {
        document.getElementById('userMenu').classList.add('hidden');
        dropdown.classList.add('hidden');
        if (adminToolsMenu) { adminToolsMenu.classList.add('hidden'); }
    });

    document.getElementById('logoutBtn').addEventListener('click', function () {
        fetch('api/auth/logout.php', { method: 'POST' }).then(function () {
            window.location.reload();
        });
    });

    loadCompanies();

    // Expose for cross-script use (create company modal)
    window._ctrykLoadCompanies  = loadCompanies;
    window._ctrykSelectCompany  = selectCompany;

    // ── Onboarding modal dismiss ──────────────────────────────────────────────
    var onboardingModal   = document.getElementById('onboardingModal');
    var onboardingDismiss = document.getElementById('onboardingDismissBtn');

    if (onboardingDismiss) {
        onboardingDismiss.addEventListener('click', function () {
            if (onboardingModal) { onboardingModal.style.display = 'none'; }
            fetch('api/auth/onboarding-dismiss.php', { method: 'POST' }).catch(function () {});
        });
    }
    // Clicking password-change link also dismisses
    var onboardingChangePw = document.getElementById('onboardingChangePwBtn');
    if (onboardingChangePw) {
        onboardingChangePw.addEventListener('click', function () {
            fetch('api/auth/onboarding-dismiss.php', { method: 'POST' }).catch(function () {});
        });
    }

    // ── Inline invite-member form ────────────────────────────────────────────
    var invBtn       = document.getElementById('coInviteBtn');
    var invForm      = document.getElementById('inviteForm');
    var invWrap      = document.getElementById('inlineInviteForm');
    var invClose     = document.getElementById('closeInlineInviteBtn');
    var invCancel    = document.getElementById('cancelInviteBtn');
    var invAlert     = document.getElementById('inviteAlert');
    var invSuccess   = document.getElementById('inviteSuccess');
    var invSubmit    = document.getElementById('submitInviteBtn');
    var invFirst     = document.getElementById('invFirst');
    var invLast      = document.getElementById('invLast');
    var invEmail     = document.getElementById('invEmail');
    var invEmailWrap = document.getElementById('invEmailWrap');
    var invUserWrap  = document.getElementById('invUsernameWrap');
    var invUser      = document.getElementById('invUsername');
    var invUserPrev  = document.getElementById('invUsernamePreview');
    var invPassword  = document.getElementById('invPassword');
    var invRole      = document.getElementById('invRole');
    var invToggleNo  = document.getElementById('invNoEmailToggle');
    var invToggleEm  = document.getElementById('invBackToEmailBtn');
    var invNoEmail   = false;

    function invShowForm() {
        if (!invWrap) return;
        invWrap.classList.remove('hidden');
        invAlert.classList.add('hidden');
        invSuccess.classList.add('hidden');
        if (window.lucide) { lucide.createIcons(); }
        setTimeout(function () { invFirst && invFirst.focus(); }, 50);
    }
    function invHideForm() { invWrap && invWrap.classList.add('hidden'); }
    function invReset() {
        if (!invForm) return;
        invFirst.value = ''; invLast.value = '';
        invEmail.value = ''; invUser.value = '';
        invPassword.value = ''; invRole.value = 'employee';
        invSetNoEmail(false);
        invAlert.classList.add('hidden');
    }
    function invSetNoEmail(enabled) {
        invNoEmail = enabled;
        invEmailWrap.classList.toggle('hidden', enabled);
        invUserWrap.classList.toggle('hidden', !enabled);
        if (enabled) invSyncUsername();
    }
    function invSyncUsername() {
        var first = (invFirst.value || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        var last  = (invLast.value  || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        if (!invUser.value) {
            invUser.value = [first, last].filter(Boolean).join('.');
        }
        invUserPrev.textContent = (invUser.value.trim() || 'username') + '@centryk.com';
    }

    if (invBtn) {
        invBtn.addEventListener('click', function () {
            if (!selectedId) {
                showToast('Select a company first.', 'error');
                return;
            }
            if (invWrap.classList.contains('hidden')) { invReset(); invShowForm(); }
            else { invHideForm(); }
        });
    }
    invClose  && invClose.addEventListener('click', invHideForm);
    invCancel && invCancel.addEventListener('click', invHideForm);
    invToggleNo && invToggleNo.addEventListener('click', function () { invSetNoEmail(true); setTimeout(function () { invUser && invUser.focus(); }, 30); });
    invToggleEm && invToggleEm.addEventListener('click', function () { invSetNoEmail(false); setTimeout(function () { invEmail && invEmail.focus(); }, 30); });
    [invFirst, invLast, invUser].forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', function () { if (invNoEmail) invSyncUsername(); });
    });

    if (invForm) {
        invForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!selectedId) {
                invAlert.textContent = 'Select a company first.';
                invAlert.classList.remove('hidden');
                return;
            }
            var payload = {
                company_id: selectedId,
                first_name: invFirst.value.trim(),
                last_name:  invLast.value.trim(),
                password:   invPassword.value,
                role:       invRole.value
            };
            if (invNoEmail) {
                var u = (invUser.value.trim() || '').toLowerCase().replace(/[^a-z0-9._-]/g, '');
                if (!u) { invAlert.textContent = 'Username is required.'; invAlert.classList.remove('hidden'); return; }
                payload.email = u + '@centryk.com';
            } else {
                payload.email = invEmail.value.trim();
            }
            if (!payload.email)            { invAlert.textContent = 'Email or username is required.'; invAlert.classList.remove('hidden'); return; }
            if (payload.password.length < 8) { invAlert.textContent = 'Password must be at least 8 characters.'; invAlert.classList.remove('hidden'); return; }

            invAlert.classList.add('hidden');
            invSubmit.disabled = true;
            invSubmit.textContent = 'Adding…';

            fetch('api/companies/invite.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                invSubmit.disabled = false;
                invSubmit.textContent = 'Add Member';
                if (!data.success) {
                    invAlert.textContent = data.message || 'Could not add member.';
                    invAlert.classList.remove('hidden');
                    return;
                }
                invSuccess.innerHTML = invNoEmail
                    ? 'Account created — they can log in as <span class="font-mono font-bold">' + payload.email + '</span> with the password you set.'
                    : 'Member added successfully.';
                invSuccess.classList.remove('hidden');
                invReset();
                // Refresh member count + close form after a beat
                setTimeout(function () { window.location.reload(); }, 1600);
            })
            .catch(function () {
                invSubmit.disabled = false;
                invSubmit.textContent = 'Add Member';
                invAlert.textContent = 'Network error. Please try again.';
                invAlert.classList.remove('hidden');
            });
        });
    }
}());
</script>

<!-- ── Create Company Modal ── -->
<div id="createCompanyModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-black/40 backdrop-blur-sm p-4">
    <div class="relative w-full max-w-sm rounded-3xl bg-white shadow-2xl overflow-hidden">
        <div class="px-6 pt-6 pb-5">
            <div class="flex items-center justify-between mb-5">
                <div>
                    <h2 class="text-lg font-black text-slate-900">New Company Profile</h2>
                    <p class="text-xs text-slate-400 mt-0.5">You'll be the admin of this company.</p>
                </div>
                <button id="btnCloseCreateCompany" class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div id="createCompanyError" class="hidden mb-3 rounded-xl bg-red-50 border border-red-200 px-4 py-2.5 text-sm font-semibold text-red-600"></div>
            <label class="block text-xs font-black uppercase tracking-[0.14em] text-slate-500 mb-1.5">Company Name</label>
            <input id="createCompanyName" type="text" placeholder="e.g. BHI Limited"
                class="w-full rounded-xl border-2 border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-900 placeholder-slate-400 outline-none focus:border-indigo-500 focus:bg-white transition">
            <div class="mt-5 flex gap-2">
                <button id="btnCancelCreateCompany" class="flex-1 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50 transition">Cancel</button>
                <button id="btnSubmitCreateCompany" class="flex-1 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white hover:bg-indigo-700 transition">Create Company</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal   = document.getElementById('createCompanyModal');
    var nameIn  = document.getElementById('createCompanyName');
    var errEl   = document.getElementById('createCompanyError');
    var submitBtn = document.getElementById('btnSubmitCreateCompany');

    function openCreateModal() {
        if (!modal) return;
        nameIn.value = '';
        errEl.classList.add('hidden');
        errEl.textContent = '';
        submitBtn.textContent = 'Create Company';
        submitBtn.disabled = false;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        // close dropdown
        var dd = document.getElementById('companyDropdown');
        if (dd) { dd.classList.add('hidden'); }
        setTimeout(function () { nameIn.focus(); }, 50);
    }

    function closeCreateModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.getElementById('btnCreateCompanyFromDropdown').addEventListener('click', openCreateModal);
    document.getElementById('btnCloseCreateCompany').addEventListener('click', closeCreateModal);
    document.getElementById('btnCancelCreateCompany').addEventListener('click', closeCreateModal);

    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeCreateModal();
    });

    nameIn.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); submitBtn.click(); }
    });

    submitBtn.addEventListener('click', function () {
        var name = nameIn.value.trim();
        if (!name) {
            errEl.textContent = 'Company name is required.';
            errEl.classList.remove('hidden');
            nameIn.focus();
            return;
        }
        errEl.classList.add('hidden');
        submitBtn.textContent = 'Creating…';
        submitBtn.disabled = true;

        fetch('api/companies/create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: name })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                errEl.textContent = data.message || 'Could not create company.';
                errEl.classList.remove('hidden');
                submitBtn.textContent = 'Create Company';
                submitBtn.disabled = false;
                return;
            }
            closeCreateModal();
            // reload company list, then auto-select the new company
            var newId = data.id;
            if (typeof window._ctrykLoadCompanies === 'function') {
                window._ctrykLoadCompanies();
                // give loadCompanies a tick to finish, then select
                setTimeout(function () {
                    if (newId && typeof window._ctrykSelectCompany === 'function') {
                        window._ctrykSelectCompany(newId);
                    }
                    if (window.lucide) lucide.createIcons();
                }, 350);
            }
        })
        .catch(function () {
            errEl.textContent = 'Network error. Please try again.';
            errEl.classList.remove('hidden');
            submitBtn.textContent = 'Create Company';
            submitBtn.disabled = false;
        });
    });
}());
</script>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    if (window.lucide) { lucide.createIcons(); }
</script>

</body>
</html>
