<?php
// Authenticated dashboard page — included by index.php when a user is logged in.
// Expects in scope: $user, $apps, $showOnboarding, $hasDefaultPassword, $isCompanyAdmin
if (!isset($user) || !isset($apps)) { header('Location: ../index.php'); exit; }
require_once __DIR__ . '/../../app/core/Env.php';
Env::load(__DIR__ . '/../../.env');
$canUseOnelink = !empty($user['is_admin']) || !empty($isCompanyAdmin);
// Same early-access allowlist as tv/includes/functions.php's
// tv_gate_coming_soon() - kept as a small duplicate here rather than a
// cross-module include, since this dashboard and the tv/ app are
// independently deployable. Update both if the mechanism ever changes.
$_tvAllowlist = array_filter(array_map(
    static fn (string $e): string => strtolower(trim($e)),
    explode(',', (string)($_ENV['TV_COMING_SOON_ALLOWLIST'] ?? ''))
));
$_tvUserEmail = strtolower(trim((string)($user['email'] ?? '')));
$canUseTv = $_tvUserEmail !== '' && in_array($_tvUserEmail, $_tvAllowlist, true);
$tvBaseUrl = (static function (): string {
    $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'http://localhost/centryk/public'), '/');
    $appPath = (string)parse_url($appUrl, PHP_URL_PATH);
    if ($appPath !== '' && preg_match('#/public$#', $appPath) === 1) {
        return preg_replace('#/public$#', '/tv', $appUrl) ?? ($appUrl . '/tv');
    }
    return $appUrl . '/tv';
})();

// Public-facing TV entry: the teaser while TV is coming-soon for this viewer,
// the live "what's on" board otherwise. Powers the "Watch" chip on the card.
$tvWatchUrl = (Env::isProduction() && !$canUseTv) ? 'tv.php' : ($tvBaseUrl . '/');
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

        @keyframes centryk-logo-settle {
            0%   { opacity: 0; transform: translateY(-2px) scale(0.965); filter: saturate(0.92); }
            62%  { opacity: 1; transform: translateY(0) scale(1.018);  filter: saturate(1.03); }
            100% { opacity: 1; transform: translateY(0) scale(1);      filter: saturate(1); }
        }
        @keyframes centryk-logo-sheen {
            0%, 14% { opacity: 0; transform: translateX(-135%) skewX(-18deg); }
            32%     { opacity: 0.34; }
            100%    { opacity: 0; transform: translateX(165%) skewX(-18deg); }
        }
        .centryk-logo-lockup {
            position: relative;
            overflow: hidden;
        }
        .centryk-logo-lockup::after {
            content: "";
            position: absolute;
            inset: -10% auto -10% -35%;
            width: 32%;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.72) 50%, transparent 100%);
            opacity: 0;
            pointer-events: none;
            animation: centryk-logo-sheen 900ms cubic-bezier(0.22, 1, 0.36, 1) 420ms 1 both;
        }
        .centryk-logo-mark {
            transform-origin: center left;
            animation: centryk-logo-settle 520ms cubic-bezier(0.22, 1, 0.36, 1) 1 both;
        }
        @media (prefers-reduced-motion: reduce) {
            .centryk-logo-lockup::after,
            .centryk-logo-mark {
                animation: none !important;
            }
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
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-2.5">

        <!-- Logo -->
        <a href="index.php" class="centryk-logo-lockup flex shrink-0 items-center">
            <img src="assets/centryk_logo.png" alt="Centryk" class="centryk-logo-mark h-14 w-auto">
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

        <!-- Notifications (shared cross-app bell) -->
        <?php include __DIR__ . '/notification_bell.php'; ?>

        <!-- Calendar preview -->
        <?php include __DIR__ . '/calendar_preview.php'; ?>

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
                    <a href="business.php" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="briefcase" class="h-4 w-4 shrink-0"></i>
                        Centryk Business
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

<!-- Calendar drawer (wired to launchApp() above). Kept outside <header> — that
     element's backdrop-blur makes it a containing block for position:fixed
     descendants, which trapped the drawer inside it. -->
<?php include __DIR__ . '/calendar_drawer.php'; ?>

<!-- Main -->
<main class="mx-auto max-w-6xl px-6 pt-1 pb-5">

    <!-- Company profile card -->
    <div id="coProfileCard" style="--i:0" class="dash-fade mb-1 overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 to-white shadow-sm">

        <!-- Empty state (no company selected) -->
        <div id="coCardEmpty" class="relative px-6 py-4">
            <h1 class="text-2xl font-black tracking-tight text-slate-900">
                Welcome back, <?= htmlspecialchars($user['first_name']) ?>
            </h1>
            <p id="companyContext" class="mt-1 text-sm font-semibold text-slate-400">Select a company above, then open an app.</p>
        </div>

        <!-- Filled state (company selected) -->
        <div id="coCardFilled" class="hidden">
            <!-- Main row -->
            <div id="coIdentityBanner" class="relative overflow-hidden bg-transparent">
                <div id="coBannerPreview" class="absolute inset-0 hidden bg-cover bg-center"></div>
                <div id="coBannerWash" class="absolute inset-0 hidden bg-transparent"></div>
                <div class="relative flex flex-col md:min-h-40 md:flex-row md:items-stretch">

                <!-- Avatar -->
                <div id="coAvatar" class="flex h-32 w-full shrink-0 items-center justify-center overflow-hidden border-b border-slate-200 bg-white/80 text-5xl font-black text-slate-700 select-none md:h-auto md:w-44 md:border-b-0 md:border-r">?</div>

                <!-- Name + context -->
                <div class="flex min-w-0 flex-1 flex-col justify-center p-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span id="coName" class="text-xl font-black tracking-tight text-slate-900 truncate">—</span>
                        <span id="coRoleBadge" class="rounded-full bg-white/55 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] ring-1 ring-white/60">—</span>
                    </div>
                    <p class="mt-0.5 text-sm font-bold text-slate-700">
                        Welcome back, <?= htmlspecialchars($user['first_name']) ?>
                    </p>

                    <!-- Actions -->
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button id="coInviteBtn" type="button"
                       class="flex items-center gap-1.5 rounded-xl border border-white/60 bg-white/50 px-3 py-2 text-xs font-black text-slate-700 backdrop-blur-sm transition hover:bg-white/75 hover:border-white">
                        <i data-lucide="user-plus" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">Invite Member</span>
                        <span class="rounded-full bg-white/55 px-1.5 py-0.5 text-[10px] text-slate-600 ring-1 ring-white/60">
                            <span id="coMemberCount">0</span> members
                        </span>
                    </button>
                    <a id="coMemberLink" href="profile.php#companies"
                       class="flex items-center gap-1.5 rounded-xl border border-white/60 bg-white/50 px-3 py-2 text-xs font-black text-slate-700 backdrop-blur-sm transition hover:bg-white/75 hover:border-white hover:text-slate-950">
                        <i data-lucide="building-2" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">Manage Company Profile</span>
                    </a>
                    <a id="coFinishProfileBtn" href="onboarding.php?resume=profile"
                       class="hidden items-center gap-1.5 rounded-xl border border-violet-300 bg-violet-100/80 px-3 py-2 text-xs font-black text-violet-800 backdrop-blur-sm transition hover:bg-violet-200/80 hover:border-violet-400">
                        <i data-lucide="clipboard-check" class="h-3.5 w-3.5"></i>
                        <span>Finish company profile</span>
                    </a>
                    <?php if ($canUseOnelink): ?>
                    <a id="coOnelinkPaymentsBtn" href="onelink-payments.php"
                       class="flex items-center gap-1.5 rounded-xl border border-white/60 bg-white/50 px-3 py-2 text-xs font-black text-cyan-800 backdrop-blur-sm transition hover:bg-white/75 hover:border-white">
                        <i data-lucide="credit-card" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">OneLink Payments</span>
                    </a>
                    <?php endif; ?>
                    <a id="coAdvertiseBtn" href="sell.php"
                       class="hidden items-center gap-1.5 rounded-xl border border-white/60 bg-white/50 px-3 py-2 text-xs font-black text-violet-800 backdrop-blur-sm transition hover:bg-white/75 hover:border-white">
                        <i data-lucide="share-2" class="h-3.5 w-3.5"></i>
                        <span class="hidden sm:inline">Sell on Store</span>
                    </a>
                    </div>
                </div>
                </div>
            </div>

            <!-- Setup progress (hidden once complete) -->
            <div id="setupProgressWrap" class="hidden border-t border-slate-100 px-6 py-3.5">
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Getting Started</p>
                    <p id="setupProgressLabel" class="text-[10px] font-bold text-slate-400">0 of 4 complete</p>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div id="setupProgressBar" class="h-1.5 rounded-full bg-violet-500 transition-all duration-500" style="width:0%"></div>
                </div>
                <div class="mt-2.5 flex flex-wrap items-center gap-x-5 gap-y-1">
                    <span id="setupStep1" class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-400">
                        <span class="h-2 w-2 rounded-full bg-slate-200"></span>Company created
                    </span>
                    <a id="setupStep2" href="onboarding.php?resume=profile" class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-400 hover:text-violet-600">
                        <span class="h-2 w-2 rounded-full bg-slate-200"></span>Set up company profile
                    </a>
                    <span id="setupStep3" class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-400">
                        <span class="h-2 w-2 rounded-full bg-slate-200"></span>Team added
                    </span>
                    <span id="setupStep4" class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-400">
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
    $_comingSoonAppKeys = Env::isProduction() ? ['tv' => true] : [];

    // Apps whose every state is handled by a dedicated hand-built card further
    // down, so they never render through the DB-driven loop.
    $_gridSkipKeys = ['tv' => true];

    $_enrolledAppCount = 0;
    foreach ($apps as $_app) {
        $_k = (string)($_app['key'] ?? '');
        if (isset($_comingSoonAppKeys[$_k]) || isset($_gridSkipKeys[$_k])) {
            continue;
        }
        if (!empty($_app['enrolled'])) {
            $_enrolledAppCount++;
        }
    }

    // ── Group apps into categories ─────────────────────────────────────────
    // Section render order follows the key order here.
    $_catLabels = [
        'business'  => 'Business',
        'finance'   => 'Finance',
        'marketing' => 'Marketing',
        'insights'  => 'Insights',
    ];
    $_catApps = ['business' => [], 'finance' => [], 'marketing' => [], 'insights' => []];
    foreach ($apps as $_app) {
        $_k = (string)($_app['key'] ?? '');
        if ($_k === '' || isset($_comingSoonAppKeys[$_k]) || isset($_gridSkipKeys[$_k])) {
            continue;
        }
        $_cat = (string)($_app['category'] ?? 'business');
        if (!isset($_catApps[$_cat])) {
            $_cat = 'business';
        }
        $_catApps[$_cat][] = $_app;
    }
    // Within a section: enrolled first, then opt-in, then locked.
    foreach ($_catApps as &$_bucket) {
        usort($_bucket, static function ($a, $b) {
            $rank = static fn ($x) => !empty($x['enrolled']) ? 0 : (!empty($x['opt_in']) ? 1 : 2);
            return $rank($a) <=> $rank($b);
        });
    }
    unset($_bucket);

    // Drives the dash-fade entrance stagger, one step per card.
    $_gridIdx = 0;

    // Categories still drive render order (business → finance → insights →
    // marketing) and the drag-to-reorder scope, but there is no visual divider:
    // the grid flows up to 5-up continuously so a row can hold cards from two
    // categories instead of leaving a short row wherever a category ends.
    // TODO (Javan): wants the category headers back without the stubby rows.
    $renderCatHeader = static function (string $label): void {};

    // One DB-backed app card. $cat is stamped on the element so the
    // drag-to-reorder JS keeps a card within its own section.
    $renderAppCard = function (array $app, string $cat) use (&$_gridIdx) {
        $_gridIdx++;
        $enrolled = !empty($app['enrolled']);
        $optIn    = !empty($app['opt_in']);
        ?>
        <button style="--i:<?= $_gridIdx ?>" class="dash-fade app-card group flex flex-col overflow-hidden rounded-2xl border text-left shadow-sm transition
                    <?= $enrolled
                        ? 'border-slate-200 bg-white hover:shadow-md hover:-translate-y-0.5 active:scale-[0.98] disabled:opacity-40 disabled:cursor-not-allowed disabled:translate-y-0 disabled:shadow-sm'
                        : ($optIn
                            ? 'border-dashed border-slate-300 bg-white hover:shadow-md hover:-translate-y-0.5 active:scale-[0.98]'
                            : 'border-slate-200/50 bg-slate-50 opacity-50 cursor-not-allowed') ?>"
                data-app="<?= htmlspecialchars($app['key']) ?>"
                data-category="<?= htmlspecialchars($cat) ?>"
                data-enrolled="<?= $enrolled ? '1' : '0' ?>"
                data-opt-in="<?= $optIn ? '1' : '0' ?>"
                <?= $enrolled ? 'draggable="true"' : '' ?>
                <?= ($enrolled || $optIn) ? '' : 'disabled' ?>>
            <div class="h-1.5 w-full" style="background:<?= htmlspecialchars($app['color']) . ($enrolled ? '' : ';opacity:.4') ?>"></div>
            <div class="flex flex-1 flex-col p-3">
                <div class="flex items-center gap-3">
                    <?php if ($app['key'] === 'onepay'): ?>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-purple-50 p-1.5 shadow-sm ring-1 ring-purple-100">
                        <img src="assets/onepay_logo.png" alt="OnePay" class="h-full w-full object-contain">
                    </span>
                    <?php elseif ($app['key'] === 'mypay'): ?>
                    <img src="assets/myPay.png" alt="MyPay" class="h-10 w-10 rounded-xl object-contain shadow-sm">
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
                    <?php elseif ($app['key'] === 'visionboard'): ?>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <rect x="2" y="3" width="20" height="14" rx="2"/><path d="m10 8 5 3-5 3V8Z"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                    </span>
                    <?php elseif ($app['key'] === 'tv'): ?>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-cyan-100 text-cyan-700 shadow-sm ring-1 ring-cyan-200">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                            <rect x="2" y="5" width="20" height="14" rx="2"/><path d="m10 9 5 3-5 3V9Z"/><path d="M8 21h8"/>
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
                            if ($app['key'] === 'onepay')           echo 'Inventory &amp; POS';
                            elseif ($app['key'] === 'mypay')        echo 'HR &amp; Payroll';
                            elseif ($app['key'] === 'invoice')      echo 'Quotes &amp; Invoicing';
                            elseif ($app['key'] === 'visionboard')  echo 'Digital Signage';
                            elseif ($app['key'] === 'tv')           echo 'Live Streaming';
                            else                                    echo htmlspecialchars($app['label']);
                            ?>
                        </div>
                        <div class="text-base font-black tracking-tight text-slate-900"><?= htmlspecialchars($app['label']) ?></div>
                    </div>
                </div>
                <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-500">
                    <?= htmlspecialchars($app['description']) ?>
                </p>
                <?php
                // MyPay carries a public job board — link straight to it, separate
                // from the SSO launch the rest of the card triggers. A <span> (not
                // <a>) because this sits inside a <button>; only on an active card
                // because a disabled <button> swallows clicks on its children.
                if ($app['key'] === 'mypay' && ($enrolled || $optIn)):
                    $_mpRaw  = Env::isProduction()
                        ? (($app['url_production'] ?? '') ?: ($app['url_local'] ?? ''))
                        : (($app['url_local'] ?? '') ?: ($app['url_production'] ?? ''));
                    $_mpBase = rtrim((string)preg_replace('#/[^/]*\.php$#', '', rtrim((string)$_mpRaw, '/')), '/');
                    if ($_mpBase !== ''):
                ?>
                <span role="link" tabindex="0"
                      class="mypay-board-link mt-2.5 inline-flex cursor-pointer items-center gap-1 self-start rounded-full border border-orange-200 bg-orange-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-orange-700 transition hover:bg-orange-100"
                      data-board-url="<?= htmlspecialchars($_mpBase . '/views/careers/index.php') ?>">
                    <i data-lucide="briefcase" class="h-3 w-3"></i> Job Board
                </span>
                <?php endif; endif; ?>
                <?php if ($enrolled): ?>
                <div id="app-count-<?= htmlspecialchars($app['key']) ?>" class="app-count-badge mt-2.5 flex items-center gap-1.5">
                    <span class="app-count-dot inline-block h-1.5 w-1.5 rounded-full bg-slate-300"></span>
                    <span class="app-count-num text-[11px] font-bold text-slate-400">0 active users</span>
                </div>
                <?php elseif ($optIn): ?>
                <div class="mt-2.5 flex items-center gap-1.5">
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
                <span class="flex items-center gap-2">
                    <i data-lucide="grip-vertical" class="app-drag-handle h-3.5 w-3.5 cursor-grab text-slate-300 transition-colors group-hover:text-slate-500"></i>
                    <i data-lucide="arrow-right" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"></i>
                </span>
            </div>
            <?php elseif ($optIn): ?>
            <div class="flex items-center justify-between border-t border-dashed border-slate-200 px-4 py-3 text-xs font-bold text-slate-600 transition-colors group-hover:text-slate-900">
                <span>Enable <?= htmlspecialchars($app['label']) ?></span>
                <i data-lucide="plus" class="h-3.5 w-3.5"></i>
            </div>
            <?php endif; ?>
        </button>
        <?php }; // end $renderAppCard ?>

        <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm md:p-5">

        <?php if ($_enrolledAppCount === 0): ?>
        <div class="mb-4 rounded-2xl border border-slate-200 bg-slate-50 px-5 py-4 text-sm font-semibold text-slate-500">
            You are not enrolled in any apps yet. Available apps are listed below.
        </div>
        <?php endif; ?>

        <!-- Apps grid — grouped into category sections. DB-backed cards flow
             through $renderAppCard; the hand-built cards (OneLink, TV, Store,
             Case Management) are slotted into the matching section by hand. -->
        <div id="appsGrid" class="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">

            <?php
            // ── Business ───────────────────────────────────────────────────
            $renderCatHeader($_catLabels['business']);
            foreach ($_catApps['business'] as $_a) { $renderAppCard($_a, 'business'); }
            ?>
            <!-- Case Management — coming soon (static, not in DB) -->
            <div style="--i:<?= ++$_gridIdx ?>" class="dash-fade flex flex-col overflow-hidden rounded-2xl border border-blue-200/70 bg-blue-50/40 text-left shadow-sm opacity-75 cursor-not-allowed select-none">
                <div class="h-1.5 w-full bg-blue-500/50"></div>
                <div class="flex flex-1 flex-col p-3">
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
                    <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-500">
                        Track and resolve cases across your team — from intake to outcome — all in one place.
                    </p>
                    <div class="mt-4 flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-[0.14em] bg-blue-100 text-blue-700 border border-blue-200">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z"/></svg>
                        Coming Soon
                    </div>
                </div>
            </div>

            <?php
            // ── Finance & Insights ─────────────────────────────────────────
            // Kept as distinct categories in the DB and on each card
            // (data-category), but shown under one heading while each holds
            // only a card or two. Split back out when either fills up.
            $renderCatHeader('Finance & Insights');
            ?>
            <?php if ($canUseOnelink): ?>
            <button type="button" style="--i:<?= ++$_gridIdx ?>" id="onelinkPaymentsCard"
                    class="dash-fade group flex flex-col overflow-hidden rounded-2xl border border-cyan-200 bg-white text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md active:scale-[0.98]">
                <div class="h-1.5 w-full bg-cyan-500"></div>
            <div class="flex flex-1 flex-col p-3">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-cyan-100 text-cyan-700">
                        <i data-lucide="credit-card" class="h-5 w-5"></i>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-600/80">Collections</div>
                        <div class="text-base font-black tracking-tight text-slate-900">OneLink Payments</div>
                    </div>
                </div>
                <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-500">
                    View POS, invoice, and payment-form collections for the selected company.
                </p>
                <div class="mt-2.5 flex items-center gap-1.5">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-cyan-500"></span>
                    <span class="text-[11px] font-bold text-cyan-700">Company-scoped ledger</span>
                </div>
            </div>
            <div class="flex items-center justify-between border-t border-cyan-100 px-4 py-3 text-xs font-bold text-cyan-700 transition-colors group-hover:text-cyan-900">
                <span>View payments</span>
                <i data-lucide="arrow-right" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"></i>
            </div>
        </button>
        <?php else: ?>
        <!-- OneLink Payments — coming soon for users without company/platform admin access -->
        <div style="--i:<?= ++$_gridIdx ?>" class="dash-fade flex flex-col overflow-hidden rounded-2xl border border-cyan-200/70 bg-cyan-50/40 text-left shadow-sm opacity-75 cursor-not-allowed select-none">
            <div class="h-1.5 w-full bg-cyan-500/50"></div>
            <div class="flex flex-1 flex-col p-3">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-cyan-100 text-cyan-700">
                        <i data-lucide="credit-card" class="h-5 w-5"></i>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-600/80">Collections</div>
                        <div class="text-base font-black tracking-tight text-slate-800">OneLink Payments</div>
                    </div>
                </div>
                <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-500">
                    Track POS, invoice, and payment-form collections once live payment data is available.
                </p>
                <div class="mt-4 flex items-center justify-center gap-1.5 rounded-xl border border-cyan-200 bg-cyan-100 px-3 py-2 text-[10px] font-black uppercase tracking-[0.14em] text-cyan-700">
                    <i data-lucide="clock-3" class="h-3 w-3"></i>
                    Coming Soon
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php
        // ── Centryk Business modules ───────────────────────────────────────
        // Hidden by default; selectCompany() reveals the ones the chosen
        // company holds (its own grant or one inherited from its group) when
        // the viewer is an admin/manager of it.
        $_bizCards = [
            ['key' => 'receivables',    'href' => 'receivables.php',    'param' => 'company_id', 'label' => 'Receivables',         'icon' => 'wallet',     'blurb' => 'Customer ledger, balances and aging for this company.'],
            ['key' => 'reconciliation', 'href' => 'reconciliation.php', 'param' => 'company_id', 'label' => 'Reconciliation',      'icon' => 'scale',      'blurb' => 'Match bank deposits to open customer invoices.'],
            ['key' => 'routes',         'href' => 'routes.php',         'param' => 'company_id', 'label' => 'Field Sales & Routes', 'icon' => 'truck',      'blurb' => 'Delivery runs and end-of-day driver cash settlement.'],
            ['key' => 'enterprise',     'href' => 'groups.php',         'param' => '',          'label' => 'Company Groups',       'icon' => 'building-2', 'blurb' => "A consolidated view across the group's companies."],
        ];
        foreach ($_bizCards as $_bc): ?>
        <a href="<?= htmlspecialchars($_bc['href']) ?>" data-biz-card="<?= htmlspecialchars($_bc['key']) ?>" data-biz-param="<?= htmlspecialchars($_bc['param']) ?>"
           style="--i:<?= $_gridIdx ?>"
           class="biz-module-card dash-fade group hidden flex-col overflow-hidden rounded-2xl border border-violet-200 bg-white text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md active:scale-[0.98]">
            <div class="h-1.5 w-full bg-violet-500"></div>
            <div class="flex flex-1 flex-col p-3">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-700">
                        <i data-lucide="<?= htmlspecialchars($_bc['icon']) ?>" class="h-5 w-5"></i>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-600/80">Centryk Business</div>
                        <div class="text-base font-black tracking-tight text-slate-900"><?= htmlspecialchars($_bc['label']) ?></div>
                    </div>
                </div>
                <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-500"><?= htmlspecialchars($_bc['blurb']) ?></p>
                <div class="mt-2.5 flex items-center gap-1.5">
                    <span data-biz-dot class="inline-block h-1.5 w-1.5 rounded-full bg-violet-500"></span>
                    <span data-biz-state class="text-[11px] font-bold text-violet-700">Active</span>
                </div>
                <div data-biz-metric class="mt-1.5 hidden text-[11px] font-semibold text-slate-500"></div>
            </div>
            <div class="flex items-center justify-between border-t border-violet-100 px-4 py-3 text-xs font-bold text-violet-700 transition-colors group-hover:text-violet-900">
                <span>Open</span>
                <i data-lucide="arrow-right" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"></i>
            </div>
        </a>
        <?php endforeach; ?>

        <?php
        // Remaining Finance + Insights cards share the heading above.
        foreach ($_catApps['finance'] as $_a)  { $renderAppCard($_a, 'finance'); }
        foreach ($_catApps['insights'] as $_a) { $renderAppCard($_a, 'insights'); }
        ?>

        <?php
        // ── Marketing ──────────────────────────────────────────────────────
        $renderCatHeader($_catLabels['marketing']);
        foreach ($_catApps['marketing'] as $_a) { $renderAppCard($_a, 'marketing'); }
        ?>
        <?php if (Env::isProduction() && !$canUseTv): ?>
        <!-- Centryk TV — still "Coming Soon" for everyone not on the early-access
             allowlist, but clickable: lands on tv.php's teaser/pitch page instead
             of dead-ending, so interest can build ahead of the real rollout. -->
        <a href="tv.php" style="--i:<?= ++$_gridIdx ?>" class="dash-fade group flex flex-col overflow-hidden rounded-2xl border border-teal-200/70 bg-teal-50/40 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md hover:bg-teal-50 active:scale-[0.98]">
            <div class="h-1.5 w-full" style="background:#0f766e80"></div>
            <div class="flex flex-1 flex-col p-3">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-100 text-teal-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="5" width="20" height="14" rx="2"/><path d="m10 9 5 3-5 3V9Z"/><path d="M8 21h8"/>
                        </svg>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-teal-600/80">Live Streaming</div>
                        <div class="text-base font-black tracking-tight text-slate-800">Centryk TV</div>
                    </div>
                </div>
                <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-500">
                    Watch live broadcasts and replays from participating organizations.
                </p>
                <span role="link" tabindex="0"
                      class="tv-watch-link mt-2.5 inline-flex cursor-pointer items-center gap-1 self-start rounded-full border border-teal-200 bg-teal-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-teal-700 transition hover:bg-teal-100"
                      data-watch-url="<?= htmlspecialchars($tvWatchUrl) ?>">
                    <i data-lucide="play-circle" class="h-3 w-3"></i> Watch
                </span>
                <div class="mt-4 flex items-center justify-center gap-1.5 rounded-xl border border-teal-200 bg-teal-100 px-3 py-2 text-[10px] font-black uppercase tracking-[0.14em] text-teal-700 transition-colors group-hover:bg-teal-200">
                    <i data-lucide="clock-3" class="h-3 w-3"></i>
                    Coming Soon
                </div>
            </div>
        </a>
        <?php else: ?>
        <!-- Centryk TV — real link: either not production, or this viewer is on the early-access allowlist -->
        <a href="<?= htmlspecialchars($tvBaseUrl) ?>/" style="--i:<?= ++$_gridIdx ?>"
           class="dash-fade group flex flex-col overflow-hidden rounded-2xl border border-teal-200 bg-white text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md active:scale-[0.98]">
            <div class="h-1.5 w-full bg-teal-600"></div>
            <div class="flex flex-1 flex-col p-3">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-100 text-teal-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="5" width="20" height="14" rx="2"/><path d="m10 9 5 3-5 3V9Z"/><path d="M8 21h8"/>
                        </svg>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-teal-600/80">Live Streaming</div>
                        <div class="text-base font-black tracking-tight text-slate-900">Centryk TV</div>
                    </div>
                </div>
                <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-500">
                    Watch live broadcasts and replays from participating organizations.
                </p>
                <span role="link" tabindex="0"
                      class="tv-watch-link mt-2.5 inline-flex cursor-pointer items-center gap-1 self-start rounded-full border border-teal-200 bg-teal-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-teal-700 transition hover:bg-teal-100"
                      data-watch-url="<?= htmlspecialchars($tvWatchUrl) ?>">
                    <i data-lucide="play-circle" class="h-3 w-3"></i> Watch
                </span>
            </div>
            <div class="flex items-center justify-between border-t border-teal-100 px-4 py-3 text-xs font-bold text-teal-700 transition-colors group-hover:text-teal-900">
                <span>Open Centryk TV</span>
                <i data-lucide="arrow-right" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"></i>
            </div>
        </a>
        <?php endif; ?>

        <!-- Store -->
        <button type="button" style="--i:<?= ++$_gridIdx ?>" id="storeCard" class="dash-fade group flex flex-col overflow-hidden rounded-2xl border border-violet-200 bg-white text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md active:scale-[0.98]">
            <div class="h-1.5 w-full bg-violet-500"></div>
            <div class="flex flex-1 flex-col p-3">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-700">
                        <i data-lucide="store" class="h-5 w-5"></i>
                    </span>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-600/80">Marketplace</div>
                        <div class="text-base font-black tracking-tight text-slate-800">Store</div>
                    </div>
                </div>
                <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-500">
                    Browse employee offers and Centryk Market listings from participating companies.
                </p>
                <?php
                // The card opens this company's own storefront; this chip jumps
                // straight to the public marketplace feed (all companies). A
                // <span>, not <a>, because it sits inside the card's <button>.
                ?>
                <span role="link" tabindex="0"
                      class="store-feed-link mt-2.5 inline-flex cursor-pointer items-center gap-1 self-start rounded-full border border-violet-200 bg-violet-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-violet-700 transition hover:bg-violet-100"
                      data-store-feed-url="store.php">
                    <i data-lucide="layout-grid" class="h-3 w-3"></i> Browse all stores
                </span>
                <div class="mt-2.5 flex items-center gap-1.5">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-violet-500"></span>
                    <span class="text-[11px] font-bold text-violet-700">Company listings</span>
                </div>
            </div>
            <div class="flex items-center justify-between border-t border-violet-100 px-4 py-3 text-xs font-bold text-violet-700 transition-colors group-hover:text-violet-900">
                <span>Open Store</span>
                <i data-lucide="arrow-right" class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"></i>
            </div>
        </button>

    </div>

    <!-- Centryk Business note — a quiet line under the apps, revealed by
         selectCompany() only for an admin/manager of the selected company that
         holds no Business package yet. Dismissible per browser. -->
    <div id="bizPromo" class="mt-5 hidden items-center gap-2 border-t border-slate-100 px-1 pt-4 text-xs font-semibold text-slate-400">
        <i data-lucide="briefcase" class="h-3.5 w-3.5 shrink-0"></i>
        <span class="min-w-0 flex-1">
            Need receivables, bank reconciliation, delivery routes or multi-company reporting?
            <a href="business.php" class="font-bold text-slate-600 underline decoration-slate-300 underline-offset-2 hover:text-violet-700 hover:decoration-violet-400">See Centryk Business</a>.
        </span>
        <button type="button" id="bizPromoDismiss" title="Dismiss" class="shrink-0 rounded p-1 text-slate-300 transition hover:text-slate-500">
            <i data-lucide="x" class="h-3.5 w-3.5"></i>
        </button>
    </div>

    <!-- No-company notice (shown when no companies exist) -->
    <div id="noCompanyNotice" class="hidden mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
        You're not part of any company yet. Contact your admin to be added.
    </div>
    </section>


</main>
<?php include __DIR__ . '/business_directory.php'; ?>
</div>

<?php include __DIR__ . '/footer_app.php'; ?>

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
    var appsGrid     = document.getElementById('appsGrid');
    var draggingAppCard = null;
    var appOrderChanged = false;
    var suppressAppClick = false;
    var canManageAllCompanies = <?= !empty($user['is_admin']) ? 'true' : 'false' ?>;

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

    function orderedActiveAppCards() {
        if (!appsGrid) { return []; }
        return Array.prototype.slice.call(appsGrid.querySelectorAll('.app-card[data-enrolled="1"]'));
    }

    function appOrderPayload() {
        return orderedActiveAppCards().map(function (card) { return card.dataset.app; });
    }

    function setAppOrderingEnabled(enabled) {
        orderedActiveAppCards().forEach(function (card) {
            if (enabled) {
                card.setAttribute('draggable', 'true');
            } else {
                card.removeAttribute('draggable');
            }
            card.querySelectorAll('.app-drag-handle').forEach(function (handle) {
                handle.classList.toggle('hidden', !enabled);
            });
        });
    }

    // Re-apply a saved drag order. The grid is grouped into category sections,
    // so cards are only reordered among their own section peers (same
    // data-category) — that keeps every card inside its section.
    function applyAppOrder(order) {
        if (!appsGrid || !Array.isArray(order) || !order.length) { return; }
        var rank = {};
        order.forEach(function (key, i) { rank[key] = i; });

        var byCat = {};
        orderedActiveAppCards().forEach(function (card) {
            var cat = card.dataset.category || '';
            (byCat[cat] = byCat[cat] || []).push(card);
        });

        Object.keys(byCat).forEach(function (cat) {
            var group = byCat[cat];
            // Anchor = whatever currently follows the group's last card. Every
            // card is re-inserted just before it, in saved-rank order, so the
            // group stays contiguous and in place.
            var anchor = group[group.length - 1].nextSibling;
            group.sort(function (a, b) {
                var ra = (a.dataset.app in rank) ? rank[a.dataset.app] : Infinity;
                var rb = (b.dataset.app in rank) ? rank[b.dataset.app] : Infinity;
                return ra - rb;
            });
            group.forEach(function (card) { appsGrid.insertBefore(card, anchor); });
        });
    }

    function loadCompanyAppOrder() {
        if (!selectedId) { return; }
        fetch('api/apps/order.php?company_id=' + encodeURIComponent(selectedId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    applyAppOrder(data.order || []);
                }
            })
            .catch(function () {});
    }

    function saveCompanyAppOrder() {
        if (!selectedId) { return; }
        fetch('api/apps/order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ company_id: selectedId, order: appOrderPayload() })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                showToast(data.message || 'Could not save app order.', 'error');
            }
        })
        .catch(function () {
            showToast('Could not save app order.', 'error');
        });
    }

    // Pull the one-line health number for each entitled Business card.
    function loadBizSnapshot(companyId) {
        var money = function (v) {
            return 'BZD ' + (Number(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        };
        var set = function (key, text, tone) {
            var card = document.querySelector('.biz-module-card[data-biz-card="' + key + '"]');
            if (!card) { return; }
            var el = card.querySelector('[data-biz-metric]');
            if (!el) { return; }
            if (!text) { el.classList.add('hidden'); el.textContent = ''; return; }
            el.textContent = text;
            el.className = 'mt-1.5 text-[11px] font-semibold ' + (tone || 'text-slate-500');
        };
        fetch('api/business/company_snapshot.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ company_id: companyId })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) { return; }
            if (companyId != selectedId) { return; }   // switched away mid-flight
            var d = res;

            if (d.receivables) {
                var ar = d.receivables;
                set('receivables',
                    ar.overdue > 0.004
                        ? money(ar.overdue) + ' overdue of ' + money(ar.outstanding)
                        : money(ar.outstanding) + ' outstanding',
                    ar.overdue > 0.004 ? 'text-rose-600' : 'text-slate-500');
            }
            if (d.reconciliation) {
                var rc = d.reconciliation;
                set('reconciliation',
                    rc.unmatched_credits > 0
                        ? rc.unmatched_credits + ' unmatched · ' + money(rc.unmatched_value)
                        : 'All deposits matched',
                    rc.unmatched_credits > 0 ? 'text-amber-600' : 'text-slate-500');
            }
            if (d.routes) {
                var rt = d.routes;
                set('routes',
                    rt.awaiting_approval > 0
                        ? rt.awaiting_approval + ' awaiting approval'
                        : (rt.out > 0 ? rt.out + ' on the road · ' + money(rt.cash_in_transit) + ' in transit' : 'No active runs'),
                    rt.awaiting_approval > 0 ? 'text-amber-600' : 'text-slate-500');
            }
        })
        .catch(function () {});
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
            setAppOrderingEnabled(canManageAllCompanies || c.role === 'admin');
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

            var coBannerPreview = document.getElementById('coBannerPreview');
            var coBannerWash = document.getElementById('coBannerWash');
            var coTheme = String(c.store_theme || '');
            var hasTheme = /^assets\/store_theme\/[a-z0-9][a-z0-9_-]{1,80}\.(png|jpe?g|webp)$/i.test(coTheme);
            // Companies that haven't picked a banner yet still get one — the
            // default Centryk-branded theme — instead of a blank strip.
            var bannerSrc = hasTheme ? coTheme : 'assets/store_theme/default01.png';
            if (coBannerPreview) {
                coBannerPreview.style.backgroundImage = "url('" + bannerSrc.replace(/'/g, "\\'") + "')";
                coBannerPreview.classList.remove('hidden');
            }
            if (coBannerWash) {
                coBannerWash.classList.remove('hidden');
            }

            var coAvatar = document.getElementById('coAvatar');
            if (coAvatar) {
                coAvatar.innerHTML = '';
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
                    coImg.className = 'h-full w-full object-contain';
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
                coRoleBadge.style.background = 'rgba(255,255,255,0.5)';
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

            // ── Centryk Business module cards ─────────────────────────────
            var bizRole = ['owner', 'admin', 'manager'].indexOf(String(c.role || '').toLowerCase()) !== -1;
            var bizEnts = (c.entitlements && typeof c.entitlements === 'object') ? c.entitlements : {};
            document.querySelectorAll('.biz-module-card').forEach(function (card) {
                var key = card.getAttribute('data-biz-card');
                var lvl = bizEnts[key];                 // 'full' | 'read' | undefined
                var show = bizRole && !!lvl;
                card.classList.toggle('hidden', !show);
                card.classList.toggle('flex', show);
                if (!show) { return; }

                var base  = card.getAttribute('href').split('?')[0];
                var param = card.getAttribute('data-biz-param');
                card.setAttribute('href', base + (param ? ('?' + param + '=' + encodeURIComponent(selectedId)) : ''));

                var st  = card.querySelector('[data-biz-state]');
                var dot = card.querySelector('[data-biz-dot]');
                if (lvl === 'read') {
                    if (st)  { st.textContent = 'Paused'; st.className = 'text-[11px] font-bold text-amber-600'; }
                    if (dot) { dot.className = 'inline-block h-1.5 w-1.5 rounded-full bg-amber-500'; }
                } else {
                    if (st)  { st.textContent = 'Active'; st.className = 'text-[11px] font-bold text-violet-700'; }
                    if (dot) { dot.className = 'inline-block h-1.5 w-1.5 rounded-full bg-violet-500'; }
                }
            });

            // One-line health numbers per entitled card. One call per switch.
            if (bizRole && Object.keys(bizEnts).length > 0) {
                loadBizSnapshot(selectedId);
            } else {
                document.querySelectorAll('[data-biz-metric]').forEach(function (el) {
                    el.classList.add('hidden'); el.textContent = '';
                });
            }

            // Promo strip — only for an admin/manager of a company with no package.
            var bizPromo = document.getElementById('bizPromo');
            if (bizPromo) {
                var promoDismissed = false;
                try { promoDismissed = localStorage.getItem('centryk_bizpromo_dismissed') === '1'; } catch (e) {}
                var showPromo = bizRole && Object.keys(bizEnts).length === 0 && !promoDismissed;
                bizPromo.classList.toggle('hidden', !showPromo);
                bizPromo.classList.toggle('flex', showPromo);
            }
            if (window.lucide) { lucide.createIcons(); }

            var advertiseUrl = 'sell.php' + (selectedUuid ? ('?company_uuid=' + encodeURIComponent(selectedUuid)) : '');
            var coAdvertiseBtn = document.getElementById('coAdvertiseBtn');
            if (coAdvertiseBtn) {
                var canAdvertise = ['owner', 'admin', 'manager'].indexOf(String(c.role || '').toLowerCase()) !== -1;
                coAdvertiseBtn.href = advertiseUrl;
                coAdvertiseBtn.classList.toggle('hidden', !canAdvertise);
                coAdvertiseBtn.classList.toggle('flex', canAdvertise);
            }

            // "Finish company profile" — shown to admins/owners while phone,
            // email or address is still blank. Points at the friendly
            // profile-only wizard step for the company being viewed.
            var profileComplete = !!(String(c.phone || '').trim() && String(c.email || '').trim() && String(c.address || '').trim());
            var resumeUrl = 'onboarding.php?resume=profile' + (selectedUuid ? ('&company=' + encodeURIComponent(selectedUuid)) : '');
            var coFinishProfileBtn = document.getElementById('coFinishProfileBtn');
            if (coFinishProfileBtn) {
                var canFinishProfile = ['owner', 'admin'].indexOf(String(c.role || '').toLowerCase()) !== -1 && !profileComplete;
                coFinishProfileBtn.href = resumeUrl;
                coFinishProfileBtn.classList.toggle('hidden', !canFinishProfile);
                coFinishProfileBtn.classList.toggle('flex', canFinishProfile);
            }
            var setupStep2Link = document.getElementById('setupStep2');
            if (setupStep2Link) {
                setupStep2Link.href = resumeUrl;
                setupStep2Link.classList.toggle('pointer-events-none', profileComplete);
            }

            // ── Setup progress ────────────────────────────────────────────
            var enrolledCount = document.querySelectorAll('.app-card[data-enrolled="1"]').length;
            var step1Done = true;
            var step2Done = profileComplete;
            var step3Done = n > 1;
            var step4Done = enrolledCount > 0;
            var stepsComplete = (step1Done ? 1 : 0) + (step2Done ? 1 : 0) + (step3Done ? 1 : 0) + (step4Done ? 1 : 0);

            var progressWrap = document.getElementById('setupProgressWrap');
            if (progressWrap) {
                if (stepsComplete < 4) {
                    progressWrap.classList.remove('hidden');
                    var pct = Math.round((stepsComplete / 4) * 100);
                    var bar = document.getElementById('setupProgressBar');
                    var lbl = document.getElementById('setupProgressLabel');
                    if (bar) { bar.style.width = pct + '%'; }
                    if (lbl) { lbl.textContent = stepsComplete + ' of 4 complete'; }

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
                    markStep('setupStep4', step4Done);
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
        loadCompanyAppOrder();
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
            setAppOrderingEnabled(false);
            var coBannerPreview = document.getElementById('coBannerPreview');
            var coBannerWash = document.getElementById('coBannerWash');
            if (coBannerPreview) {
                coBannerPreview.style.backgroundImage = '';
                coBannerPreview.classList.add('hidden');
            }
            if (coBannerWash) {
                coBannerWash.classList.add('hidden');
            }
            var coAdvertiseBtn = document.getElementById('coAdvertiseBtn');
            if (coAdvertiseBtn) {
                coAdvertiseBtn.classList.add('hidden');
                coAdvertiseBtn.classList.remove('flex');
            }
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

    var appLabels = { onepay: 'OnePay', mypay: 'MyPay', tv: 'Centryk TV', centryk: 'Centryk' };
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
    function openStore() {
        // Go to the selected company's own storefront (where the empty-state
        // "add items" prompt and share tools live); fall back to the aggregate
        // feed only when no company is selected.
        window.location.href = 'store.php' + (selectedUuid ? ('?company_uuid=' + encodeURIComponent(selectedUuid)) : '');
    }

    function launchApp(appKey) {
        if (appKey === 'store') {
            openStore();
            return;
        }
        if (appKey === 'calendar' && typeof window.centrykOpenCalendarDrawer === 'function') {
            window.centrykOpenCalendarDrawer(selectedUuid || '');
            return;
        }

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

    if (appsGrid) {
        appsGrid.addEventListener('dragover', function (e) {
            if (!draggingAppCard) { return; }
            var target = e.target.closest('.app-card[data-enrolled="1"]');
            if (!target || target === draggingAppCard) { return; }
            // Reordering is confined to a single category section.
            if (target.dataset.category !== draggingAppCard.dataset.category) { return; }

            e.preventDefault();
            var rect = target.getBoundingClientRect();
            var middleX = rect.left + (rect.width / 2);
            var middleY = rect.top + (rect.height / 2);
            var sameRowBand = e.clientY > (rect.top + rect.height * 0.25) && e.clientY < (rect.bottom - rect.height * 0.25);
            var placeAfter = e.clientY > middleY || (sameRowBand && e.clientX > middleX);

            if (placeAfter) {
                appsGrid.insertBefore(draggingAppCard, target.nextSibling);
            } else {
                appsGrid.insertBefore(draggingAppCard, target);
            }
            appOrderChanged = true;
        });

        appsGrid.addEventListener('drop', function (e) {
            if (!draggingAppCard) { return; }
            e.preventDefault();
        });
    }

    document.querySelectorAll('.app-card').forEach(function (card) {
        if (card.dataset.enrolled === '1') {
            card.addEventListener('dragstart', function (e) {
                draggingAppCard = card;
                appOrderChanged = false;
                card.classList.add('opacity-60', 'ring-2', 'ring-slate-300');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', card.dataset.app || '');
                }
            });

            card.addEventListener('dragend', function () {
                card.classList.remove('opacity-60', 'ring-2', 'ring-slate-300');
                draggingAppCard = null;
                if (appOrderChanged) {
                    suppressAppClick = true;
                    saveCompanyAppOrder();
                    setTimeout(function () { suppressAppClick = false; }, 150);
                }
            });
        }

        card.addEventListener('click', function () {
            if (suppressAppClick) { return; }
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

    var storeCard = document.getElementById('storeCard');
    if (storeCard) {
        storeCard.addEventListener('click', openStore);
    }

    // "Job Board" chip inside the MyPay card — opens the public board in a new
    // tab without triggering the card's SSO launch.
    document.querySelectorAll('.mypay-board-link').forEach(function (el) {
        var open = function (e) {
            e.stopPropagation();
            var url = el.getAttribute('data-board-url');
            if (url) { window.open(url, '_blank', 'noopener'); }
        };
        el.addEventListener('click', open);
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(e); }
        });
    });

    // "Browse all stores" chip inside the Store card — the public marketplace
    // feed, as opposed to the card's own-storefront default.
    document.querySelectorAll('.store-feed-link').forEach(function (el) {
        var open = function (e) {
            e.stopPropagation();
            window.location.href = el.getAttribute('data-store-feed-url') || 'store.php';
        };
        el.addEventListener('click', open);
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(e); }
        });
    });

    // "Watch" chip inside the Centryk TV card — the public-facing TV page.
    document.querySelectorAll('.tv-watch-link').forEach(function (el) {
        var open = function (e) {
            e.stopPropagation();
            e.preventDefault();
            var url = el.getAttribute('data-watch-url');
            if (url) { window.open(url, '_blank', 'noopener'); }
        };
        el.addEventListener('click', open);
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { open(e); }
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

    var bizPromoDismiss = document.getElementById('bizPromoDismiss');
    if (bizPromoDismiss) {
        bizPromoDismiss.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            try { localStorage.setItem('centryk_bizpromo_dismissed', '1'); } catch (e2) {}
            var p = document.getElementById('bizPromo');
            if (p) { p.classList.add('hidden'); p.classList.remove('flex'); }
        });
    }

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
