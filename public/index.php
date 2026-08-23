<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';
Auth::start();

// Phones get the mobile hub instead of the full desktop UI. Tablets are
// treated as desktop - enough screen for the real thing. A visitor who
// picks "Continue to desktop site" (mobile/views/account.php, or anywhere
// desktop.php gets linked) gets a cookie that skips this permanently.
if (empty($_COOKIE['centryk_view']) || $_COOKIE['centryk_view'] !== 'desktop') {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isMobile = preg_match('/iPhone|Android.*Mobile|Windows Phone|BlackBerry/i', $ua) === 1;
    if ($isMobile) {
        header('Location: mobile/');
        exit;
    }
}

$me = AuthService::me();

// Logged in → render the dashboard. Logged out → marketing landing below.
if ($me['authenticated']) {
    $user = $me['user'];
    $apps = $me['apps'];

    // First-login company setup: if this admin has a company that hasn't been
    // through the setup wizard yet, send them there before the dashboard.
    try {
        $pend = DB::pdo()->prepare("
            SELECT c.id FROM companies c
            JOIN company_members cm ON cm.company_id = c.id
            WHERE cm.user_id = :uid AND cm.role = 'admin' AND cm.status = 'active'
              AND c.onboarded_at IS NULL
            ORDER BY c.created_at ASC LIMIT 1");
        $pend->execute(['uid' => (int)$user['id']]);
        if ($pend->fetchColumn()) {
            header('Location: onboarding.php');
            exit;
        }
    } catch (Throwable $e) {}

    $isCompanyAdmin = false;
    try {
        $caStmt = DB::pdo()->prepare("
            SELECT 1 FROM company_members cm
            JOIN companies c ON c.id = cm.company_id
            WHERE cm.user_id = :uid AND cm.role = 'admin' AND cm.status = 'active'
              AND c.status = 'active'
            LIMIT 1");
        $caStmt->execute(['uid' => (int)$user['id']]);
        $isCompanyAdmin = (bool)$caStmt->fetchColumn();
    } catch (Throwable $e) {}

    $showOnboarding     = false;
    $hasDefaultPassword = false;
    try {
        $obRow = DB::pdo()->prepare("SELECT onboarding_seen, password FROM users WHERE id = :id LIMIT 1");
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
    include __DIR__ . '/partials/dashboard.php';
    exit;
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Centryk — Run your business. All in one place.</title>
    <meta name="description" content="Centryk is a unified business platform built for Belizean companies. One login for OnePay (Inventory & POS) and MyPay (HR & Payroll).">
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
        /* ── Flowing gradient text ───────────────────────────────────────── */
        .gradient-text {
            background: linear-gradient(90deg, #7c3aed 0%, #3b82f6 28%, #f97316 55%, #7c3aed 100%);
            background-size: 250% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: gradient-flow 6s linear infinite;
        }
        @keyframes gradient-flow {
            0%   { background-position: 0% center; }
            100% { background-position: 250% center; }
        }

        /* ── Hero entrance ───────────────────────────────────────────────── */
        @keyframes fade-up {
            from { opacity: 0; transform: translateY(22px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .hero-fade {
            opacity: 0;
            animation: fade-up 0.75s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }
        .d1 { animation-delay: 0.1s; }
        .d2 { animation-delay: 0.28s; }
        .d3 { animation-delay: 0.5s; }

        /* ── Step card flip-in ───────────────────────────────────────────── */
        @keyframes card-enter {
            from {
                opacity: 0;
                transform: perspective(700px) rotateX(10deg) translateY(28px) scale(0.97);
            }
            to {
                opacity: 1;
                transform: perspective(700px) rotateX(0deg) translateY(0) scale(1);
            }
        }
        .step-card {
            opacity: 0;
            animation: card-enter 0.65s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }
        .sc1 { animation-delay: 0.62s; }
        .sc2 { animation-delay: 0.80s; }
        .sc3 { animation-delay: 0.98s; }

        /* ── Background orb breathe ──────────────────────────────────────── */
        @keyframes orb-breathe {
            0%, 100% { opacity: 0.7; transform: scale(1) translateX(-50%) translateY(-25%); }
            50%       { opacity: 1;   transform: scale(1.12) translateX(-50%) translateY(-25%); }
        }
        @keyframes orb-breathe-b {
            0%, 100% { opacity: 0.5; transform: scale(1) translateX(33%) translateY(25%); }
            50%       { opacity: 0.9; transform: scale(1.1) translateX(33%) translateY(25%); }
        }
        .orb-a { animation: orb-breathe   12s ease-in-out infinite; }
        .orb-b { animation: orb-breathe-b 15s ease-in-out infinite 2s; }

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
<body class="bg-white font-sans antialiased text-slate-900">

<!-- Top accent -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500 sticky top-0 z-50"></div>

<!-- ── NAV ─────────────────────────────────────────────────────────────────── -->
<nav class="sticky top-[3px] z-40 border-b border-slate-200 bg-gradient-to-b from-white to-slate-50/80 shadow-sm shadow-slate-200/70 backdrop-blur-sm">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-1.5">
        <a href="index.php" class="centryk-logo-lockup flex items-center">
            <img src="assets/centryk_logo.png" alt="Centryk" class="centryk-logo-mark h-10 w-auto">
        </a>

        <div class="flex-1"></div>

        <div class="hidden md:flex items-center gap-2">
            <?php $awAlign = 'left'; include __DIR__ . '/partials/app_switcher.php'; ?>
            <div class="h-4 w-px bg-slate-200"></div>
            <a href="about.php"   class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition">About</a>
            <a href="contact.php" class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition">Contact</a>
        </div>

        <a href="login.php"
           class="ml-3 rounded-lg bg-slate-900 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white shadow-sm shadow-slate-900/20 transition hover:bg-slate-700">
            Sign In
        </a>
    </div>
</nav>


<!-- ── HERO ───────────────────────────────────────────────────────────────── -->
<section class="relative overflow-hidden bg-[linear-gradient(135deg,#0d1117_0%,#0f1928_50%,#111d35_100%)] px-6 py-10 text-white md:py-14">
    <div class="absolute inset-x-0 top-0 h-px bg-white/10"></div>
    <div class="orb-a absolute left-0 top-0 h-[500px] w-[500px] rounded-full bg-purple-700/10 blur-3xl -translate-x-1/2 -translate-y-1/4"></div>
    <div class="orb-b absolute right-0 bottom-0 h-[400px] w-[400px] rounded-full bg-blue-700/10 blur-3xl translate-x-1/3 translate-y-1/4"></div>

    <div class="relative mx-auto max-w-4xl text-center">
        <h1 class="hero-fade d1 text-4xl font-black leading-[1.05] tracking-tight md:text-5xl">
            Run your business.<br>
            <span class="gradient-text">All in one place.</span>
        </h1>
        <p class="hero-fade d2 mx-auto mt-4 max-w-2xl text-base font-semibold leading-relaxed text-white/60 md:text-lg">
            Centryk is a unified business platform that gives Belizean companies one secure login to access
            powerful tools for inventory, point of sale, HR, payroll, and cashless payments.
        </p>

        <div class="hero-fade d3 mx-auto mt-6 max-w-5xl rounded-xl border border-white/10 bg-white/5 p-3 text-left backdrop-blur-sm md:p-4">
            <div class="grid gap-3 md:grid-cols-3">
                <div class="step-card sc1 rounded-xl border border-white/10 bg-white/5 p-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white text-xs font-black text-slate-900">1</span>
                    <h3 class="mt-3 text-base font-black tracking-tight text-white">Create your account</h3>
                    <p class="mt-1.5 text-xs font-semibold leading-relaxed text-white/60">
                        Enter your company email, name, and business name to get instant access.
                    </p>
                </div>

                <div class="step-card sc2 rounded-xl border border-white/10 bg-white/5 p-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-xs font-black text-white ring-1 ring-white/15">2</span>
                    <h3 class="mt-3 text-base font-black tracking-tight text-white">Set up your business</h3>
                    <p class="mt-1.5 text-xs font-semibold leading-relaxed text-white/60">
                        Add your stores, products, employees, or payroll information depending on the tools you need.
                    </p>
                </div>

                <div class="step-card sc3 rounded-xl border border-white/10 bg-white/5 p-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-xs font-black text-white ring-1 ring-white/15">3</span>
                    <h3 class="mt-3 text-base font-black tracking-tight text-white">Start using your tools</h3>
                    <p class="mt-1.5 text-xs font-semibold leading-relaxed text-white/60">
                        Access OnePay for inventory and POS or MyPay for payroll and HR management.
                    </p>
                </div>
            </div>

            <div class="mt-4 border-t border-white/10 pt-4">
                <div class="flex flex-wrap items-center justify-center gap-2 md:justify-start">
                    <span class="text-[10px] font-black uppercase tracking-[0.18em] text-white/35">Included Apps</span>
                    <a href="about.php#onepay" class="inline-flex items-center gap-2 rounded-full border border-purple-200/15 bg-purple-50/10 px-3 py-1.5 text-xs font-black text-purple-100 transition hover:bg-purple-50/15">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-purple-100 p-0.5">
                            <img src="assets/onepay_logo.png" alt="" class="h-full w-full object-contain">
                        </span>
                        OnePay
                    </a>
                    <a href="about.php#mypay" class="inline-flex items-center gap-2 rounded-full border border-orange-200/15 bg-orange-50/10 px-3 py-1.5 text-xs font-black text-orange-100 transition hover:bg-orange-50/15">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-orange-100">
                            <img src="assets/myPay.png" alt="" class="h-3.5 w-3.5 object-contain">
                        </span>
                        MyPay
                    </a>
                    <a href="login.php#request" class="inline-flex items-center gap-2 rounded-full border border-teal-200/15 bg-teal-50/10 px-3 py-1.5 text-xs font-black text-teal-100 transition hover:bg-teal-50/15">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-teal-100 text-teal-700">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </span>
                        Calendar
                    </a>
                    <a href="login.php#request" class="inline-flex items-center gap-2 rounded-full border border-emerald-200/15 bg-emerald-50/10 px-3 py-1.5 text-xs font-black text-emerald-100 transition hover:bg-emerald-50/15">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></svg>
                        </span>
                        Invoices
                    </a>
                    <a href="login.php#request" class="inline-flex items-center gap-2 rounded-full border border-cyan-200/15 bg-cyan-50/10 px-3 py-1.5 text-xs font-black text-cyan-100 transition hover:bg-cyan-50/15">
                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-cyan-100 text-cyan-700">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="m10 9 5 3-5 3V9Z"/><path d="M8 21h8"/></svg>
                        </span>
                        Centryk TV
                    </a>
                </div>
            </div>
            <div class="mt-4 flex justify-center md:justify-start">
                <a href="login.php#request"
                   class="rounded-xl bg-white px-5 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-slate-900 shadow-lg transition hover:bg-slate-100">
                    Get Started - It's Free
                </a>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/partials/business_directory.php'; ?>


<?php include __DIR__ . '/partials/footer.php'; ?>

</body>
</html>
