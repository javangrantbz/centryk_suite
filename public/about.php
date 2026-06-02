<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>About Centryk — Run your business. All in one place.</title>
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
        html { scroll-behavior: smooth; }
        .gradient-text {
            background: linear-gradient(90deg, #7c3aed, #3b82f6, #f97316);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .section-fade {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 0.55s ease, transform 0.55s ease;
        }
        .section-fade.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .step-line::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 100%;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, #e2e8f0, transparent);
        }
        @keyframes pulse-ring {
            0%   { box-shadow: 0 0 0 0 rgba(124,58,237,0.25); }
            70%  { box-shadow: 0 0 0 12px rgba(124,58,237,0); }
            100% { box-shadow: 0 0 0 0 rgba(124,58,237,0); }
        }
        .pulse { animation: pulse-ring 2.5s ease-out infinite; }
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
            <a href="about.php"   class="px-3 py-1.5 rounded-lg text-sm font-bold text-slate-900 bg-slate-100">About</a>
            <a href="contact.php" class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition">Contact</a>
        </div>

        <a href="login.php"
           class="ml-3 rounded-xl bg-slate-900 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-700">
            Sign In
        </a>
    </div>
</nav>


<!-- ── HERO ───────────────────────────────────────────────────────────────── -->
<section class="relative overflow-hidden bg-[linear-gradient(135deg,#0d1117_0%,#0f1928_50%,#111d35_100%)] px-6 py-20 text-white">
    <div class="absolute left-0 top-0 h-96 w-96 rounded-full bg-purple-700/10 blur-3xl -translate-x-1/2 -translate-y-1/4"></div>
    <div class="absolute right-0 bottom-0 h-80 w-80 rounded-full bg-blue-700/8 blur-3xl translate-x-1/3"></div>
    <div class="relative mx-auto max-w-3xl text-center">
        <span class="inline-block rounded-full border border-white/15 bg-white/8 px-4 py-1.5 text-[11px] font-black uppercase tracking-[0.22em] text-white/60 mb-6">Built for Belize</span>
        <h1 class="text-5xl font-black leading-tight tracking-tight">About Centryk</h1>
        <p class="mt-5 text-lg font-semibold leading-relaxed text-white/55 max-w-2xl mx-auto">
            One platform. One login. Everything your Belizean business needs to run inventory, payroll, and payments.
        </p>
    </div>
</section>


<!-- ── SECTION JUMP BAR ──────────────────────────────────────────────────── -->


<!-- ── WHAT IS CENTRYK ────────────────────────────────────────────────────── -->
<section id="platform" class="px-6 py-20 bg-white section-fade">
    <div class="mx-auto max-w-6xl">
        <div class="grid gap-12 lg:grid-cols-2 items-center">
            <div>
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-400 mb-3">What is Centryk</p>
                <h2 class="text-4xl font-black leading-tight tracking-tight text-slate-950">
                    One platform.<br>Every tool your<br>business needs.
                </h2>
                <p class="mt-5 text-base font-semibold leading-relaxed text-slate-500">
                    Centryk is the central hub that connects all the business tools you use every day.
                    Instead of juggling multiple logins, dashboards, and subscriptions, Centryk gives your
                    team a single, secure entry point to two powerful apps — <strong class="text-purple-700">OnePay</strong> and <strong class="text-orange-600">MyPay</strong>.
                </p>
                <p class="mt-4 text-base font-semibold leading-relaxed text-slate-500">
                    Whether you're a retailer managing inventory across multiple stores, a restaurant running
                    a point-of-sale system, or an employer processing payroll for your staff — Centryk keeps
                    everything connected, organized, and accessible from one place.
                </p>

                <div class="mt-8 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400 mb-1">Businesses Served</p>
                        <p class="text-3xl font-black text-slate-900">Belize</p>
                        <p class="text-xs font-semibold text-slate-400 mt-0.5">Locally built &amp; operated</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400 mb-1">Apps Included</p>
                        <p class="text-3xl font-black text-slate-900">2</p>
                        <p class="text-xs font-semibold text-slate-400 mt-0.5">OnePay &amp; MyPay</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400 mb-1">Logins Required</p>
                        <p class="text-3xl font-black text-slate-900">1</p>
                        <p class="text-xs font-semibold text-slate-400 mt-0.5">One set of credentials</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400 mb-1">Cost to Start</p>
                        <p class="text-3xl font-black text-emerald-600">Free</p>
                        <p class="text-xs font-semibold text-slate-400 mt-0.5">Request access today</p>
                    </div>
                </div>
            </div>

            <!-- App cards preview -->
            <div class="space-y-4">
                <div class="rounded-2xl border-2 border-purple-100 bg-white p-6 shadow-lg">
                    <div class="flex items-center gap-4">
                        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-purple-700 text-white shadow-md shadow-purple-200">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="h-7 w-7">
                                <path d="M12 2.5c.72 5.08 2.42 6.78 7.5 7.5-5.08.72-6.78 2.42-7.5 7.5-.72-5.08-2.42-6.78-7.5-7.5 5.08-.72 6.78-2.42 7.5-7.5Z"/>
                            </svg>
                        </span>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-purple-700">Inventory &amp; POS</p>
                            <h3 class="text-xl font-black tracking-tight text-slate-950">OnePay</h3>
                        </div>
                    </div>
                    <p class="mt-3 text-sm font-semibold leading-relaxed text-slate-500">Multi-store inventory, point of sale terminals, live registers, and cashless payments via OneLink — all unified.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="rounded-full bg-purple-50 px-3 py-1 text-[10px] font-black text-purple-700">Inventory</span>
                        <span class="rounded-full bg-purple-50 px-3 py-1 text-[10px] font-black text-purple-700">POS</span>
                        <span class="rounded-full bg-purple-50 px-3 py-1 text-[10px] font-black text-purple-700">OneLink Payments</span>
                        <span class="rounded-full bg-purple-50 px-3 py-1 text-[10px] font-black text-purple-700">Multi-Store</span>
                    </div>
                </div>

                <div class="rounded-2xl border-2 border-orange-200 bg-white p-6 shadow-lg">
                    <div class="flex items-center gap-4">
                        <img src="../myPay.png" alt="MyPay" class="h-14 w-14 rounded-xl object-contain shadow-md shadow-orange-100">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-orange-600">HR &amp; Payroll</p>
                            <h3 class="text-xl font-black tracking-tight text-slate-950">MyPay</h3>
                        </div>
                    </div>
                    <p class="mt-3 text-sm font-semibold leading-relaxed text-slate-500">Payroll processing, employee records, time tracking, and HR management built for Belizean businesses.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="rounded-full bg-orange-50 px-3 py-1 text-[10px] font-black text-orange-700">Payroll</span>
                        <span class="rounded-full bg-orange-50 px-3 py-1 text-[10px] font-black text-orange-700">HR</span>
                        <span class="rounded-full bg-orange-50 px-3 py-1 text-[10px] font-black text-orange-700">Time Tracking</span>
                        <span class="rounded-full bg-orange-50 px-3 py-1 text-[10px] font-black text-orange-700">Employee Mgmt</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section nav -->
    </div>
</section>


<!-- ── HOW IT WORKS ───────────────────────────────────────────────────────── -->
<section id="how-it-works" class="px-6 py-20 bg-slate-50 section-fade">
    <div class="mx-auto max-w-5xl">
        <div class="text-center mb-14">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-400 mb-3">How it works</p>
            <h2 class="text-4xl font-black tracking-tight text-slate-950">Up and running in three steps</h2>
            <p class="mt-4 text-base font-semibold text-slate-500">From account request to launching your first app — the process is fast, guided, and free.</p>
        </div>

        <div class="grid gap-6 md:grid-cols-3">
            <div class="relative rounded-2xl border border-slate-200 bg-white p-6 shadow-sm text-center">
                <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-slate-900 text-white text-xl font-black shadow-lg">1</div>
                <h3 class="text-base font-black tracking-tight text-slate-900 mb-2">Request Access</h3>
                <p class="text-sm font-semibold leading-relaxed text-slate-500">Submit your name, company, and email on the login page. No credit card. No commitment. Approval is fast.</p>
            </div>
            <div class="relative rounded-2xl border border-blue-100 bg-blue-50/40 p-6 shadow-sm text-center">
                <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-blue-600 text-white text-xl font-black shadow-lg shadow-blue-200">2</div>
                <h3 class="text-base font-black tracking-tight text-slate-900 mb-2">Get Provisioned</h3>
                <p class="text-sm font-semibold leading-relaxed text-slate-500">Our team reviews your request, creates your account, and sends you a welcome email with login credentials and your assigned apps.</p>
            </div>
            <div class="relative rounded-2xl border border-purple-100 bg-purple-50/40 p-6 shadow-sm text-center">
                <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-purple-700 text-white text-xl font-black shadow-lg shadow-purple-200">3</div>
                <h3 class="text-base font-black tracking-tight text-slate-900 mb-2">Launch Your Apps</h3>
                <p class="text-sm font-semibold leading-relaxed text-slate-500">Sign in to Centryk, select your company, and open OnePay or MyPay with one click. Your session follows you across apps.</p>
            </div>
        </div>

        <div class="mt-10 text-center">
            <a href="login.php"
               class="inline-block rounded-xl bg-slate-900 px-8 py-3 text-sm font-black uppercase tracking-[0.12em] text-white shadow transition hover:bg-slate-700">
                Request Access Now
            </a>
        </div>

        <!-- Section nav -->
    </div>
</section>


<!-- ── ONEPAY ─────────────────────────────────────────────────────────────── -->
<section id="onepay" class="px-6 py-20 bg-white section-fade">
    <div class="mx-auto max-w-6xl">
        <div class="flex items-center gap-4 mb-3">
            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-purple-700 text-white shadow-md shadow-purple-200">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6">
                    <path d="M12 2.5c.72 5.08 2.42 6.78 7.5 7.5-5.08.72-6.78 2.42-7.5 7.5-.72-5.08-2.42-6.78-7.5-7.5 5.08-.72 6.78-2.42 7.5-7.5Z"/>
                </svg>
            </span>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-purple-700">App 01</p>
                <h2 class="text-3xl font-black tracking-tight text-slate-950">OnePay</h2>
            </div>
        </div>
        <p class="text-base font-semibold leading-relaxed text-slate-500 max-w-2xl mb-12">
            OnePay is your all-in-one inventory and point-of-sale platform. Whether you run one shop or a chain of stores across Belize, OnePay gives you live visibility, fast checkout, and integrated cashless payments.
        </p>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">

            <div class="rounded-2xl border border-purple-100 bg-purple-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-purple-700/10 text-purple-700">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Multi-Store Inventory</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Track stock levels across all your locations in real time. Get low-stock alerts, manage categories, and transfer inventory between stores without manual reconciliation.</p>
            </div>

            <div class="rounded-2xl border border-purple-100 bg-purple-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-purple-700/10 text-purple-700">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7H6a2 2 0 00-2 2v9a2 2 0 002 2h9a2 2 0 002-2v-3M16 3l5 5-9 9H7v-5l9-9z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Point of Sale (POS)</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">A fast, intuitive POS interface designed for busy checkout environments. Process sales, apply discounts, handle returns, and print receipts — all from one screen.</p>
            </div>

            <div class="rounded-2xl border border-purple-100 bg-purple-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-purple-700/10 text-purple-700">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Live Registers</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Open and close cash registers with live reconciliation. Track cash-in, cash-out, voids, and refunds per register session. Managers can monitor all registers in real time from the dashboard.</p>
            </div>

            <div class="rounded-2xl border border-purple-100 bg-purple-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-purple-700/10 text-purple-700">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Cashless Payments</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Accept card and digital payments at the point of sale via the OneLink payment gateway. No separate terminal software needed — payments flow directly into OnePay's transaction record.</p>
            </div>

            <div class="rounded-2xl border border-purple-100 bg-purple-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-purple-700/10 text-purple-700">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Sales Reporting</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Daily, weekly, and monthly sales summaries broken down by store, cashier, product, and category. Export reports to review performance and make data-driven purchasing decisions.</p>
            </div>

            <div class="rounded-2xl border border-purple-100 bg-purple-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-purple-700/10 text-purple-700">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Role-Based Staff Access</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Assign cashier, manager, or admin roles per store. Cashiers can only process sales; managers can view reports and void transactions; admins control everything.</p>
            </div>

        </div>

        <!-- Who OnePay is for -->
        <div class="mt-10 rounded-2xl border border-slate-200 bg-slate-50 p-6">
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-4">Who OnePay is built for</p>
            <div class="flex flex-wrap gap-3">
                <span class="rounded-full border border-purple-200 bg-white px-4 py-2 text-xs font-bold text-purple-700">Retail Stores</span>
                <span class="rounded-full border border-purple-200 bg-white px-4 py-2 text-xs font-bold text-purple-700">Supermarkets</span>
                <span class="rounded-full border border-purple-200 bg-white px-4 py-2 text-xs font-bold text-purple-700">Restaurants &amp; Cafes</span>
                <span class="rounded-full border border-purple-200 bg-white px-4 py-2 text-xs font-bold text-purple-700">Pharmacies</span>
                <span class="rounded-full border border-purple-200 bg-white px-4 py-2 text-xs font-bold text-purple-700">Hardware Stores</span>
                <span class="rounded-full border border-purple-200 bg-white px-4 py-2 text-xs font-bold text-purple-700">Multi-Branch Retailers</span>
                <span class="rounded-full border border-purple-200 bg-white px-4 py-2 text-xs font-bold text-purple-700">Service Businesses</span>
            </div>
        </div>

        <!-- Section nav -->
    </div>
</section>


<!-- ── MYPAY ──────────────────────────────────────────────────────────────── -->
<section id="mypay" class="px-6 py-20 bg-slate-50 section-fade">
    <div class="mx-auto max-w-6xl">
        <div class="flex items-center gap-4 mb-3">
            <img src="../myPay.png" alt="MyPay" class="h-12 w-12 rounded-xl object-contain shadow-md shadow-orange-100">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-orange-600">App 02</p>
                <h2 class="text-3xl font-black tracking-tight text-slate-950">MyPay</h2>
            </div>
        </div>
        <p class="text-base font-semibold leading-relaxed text-slate-500 max-w-2xl mb-12">
            MyPay simplifies everything related to your workforce — from paying your employees accurately and on time, to tracking hours, managing HR records, and staying compliant with Belizean labour regulations.
        </p>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">

            <div class="rounded-2xl border border-orange-100 bg-orange-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Payroll Processing</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Run accurate payroll for your entire team in minutes. Calculate wages, overtime, deductions, and bonuses automatically. Generate payslips and maintain a complete payroll history.</p>
            </div>

            <div class="rounded-2xl border border-orange-100 bg-orange-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Employee Management</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Maintain complete employee profiles — contact details, position, department, start date, salary, and employment type. Update records easily as your team grows or changes.</p>
            </div>

            <div class="rounded-2xl border border-orange-100 bg-orange-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Time Tracking</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Log clock-in and clock-out times for employees. Track regular hours, overtime, and absences. Time data feeds directly into payroll so calculations are always accurate.</p>
            </div>

            <div class="rounded-2xl border border-orange-100 bg-orange-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">HR Records &amp; Documents</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Store and manage HR documents, contracts, and notes in one place. Keep a full audit trail of salary changes, promotions, warnings, and disciplinary actions.</p>
            </div>

            <div class="rounded-2xl border border-orange-100 bg-orange-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Leave &amp; Attendance</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Manage vacation requests, sick leave, and public holidays. View team attendance at a glance and ensure adequate staffing coverage across all departments.</p>
            </div>

            <div class="rounded-2xl border border-orange-100 bg-orange-50/40 p-5">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Payroll Reports</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Generate detailed payroll summaries, tax reports, and deduction breakdowns. Stay prepared for Social Security contributions and annual reporting requirements.</p>
            </div>

        </div>

        <div class="mt-10 rounded-2xl border border-slate-200 bg-white p-6">
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-4">Who MyPay is built for</p>
            <div class="flex flex-wrap gap-3">
                <span class="rounded-full border border-orange-200 bg-orange-50 px-4 py-2 text-xs font-bold text-orange-700">Small Businesses</span>
                <span class="rounded-full border border-orange-200 bg-orange-50 px-4 py-2 text-xs font-bold text-orange-700">Medium Enterprises</span>
                <span class="rounded-full border border-orange-200 bg-orange-50 px-4 py-2 text-xs font-bold text-orange-700">Retail Chains</span>
                <span class="rounded-full border border-orange-200 bg-orange-50 px-4 py-2 text-xs font-bold text-orange-700">Hospitality</span>
                <span class="rounded-full border border-orange-200 bg-orange-50 px-4 py-2 text-xs font-bold text-orange-700">Construction Companies</span>
                <span class="rounded-full border border-orange-200 bg-orange-50 px-4 py-2 text-xs font-bold text-orange-700">Professional Services</span>
                <span class="rounded-full border border-orange-200 bg-orange-50 px-4 py-2 text-xs font-bold text-orange-700">Any Belizean Employer</span>
            </div>
        </div>

        <!-- Section nav -->
    </div>
</section>


<!-- ── ONELINK PAYMENT GATEWAY ────────────────────────────────────────────── -->
<section id="onelink" class="px-6 py-20 bg-[linear-gradient(135deg,#0d1117_0%,#0f1928_50%,#111d35_100%)] text-white section-fade">
    <div class="mx-auto max-w-6xl">
        <div class="text-center mb-14">
            <span class="inline-block rounded-full border border-white/15 bg-white/8 px-4 py-1.5 text-[11px] font-black uppercase tracking-[0.22em] text-white/50 mb-5">
                Integrated Payments
            </span>
            <h2 class="text-4xl font-black tracking-tight">OneLink Payment Gateway</h2>
            <p class="mt-4 text-base font-semibold leading-relaxed text-white/55 max-w-2xl mx-auto">
                OneLink is Centryk's built-in payment gateway that connects your OnePay POS to cashless payment networks.
                It eliminates the need for third-party terminals and keeps your payment data inside your business dashboard.
            </p>
        </div>

        <!-- How OneLink works flow -->
        <div class="mb-12">
            <p class="text-center text-[10px] font-black uppercase tracking-[0.22em] text-white/35 mb-8">How a payment flows through OneLink</p>
            <div class="grid gap-4 md:grid-cols-5 items-center">
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5 text-center">
                    <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-purple-700 text-white text-sm font-black">1</div>
                    <p class="text-xs font-black text-white mb-1">Customer Pays</p>
                    <p class="text-[11px] font-semibold text-white/45">Card tap, swipe, or digital wallet presented at POS</p>
                </div>
                <div class="hidden md:flex justify-center text-white/20">
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M14 5l7 7-7 7M3 12h18"/></svg>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5 text-center">
                    <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-blue-600 text-white text-sm font-black">2</div>
                    <p class="text-xs font-black text-white mb-1">OneLink Processes</p>
                    <p class="text-[11px] font-semibold text-white/45">Encrypted transaction sent to payment network for authorization</p>
                </div>
                <div class="hidden md:flex justify-center text-white/20">
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M14 5l7 7-7 7M3 12h18"/></svg>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 p-5 text-center">
                    <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-emerald-600 text-white text-sm font-black">3</div>
                    <p class="text-xs font-black text-white mb-1">Approved &amp; Logged</p>
                    <p class="text-[11px] font-semibold text-white/45">Payment confirmed, sale recorded in OnePay automatically</p>
                </div>
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <div class="mb-3 text-blue-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                </div>
                <h3 class="text-sm font-black text-white mb-1.5">Multiple Payment Types</h3>
                <p class="text-xs font-semibold leading-relaxed text-white/50">Accept credit and debit cards from major networks. Support for tap-to-pay and contactless transactions at the POS terminal.</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <div class="mb-3 text-emerald-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <h3 class="text-sm font-black text-white mb-1.5">End-to-End Encryption</h3>
                <p class="text-xs font-semibold leading-relaxed text-white/50">Every transaction is encrypted from the moment the card is presented to the moment settlement occurs. Cardholder data is never stored in plain text.</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <div class="mb-3 text-purple-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <h3 class="text-sm font-black text-white mb-1.5">Instant Authorization</h3>
                <p class="text-xs font-semibold leading-relaxed text-white/50">Payments are authorized in seconds. Approved or declined responses appear on the POS screen immediately, keeping checkout lines moving fast.</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <div class="mb-3 text-orange-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <h3 class="text-sm font-black text-white mb-1.5">Unified Transaction Records</h3>
                <p class="text-xs font-semibold leading-relaxed text-white/50">Every card payment is automatically logged in OnePay's transaction history. No manual reconciliation between your payment terminal and POS system.</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <div class="mb-3 text-blue-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </div>
                <h3 class="text-sm font-black text-white mb-1.5">Refunds &amp; Voids</h3>
                <p class="text-xs font-semibold leading-relaxed text-white/50">Process refunds and void transactions directly from OnePay. Refund amounts are reversed to the original payment method with a complete audit trail.</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <div class="mb-3 text-emerald-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <h3 class="text-sm font-black text-white mb-1.5">Settlement Reporting</h3>
                <p class="text-xs font-semibold leading-relaxed text-white/50">View daily settlement summaries showing total card sales, fees, and net deposits. Reconcile your bank deposits against OneLink settlement reports with ease.</p>
            </div>
        </div>

        <!-- Section nav -->
    </div>
</section>


<!-- ── USER MANAGEMENT ────────────────────────────────────────────────────── -->
<section id="users" class="px-6 py-20 bg-white section-fade">
    <div class="mx-auto max-w-6xl">
        <div class="text-center mb-14">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-400 mb-3">User &amp; Company Management</p>
            <h2 class="text-4xl font-black tracking-tight text-slate-950">The right access for the right people</h2>
            <p class="mt-4 text-base font-semibold text-slate-500 max-w-2xl mx-auto">
                Centryk gives you granular control over who can access what. Manage companies, invite team members, and assign roles — all without IT support.
            </p>
        </div>

        <div class="grid gap-8 lg:grid-cols-2">
            <!-- Left: Managing companies -->
            <div>
                <h3 class="text-lg font-black tracking-tight text-slate-900 mb-5 flex items-center gap-2">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-900 text-white">
                        <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M3 21h18V9l-9-6-9 6v12zM9 21v-6h6v6H9z"/></svg>
                    </span>
                    Managing Companies
                </h3>
                <div class="space-y-3">
                    <div class="flex gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-white text-[10px] font-black">1</span>
                        <div>
                            <p class="text-sm font-black text-slate-900">Create your company</p>
                            <p class="text-xs font-semibold text-slate-500 mt-0.5">Admins can create one or more companies under their Centryk account. Each company is a separate workspace with its own apps, users, and data.</p>
                        </div>
                    </div>
                    <div class="flex gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-white text-[10px] font-black">2</span>
                        <div>
                            <p class="text-sm font-black text-slate-900">Invite team members</p>
                            <p class="text-xs font-semibold text-slate-500 mt-0.5">Send invitations by email. Team members accept the invite and are added to your company automatically — no Centryk admin approval needed.</p>
                        </div>
                    </div>
                    <div class="flex gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-white text-[10px] font-black">3</span>
                        <div>
                            <p class="text-sm font-black text-slate-900">Assign roles</p>
                            <p class="text-xs font-semibold text-slate-500 mt-0.5">Give each member a role that controls what they can see and do. Roles can be updated anytime as your team's responsibilities change.</p>
                        </div>
                    </div>
                    <div class="flex gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-white text-[10px] font-black">4</span>
                        <div>
                            <p class="text-sm font-black text-slate-900">Remove members instantly</p>
                            <p class="text-xs font-semibold text-slate-500 mt-0.5">Revoke a team member's access from the company management panel. Their Centryk account remains intact but they lose access to your company's apps immediately.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Roles -->
            <div>
                <h3 class="text-lg font-black tracking-tight text-slate-900 mb-5 flex items-center gap-2">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-900 text-white">
                        <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
                    </span>
                    Role Permissions
                </h3>
                <div class="space-y-3">
                    <div class="rounded-xl border-2 border-purple-100 bg-purple-50/30 p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="rounded-full bg-purple-700 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-[0.12em] text-white">Admin</span>
                        </div>
                        <p class="text-xs font-semibold leading-relaxed text-slate-600">Full control. Admins manage companies, invite and remove members, assign roles, access all apps, view all reports, and configure business settings. There must always be at least one admin per company.</p>
                    </div>
                    <div class="rounded-xl border-2 border-blue-100 bg-blue-50/30 p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="rounded-full bg-blue-600 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-[0.12em] text-white">Manager</span>
                        </div>
                        <p class="text-xs font-semibold leading-relaxed text-slate-600">Operational access. Managers can use all app features, view reports, and manage day-to-day tasks — but cannot change company structure, invite members, or adjust billing and account settings.</p>
                    </div>
                    <div class="rounded-xl border-2 border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="rounded-full bg-slate-600 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-[0.12em] text-white">Employee</span>
                        </div>
                        <p class="text-xs font-semibold leading-relaxed text-slate-600">Task-level access. Employees can access the apps they're assigned to and perform their specific functions — such as processing sales at POS — without access to reports, settings, or other users' data.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section nav -->
    </div>
</section>


<!-- ── SECURITY ───────────────────────────────────────────────────────────── -->
<section id="security" class="px-6 py-20 bg-slate-50 section-fade">
    <div class="mx-auto max-w-6xl">
        <div class="text-center mb-14">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-400 mb-3">Security &amp; Trust</p>
            <h2 class="text-4xl font-black tracking-tight text-slate-950">Your data is protected by design</h2>
            <p class="mt-4 text-base font-semibold text-slate-500 max-w-2xl mx-auto">
                Security isn't an afterthought at Centryk. Every layer of the platform is built with protection in mind —
                from the moment you log in to the moment a payment is processed.
            </p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Encrypted Sessions</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">All user sessions are encrypted and server-side. Your login token is never exposed in the URL or stored insecurely in the browser.</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">One-Time App Launch Tokens</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">When you launch OnePay or MyPay from Centryk, a short-lived, single-use token is generated. It expires immediately after use, preventing replay attacks.</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Role-Based Access Control</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Every action in Centryk is gated by the user's role. Employees can only do what their role allows — enforced server-side, not just in the UI.</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Welcome Email Verification</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">New accounts are provisioned manually by our team and confirmed with a welcome email. No automated self-signup means no unauthorized account creation.</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Company Data Isolation</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Each company's data is completely isolated. Users can only see data from companies they belong to. There is no cross-company data leakage.</p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                </div>
                <h3 class="text-sm font-black tracking-tight text-slate-900 mb-1.5">Secure Data Storage</h3>
                <p class="text-xs font-semibold leading-relaxed text-slate-500">Passwords are hashed using industry-standard algorithms. Sensitive data is never stored in plain text. Database access is restricted to application-layer requests only.</p>
            </div>
        </div>

        <!-- Section nav -->
    </div>
</section>


<!-- ── WHO WE SERVE ───────────────────────────────────────────────────────── -->
<section id="who-we-serve" class="px-6 py-20 bg-white section-fade">
    <div class="mx-auto max-w-5xl text-center">
        <p class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-400 mb-3">Who we serve</p>
        <h2 class="text-4xl font-black tracking-tight text-slate-950 mb-5">Built for Belizean businesses</h2>
        <p class="text-base font-semibold text-slate-500 max-w-2xl mx-auto mb-12">
            Centryk was designed from the ground up for businesses operating in Belize. We understand the local business environment, payment landscape, and workforce realities — and we've built tools that reflect that.
        </p>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-left">
                <div class="mb-3 text-2xl">🛒</div>
                <h3 class="text-sm font-black text-slate-900 mb-1">Retail &amp; Grocery</h3>
                <p class="text-xs font-semibold text-slate-500">Multi-store inventory, fast checkout, and card payment acceptance for shops and supermarkets of any size.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-left">
                <div class="mb-3 text-2xl">🍽️</div>
                <h3 class="text-sm font-black text-slate-900 mb-1">Restaurants &amp; Hospitality</h3>
                <p class="text-xs font-semibold text-slate-500">Table-side or counter POS, staff payroll, and tip management for restaurants, hotels, and bars.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-left">
                <div class="mb-3 text-2xl">🏗️</div>
                <h3 class="text-sm font-black text-slate-900 mb-1">Construction &amp; Trades</h3>
                <p class="text-xs font-semibold text-slate-500">Weekly payroll runs, time tracking for field crews, and labour cost reporting for project-based businesses.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-left">
                <div class="mb-3 text-2xl">💼</div>
                <h3 class="text-sm font-black text-slate-900 mb-1">Professional Services</h3>
                <p class="text-xs font-semibold text-slate-500">HR records, salary management, and employee documentation for law firms, clinics, and service companies.</p>
            </div>
        </div>

        <!-- Section nav -->
    </div>
</section>


<!-- ── FAQ ────────────────────────────────────────────────────────────────── -->
<section id="faq" class="px-6 py-20 bg-slate-50 section-fade">
    <div class="mx-auto max-w-3xl">
        <div class="text-center mb-12">
            <p class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-400 mb-3">FAQ</p>
            <h2 class="text-4xl font-black tracking-tight text-slate-950">Common questions</h2>
        </div>

        <div class="space-y-3" id="faqList">

            <details class="group rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center justify-between px-5 py-4 text-sm font-black text-slate-900 list-none">
                    Do I need separate logins for OnePay and MyPay?
                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="border-t border-slate-100 px-5 pb-4 pt-3 text-sm font-semibold leading-relaxed text-slate-500">
                    No. That's the whole point of Centryk. You sign in once with your Centryk credentials, select your company, and then launch either OnePay or MyPay with one click. Your session carries over securely — no re-login required.
                </div>
            </details>

            <details class="group rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center justify-between px-5 py-4 text-sm font-black text-slate-900 list-none">
                    How long does account provisioning take?
                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="border-t border-slate-100 px-5 pb-4 pt-3 text-sm font-semibold leading-relaxed text-slate-500">
                    After you submit your access request, our team reviews it and provisions your account typically within 1 business day. You'll receive a welcome email with your login credentials and the apps assigned to your account.
                </div>
            </details>

            <details class="group rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center justify-between px-5 py-4 text-sm font-black text-slate-900 list-none">
                    Can my business have access to both OnePay and MyPay?
                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="border-t border-slate-100 px-5 pb-4 pt-3 text-sm font-semibold leading-relaxed text-slate-500">
                    Yes. Your Centryk account can have access to one or both apps depending on your business needs. Simply mention which apps you need in your access request and we'll configure them accordingly.
                </div>
            </details>

            <details class="group rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center justify-between px-5 py-4 text-sm font-black text-slate-900 list-none">
                    Can I add multiple companies under one Centryk account?
                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="border-t border-slate-100 px-5 pb-4 pt-3 text-sm font-semibold leading-relaxed text-slate-500">
                    Yes. If you operate multiple businesses or branches, you can create separate companies within Centryk and switch between them using the company selector in the dashboard. Each company has its own users, roles, and data.
                </div>
            </details>

            <details class="group rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center justify-between px-5 py-4 text-sm font-black text-slate-900 list-none">
                    How does OneLink connect to my payment terminal?
                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="border-t border-slate-100 px-5 pb-4 pt-3 text-sm font-semibold leading-relaxed text-slate-500">
                    OneLink is integrated directly into OnePay's POS interface. When a cashier initiates a card payment, the POS communicates with OneLink to request authorization. The payment terminal receives the transaction, the customer taps or swipes, and the approved result flows back into OnePay automatically. There is no separate terminal software to manage.
                </div>
            </details>

            <details class="group rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center justify-between px-5 py-4 text-sm font-black text-slate-900 list-none">
                    Is my business data stored securely?
                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="border-t border-slate-100 px-5 pb-4 pt-3 text-sm font-semibold leading-relaxed text-slate-500">
                    Absolutely. All data is encrypted in transit and at rest. Passwords are hashed and never stored in plain text. Company data is isolated so no user can ever see another company's information. Sessions use secure, server-side tokens that expire after use.
                </div>
            </details>

            <details class="group rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center justify-between px-5 py-4 text-sm font-black text-slate-900 list-none">
                    What happens if I remove a team member from my company?
                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="border-t border-slate-100 px-5 pb-4 pt-3 text-sm font-semibold leading-relaxed text-slate-500">
                    Their access to your company and all its apps is revoked instantly. They can no longer see your company in their Centryk dashboard. Their Centryk account itself is not deleted — they may still belong to other companies — but they have zero access to your data.
                </div>
            </details>

            <details class="group rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center justify-between px-5 py-4 text-sm font-black text-slate-900 list-none">
                    Is Centryk free to use?
                    <svg class="h-4 w-4 shrink-0 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="border-t border-slate-100 px-5 pb-4 pt-3 text-sm font-semibold leading-relaxed text-slate-500">
                    Requesting access to Centryk is free. Reach out to us to learn about app-specific pricing for OnePay and MyPay. We offer plans sized for businesses of all types across Belize.
                </div>
            </details>

        </div>

        <!-- Section nav -->
    </div>
</section>


<!-- ── CTA ────────────────────────────────────────────────────────────────── -->
<section class="px-6 py-20 bg-[linear-gradient(135deg,#0d1117_0%,#0f1928_50%,#111d35_100%)] text-white section-fade">
    <div class="mx-auto max-w-3xl text-center">
        <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10 text-white">
            <svg viewBox="0 0 24 24" fill="currentColor" class="h-8 w-8">
                <rect x="3" y="3" width="7" height="7" rx="1.5"/>
                <rect x="14" y="3" width="7" height="7" rx="1.5"/>
                <rect x="3" y="14" width="7" height="7" rx="1.5"/>
                <rect x="14" y="14" width="7" height="7" rx="1.5"/>
            </svg>
        </div>
        <h2 class="text-4xl font-black tracking-tight">Ready to get started?</h2>
        <p class="mt-4 text-base font-semibold leading-relaxed text-white/55 max-w-xl mx-auto">
            Join businesses across Belize already running on Centryk. Request access today — it's free, fast, and our team will have you up and running within one business day.
        </p>
        <div class="mt-10 flex flex-wrap items-center justify-center gap-4">
            <a href="login.php"
               class="rounded-xl bg-white px-8 py-3 text-sm font-black uppercase tracking-[0.12em] text-slate-900 shadow-lg transition hover:bg-slate-100">
                Request Free Access
            </a>
            <a href="mailto:support@centryk.com"
               class="rounded-xl border border-white/15 px-8 py-3 text-sm font-black uppercase tracking-[0.12em] text-white/80 transition hover:border-white/30 hover:text-white">
                Contact Us
            </a>
        </div>
    </div>
</section>


<!-- ── BACK TO TOP ───────────────────────────────────────────────────────── -->
<button id="backToTop" style="display:none" class="fixed bottom-6 right-6 z-50 h-12 w-12 items-center justify-center rounded-full bg-slate-900 text-white shadow-lg transition hover:bg-slate-700 hover:scale-110 active:scale-95">
    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
</button>


<?php include __DIR__ . '/partials/footer.php'; ?>


<script>
(function () {
    // Scroll-triggered fade-in
    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
            }
        });
    }, { threshold: 0.08 });

    document.querySelectorAll('.section-fade').forEach(function (el) {
        observer.observe(el);
    });
})();

// Back to top
(function () {
    var btn = document.getElementById('backToTop');
    window.addEventListener('scroll', function () {
        btn.style.display = window.scrollY > 400 ? 'flex' : 'none';
    });
    btn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
})();
</script>

</body>
</html>
