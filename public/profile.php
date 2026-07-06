<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
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

// ── Connected Apps (linked app access) ─────────────────────────────────────────
// Locally every app DB shares one MySQL, so read them directly. On production
// each app has its own isolated DB/user, so ask the app over HTTP instead
// (server-to-server, shared secret). Both paths return identical row shapes.

/** Fetch a user's access rows from an app's account endpoint. */
function sw_fetch_app_access(PDO $pdo, string $appKey, string $email, string $secret): array {
    if ($secret === '' || $email === '') return [];
    $stmt = $pdo->prepare("SELECT url_production FROM apps WHERE `key` = :k LIMIT 1");
    $stmt->execute(['k' => $appKey]);
    $url = (string)($stmt->fetchColumn() ?: '');
    if ($url === '') return [];
    $base = preg_replace('#/[^/]*$#', '', rtrim($url, '/')); // strip /sso.php
    $ch = curl_init($base . '/api/account/access.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['secret' => $secret, 'email' => $email]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 5,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) return [];
    $data = json_decode($res, true);
    return (is_array($data) && !empty($data['rows'])) ? $data['rows'] : [];
}

$onePayStores = [];
$myPayAccess  = [];

$_swHost    = $_SERVER['HTTP_HOST'] ?? '';
$_swIsLocal = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $_swHost) === 1;

if ($_swIsLocal) {
    try {
        $s = $pdo->prepare("
            SELECT s.name, s.status, sm.status AS membership_status
            FROM onepay.stores s
            JOIN onepay.store_memberships sm ON sm.store_id = s.id
            JOIN onepay.users u ON u.id = sm.user_id
            WHERE u.email = :email AND sm.status = 'active'
            ORDER BY s.name
        ");
        $s->execute(['email' => $user['email']]);
        $onePayStores = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
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
} else {
    $onePayStores = sw_fetch_app_access($pdo, 'onepay', $user['email'], $_ENV['PROVISION_SECRET']    ?? '');
    $myPayAccess  = sw_fetch_app_access($pdo, 'mypay',  $user['email'], $_ENV['MYPAY_WEBHOOK_SECRET'] ?? '');
}
// ─────────────────────────────────────────────────────────────────────────────

// ── Notification preferences (catalog + the user's stored overrides) ──────────
$notifCatalog = require __DIR__ . '/../app/config/notifications.php';
$notifSaved   = [];
try {
    $s = $pdo->prepare('SELECT pref_key, enabled FROM user_notification_prefs WHERE user_id = :uid');
    $s->execute(['uid' => (int)$user['id']]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $notifSaved[$r['pref_key']] = (int)$r['enabled'];
    }
} catch (Exception $e) {}
$notifPrefs = [];
foreach ($notifCatalog as $key => $meta) {
    $meta['enabled'] = array_key_exists($key, $notifSaved) ? $notifSaved[$key] : (int)$meta['default'];
    $notifPrefs[$key] = $meta;
}

$fullName     = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
$firstName    = htmlspecialchars($user['first_name']);
$lastName     = htmlspecialchars($user['last_name']);
$initial      = strtoupper(substr($user['first_name'], 0, 1));
$email        = htmlspecialchars($user['email']);
$memberSince  = date('F Y', strtotime($user['created_at']));
$companyCount = count($myCompanies);
// Optional deep-link: open a specific company in the embedded companies manager.
$companyDeepUuid = trim($_GET['company_uuid'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
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

<?php $pageTitle = 'Profile'; $headerMaxW = 'max-w-5xl'; $awCurrent = 'account'; include __DIR__ . '/partials/account_header.php'; ?>

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
        <div class="border-t border-white/6 bg-white/4 px-5 py-1.5 flex items-center gap-2 text-[9px] font-bold uppercase tracking-widest text-white/50">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3 text-blue-400 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            Credentials encrypted · Centryk never stores plain-text passwords
        </div>
    </div>

    <!-- Account: left menu + panels -->
    <div class="grid gap-4 lg:grid-cols-[200px_1fr]">

        <!-- Left menu -->
        <aside class="rounded-xl border border-white/10 bg-[#111827] p-2 h-max lg:sticky lg:top-4">
            <nav class="space-y-0.5" id="accountNav">
                <button type="button" data-target="personal" class="acct-nav-btn w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-bold text-white bg-blue-500/15 transition hover:bg-white/8 text-left">
                    <i data-lucide="user" class="h-4 w-4 shrink-0"></i> Personal Information
                </button>
                <button type="button" data-target="password" class="acct-nav-btn w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-bold text-white/60 transition hover:bg-white/8 text-left">
                    <i data-lucide="lock" class="h-4 w-4 shrink-0"></i> Change Password
                </button>
                <button type="button" data-target="security" class="acct-nav-btn w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-bold text-white/60 transition hover:bg-white/8 text-left">
                    <i data-lucide="shield-check" class="h-4 w-4 shrink-0"></i> Sign-in activity
                </button>
                <button type="button" data-target="notifications" class="acct-nav-btn w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-bold text-white/60 transition hover:bg-white/8 text-left">
                    <i data-lucide="bell" class="h-4 w-4 shrink-0"></i> Notifications
                </button>
                <button type="button" data-target="companies" class="acct-nav-btn w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-bold text-white/60 transition hover:bg-white/8 text-left">
                    <i data-lucide="building-2" class="h-4 w-4 shrink-0"></i> My Companies
                </button>
                <button type="button" data-target="apps" class="acct-nav-btn w-full flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-bold text-white/60 transition hover:bg-white/8 text-left">
                    <i data-lucide="layout-grid" class="h-4 w-4 shrink-0"></i> Connected Apps
                </button>
            </nav>
        </aside>

        <!-- Panels -->
        <div class="min-w-0 space-y-4">

        <!-- Change Password -->
        <section data-panel="password" class="acct-panel hidden rounded-xl border border-white/10 bg-[#111827] p-4 max-w-2xl">
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
                        <button type="button" data-password-toggle="currentPassword" data-password-icon="eyeCurrent" aria-label="Show current password" aria-pressed="false" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/25 hover:text-white/60 transition">
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
                        <button type="button" data-password-toggle="newPassword" data-password-icon="eyeNew" aria-label="Show new password" aria-pressed="false" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/25 hover:text-white/60 transition">
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
                        <button type="button" data-password-toggle="confirmPassword" data-password-icon="eyeConfirm" aria-label="Show confirm password" aria-pressed="false" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/25 hover:text-white/60 transition">
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
        </section>

        <!-- Sign-in activity -->
        <section data-panel="security" class="acct-panel hidden rounded-xl border border-white/10 bg-[#111827] p-4 max-w-2xl">
            <div class="flex items-center gap-2 mb-3">
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-500/15">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-emerald-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xs font-black text-white">Sign-in activity</h2>
                    <p class="text-[10px] text-white/35">Your recent sign-ins across Centryk and its apps.</p>
                </div>
            </div>
            <div id="loginActivityBody" class="space-y-1.5">
                <p class="px-1 py-6 text-center text-xs text-white/30">Loading…</p>
            </div>
            <p class="mt-3 pt-3 border-t border-white/6 text-[10px] text-white/30">
                Don't recognize an entry? <a href="#" class="font-bold text-blue-400 hover:text-blue-300" data-target="password" id="goChangePw">Change your password</a> right away.
            </p>
        </section>

        <!-- Notifications -->
        <section data-panel="notifications" class="acct-panel hidden rounded-xl border border-white/10 bg-[#111827] p-4 max-w-2xl">
            <div class="flex items-center gap-2 mb-3">
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500/15">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-amber-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xs font-black text-white">Notifications</h2>
                    <p class="text-[10px] text-white/35">Choose which emails Centryk sends you.</p>
                </div>
            </div>
            <div class="space-y-1.5">
                <?php foreach ($notifPrefs as $key => $pref): ?>
                <label class="flex items-center justify-between gap-3 rounded-lg bg-white/4 border border-white/6 px-3 py-3 cursor-pointer">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-white"><?= htmlspecialchars($pref['label']) ?></p>
                        <p class="text-[11px] text-white/40"><?= htmlspecialchars($pref['desc']) ?></p>
                    </div>
                    <input type="checkbox" class="notif-toggle sr-only peer" data-key="<?= htmlspecialchars($key) ?>" <?= $pref['enabled'] ? 'checked' : '' ?>>
                    <span class="relative h-5 w-9 shrink-0 rounded-full bg-white/15 transition-colors peer-checked:bg-blue-500 after:absolute after:left-0.5 after:top-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-4"></span>
                </label>
                <?php endforeach; ?>
            </div>
            <p id="notifStatus" class="mt-3 h-4 text-[10px] font-bold text-emerald-400"></p>
        </section>

        <!-- Personal Information -->
        <section data-panel="personal" class="acct-panel rounded-xl border border-white/10 bg-[#111827] p-4 max-w-2xl">
            <div class="flex items-center gap-2 mb-3">
                <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-orange-500/15">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-orange-400">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="text-xs font-black text-white">Personal Information</h2>
                    <p class="text-[10px] text-white/35">Update your name and contact details.</p>
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
        </section>

        <!-- My Companies -->
        <section data-panel="companies" class="acct-panel hidden rounded-xl border border-white/10 bg-[#111827] overflow-hidden">
            <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-white/6">
                <div class="flex items-center gap-2">
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
            </div>
            <iframe id="companiesFrame" data-src="companies.php?embed=1<?= $companyDeepUuid !== '' ? '&amp;company_uuid=' . urlencode($companyDeepUuid) : '' ?>" title="My Companies"
                    class="block w-full" style="height:560px;border:0;background:transparent;" scrolling="no"></iframe>
        </section>

        <!-- Connected Apps -->
        <section data-panel="apps" class="acct-panel hidden rounded-xl border border-white/10 bg-[#111827] p-4">
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
        </section>

        </div><!-- /panels -->
    </div><!-- /account layout -->

</div><!-- /page body -->

<script src="https://unpkg.com/lucide@latest"></script>
<script>if (window.lucide) lucide.createIcons();</script>
<script>
// Header behaviour (waffle, account menu, theme, logout) is owned by
// partials/account_header.php.

// ── Show/hide password ─────────────────────────────────────────────────────
function setPasswordToggleState(button, showPassword) {
    const input = document.getElementById(button.dataset.passwordToggle);
    const icon  = document.getElementById(button.dataset.passwordIcon);
    if (!input) return;

    input.type = showPassword ? 'text' : 'password';
    button.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
    button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');

    if (icon) {
        icon.setAttribute('data-lucide', showPassword ? 'eye-off' : 'eye');
        if (window.lucide) lucide.createIcons();
    }
}

document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
        const input = document.getElementById(button.dataset.passwordToggle);
        setPasswordToggleState(button, input && input.type === 'password');
    });
});

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
            document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
                setPasswordToggleState(button, false);
            });
        } else {
            showAlert('pwAlert', data.message || 'Something went wrong.', 'error');
        }
    } catch (_) {
        showAlert('pwAlert', 'Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="lock" class="h-3.5 w-3.5"></i> Update Password';
        if (window.lucide) lucide.createIcons();
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
            const heroName = document.getElementById('heroName');
            if (heroName) heroName.textContent = full;
            document.querySelectorAll('.js-hdr-name').forEach(el => { el.textContent = full; });
            const newInitial = firstName.charAt(0).toUpperCase();
            const heroInitial = document.getElementById('heroInitial');
            if (heroInitial) heroInitial.textContent = newInitial;
            document.querySelectorAll('.js-hdr-initial').forEach(el => { el.textContent = newInitial; });
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

// ── Account menu (left panel) ──────────────────────────────────────────────
(function () {
    const btns   = Array.from(document.querySelectorAll('.acct-nav-btn'));
    const panels = Array.from(document.querySelectorAll('.acct-panel'));
    if (!btns.length) return;
    const valid = panels.map(p => p.dataset.panel);

    // ── Sign-in activity (lazy-loaded from login_events) ───────────────────
    let activityLoaded = false;
    function esc(s) { return String(s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
    function fmtWhen(s) {
        if (!s) return '';
        const d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d) ? esc(s) : d.toLocaleString('en-US', { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
    }
    function activityRow(e) {
        const ok = e.success;
        return '<div class="flex items-center gap-3 rounded-lg bg-white/4 border border-white/6 px-3 py-2.5">' +
            '<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ' + (ok ? 'bg-blue-500/15 text-blue-400' : 'bg-rose-500/15 text-rose-400') + '">' +
                '<i data-lucide="' + esc(e.icon || 'monitor') + '" class="h-4 w-4"></i>' +
            '</div>' +
            '<div class="min-w-0 flex-1">' +
                '<p class="text-sm font-bold text-white truncate">' + esc(e.label) + '</p>' +
                '<p class="text-[11px] text-white/40">' + esc(e.ip) + ' · ' + fmtWhen(e.created_at) + '</p>' +
            '</div>' +
            (ok ? '' : '<span class="shrink-0 rounded-full bg-rose-500/15 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-rose-400">Failed</span>') +
        '</div>';
    }
    function loadLoginActivity() {
        if (activityLoaded) return;
        activityLoaded = true;
        const body = document.getElementById('loginActivityBody');
        fetch('api/profile/login-activity.php')
            .then(r => r.json())
            .then(d => {
                const evs = (d && d.events) || [];
                body.innerHTML = evs.length ? evs.map(activityRow).join('')
                    : '<p class="px-1 py-6 text-center text-xs text-white/30">No recent sign-ins.</p>';
                if (window.lucide) lucide.createIcons();
            })
            .catch(() => { activityLoaded = false; body.innerHTML = '<p class="px-1 py-6 text-center text-xs text-white/30">Couldn\'t load activity.</p>'; });
    }

    // ── Embedded companies page (lazy-loaded; auto-height via postMessage) ──
    let frameLoaded = false;
    function loadCompaniesFrame() {
        if (frameLoaded) return;
        frameLoaded = true;
        const f = document.getElementById('companiesFrame');
        if (f && f.dataset.src) f.src = f.dataset.src;
    }
    window.addEventListener('message', e => {
        const d = e.data;
        if (!d || d.type !== 'centryk-embed-height') return;
        const f = document.getElementById('companiesFrame');
        if (f && d.height) f.style.height = Math.max(320, d.height) + 'px';
    });

    function activate(target) {
        if (!valid.includes(target)) target = valid[0];
        panels.forEach(p => p.classList.toggle('hidden', p.dataset.panel !== target));
        btns.forEach(b => {
            const on = b.dataset.target === target;
            b.classList.toggle('bg-blue-500/15', on);
            b.classList.toggle('text-white', on);
            b.classList.toggle('text-white/60', !on);
        });
        if (target === 'security')  loadLoginActivity();
        if (target === 'companies') loadCompaniesFrame();
        try { localStorage.setItem('centrykAccountTab', target); } catch (e) {}
    }
    btns.forEach(b => b.addEventListener('click', () => activate(b.dataset.target)));
    document.getElementById('goChangePw')?.addEventListener('click', e => { e.preventDefault(); activate('password'); });

    const hash = (location.hash || '').replace('#', '');
    let saved = null; try { saved = localStorage.getItem('centrykAccountTab'); } catch (e) {}
    const hasCompanyParam = new URLSearchParams(location.search).has('company_uuid');
    activate(valid.includes(hash) ? hash : (hasCompanyParam ? 'companies' : (saved || 'personal')));
})();

// ── Notification preferences (save each toggle) ────────────────────────────
(function () {
    const status = document.getElementById('notifStatus');
    let statusTimer = null;
    function flash(msg, ok) {
        if (!status) return;
        status.textContent = msg;
        status.className = 'mt-3 h-4 text-[10px] font-bold ' + (ok ? 'text-emerald-400' : 'text-rose-400');
        clearTimeout(statusTimer);
        statusTimer = setTimeout(() => { status.textContent = ''; }, 2500);
    }
    document.querySelectorAll('.notif-toggle').forEach(cb => {
        cb.addEventListener('change', () => {
            const enabled = cb.checked;
            cb.disabled = true;
            fetch('api/profile/notification-prefs.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pref_key: cb.dataset.key, enabled })
            })
            .then(r => r.json())
            .then(d => {
                if (d && d.success) { flash('Saved', true); }
                else { cb.checked = !enabled; flash('Could not save', false); }
            })
            .catch(() => { cb.checked = !enabled; flash('Network error', false); })
            .finally(() => { cb.disabled = false; });
        });
    });
})();
</script>
</body>
</html>
