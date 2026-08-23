<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';

// ── Maintenance mode — set to true to gate the login form ────────────────────
$maintenance = false;
$maintenance_whitelist = ['190.197.30.112'];  // add your IP to bypass maintenance
$visitor_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (in_array(trim(explode(',', $visitor_ip)[0]), $maintenance_whitelist)) {
    $maintenance = false;
}

Auth::start();
function centryk_safe_login_redirect(string $candidate): string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return 'index.php';
    }

    if (preg_match('/^(?:[a-z][a-z0-9+\-.]*:)?\/\//i', $candidate) === 1) {
        return 'index.php';
    }

    if ($candidate[0] === '/' || str_contains($candidate, '\\')) {
        return 'index.php';
    }

    return $candidate;
}

$postLoginRedirect = centryk_safe_login_redirect((string)($_GET['redirect'] ?? 'index.php'));
// Logged-in users belong on their dashboard (index.php), not the login form.
if (AuthService::me()['authenticated']) {
    header('Location: ' . $postLoginRedirect);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Sign In — Centryk</title>
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

        @keyframes lf-card {
            from { opacity: 0; transform: perspective(700px) rotateX(8deg) translateY(24px) scale(0.97); }
            to   { opacity: 1; transform: perspective(700px) rotateX(0deg) translateY(0) scale(1); }
        }
        .lf-d { opacity:0; animation: lf-card 0.65s cubic-bezier(0.22,1,0.36,1) 0.15s forwards; }

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

<?php if ($maintenance): ?>

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

<?php else: ?>

<!-- Top accent -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500 sticky top-0 z-50"></div>

<!-- ── NAV ── -->
<nav class="sticky top-[3px] z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-3">
        <a href="index.php" class="centryk-logo-lockup flex items-center">
            <img src="assets/centryk_logo.png" alt="Centryk" class="centryk-logo-mark h-14 w-auto">
        </a>
        <div class="flex-1"></div>
        <div class="hidden md:flex items-center gap-2">
            <?php $awAlign = 'left'; $awMode = 'links'; include __DIR__ . '/partials/app_switcher.php'; ?>
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

<!-- ── Forms ── -->
<section class="bg-slate-50 px-6 py-4 lg:py-5">
    <div class="mx-auto max-w-5xl">
        <div class="grid items-start gap-4 lg:grid-cols-[1.1fr_0.9fr]">

            <!-- ── Left: News & trust ── -->
            <div class="space-y-3.5">

                <!-- Announcements — shown with sign-in. Update these whenever something ships -->
                <div id="updatesPanel" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="mb-3.5 flex items-center gap-2.5">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-900 text-white">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                        </span>
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-500">Platform Updates</p>
                    </div>

                    <div class="divide-y divide-slate-100">
                        <div class="pb-3.5">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="rounded-full bg-teal-500 px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.1em] text-white">Coming Soon</span>
                                <span class="text-[10px] font-semibold text-slate-400">Aug 2026</span>
                            </div>
                            <p class="text-sm font-black text-slate-900">Centryk TV</p>
                            <p class="mt-0.5 text-xs font-semibold leading-relaxed text-slate-500">Live streaming and broadcasting for your organization, right from Centryk.</p>
                        </div>
                        <div class="py-3.5">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="rounded-full bg-blue-500 px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.1em] text-white">Update</span>
                                <span class="text-[10px] font-semibold text-slate-400">Aug 2026</span>
                            </div>
                            <p class="text-sm font-black text-slate-900">Centryk Connect</p>
                            <p class="mt-0.5 text-xs font-semibold leading-relaxed text-slate-500">Companies can now connect directly with each other on Centryk.</p>
                        </div>
                        <div class="pt-3.5">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="rounded-full bg-emerald-500 px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.1em] text-white">Update</span>
                                <span class="text-[10px] font-semibold text-slate-400">Aug 2026</span>
                            </div>
                            <p class="text-sm font-black text-slate-900">Instant card payment setup</p>
                            <p class="mt-0.5 text-xs font-semibold leading-relaxed text-slate-500">New companies now get OneLink card payment accounts provisioned automatically — no manual setup wait.</p>
                        </div>
                    </div>
                </div>

                <!-- Why Centryk — shown with request access -->
                <div id="whyPanel" class="hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="mb-3.5 text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Why Centryk</p>
                    <div class="space-y-3">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            </span>
                            <div>
                                <p class="text-sm font-black text-slate-900">One login for everything</p>
                                <p class="mt-0.5 text-xs font-semibold leading-relaxed text-slate-500">Access OnePay and MyPay with a single set of credentials. No juggling accounts.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </span>
                            <div>
                                <p class="text-sm font-black text-slate-900">Built for Belizean businesses</p>
                                <p class="mt-0.5 text-xs font-semibold leading-relaxed text-slate-500">Designed around local workflows, labour regulations, and payment systems.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            </span>
                            <div>
                                <p class="text-sm font-black text-slate-900">Secure by design</p>
                                <p class="mt-0.5 text-xs font-semibold leading-relaxed text-slate-500">Encrypted sessions, role-based access, and company data isolation built in from day one.</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ── Right: Form ── -->
            <div class="lg:sticky lg:top-24">
                <div class="lf-d rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:p-5">

            <!-- ── Sign-in view ── -->
            <div id="signinView">
                <div class="mb-3.5">
                    <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Returning user</p>
                    <h2 class="mt-1.5 text-2xl font-black tracking-tight text-slate-950">Welcome back</h2>
                </div>

                <form id="loginForm">
                    <div id="loginAlert" class="mb-3.5 hidden rounded-2xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600"></div>
                    <div class="space-y-3.5">
                        <div>
                            <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Email Address</label>
                            <input name="email" type="email" required autofocus
                                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                placeholder="you@company.com">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Password</label>
                            <div class="relative">
                                <input name="password" id="passwordInput" type="password" required
                                    class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 pr-11 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
                                    placeholder="Enter password">
                                <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 flex items-center px-3.5 text-slate-400 hover:text-slate-700" tabindex="-1">
                                    <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                    <svg id="eyeOffIcon" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.97 9.97 0 012.5-4.1M9.88 9.88a3 3 0 104.243 4.243M3 3l18 18"/>
                                    </svg>
                                </button>
                            </div>
                            <p id="capsLockWarning" class="mt-1.5 hidden text-[11px] font-bold text-amber-600">Caps Lock is on</p>
                        </div>
                    </div>

                    <button id="loginBtn"
                        class="mt-4 w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-black uppercase tracking-[0.12em] text-white shadow transition-all duration-200 hover:bg-slate-700 active:scale-[0.99] focus:outline-none focus:ring-4 focus:ring-slate-300">
                        Sign In to Centryk
                    </button>

                    <div class="mt-3.5 flex items-center justify-between gap-3 border-t border-slate-100 pt-3.5">
                        <a href="forgot-password.php" class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400 transition hover:text-slate-700">Forgot Password?</a>
                        <a href="contact.php" class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400 transition hover:text-slate-700">Need Help?</a>
                    </div>
                </form>

                <div class="mt-3.5 flex items-center gap-2.5 rounded-xl border border-emerald-100 bg-emerald-50/60 px-4 py-2.5">
                    <svg class="h-4 w-4 shrink-0 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.35C17.25 22.15 21 17.25 21 12V7l-9-5z"/></svg>
                    <p class="text-xs font-semibold text-slate-500">Encrypted sessions &amp; role-based access control.</p>
                </div>

                <div class="mt-3.5 border-t border-slate-100 pt-3 text-center">
                    <button id="showRequestBtn" class="group text-sm font-semibold text-slate-400 transition hover:text-slate-700">
                        New to Centryk? <span class="font-black text-slate-600 group-hover:text-slate-900">Request access →</span>
                    </button>
                </div>
            </div>

            <!-- ── Request access view ── -->
            <div id="requestView" class="hidden">
                <div class="mb-3.5 flex items-start justify-between gap-2">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">New to Centryk</p>
                        <h2 class="mt-1.5 text-2xl font-black tracking-tight text-slate-950">Request Access</h2>
                    </div>
                    <span class="mt-1 shrink-0 rounded-full bg-emerald-500 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.12em] text-white">Free</span>
                </div>

                <div id="reqSuccess" class="hidden rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center">
                    <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100">
                        <svg class="h-5 w-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <p class="text-sm font-black text-emerald-800">Request submitted!</p>
                    <p class="mt-1 text-xs font-semibold text-emerald-600">We'll review it and send your login details shortly.</p>
                </div>

                <form id="requestForm" class="space-y-2">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Email Address</label>
                            <input id="reqEmail" type="email" required placeholder="you@company.com"
                                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">First Name</label>
                            <input id="reqFirstName" type="text" required placeholder="Your first name"
                                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                        </div>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Company Name</label>
                        <input id="reqCompanyName" type="text" required placeholder="Your company name"
                            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                    </div>
                    <button id="reqBtn"
                        class="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-black uppercase tracking-[0.12em] text-white shadow transition-all duration-200 hover:bg-slate-700 focus:outline-none focus:ring-4 focus:ring-slate-300">
                        Get Instant Access
                    </button>
                    <div id="reqAlert" class="hidden rounded-xl p-3 text-xs font-semibold"></div>
                </form>

                <p class="mt-3 flex items-center gap-1.5 text-[11px] font-semibold text-slate-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Your login details will be sent to your inbox.
                </p>

                <div class="mt-3.5 border-t border-slate-100 pt-3 text-center">
                    <button id="showSigninBtn" class="group text-sm font-semibold text-slate-400 transition hover:text-slate-700">
                        ← <span class="font-black text-slate-600 group-hover:text-slate-900">Back to sign in</span>
                    </button>
                </div>
            </div>

                </div>
            </div>

        </div>
    </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>

<script>
(function () {
    var postLoginRedirect = <?= json_encode($postLoginRedirect, JSON_UNESCAPED_SLASHES) ?>;

    // ── Login ──────────────────────────────────────────────────────────────────
    var loginForm  = document.getElementById('loginForm');
    var loginAlert = document.getElementById('loginAlert');
    var loginBtn   = document.getElementById('loginBtn');

    loginForm.addEventListener('submit', function (e) {
        e.preventDefault();
        loginAlert.classList.add('hidden');
        loginBtn.disabled    = true;
        loginBtn.textContent = 'Signing in…';

        var loginRes = null;
        fetch('api/auth/login.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                email:    loginForm.querySelector('[name="email"]').value,
                password: loginForm.querySelector('[name="password"]').value,
            }),
        })
        .then(function (r) {
            loginRes = r;
            return r.clone().json();
        })
        .then(function (data) {
            if (data.success) {
                window.location.href = postLoginRedirect;
            } else {
                loginAlert.textContent = data.message || 'Invalid credentials.';
                loginAlert.classList.remove('hidden');
                loginAlert.classList.add('animate-shake');
                setTimeout(function () { loginAlert.classList.remove('animate-shake'); }, 500);
                loginBtn.disabled    = false;
                loginBtn.textContent = 'Sign In to Centryk';
            }
        })
        .catch(function (err) {
            if (loginRes) {
                loginRes.clone().text().then(function (bodyText) {
                    console.error('Login: response was not valid JSON.', loginRes.status, bodyText);
                }).catch(function () {});
                loginAlert.textContent = 'Login failed (HTTP ' + loginRes.status + '). Check the browser console for details.';
            } else {
                console.error('Login: request could not reach the server.', err);
                loginAlert.textContent = 'Network error (could not reach the server). Please try again.';
            }
            loginAlert.classList.remove('hidden');
            loginBtn.disabled    = false;
            loginBtn.textContent = 'Sign In to Centryk';
        });
    });

    // ── View toggle ────────────────────────────────────────────────────────────
    function showRequest() {
        document.getElementById('signinView').classList.add('hidden');
        document.getElementById('requestView').classList.remove('hidden');
        document.getElementById('updatesPanel').classList.add('hidden');
        document.getElementById('whyPanel').classList.remove('hidden');
    }
    function showSignin() {
        document.getElementById('requestView').classList.add('hidden');
        document.getElementById('signinView').classList.remove('hidden');
        document.getElementById('whyPanel').classList.add('hidden');
        document.getElementById('updatesPanel').classList.remove('hidden');
    }

    document.getElementById('showRequestBtn').addEventListener('click', showRequest);
    document.getElementById('showSigninBtn').addEventListener('click', showSignin);

    // Open request view directly when arriving via #request (e.g. "Get Started" CTA)
    if (window.location.hash === '#request') {
        showRequest();
        document.getElementById('reqEmail').focus();
    }

    // ── Caps Lock warning ──────────────────────────────────────────────────────
    // getModifierState reflects the physical keyboard state at the moment of
    // any keyboard event on the field, so checking on keydown/keyup (rather
    // than only on the character typed) catches it as soon as focus + a key
    // press happen, not just after Caps Lock itself is pressed.
    (function () {
        var pwInput = document.getElementById('passwordInput');
        var warning = document.getElementById('capsLockWarning');
        if (!pwInput || !warning) return;

        function checkCapsLock(e) {
            var on = typeof e.getModifierState === 'function' && e.getModifierState('CapsLock');
            warning.classList.toggle('hidden', !on);
        }

        pwInput.addEventListener('keydown', checkCapsLock);
        pwInput.addEventListener('keyup', checkCapsLock);
        pwInput.addEventListener('blur', function () { warning.classList.add('hidden'); });
    }());

    // ── Password toggle ────────────────────────────────────────────────────────
    document.getElementById('togglePassword').addEventListener('click', function () {
        var input  = document.getElementById('passwordInput');
        var hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        document.getElementById('eyeIcon').classList.toggle('hidden', hidden);
        document.getElementById('eyeOffIcon').classList.toggle('hidden', !hidden);
    });

    // ── Request access ─────────────────────────────────────────────────────────
    var reqForm    = document.getElementById('requestForm');
    var reqAlert   = document.getElementById('reqAlert');
    var reqBtn     = document.getElementById('reqBtn');
    var reqSuccess = document.getElementById('reqSuccess');

    reqForm.addEventListener('submit', function (e) {
        e.preventDefault();
        reqAlert.classList.add('hidden');
        reqBtn.disabled    = true;
        reqBtn.textContent = 'Submitting…';

        var reqRes = null;
        fetch('api/requests/submit.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                email:        document.getElementById('reqEmail').value,
                first_name:   document.getElementById('reqFirstName').value,
                company_name: document.getElementById('reqCompanyName').value,
            }),
        })
        .then(function (r) {
            reqRes = r;
            return r.clone().json();
        })
        .then(function (data) {
            if (data.success) {
                reqForm.classList.add('hidden');
                reqSuccess.classList.remove('hidden');
            } else {
                reqAlert.className   = 'rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600';
                reqAlert.textContent = data.message || 'Something went wrong.';
                reqAlert.classList.remove('hidden');
                reqBtn.disabled    = false;
                reqBtn.textContent = 'Get Instant Access';
            }
        })
        .catch(function (err) {
            reqAlert.className = 'rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600';
            if (reqRes) {
                reqRes.clone().text().then(function (bodyText) {
                    console.error('Request access: response was not valid JSON.', reqRes.status, bodyText);
                }).catch(function () {});
                reqAlert.textContent = 'Submission failed (HTTP ' + reqRes.status + '). Check the browser console for details.';
            } else {
                console.error('Request access: request could not reach the server.', err);
                reqAlert.textContent = 'Network error (could not reach the server). Please try again.';
            }
            reqAlert.classList.remove('hidden');
            reqBtn.disabled    = false;
            reqBtn.textContent = 'Get Instant Access';
        });
    });
}());
</script>

<?php endif; ?>

</body>
</html>
