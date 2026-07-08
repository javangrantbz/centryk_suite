<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';
Auth::start();
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
    </style>
</head>
<body class="bg-white font-sans antialiased text-slate-900">

<!-- Top accent -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500 sticky top-0 z-50"></div>

<!-- ── NAV ─────────────────────────────────────────────────────────────────── -->
<nav class="sticky top-[3px] z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-3">
        <a href="index.php" class="flex items-center">
            <img src="../centryk_logo.png" alt="Centryk" class="h-14 w-auto">
        </a>

        <div class="flex-1"></div>

        <div class="hidden md:flex items-center gap-2">
            <?php $awAlign = 'left'; include __DIR__ . '/partials/app_switcher.php'; ?>
            <div class="h-4 w-px bg-slate-200"></div>
            <a href="about.php"   class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition">About</a>
            <a href="contact.php" class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition">Contact</a>
        </div>

        <a href="login.php"
           class="ml-3 rounded-xl bg-slate-900 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-700">
            Sign In
        </a>
    </div>
</nav>


<!-- ── HERO ───────────────────────────────────────────────────────────────── -->
<section class="relative overflow-hidden bg-[linear-gradient(135deg,#0d1117_0%,#0f1928_50%,#111d35_100%)] px-6 py-24 text-white">
    <div class="absolute inset-x-0 top-0 h-px bg-white/10"></div>
    <div class="orb-a absolute left-0 top-0 h-[500px] w-[500px] rounded-full bg-purple-700/10 blur-3xl -translate-x-1/2 -translate-y-1/4"></div>
    <div class="orb-b absolute right-0 bottom-0 h-[400px] w-[400px] rounded-full bg-blue-700/10 blur-3xl translate-x-1/3 translate-y-1/4"></div>

    <div class="relative mx-auto max-w-4xl text-center">
        <h1 class="hero-fade d1 text-5xl font-black leading-[1.05] tracking-tight md:text-6xl">
            Run your business.<br>
            <span class="gradient-text">All in one place.</span>
        </h1>
        <p class="hero-fade d2 mx-auto mt-6 max-w-2xl text-lg font-semibold leading-relaxed text-white/60">
            Centryk is a unified business platform that gives Belizean companies one secure login to access
            powerful tools for inventory, point of sale, HR, payroll, and cashless payments.
        </p>

        <div class="hero-fade d3 mx-auto mt-12 max-w-5xl rounded-[28px] border border-white/10 bg-white/5 p-6 text-left backdrop-blur-sm">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="step-card sc1 rounded-3xl border border-white/10 bg-white/5 p-5">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white text-sm font-black text-slate-900">1</span>
                    <h3 class="mt-4 text-lg font-black tracking-tight text-white">Create your account</h3>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-white/60">
                        Enter your company email, name, and business name to get instant access.
                    </p>
                </div>

                <div class="step-card sc2 rounded-3xl border border-white/10 bg-white/5 p-5">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-sm font-black text-white ring-1 ring-white/15">2</span>
                    <h3 class="mt-4 text-lg font-black tracking-tight text-white">Set up your business</h3>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-white/60">
                        Add your stores, products, employees, or payroll information depending on the tools you need.
                    </p>
                </div>

                <div class="step-card sc3 rounded-3xl border border-white/10 bg-white/5 p-5">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-sm font-black text-white ring-1 ring-white/15">3</span>
                    <h3 class="mt-4 text-lg font-black tracking-tight text-white">Start using your tools</h3>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-white/60">
                        Access OnePay for inventory and POS or MyPay for payroll and HR management.
                    </p>
                </div>
            </div>
            <div class="mt-6 flex justify-center md:justify-start">
                <a href="login.php#request"
                   class="rounded-xl bg-white px-6 py-3 text-sm font-black uppercase tracking-[0.12em] text-slate-900 shadow-lg transition hover:bg-slate-100">
                    Get Started - It's Free
                </a>
            </div>
        </div>
    </div>
</section>


<?php include __DIR__ . '/partials/footer.php'; ?>

</body>
</html>
