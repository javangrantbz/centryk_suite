<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: index.php');
    exit;
}

$pdo  = DB::pdo();
$stmt = $pdo->prepare("
    SELECT c.name, c.status, cm.role
    FROM companies c
    JOIN company_members cm ON cm.company_id = c.id
    WHERE cm.user_id = :uid
    ORDER BY c.name
");
$stmt->execute(['uid' => $user['id']]);
$myCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Connected Apps ────────────────────────────────────────────────────────────
$onePayStores = [];
try {
    $s = $pdo->prepare("
        SELECT s.name, s.status, sm.status AS membership_status
        FROM onepay.stores s
        JOIN onepay.store_memberships sm ON sm.store_id = s.id
        WHERE sm.user_id = :uid AND sm.status = 'active'
        ORDER BY s.name
    ");
    $s->execute(['uid' => $user['id']]);
    $onePayStores = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$myPayAccess = [];
try {
    $s = $pdo->prepare("
        SELECT c.name, c.status AS company_status, r.name AS role_name, u.status AS user_status
        FROM payroll.users u
        JOIN payroll.user_company_assignments uca ON uca.user_id = u.id
        JOIN payroll.companies c ON c.id = uca.company_id
        LEFT JOIN payroll.roles r ON r.id = uca.role_id
        WHERE u.email = :email
        ORDER BY c.name
    ");
    $s->execute(['email' => $user['email']]);
    $myPayAccess = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
// ─────────────────────────────────────────────────────────────────────────────

$fullName     = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
$firstName    = htmlspecialchars($user['first_name']);
$lastName     = htmlspecialchars($user['last_name']);
$initial      = strtoupper(substr($user['first_name'], 0, 1));
$email        = htmlspecialchars($user['email']);
$memberSince  = date('F Y', strtotime($user['created_at']));
$companyCount = count($myCompanies);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile — Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>
        [data-lucide] { display: inline-block; }

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
        body.light .border-white\/10,
        body.light .border-white\/8 { border-color: #e2e8f0; }
        body.light .text-white      { color: #0f172a; }
        body.light .text-white\/80  { color: #334155; }
        body.light .text-white\/60  { color: #64748b; }
        body.light .text-white\/45,
        body.light .text-white\/40  { color: #94a3b8; }
        body.light .text-white\/35,
        body.light .text-white\/30  { color: #94a3b8; }
        body.light .hover\:bg-white\/8:hover  { background-color: #f1f5f9; }
        body.light .hover\:bg-white\/15:hover { background-color: #e2e8f0; }
        body.light .hover\:text-white\/80:hover { color: #334155; }
        body.light input { background-color: #f8fafc !important; border-color: #e2e8f0 !important; color: #0f172a !important; }
        body.light input::placeholder { color: #94a3b8; }
        body.light input:focus { background-color: #f1f5f9 !important; border-color: #3b82f6 !important; }
        body.light .identity-card { background: linear-gradient(135deg, #f8fafc 0%, #f0f4ff 100%) !important; border-color: #e2e8f0 !important; }
        body.light .identity-card .text-white\/50 { color: #64748b; }

        .identity-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .animate-spin { animation: spin 1s linear infinite; }
    </style>
</head>
<body class="min-h-screen bg-[#0d1117] font-sans antialiased text-white">
<script>var _ct=localStorage.getItem('centrikyTheme');if(_ct==='light'){document.body.classList.add('light');}if(_ct==='dark'){document.body.classList.add('dark');}</script>

<!-- Top accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>

<!-- Header -->
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white shadow-sm">
    <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <a href="index.php" class="flex shrink-0 items-center hover:opacity-80 transition-opacity">
                <img src="../centryk_logo.png" alt="Centryk" class="h-12 w-auto">
            </a>
            <span class="text-slate-300">/</span>
            <span class="text-sm font-bold text-slate-400">Profile</span>
        </div>

        <div class="flex items-center gap-3">
            <a href="index.php" class="hidden sm:flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
                <i data-lucide="layout-grid" class="h-3.5 w-3.5"></i>
                Launcher
            </a>
            <a href="companies.php" class="hidden sm:flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
                <i data-lucide="building-2" class="h-3.5 w-3.5"></i>
                Companies
            </a>

            <div class="relative" id="userMenuWrapper">
                <button id="userMenuBtn" class="flex items-center gap-2.5 rounded-xl px-3 py-2 transition hover:bg-slate-100">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[12px] font-black text-slate-700">
                        <?= $initial ?>
                    </div>
                    <div class="text-left hidden sm:block">
                        <p class="text-sm font-semibold text-slate-800 leading-tight" id="headerName"><?= $fullName ?></p>
                        <p class="text-[10px] text-slate-400 leading-tight"><?= $email ?></p>
                    </div>
                    <i data-lucide="chevron-down" class="h-3.5 w-3.5 text-slate-400 shrink-0"></i>
                </button>

                <div id="userMenu" class="absolute right-0 top-full mt-2 w-60 hidden rounded-2xl border border-slate-200 bg-white shadow-xl z-50 overflow-hidden">
                    <div class="px-4 py-3.5 border-b border-slate-100">
                        <p class="text-sm font-bold text-slate-900 leading-tight" id="dropdownName"><?= $fullName ?></p>
                        <p class="text-xs text-slate-400 mt-0.5"><?= $email ?></p>
                    </div>
                    <div class="p-2 space-y-0.5">
                        <button id="themeToggle" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition text-left">
                            <i data-lucide="sun"  id="themeIconSun"  class="h-4 w-4 shrink-0"></i>
                            <i data-lucide="moon" id="themeIconMoon" class="h-4 w-4 shrink-0 hidden"></i>
                            <span id="themeLabel">Light mode</span>
                        </button>
                        <button id="logoutBtn" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 transition text-left">
                            <i data-lucide="log-out" class="h-4 w-4 shrink-0"></i>
                            Sign out
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Page body -->
<div class="mx-auto max-w-5xl px-6 py-5 space-y-4">

    <!-- Identity strip -->
    <div class="identity-card rounded-xl border border-white/10 overflow-hidden">
        <div class="flex items-center gap-4 px-5 py-3.5">

            <!-- Avatar -->
            <div class="relative shrink-0">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 via-purple-500 to-indigo-600 text-lg font-black text-white shadow-md shadow-purple-900/30" id="heroInitial">
                    <?= $initial ?>
                </div>
                <div class="absolute -bottom-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-emerald-500 ring-2 ring-[#0f172a]">
                    <svg viewBox="0 0 10 10" class="h-2 w-2"><path d="M1.5 5l2.5 2.5 4.5-4.5" stroke="white" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
            </div>

            <!-- Identity -->
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <p class="text-base font-black text-white tracking-tight leading-tight" id="heroName"><?= $fullName ?></p>
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[9px] font-black uppercase tracking-widest text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>Active
                    </span>
                </div>
                <p class="text-xs text-white/40 mt-0.5"><?= $email ?> <span class="text-white/20 mx-1">·</span> Member since <?= $memberSince ?></p>
            </div>

            <!-- Security badge -->
            <div class="shrink-0 hidden sm:flex items-center gap-2 border-l border-white/8 pl-5">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-blue-400 shrink-0">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                <div>
                    <p class="text-xs font-black text-white leading-tight">Secured by Centryk</p>
                    <p class="text-[10px] text-white/35 leading-tight">Protecting Belizean businesses</p>
                </div>
            </div>

        </div>
        <div class="border-t border-white/6 bg-white/4 px-5 py-1.5 flex items-center gap-2 text-[9px] font-bold uppercase tracking-widest text-white/20">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3 text-blue-500/50 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            Credentials encrypted · Centryk never stores plain-text passwords
        </div>
    </div>

    <!-- 3-col grid -->
    <div class="grid gap-4 lg:grid-cols-3">

        <!-- Change Password -->
        <div class="rounded-xl border border-white/10 bg-[#111827] p-4">
            <div class="flex items-center gap-2 mb-3">
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-500/15">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-blue-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xs font-black text-white">Change Password</h2>
                    <p class="text-[10px] text-white/35">Use a strong, unique password.</p>
                </div>
            </div>

            <div id="pwAlert" class="hidden mb-3 rounded-lg px-3 py-2 text-xs font-semibold"></div>

            <form id="changePasswordForm" class="space-y-2" novalidate>
                <div>
                    <label class="block text-[10px] font-bold text-white/35 mb-1 uppercase tracking-wider">Current password</label>
                    <div class="relative">
                        <input id="currentPassword" type="password" autocomplete="current-password"
                               class="w-full rounded-lg border border-white/10 bg-white/6 px-3 py-2 text-xs text-white placeholder-white/20 outline-none focus:border-blue-500 focus:bg-white/8 transition pr-8"
                               placeholder="Current password">
                        <button type="button" onclick="togglePw('currentPassword','eyeCurrent')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/25 hover:text-white/60 transition">
                            <i data-lucide="eye" id="eyeCurrent" class="h-3.5 w-3.5"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-white/35 mb-1 uppercase tracking-wider">New password</label>
                    <div class="relative">
                        <input id="newPassword" type="password" autocomplete="new-password"
                               class="w-full rounded-lg border border-white/10 bg-white/6 px-3 py-2 text-xs text-white placeholder-white/20 outline-none focus:border-blue-500 focus:bg-white/8 transition pr-8"
                               placeholder="Min. 8 characters">
                        <button type="button" onclick="togglePw('newPassword','eyeNew')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/25 hover:text-white/60 transition">
                            <i data-lucide="eye" id="eyeNew" class="h-3.5 w-3.5"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-white/35 mb-1 uppercase tracking-wider">Confirm password</label>
                    <div class="relative">
                        <input id="confirmPassword" type="password" autocomplete="new-password"
                               class="w-full rounded-lg border border-white/10 bg-white/6 px-3 py-2 text-xs text-white placeholder-white/20 outline-none focus:border-blue-500 focus:bg-white/8 transition pr-8"
                               placeholder="Repeat password">
                        <button type="button" onclick="togglePw('confirmPassword','eyeConfirm')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/25 hover:text-white/60 transition">
                            <i data-lucide="eye" id="eyeConfirm" class="h-3.5 w-3.5"></i>
                        </button>
                    </div>
                </div>
                <div class="pt-1">
                    <button type="submit" id="pwSubmitBtn"
                            class="flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-1.5 text-xs font-black text-white transition hover:bg-blue-500 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i data-lucide="lock" class="h-3 w-3"></i>
                        Update Password
                    </button>
                </div>
            </form>
        </div>

        <!-- Account Details -->
        <div class="rounded-xl border border-white/10 bg-[#111827] p-4">
            <div class="flex items-center gap-2 mb-3">
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-orange-500/15">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-orange-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xs font-black text-white">Account Details</h2>
                    <p class="text-[10px] text-white/35">Update your display name.</p>
                </div>
            </div>

            <div id="nameAlert" class="hidden mb-3 rounded-lg px-3 py-2 text-xs font-semibold"></div>

            <form id="updateNameForm" novalidate class="space-y-2">
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[10px] font-bold text-white/35 mb-1 uppercase tracking-wider">First name</label>
                        <input id="firstNameInput" type="text" value="<?= $firstName ?>" maxlength="50"
                               class="w-full rounded-lg border border-white/10 bg-white/6 px-3 py-2 text-xs text-white placeholder-white/20 outline-none focus:border-orange-500 focus:bg-white/8 transition"
                               placeholder="First name">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-white/35 mb-1 uppercase tracking-wider">Last name</label>
                        <input id="lastNameInput" type="text" value="<?= $lastName ?>" maxlength="50"
                               class="w-full rounded-lg border border-white/10 bg-white/6 px-3 py-2 text-xs text-white placeholder-white/20 outline-none focus:border-orange-500 focus:bg-white/8 transition"
                               placeholder="Last name">
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-white/35 mb-1 uppercase tracking-wider">Phone</label>
                    <input id="phoneInput" type="tel" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" maxlength="30"
                           class="w-full rounded-lg border border-white/10 bg-white/6 px-3 py-2 text-xs text-white placeholder-white/20 outline-none focus:border-orange-500 focus:bg-white/8 transition"
                           placeholder="e.g. 6001234">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-white/35 mb-1 uppercase tracking-wider">Email</label>
                    <input type="email" value="<?= $email ?>" readonly
                           class="w-full rounded-lg border border-white/6 bg-white/4 px-3 py-2 text-xs text-white/40 cursor-default"
                           title="Contact your administrator to update your email.">
                </div>
                <div class="pt-1 flex items-center gap-3">
                    <button type="submit" id="nameSubmitBtn"
                            class="flex items-center gap-1.5 rounded-lg bg-orange-600 px-3.5 py-1.5 text-xs font-black text-white transition hover:bg-orange-500 disabled:opacity-50 disabled:cursor-not-allowed">
                        <i data-lucide="save" class="h-3 w-3"></i>
                        Save Changes
                    </button>
                    <span class="text-[10px] text-white/25 font-semibold">Email changes require admin.</span>
                </div>
            </form>
        </div>

        <!-- My Companies -->
        <div class="rounded-xl border border-white/10 bg-[#111827] p-4 flex flex-col">
            <div class="flex items-center gap-2 mb-3">
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-purple-500/15">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-purple-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xs font-black text-white">My Companies</h2>
                    <p class="text-[10px] text-white/35"><?= $companyCount ?> <?= $companyCount === 1 ? 'company' : 'companies' ?> linked</p>
                </div>
            </div>

            <?php if (empty($myCompanies)): ?>
                <div class="flex-1 flex flex-col items-center justify-center text-center py-4">
                    <i data-lucide="building-2" class="h-8 w-8 text-white/15 mb-2"></i>
                    <p class="text-xs font-bold text-white/25">No companies yet.</p>
                </div>
            <?php else: ?>
                <div class="flex-1 space-y-1.5">
                    <?php foreach ($myCompanies as $co):
                        $role      = htmlspecialchars($co['role'] ?? 'Member');
                        $active    = strtolower($co['status'] ?? 'active') === 'active';
                        $roleLower = strtolower($role);
                        $badgeClass = match(true) {
                            $roleLower === 'owner' => 'bg-purple-500/15 text-purple-400',
                            $roleLower === 'admin' => 'bg-blue-500/15 text-blue-400',
                            default                => 'bg-white/8 text-white/45',
                        };
                    ?>
                    <div class="flex items-center justify-between gap-2 rounded-lg bg-white/4 border border-white/6 px-3 py-2">
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-white truncate"><?= htmlspecialchars($co['name']) ?></p>
                            <span class="text-[9px] <?= $active ? 'text-emerald-500' : 'text-white/25' ?> font-bold uppercase tracking-wider"><?= $active ? 'Active' : 'Inactive' ?></span>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-wider <?= $badgeClass ?>"><?= $role ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3 pt-3 border-t border-white/6">
                    <a href="companies.php" class="flex items-center gap-1.5 text-[10px] font-black uppercase tracking-[0.1em] text-white/30 transition hover:text-white/60">
                        <i data-lucide="settings-2" class="h-3 w-3"></i>
                        Manage Companies
                    </a>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Connected Apps -->
    <div class="hidden rounded-xl border border-white/10 bg-[#111827] p-4">
        <div class="flex items-center gap-2 mb-4">
            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/8">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5 text-white/50">
                    <circle cx="5" cy="5" r="1.6"/><circle cx="12" cy="5" r="1.6"/><circle cx="19" cy="5" r="1.6"/>
                    <circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>
                    <circle cx="5" cy="19" r="1.6"/><circle cx="12" cy="19" r="1.6"/><circle cx="19" cy="19" r="1.6"/>
                </svg>
            </div>
            <div>
                <h2 class="text-xs font-black text-white">Connected Apps</h2>
                <p class="text-[10px] text-white/35">Your single Centryk login grants access to these apps.</p>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">

            <!-- OnePay -->
            <div class="rounded-lg border border-indigo-500/20 bg-indigo-500/5 p-3">
                <div class="flex items-center gap-2.5 mb-2.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-600 shadow-sm shadow-indigo-900/40">
                        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs font-black text-white">OnePay</p>
                        <p class="text-[10px] text-indigo-400/70">Point of Sale &amp; Payments</p>
                    </div>
                    <?php if (!empty($onePayStores)): ?>
                    <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[9px] font-black uppercase tracking-widest text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>Active
                    </span>
                    <?php else: ?>
                    <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-white/8 px-2 py-0.5 text-[9px] font-black uppercase tracking-widest text-white/30">
                        No Access
                    </span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($onePayStores)): ?>
                <div class="space-y-1">
                    <?php foreach ($onePayStores as $st): ?>
                    <div class="flex items-center justify-between rounded-md bg-white/4 px-2.5 py-1.5">
                        <span class="text-[11px] font-semibold text-white/80 truncate"><?= htmlspecialchars($st['name']) ?></span>
                        <span class="shrink-0 ml-2 text-[9px] font-black uppercase tracking-wider <?= strtolower($st['status']) === 'active' ? 'text-emerald-500' : 'text-white/25' ?>">
                            <?= htmlspecialchars($st['status']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-[11px] text-white/25 italic">No stores assigned.</p>
                <?php endif; ?>
            </div>

            <!-- MyPay -->
            <div class="rounded-lg border border-orange-500/20 bg-orange-500/5 p-3">
                <div class="flex items-center gap-2.5 mb-2.5">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-orange-500 shadow-sm shadow-orange-900/40">
                        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs font-black text-white">MyPay</p>
                        <p class="text-[10px] text-orange-400/70">Payroll &amp; HR</p>
                    </div>
                    <?php if (!empty($myPayAccess)): ?>
                    <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[9px] font-black uppercase tracking-widest text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>Active
                    </span>
                    <?php else: ?>
                    <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-white/8 px-2 py-0.5 text-[9px] font-black uppercase tracking-widest text-white/30">
                        No Access
                    </span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($myPayAccess)): ?>
                <div class="space-y-1">
                    <?php foreach ($myPayAccess as $mp): ?>
                    <div class="flex items-center justify-between rounded-md bg-white/4 px-2.5 py-1.5">
                        <span class="text-[11px] font-semibold text-white/80 truncate"><?= htmlspecialchars($mp['name']) ?></span>
                        <?php if ($mp['role_name']): ?>
                        <span class="shrink-0 ml-2 rounded-full bg-orange-500/15 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-orange-400">
                            <?= htmlspecialchars($mp['role_name']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-[11px] text-white/25 italic">No companies assigned.</p>
                <?php endif; ?>
            </div>

        </div>
    </div>

</div><!-- /page body -->

<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
<script>
// ── Theme ──────────────────────────────────────────────────────────────────
(function () {
    const sun  = document.getElementById('themeIconSun');
    const moon = document.getElementById('themeIconMoon');
    const lbl  = document.getElementById('themeLabel');
    function apply(theme) {
        document.body.classList.toggle('light', theme === 'light');
        sun?.classList.toggle('hidden', theme === 'light');
        moon?.classList.toggle('hidden', theme !== 'light');
        if (lbl) lbl.textContent = theme === 'light' ? 'Dark mode' : 'Light mode';
    }
    apply(localStorage.getItem('centrikyTheme') || 'dark');
    document.getElementById('themeToggle')?.addEventListener('click', () => {
        const next = document.body.classList.contains('light') ? 'dark' : 'light';
        localStorage.setItem('centrikyTheme', next);
        apply(next);
    });
})();

// ── User dropdown ──────────────────────────────────────────────────────────
document.getElementById('userMenuBtn')?.addEventListener('click', e => {
    e.stopPropagation();
    document.getElementById('userMenu').classList.toggle('hidden');
});
document.addEventListener('click', () => document.getElementById('userMenu')?.classList.add('hidden'));
document.getElementById('logoutBtn')?.addEventListener('click', () => {
    fetch('api/auth/logout.php', { method: 'POST' }).finally(() => { window.location.href = 'index.php'; });
});

// ── Show/hide password ─────────────────────────────────────────────────────
function togglePw(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (!input) return;
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    icon.setAttribute('data-lucide', showing ? 'eye' : 'eye-off');
    lucide.createIcons();
}

// ── Alert helper ───────────────────────────────────────────────────────────
function showAlert(containerId, message, type) {
    const el = document.getElementById(containerId);
    el.textContent = message;
    el.className = 'mb-4 rounded-xl px-4 py-2.5 text-sm font-semibold ' + (
        type === 'success'
            ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/20'
            : 'bg-red-500/15 text-red-400 border border-red-500/20'
    );
}

// ── Change Password ────────────────────────────────────────────────────────
document.getElementById('changePasswordForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const currentPassword = document.getElementById('currentPassword').value;
    const newPassword     = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    const btn             = document.getElementById('pwSubmitBtn');

    document.getElementById('pwAlert').className = 'hidden mb-4 rounded-xl px-4 py-2.5 text-sm font-semibold';

    if (!currentPassword || !newPassword || !confirmPassword) return showAlert('pwAlert','All fields are required.','error');
    if (newPassword.length < 8) return showAlert('pwAlert','New password must be at least 8 characters.','error');
    if (newPassword !== confirmPassword) return showAlert('pwAlert','Passwords do not match.','error');

    btn.disabled = true;
    btn.innerHTML = '<svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Updating…';

    try {
        const res  = await fetch('api/auth/change-password.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ current_password: currentPassword, new_password: newPassword, confirm_password: confirmPassword })
        });
        const data = await res.json();
        if (data.success) {
            showAlert('pwAlert', 'Password updated successfully.', 'success');
            document.getElementById('changePasswordForm').reset();
        } else {
            showAlert('pwAlert', data.message || 'Something went wrong.', 'error');
        }
    } catch (_) {
        showAlert('pwAlert', 'Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="lock" class="h-3.5 w-3.5"></i> Update Password';
        lucide.createIcons();
    }
});

// ── Update Name ────────────────────────────────────────────────────────────
document.getElementById('updateNameForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const firstName = document.getElementById('firstNameInput').value.trim();
    const lastName  = document.getElementById('lastNameInput').value.trim();
    const phone     = document.getElementById('phoneInput').value.trim();
    const btn       = document.getElementById('nameSubmitBtn');

    document.getElementById('nameAlert').className = 'hidden mb-4 rounded-xl px-4 py-2.5 text-sm font-semibold';

    if (!firstName || !lastName) return showAlert('nameAlert','First and last name are required.','error');

    btn.disabled = true;
    btn.innerHTML = '<svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Saving…';

    try {
        const res  = await fetch('api/profile/update-name.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ first_name: firstName, last_name: lastName, phone: phone })
        });
        const data = await res.json();
        if (data.success) {
            showAlert('nameAlert', 'Name updated successfully.', 'success');
            const full = data.full_name;
            document.getElementById('heroName').textContent    = full;
            document.getElementById('headerName').textContent  = full;
            document.getElementById('dropdownName').textContent = full;
            const newInitial = firstName.charAt(0).toUpperCase();
            document.getElementById('heroInitial').textContent = newInitial;
        } else {
            showAlert('nameAlert', data.message || 'Something went wrong.', 'error');
        }
    } catch (_) {
        showAlert('nameAlert', 'Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="save" class="h-3.5 w-3.5"></i> Save Changes';
        lucide.createIcons();
    }
});
</script>
</body>
</html>
