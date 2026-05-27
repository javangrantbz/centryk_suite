<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';

// ── Maintenance mode — set to false to re-open the site ──────────────────────
$maintenance = false;
$maintenance_whitelist = ['190.197.30.112'];  // add your IP to bypass maintenance
$visitor_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (in_array(trim(explode(',', $visitor_ip)[0]), $maintenance_whitelist)) {
    $maintenance = false;
}

Auth::start();
$me = AuthService::me();

$showOnboarding    = false;
$hasDefaultPassword = false;
if ($me['authenticated']) {
    $user = $me['user'];
    $apps = $me['apps'];

    // Check if onboarding modal should be shown
    try {
        $obRow = DB::pdo()->prepare(
            "SELECT onboarding_seen, password FROM users WHERE id = :id LIMIT 1"
        );
        $obRow->execute(['id' => $user['id']]);
        $obData = $obRow->fetch(PDO::FETCH_ASSOC);
        if ($obData) {
            $hasDefaultPassword = (
                password_verify('password',    $obData['password']) ||
                password_verify('password123', $obData['password'])
            );
            $showOnboarding = (!(int)$obData['onboarding_seen']) || $hasDefaultPassword;
        }
    } catch (Exception $e) {
        $showOnboarding = false;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Centryk — Built for Belize. Built for business.</title>
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
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25%       { transform: translateX(-4px); }
            75%       { transform: translateX(4px); }
        }
        .animate-shake { animation: shake 0.2s ease-in-out 0s 2; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .animate-spin  { animation: spin 1s linear infinite; }
        [data-lucide]  { display: inline-block; }

        /* ── Light theme (authenticated launcher only) ───────────────────── */
        body.light #launcherBg {
            background: linear-gradient(135deg, #f8fafc 0%, #f0f4f8 45%, #e8edf5 100%) !important;
        }
        body.light .bg-\[\#111827\],
        body.light .bg-\[\#0d1420\] { background-color: #ffffff; }
        body.light .border-white\/10,
        body.light .border-white\/8  { border-color: #e2e8f0; }
        body.light .bg-white\/10,
        body.light .bg-white\/8      { background-color: #f1f5f9; }
        body.light .bg-white\/4      { background-color: #f8fafc; }
        body.light .bg-slate-700     { background-color: #e2e8f0; }

        body.light .text-white       { color: #0f172a; }
        body.light .text-white\/80   { color: #334155; }
        body.light .text-white\/45   { color: #64748b; }
        body.light .text-white\/35   { color: #94a3b8; }
        body.light .text-white\/30   { color: #94a3b8; }
        body.light .text-white\/20   { color: #cbd5e1; }

        /* Preserve white text on solid-colour CTA buttons & overlays */
        body.light .app-cta,
        body.light #launchOverlay .text-white { color: #ffffff; }

        body.light .hover\:border-white\/20:hover { border-color: #cbd5e1; }
        body.light .hover\:text-white\/80:hover   { color: #334155; }

        /* Preserve white text on dark slate buttons in light mode */
        body.light .bg-slate-900.text-white { color: #ffffff; }
        body.light .text-white\/60           { color: #475569; }
        body.light .hover\:bg-white\/8:hover { background-color: #f1f5f9; }

        /* ── Login page dark theme ────────────────────────────────────── */
        body.dark #loginCard {
            background-color: #111827;
            border-color: rgba(255,255,255,0.1);
            box-shadow: 0 32px 100px rgba(0,0,0,0.55);
        }
        body.dark #loginLeftPanel  { background-color: #0d1420; border-color: rgba(255,255,255,0.08); }
        body.dark #loginRightPanel { background-color: #0f1928; }
        body.dark #loginFeatureGrid { background-color: #111827; border-color: rgba(255,255,255,0.06); }

        body.dark #loginCard .bg-white  { background-color: #111827; }
        body.dark #loginCard .bg-slate-50 { background-color: #0d1420; }
        body.dark #loginCard .border-slate-200,
        body.dark #loginCard .border-slate-100 { border-color: rgba(255,255,255,0.08); }
        body.dark #loginCard .border-orange-200 { border-color: rgba(255,255,255,0.08); }
        body.dark #loginCard .bg-slate-200 { background-color: rgba(255,255,255,0.08); }

        body.dark #loginCard .text-slate-950,
        body.dark #loginCard .text-slate-900 { color: #ffffff; }
        body.dark #loginCard .text-slate-800 { color: rgba(255,255,255,0.85); }
        body.dark #loginCard .text-slate-700 { color: rgba(255,255,255,0.65); }
        body.dark #loginCard .text-slate-600 { color: rgba(255,255,255,0.5); }
        body.dark #loginCard .text-slate-500 { color: rgba(255,255,255,0.4); }
        body.dark #loginCard .text-slate-400 { color: rgba(255,255,255,0.3); }

        body.dark #loginCard input {
            background-color: rgba(255,255,255,0.06);
            border-color: rgba(255,255,255,0.1);
            color: #ffffff;
        }
        body.dark #loginCard input::placeholder { color: rgba(255,255,255,0.22); }
        body.dark #loginCard input:focus {
            border-color: #3b82f6;
            background-color: rgba(255,255,255,0.09);
            box-shadow: 0 0 0 4px rgba(59,130,246,0.15);
        }

        body.dark #loginCard .hover\:text-slate-700:hover { color: rgba(255,255,255,0.7); }
        body.dark #loginCard .hover\:shadow-md:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.3); }

        /* Buttons: invert to light on dark background so they stand out */
        body.dark #loginCard .bg-slate-900 { background-color: #f1f5f9; }
        body.dark #loginCard .bg-slate-900.text-white { color: #0f172a; }
        body.dark #loginCard .hover\:bg-slate-700:hover { background-color: #e2e8f0; }

        /* App showcase tints */
        body.dark #loginCard .bg-purple-50\/60 { background-color: rgba(124,58,237,0.1); }
        body.dark #loginCard .bg-orange-50\/70 { background-color: rgba(249,115,22,0.1); }
        body.dark #loginCard .bg-purple-50     { background-color: rgba(124,58,237,0.15); }
        body.dark #loginCard .bg-orange-50     { background-color: rgba(249,115,22,0.15); }
        body.dark #loginCard .text-purple-700  { color: #a78bfa; }
        body.dark #loginCard .text-orange-700  { color: #fb923c; }
        body.dark #loginCard .border-purple-100 { border-color: rgba(124,58,237,0.2); }
        body.dark #loginCard .border-orange-100 { border-color: rgba(249,115,22,0.2); }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased">
<script>var _ct=localStorage.getItem('centrikyTheme');if(_ct==='light'){document.body.classList.add('light');}if(_ct==='dark'){document.body.classList.add('dark');}</script>

<?php if (!$me['authenticated'] && $maintenance): ?>

<!-- ── Maintenance page ── -->
<main class="relative flex min-h-screen items-center justify-center overflow-hidden px-4">
    <div class="absolute inset-0 bg-[linear-gradient(135deg,#0d1117_0%,#0f1928_45%,#111d35_100%)]"></div>
    <div class="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>
    <div class="absolute left-8 top-16 h-72 w-72 rounded-full bg-blue-700/10 blur-3xl"></div>
    <div class="absolute right-10 bottom-20 h-80 w-80 rounded-full bg-slate-600/15 blur-3xl"></div>

    <div class="relative w-full max-w-md text-center">
        <div class="flex items-center justify-center gap-3 mb-8">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/10 text-white">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6">
                    <rect x="3" y="3" width="7" height="7" rx="1.5"/>
                    <rect x="14" y="3" width="7" height="7" rx="1.5"/>
                    <rect x="3" y="14" width="7" height="7" rx="1.5"/>
                    <rect x="14" y="14" width="7" height="7" rx="1.5"/>
                </svg>
            </div>
            <span class="text-2xl font-black tracking-tight text-white">Centryk</span>
        </div>

        <div class="overflow-hidden rounded-2xl border border-white/10 bg-[#111827] px-8 py-10 shadow-2xl">
            <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-amber-400/15">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
            </div>
            <h1 class="text-xl font-black tracking-tight text-white">Under Construction</h1>
            <p class="mt-3 text-sm font-semibold leading-relaxed text-white/50">
                We're making some improvements. Centryk will be back shortly.
            </p>
            <p class="mt-6 text-[11px] font-bold uppercase tracking-[0.18em] text-white/20">
                &copy; <?= date('Y') ?> Centryk
            </p>
        </div>
    </div>
</main>

<?php elseif (!$me['authenticated']): ?>

<!-- ── Landing page (unauthenticated) ── -->
<main class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-6">

    <!-- Background: dark navy — no purple -->
    <div class="absolute inset-0 bg-[linear-gradient(135deg,#0d1117_0%,#0f1928_45%,#111d35_100%)]"></div>
    <!-- Subtle orbs -->
    <div class="absolute left-8 top-16 h-72 w-72 rounded-full bg-blue-700/10 blur-3xl"></div>
    <div class="absolute right-10 bottom-20 h-80 w-80 rounded-full bg-slate-600/20 blur-3xl"></div>
    <!-- Top accent: purple → blue → orange — representing both apps -->
    <div class="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>

    <section id="loginCard" class="relative grid w-full max-w-6xl overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_32px_100px_rgba(0,0,0,0.18)] md:grid-cols-[1.05fr_0.95fr]">

        <!-- ── LEFT: Branding + app showcase ── -->
        <div id="loginLeftPanel" class="hidden border-r border-slate-100 bg-white p-6 md:flex md:flex-col md:justify-start md:overflow-y-auto">
            <div class="space-y-5">

                <!-- Centryk brand — dark, neutral -->
                <div class="flex items-center gap-3">
                    <img src="../centryk_logo.png" alt="Centryk" class="h-16 w-auto">
                    <div class="flex-1"></div>
                    <div class="flex items-center gap-1.5">
                        <a href="about.php"   class="rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-1.5 text-[11px] font-black uppercase tracking-[0.14em] text-slate-600 shadow-sm transition hover:bg-slate-900 hover:text-white hover:border-slate-900">About</a>
                        <a href="contact.php" class="rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-1.5 text-[11px] font-black uppercase tracking-[0.14em] text-slate-600 shadow-sm transition hover:bg-slate-900 hover:text-white hover:border-slate-900">Contact</a>
                        <a href="refer.php"   class="rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-1.5 text-[11px] font-black uppercase tracking-[0.14em] text-slate-600 shadow-sm transition hover:bg-slate-900 hover:text-white hover:border-slate-900">Refer</a>
                        <a href="terms.php"   class="rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-1.5 text-[11px] font-black uppercase tracking-[0.14em] text-slate-600 shadow-sm transition hover:bg-slate-900 hover:text-white hover:border-slate-900">Terms</a>
                    </div>
                </div>

                <!-- Tagline -->
                <div>
                    <h1 class="max-w-sm text-[2rem] font-black leading-[1.05] tracking-tight text-slate-950">
                        Built for Belize.<br>Built for business.
                    </h1>
                    <p class="mt-2 text-sm font-semibold text-slate-500">The business platform Belize was waiting for.</p>
                </div>

                <!-- 3-step onboarding -->
                <div>
                    <p class="mb-3 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Get started in 3 easy steps</p>
                    <div class="flex flex-col gap-0">

                        <!-- Step 1 -->
                        <div class="flex gap-3">
                            <div class="flex flex-col items-center">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-[11px] font-black text-white">1</span>
                                <div class="mt-1 w-px flex-1 bg-slate-200"></div>
                            </div>
                            <div class="pb-4">
                                <p class="text-sm font-black text-slate-900">Create your account</p>
                                <p class="mt-0.5 text-xs font-semibold leading-relaxed text-slate-500">Enter your company email, name, and business name to get instant access.</p>
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div class="flex gap-3">
                            <div class="flex flex-col items-center">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-black text-slate-500 ring-1 ring-slate-200">2</span>
                                <div class="mt-1 w-px flex-1 bg-slate-200"></div>
                            </div>
                            <div class="pb-4">
                                <p class="text-sm font-black text-slate-900">Set up your business</p>
                                <p class="mt-0.5 text-xs font-semibold leading-relaxed text-slate-500">Add your stores, products, employees, or payroll information depending on the app you'll use.</p>
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div class="flex gap-3">
                            <div class="flex flex-col items-center">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-black text-slate-500 ring-1 ring-slate-200">3</span>
                            </div>
                            <div class="pb-1">
                                <p class="text-sm font-black text-slate-900">Start using your tools</p>
                                <p class="mt-0.5 text-xs font-semibold leading-relaxed text-slate-500">Access OnePay for inventory &amp; POS or MyPay for payroll and HR management.</p>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="h-px w-full bg-slate-200"></div>
                <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Your apps</p>

                <!-- OnePay card — purple is OnePay's brand, not Centryk's -->
                <div class="max-w-md rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md">
                    <div class="flex items-center gap-3">
                        <!-- OnePay logo: circle with star -->
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-purple-700 text-white shadow-sm shadow-purple-200 ring-1 ring-white/60">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5">
                                <path d="M12 2.5c.72 5.08 2.42 6.78 7.5 7.5-5.08.72-6.78 2.42-7.5 7.5-.72-5.08-2.42-6.78-7.5-7.5 5.08-.72 6.78-2.42 7.5-7.5Z"/>
                            </svg>
                        </span>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-purple-700">Inventory &amp; POS</p>
                            <h2 class="text-base font-black tracking-tight text-slate-950">OnePay</h2>
                        </div>
                    </div>
                    <p class="mt-2 text-xs font-semibold text-slate-500">Multi-store inventory, point of sale, live registers, and cashless payments — all in one dashboard.</p>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <span class="rounded-full bg-purple-50 px-2.5 py-0.5 text-[10px] font-bold text-purple-700">Inventory</span>
                        <span class="rounded-full bg-purple-50 px-2.5 py-0.5 text-[10px] font-bold text-purple-700">POS</span>
                        <span class="rounded-full bg-purple-50 px-2.5 py-0.5 text-[10px] font-bold text-purple-700">Payments</span>
                    </div>
                </div>

                <!-- MyPay card — orange brand, real logo -->
                <div class="max-w-md rounded-2xl border-2 border-orange-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md">
                    <div class="flex items-center gap-3">
                        <img src="../myPay.png" alt="MyPay" class="h-10 w-10 rounded-xl object-contain shadow-sm shadow-orange-200">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-orange-600">HR &amp; Payroll</p>
                            <h2 class="text-base font-black tracking-tight text-slate-950">MyPay</h2>
                        </div>
                    </div>
                    <p class="mt-2 text-xs font-semibold text-slate-500">Payroll processing, employee management, time tracking, and HR tools — simplified for Belizean businesses.</p>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <span class="rounded-full bg-orange-50 px-2.5 py-0.5 text-[10px] font-bold text-orange-700">Payroll</span>
                        <span class="rounded-full bg-orange-50 px-2.5 py-0.5 text-[10px] font-bold text-orange-700">HR</span>
                        <span class="rounded-full bg-orange-50 px-2.5 py-0.5 text-[10px] font-bold text-orange-700">Time Tracking</span>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── RIGHT: Login + account request ── -->
        <div id="loginRightPanel" class="bg-[rgba(249,250,251,0.98)] p-5 sm:p-7 lg:px-8 lg:py-6">

            <!-- Mobile brand -->
            <div class="mb-4 flex items-center gap-2 md:hidden">
                <img src="../centryk_logo.png" alt="Centryk" class="h-12 w-auto">
                <div class="flex-1"></div>
                <div class="flex items-center gap-1.5">
                    <a href="about.php"   class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-[11px] font-black uppercase tracking-[0.14em] text-slate-600 shadow-sm transition hover:bg-slate-900 hover:text-white hover:border-slate-900">About</a>
                    <a href="contact.php" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-[11px] font-black uppercase tracking-[0.14em] text-slate-600 shadow-sm transition hover:bg-slate-900 hover:text-white hover:border-slate-900">Contact</a>
                </div>
            </div>

            <div class="mb-4">
                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Secure Access</p>
                <h2 class="mt-1 text-[1.7rem] font-black tracking-tight text-slate-950">Welcome back</h2>
            </div>

            <!-- Login form -->
            <form id="loginForm" class="rounded-3xl border border-slate-200 bg-white p-5 text-slate-900 shadow-sm">
                <div id="loginAlert" class="mb-4 hidden rounded-2xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600"></div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Email Address</label>
                        <input name="email" type="email" required autofocus
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                            placeholder="you@company.com">
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Password</label>
                        <div class="relative">
                            <input name="password" id="passwordInput" type="password" required
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 pr-10 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                placeholder="Enter password">
                            <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 hover:text-slate-700" tabindex="-1">
                                <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg id="eyeOffIcon" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.97 9.97 0 012.5-4.1M9.88 9.88a3 3 0 104.243 4.243M3 3l18 18"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <button id="loginBtn" class="mt-4 w-full rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-black uppercase tracking-[0.12em] text-white shadow transition-all duration-200 hover:bg-slate-700 active:scale-[0.99] focus:outline-none focus:ring-4 focus:ring-slate-300">
                    Sign In to Centryk
                </button>
            </form>

            <!-- Divider -->
            <div class="my-4 flex items-center gap-3">
                <div class="h-px flex-1 bg-slate-200"></div>
                <span class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">or</span>
                <div class="h-px flex-1 bg-slate-200"></div>
            </div>

            <!-- Account request -->
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-start justify-between gap-2 mb-0.5">
                    <h3 class="text-base font-black tracking-tight text-slate-900">Are you a business in Belize?</h3>
                    <span class="mt-0.5 shrink-0 rounded-full bg-emerald-500 px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.12em] text-white">Free</span>
                </div>
                <p class="mb-3 text-xs font-semibold text-slate-500">Get started free — no credit card required.</p>
                <form id="requestForm" class="mt-3 flex flex-col gap-2">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <input id="reqEmail" type="email" required placeholder="you@company.com"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                        <input id="reqFirstName" type="text" required placeholder="Your first name"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                    </div>
                    <input id="reqCompanyName" type="text" required placeholder="Your company name"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                    <button id="reqBtn" class="w-full rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-black uppercase tracking-[0.12em] text-white shadow transition-all duration-200 hover:bg-slate-700 focus:outline-none focus:ring-4 focus:ring-slate-300">
                        Get Instant Access
                    </button>
                </form>
                <div id="reqAlert" class="mt-3 hidden rounded-xl p-3 text-xs font-semibold"></div>
                <p class="mt-3 flex items-center gap-1.5 text-[11px] font-semibold text-slate-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Your login details will be sent to your inbox instantly.
                </p>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-center gap-4">
                <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-400">
                    <i data-lucide="shield-check" class="h-3.5 w-3.5 shrink-0"></i>
                    Secured by Centryk &copy; <?= date('Y') ?>
                </p>
                <a href="refer.php" class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400 transition hover:text-slate-700">Refer</a>
                <a href="terms.php" class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400 transition hover:text-slate-700">Terms</a>
                <button id="themeToggle" class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-[0.12em] text-slate-400 transition hover:text-slate-600">
                    <i data-lucide="sun"  id="themeIconSun"  class="h-3.5 w-3.5 hidden"></i>
                    <i data-lucide="moon" id="themeIconMoon" class="h-3.5 w-3.5"></i>
                    <span id="themeLabel">Dark</span>
                </button>
            </div>
        </div>

        <!-- ── BOTTOM: Feature grid ── -->
        <div id="loginFeatureGrid" class="border-t border-slate-100 bg-white px-5 py-5 sm:px-7 lg:px-8 md:col-span-2">
            <div class="mx-auto max-w-5xl">
                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Why Centryk</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 shadow-sm">
                        <div class="flex items-center gap-2 text-slate-700">
                            <i data-lucide="key-round" class="h-4 w-4 shrink-0"></i>
                            <h3 class="text-[10px] font-black uppercase tracking-[0.14em]">One Login</h3>
                        </div>
                        <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-600">Sign in once to access OnePay and MyPay. No separate credentials. No wasted time.</p>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 shadow-sm">
                        <div class="flex items-center gap-2 text-slate-700">
                            <i data-lucide="arrow-left-right" class="h-4 w-4 shrink-0"></i>
                            <h3 class="text-[10px] font-black uppercase tracking-[0.14em]">Instant Switch</h3>
                        </div>
                        <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-600">Jump between OnePay and MyPay in seconds. Your session follows you — no re-login needed.</p>
                    </div>

                    <div class="rounded-2xl border border-purple-100 bg-purple-50/60 p-3 shadow-sm">
                        <div class="flex items-center gap-2 text-purple-700">
                            <i data-lucide="shopping-cart" class="h-4 w-4 shrink-0"></i>
                            <h3 class="text-[10px] font-black uppercase tracking-[0.14em]">Inventory &amp; POS</h3>
                        </div>
                        <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-600">OnePay handles multi-store inventory, live registers, and cashless payments built for Belize.</p>
                    </div>

                    <div class="rounded-2xl border border-orange-100 bg-orange-50/70 p-3 shadow-sm">
                        <div class="flex items-center gap-2 text-orange-700">
                            <i data-lucide="users" class="h-4 w-4 shrink-0"></i>
                            <h3 class="text-[10px] font-black uppercase tracking-[0.14em]">Payroll &amp; HR</h3>
                        </div>
                        <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-600">MyPay manages your team — payroll, time tracking, and HR records in one clean platform.</p>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3 shadow-sm">
                        <div class="flex items-center gap-2 text-slate-700">
                            <i data-lucide="shield-check" class="h-4 w-4 shrink-0"></i>
                            <h3 class="text-[10px] font-black uppercase tracking-[0.14em]">Secure by Design</h3>
                        </div>
                        <p class="mt-2 text-xs font-semibold leading-relaxed text-slate-600">Role-based access, encrypted sessions, and one-time tokens keep every login safe and verified.</p>
                    </div>

                </div>
            </div>
        </div>

    </section>
</main>

<script>
(function () {
    // ── Theme ─────────────────────────────────────────────────────────────────
    function applyThemeUI() {
        var isDark = document.body.classList.contains('dark');
        document.getElementById('themeIconSun').classList.toggle('hidden', !isDark);
        document.getElementById('themeIconMoon').classList.toggle('hidden', isDark);
        document.getElementById('themeLabel').textContent = isDark ? 'Light' : 'Dark';
    }

    document.getElementById('themeToggle').addEventListener('click', function () {
        var isDark = document.body.classList.toggle('dark');
        localStorage.setItem('centrikyTheme', isDark ? 'dark' : 'light');
        applyThemeUI();
        if (window.lucide) { lucide.createIcons(); }
    });

    applyThemeUI();

    // ── Login ──
    var loginForm  = document.getElementById('loginForm');
    var loginAlert = document.getElementById('loginAlert');
    var loginBtn   = document.getElementById('loginBtn');

    loginForm.addEventListener('submit', function (e) {
        e.preventDefault();
        loginAlert.classList.add('hidden');
        loginBtn.disabled    = true;
        loginBtn.textContent = 'Signing in…';

        fetch('api/auth/login.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                email:    loginForm.querySelector('[name="email"]').value,
                password: loginForm.querySelector('[name="password"]').value,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                loginAlert.textContent = data.message || 'Invalid credentials.';
                loginAlert.classList.remove('hidden');
                loginAlert.classList.add('animate-shake');
                setTimeout(function () { loginAlert.classList.remove('animate-shake'); }, 500);
                loginBtn.disabled    = false;
                loginBtn.textContent = 'Sign In to Centryk';
            }
        })
        .catch(function () {
            loginAlert.textContent = 'Network error. Please try again.';
            loginAlert.classList.remove('hidden');
            loginBtn.disabled    = false;
            loginBtn.textContent = 'Sign In to Centryk';
        });
    });

    // ── Toggle password ──
    document.getElementById('togglePassword').addEventListener('click', function () {
        var input  = document.getElementById('passwordInput');
        var hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        document.getElementById('eyeIcon').classList.toggle('hidden', hidden);
        document.getElementById('eyeOffIcon').classList.toggle('hidden', !hidden);
    });

    // ── Account request ──
    var reqForm  = document.getElementById('requestForm');
    var reqAlert = document.getElementById('reqAlert');
    var reqBtn   = document.getElementById('reqBtn');

    reqForm.addEventListener('submit', function (e) {
        e.preventDefault();
        reqAlert.classList.add('hidden');
        reqBtn.disabled    = true;
        reqBtn.textContent = 'Submitting…';

        fetch('api/requests/submit.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                email:        document.getElementById('reqEmail').value,
                first_name:   document.getElementById('reqFirstName').value,
                company_name: document.getElementById('reqCompanyName').value,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                reqAlert.className   = 'mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-700';
                reqAlert.textContent = data.message;
                reqAlert.classList.remove('hidden');
                reqForm.reset();
            } else {
                reqAlert.className   = 'mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600';
                reqAlert.textContent = data.message || 'Something went wrong.';
                reqAlert.classList.remove('hidden');
            }
            reqBtn.disabled    = false;
            reqBtn.textContent = 'Get Instant Access';
        })
        .catch(function () {
            reqAlert.className   = 'mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600';
            reqAlert.textContent = 'Network error. Please try again.';
            reqAlert.classList.remove('hidden');
            reqBtn.disabled    = false;
            reqBtn.textContent = 'Get Instant Access';
        });
    });
}());
</script>

<?php else: ?>

<!-- ── Dashboard (authenticated) ── -->
<div class="min-h-screen bg-slate-100">

<!-- Top accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>

<!-- Header -->
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white shadow-sm">
    <div class="mx-auto flex max-w-5xl items-center gap-4 px-6 py-3">

        <!-- Logo -->
        <div class="flex shrink-0 items-center">
            <img src="../centryk_logo.png" alt="Centryk" class="h-12 w-auto">
        </div>

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
            </div>
        </div>

        <!-- Manage Companies shortcut -->
        <a href="companies.php" class="shrink-0 flex items-center gap-1.5 rounded-xl border border-slate-200 px-2.5 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 hover:border-slate-300">
            <i data-lucide="settings-2" class="h-3.5 w-3.5"></i>
            <span class="hidden sm:inline">Manage Companies</span>
        </a>

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
                    <p class="text-sm font-bold text-slate-900 leading-tight"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></p>
                    <p class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars($user['email']) ?></p>
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
<main class="mx-auto max-w-5xl px-6 py-10">

    <!-- Greeting -->
    <div class="mb-8">
        <h1 class="text-2xl font-black tracking-tight text-slate-900">
            Welcome back, <?= htmlspecialchars($user['first_name']) ?>
        </h1>
        <p id="companyContext" class="mt-1 text-sm font-semibold text-slate-400">Select a company above, then open an app.</p>
        <a id="companyProfileLink"
           href="companies.php"
           class="mt-2 hidden inline-flex items-center gap-1.5 text-xs font-black uppercase tracking-[0.12em] text-slate-500 transition hover:text-slate-900">
            <i data-lucide="users" class="h-3.5 w-3.5"></i>
            <span id="companyMemberCount">0 members</span>
            <i data-lucide="arrow-up-right" class="h-3.5 w-3.5"></i>
        </a>
    </div>

    <!-- Apps grid -->
    <div id="appsGrid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($apps as $app):
            $enrolled = !empty($app['enrolled']);
        ?>
        <button class="app-card group flex flex-col overflow-hidden rounded-2xl border text-left shadow-sm transition
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
                <div id="app-count-<?= htmlspecialchars($app['key']) ?>" class="app-count-badge mt-3 hidden items-center gap-1.5 text-[11px] font-bold text-slate-400">
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0Z"/>
                    </svg>
                    <span class="app-count-num">—</span>
                </div>
                <div class="mt-3 flex items-center justify-between rounded-xl px-3 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white"
                     style="background:<?= htmlspecialchars($app['color']) ?>">
                    Open <?= htmlspecialchars($app['label']) ?>
                    <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                </div>
                <?php else: ?>
                <div class="mt-4 flex items-center justify-center gap-1.5 rounded-xl px-3 py-2 text-[10px] font-black uppercase tracking-[0.14em] bg-slate-200 text-slate-400">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                    Not enrolled
                </div>
                <?php endif; ?>
            </div>
        </button>
        <?php endforeach; ?>

        <!-- Invoice Maker — coming soon (static, not in DB) -->
        <div class="flex flex-col overflow-hidden rounded-2xl border border-emerald-200/70 bg-emerald-50/40 text-left shadow-sm opacity-75 cursor-not-allowed select-none">
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
    </div>

    <!-- No-company notice (shown when no companies exist) -->
    <div id="noCompanyNotice" class="hidden mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
        You're not part of any company yet. Contact your admin to be added.
    </div>

    <p class="mt-10 flex items-center justify-center gap-1.5 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-300">
        <i data-lucide="shield-check" class="h-3.5 w-3.5"></i>
        Secured by Centryk &copy; <?= date('Y') ?>
    </p>

</main>
</div>

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
    var profileLink  = document.getElementById('companyProfileLink');
    var memberCount  = document.getElementById('companyMemberCount');
    var noCompNotice = document.getElementById('noCompanyNotice');

    var roleColors = { admin: '#7c3aed', manager: '#2563eb', employee: '#475569' };

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
            memberCount.textContent = c.member_count + ' member' + (Number(c.member_count) === 1 ? '' : 's');
            profileLink.href = 'companies.php' + (selectedUuid ? ('?company_uuid=' + encodeURIComponent(selectedUuid)) : '');
            profileLink.classList.remove('hidden');

            // Update per-app employee counts on each card
            var counts = (c.app_counts && typeof c.app_counts === 'object') ? c.app_counts : {};
            document.querySelectorAll('.app-count-badge').forEach(function (badge) {
                var appKey = badge.id.replace('app-count-', '');
                var n = counts[appKey];
                var numEl = badge.querySelector('.app-count-num');
                if (numEl) {
                    numEl.textContent = (n !== undefined && n !== null)
                        ? (n === 1 ? '1 active user' : n + ' active users')
                        : 'No users yet';
                }
                badge.classList.remove('hidden');
                badge.classList.add('flex');
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
            profileLink.classList.add('hidden');
            return;
        }

        dropdownList.innerHTML = companies.map(function (c) {
            var color   = roleColors[c.role] || roleColors.employee;
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
            profileLink.classList.add('hidden');
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

<?php endif; ?>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    if (window.lucide) { lucide.createIcons(); }
</script>

</body>
</html>
