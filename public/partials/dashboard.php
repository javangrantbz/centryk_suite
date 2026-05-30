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
                    <a href="companies.php" class="flex items-center justify-between px-3 py-2 text-[10px] font-bold text-slate-400 hover:text-slate-600 transition border-t border-slate-50">
                        <span>Manage all companies</span>
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
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
                        Manage Profile
                    </a>
                    <?php if (!empty($user['is_admin'])): ?>
                    <a href="companies.php" class="flex sm:hidden items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="building-2" class="h-4 w-4 shrink-0"></i>
                        Companies
                    </a>
                    <a href="requests.php" class="flex sm:hidden items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="users" class="h-4 w-4 shrink-0"></i>
                        New Users
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
                        Welcome back, <?= htmlspecialchars($user['first_name']) ?> &middot;
                        <a id="coMemberLink" href="companies.php" class="transition hover:text-slate-700">
                            <span id="coMemberCount">0</span> members
                        </a>
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <a id="coInviteBtn" href="companies.php"
                       class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-xs font-black text-slate-600 transition hover:bg-slate-50 hover:border-slate-300">
                        <i data-lucide="user-plus" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">Invite Member</span>
                    </a>
                    <a href="companies.php"
                       class="flex items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-xs font-black text-slate-600 transition hover:bg-slate-50 hover:border-slate-300">
                        <i data-lucide="building-2" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">Manage</span>
                    </a>
                    <button disabled title="Coming soon"
                            class="flex items-center gap-1.5 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-xs font-black text-slate-400 cursor-not-allowed">
                        <i data-lucide="share-2" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">Advertise</span>
                        <span class="rounded-full bg-violet-100 px-1.5 py-0.5 text-[9px] font-black uppercase tracking-[0.1em] text-violet-500">Soon</span>
                    </button>
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

        </div>
    </div>

    <!-- Apps grid -->
    <div id="appsGrid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php $_appIdx = 0; foreach ($apps as $app):
            $_appIdx++;
            $enrolled = !empty($app['enrolled']);
        ?>
        <button style="--i:<?= $_appIdx ?>" class="dash-fade app-card group flex flex-col overflow-hidden rounded-2xl border text-left shadow-sm transition
                    <?= $enrolled
                        ? 'border-slate-200 bg-white hover:shadow-md hover:-translate-y-0.5 active:scale-[0.98] disabled:opacity-40 disabled:cursor-not-allowed disabled:translate-y-0 disabled:shadow-sm'
                        : 'border-slate-200/50 bg-slate-50 opacity-50 cursor-not-allowed' ?>"
                data-app="<?= htmlspecialchars($app['key']) ?>"
                data-enrolled="<?= $enrolled ? '1' : '0' ?>"
                <?= $enrolled ? '' : 'disabled' ?>>
            <div class="h-1.5 w-full" style="background:<?= htmlspecialchars($app['color']) . ($enrolled ? '' : ';opacity:.4') ?>"></div>
            <div class="flex flex-1 flex-col p-5">
                <div class="flex items-center gap-3">
                    <?php if ($app['key'] === 'onepay'): ?>
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full shadow-sm"
                          style="background:<?= htmlspecialchars($app['color']) ?>">
                        <svg viewBox="0 0 24 24" fill="white" class="h-6 w-6">
                            <path d="M12 2.5c.72 5.08 2.42 6.78 7.5 7.5-5.08.72-6.78 2.42-7.5 7.5-.72-5.08-2.42-6.78-7.5-7.5 5.08-.72 6.78-2.42 7.5-7.5Z"/>
                        </svg>
                    </span>
                    <?php elseif ($app['key'] === 'mypay'): ?>
                    <img src="../myPay.png" alt="MyPay" class="h-12 w-12 rounded-xl object-contain shadow-sm">
                    <?php else: ?>
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-2xl"
                          style="background:<?= htmlspecialchars($app['color']) ?>18">
                        <?= htmlspecialchars($app['icon'] ?? '') ?>
                    </span>
                    <?php endif; ?>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">
                            <?php
                            if ($app['key'] === 'onepay')    echo 'Inventory &amp; POS';
                            elseif ($app['key'] === 'mypay') echo 'HR &amp; Payroll';
                            else                             echo htmlspecialchars($app['label']);
                            ?>
                        </div>
                        <div class="text-lg font-black tracking-tight text-slate-900"><?= htmlspecialchars($app['label']) ?></div>
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
                <?php else: ?>
                <div class="mt-4 flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-[0.14em] bg-slate-200 text-slate-400">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                    Not enrolled
                </div>
                <?php endif; ?>
            </div>
            <?php if ($enrolled): ?>
            <div class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs font-bold text-slate-500 transition-colors group-hover:text-slate-800">
                <span>Launch <?= htmlspecialchars($app['label']) ?></span>
                <i data-lucide="arrow-right" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"></i>
            </div>
            <?php endif; ?>
        </button>
        <?php endforeach; ?>

        <!-- Invoice Maker — coming soon (static, not in DB) -->
        <div style="--i:<?= ($_appIdx ?? 0) + 1 ?>" class="dash-fade flex flex-col overflow-hidden rounded-2xl border border-emerald-200/70 bg-emerald-50/40 text-left shadow-sm opacity-75 cursor-not-allowed select-none">
            <div class="h-1.5 w-full bg-emerald-500/50"></div>
            <div class="flex flex-1 flex-col p-5">
                <div class="flex items-center gap-3">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-100">
                        <svg class="h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z"/>
                        </svg>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-600/80">Quotes &amp; Invoicing</div>
                        <div class="text-lg font-black tracking-tight text-slate-800">Invoice Maker</div>
                    </div>
                </div>
                <p class="mt-3 text-xs font-semibold leading-relaxed text-slate-500">
                    A simplified way of making quotes, invoices, and sharing them with clients.
                </p>
                <div class="mt-4 flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-[0.14em] bg-emerald-100 text-emerald-700 border border-emerald-200">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z"/></svg>
                    Coming Soon
                </div>
            </div>
        </div>

        <!-- Case Management — coming soon (static, not in DB) -->
        <div style="--i:<?= ($_appIdx ?? 0) + 2 ?>" class="dash-fade flex flex-col overflow-hidden rounded-2xl border border-blue-200/70 bg-blue-50/40 text-left shadow-sm opacity-75 cursor-not-allowed select-none">
            <div class="h-1.5 w-full bg-blue-500/50"></div>
            <div class="flex flex-1 flex-col p-5">
                <div class="flex items-center gap-3">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-100">
                        <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.073a2.25 2.25 0 01-2.25 2.25H5.904a2.25 2.25 0 01-2.25-2.25V14.15M16.5 6.75V5.625a2.25 2.25 0 00-2.25-2.25h-2.25a2.25 2.25 0 00-2.25 2.25V6.75M3.375 6.75h17.25a1.125 1.125 0 011.125 1.125v3.026a48.34 48.34 0 01-10.5 1.299 48.34 48.34 0 01-10.5-1.299V7.875A1.125 1.125 0 013.375 6.75z"/>
                        </svg>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-blue-600/80">Cases &amp; Workflows</div>
                        <div class="text-lg font-black tracking-tight text-slate-800">Case Management</div>
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
                coAvatar.textContent = (c.name || '?').charAt(0).toUpperCase();
                coAvatar.style.background = rColor;
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

            var companiesUrl = 'companies.php' + (selectedUuid ? ('?company_uuid=' + encodeURIComponent(selectedUuid)) : '');
            var coMemberLink = document.getElementById('coMemberLink');
            if (coMemberLink) { coMemberLink.href = companiesUrl; }
            var coInviteBtn  = document.getElementById('coInviteBtn');
            if (coInviteBtn)  { coInviteBtn.href = companiesUrl; }

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
                '</button>';
        }).join('');

        dropdownList.querySelectorAll('.company-option').forEach(function (btn) {
            btn.addEventListener('click', function () {
                selectCompany(btn.dataset.id);
            });
        });

        // Auto-select first if only one company
        if (companies.length === 1) {
            selectCompany(companies[0].id);
        } else {
            pickerLabel.textContent = 'Select a company…';
            // Dim enrolled app cards until a company is chosen
            document.querySelectorAll('.app-card[data-enrolled="1"]').forEach(function (card) {
                card.disabled = true;
            });
        }
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

    // ── App launch ────────────────────────────────────────────────────────────
    function launchApp(appKey) {
        var overlay = document.getElementById('launchOverlay');
        overlay.style.display = 'flex';

        fetch('api/auth/launch.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ app_key: appKey, company_uuid: selectedUuid || '' }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && data.redirect_url) {
                window.location.href = data.redirect_url;
            } else {
                overlay.style.display = 'none';
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
            showToast('Network error. Please try again.', 'error');
        });
    }

    document.querySelectorAll('.app-card').forEach(function (card) {
        card.addEventListener('click', function () {
            if (!selectedId && companies.length > 1) {
                pickerBtn.focus();
                dropdown.classList.remove('hidden');
                return;
            }
            launchApp(card.dataset.app);
        });
    });

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
