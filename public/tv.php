<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Centryk TV — Keep the ticket money. Keep the viewer data.</title>
    <meta name="description" content="Centryk TV is coming soon: browser-based live broadcasting with pay-per-event ticketing through OneLink, built into the Centryk platform you already use.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] },
                    colors: {
                        brand: { 50: '#f0fdfa', 100: '#ccfbf1', 500: '#14b8a6', 600: '#0d9488', 700: '#0f766e', 900: '#134e4a' }
                    }
                }
            }
        }
    </script>
    <style>
        html { scroll-behavior: smooth; }
        @keyframes pulse-dot { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .5; transform: scale(.8); } }
        .live-dot { animation: pulse-dot 1.8s ease-in-out infinite; }
        @media (prefers-reduced-motion: reduce) { .live-dot { animation: none; } }
        .monitor-screen { background:
            radial-gradient(circle at 30% 20%, rgba(45,212,191,0.18), transparent 55%),
            radial-gradient(circle at 80% 80%, rgba(225,29,72,0.14), transparent 50%),
            linear-gradient(160deg, #0c1615 0%, #0a1110 60%, #090f0e 100%);
        }
        .monitor-screen::before {
            content: ""; position: absolute; inset: 0; pointer-events: none;
            background: repeating-linear-gradient(0deg, rgba(255,255,255,0.025) 0px, rgba(255,255,255,0.025) 1px, transparent 1px, transparent 3px);
        }
    </style>
</head>
<body class="bg-white font-sans antialiased text-slate-900">

<div class="h-[3px] w-full bg-gradient-to-r from-brand-700 via-brand-500 to-teal-300 sticky top-0 z-50"></div>

<nav class="sticky top-[3px] z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-3">
        <a href="index.php" class="flex items-center gap-2.5">
            <img src="assets/centryk_logo.png" alt="Centryk" class="h-9 w-auto">
            <span class="h-4 w-px bg-slate-200"></span>
            <span class="text-sm font-black tracking-tight text-slate-900">TV</span>
        </a>
        <div class="flex-1"></div>
        <span class="hidden items-center gap-1.5 rounded-full border border-brand-200 bg-brand-50 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.14em] text-brand-700 sm:flex">
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            Coming Soon
        </span>
        <a href="index.php" class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-700">Back to Centryk</a>
    </div>
</nav>

<!-- ── HERO ─────────────────────────────────────────────────────────────── -->
<header class="px-6 py-14 md:py-20">
    <div class="mx-auto grid max-w-6xl items-center gap-12 lg:grid-cols-2">
        <div>
            <span class="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3 py-1.5 text-[11px] font-black uppercase tracking-[0.14em] text-brand-700">
                <span class="live-dot inline-block h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                Live broadcasting, built into Centryk
            </span>
            <h1 class="mt-5 text-4xl font-black leading-[1.05] tracking-tight text-slate-900 md:text-5xl">
                Keep the ticket money.<br>Keep the viewer data.<br><span class="text-brand-700">Keep your audience.</span>
            </h1>
            <p class="mt-5 max-w-xl text-base font-medium leading-relaxed text-slate-500 md:text-lg">
                Stream your service, game, or conference straight from a phone's browser — no software, no download.
                Charge for access with the payment system you already use, and never split the door with a platform
                that also sells ads against your audience's attention.
            </p>
            <div class="mt-7 flex flex-wrap items-center gap-3">
                <a href="contact.php" class="rounded-xl bg-brand-700 px-5 py-3 text-xs font-black uppercase tracking-[0.12em] text-white shadow-lg shadow-brand-900/10 transition hover:bg-brand-600">Get notified at launch</a>
                <a href="#compare" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-xs font-black uppercase tracking-[0.12em] text-slate-700 transition hover:bg-slate-50">See the comparison</a>
            </div>
            <p class="mt-4 text-xs font-semibold text-slate-400">Still in early access — this page is a preview of what's coming to your dashboard.</p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-950 p-3 shadow-xl">
            <div class="monitor-screen relative aspect-[16/10] overflow-hidden rounded-xl">
                <div class="absolute left-3.5 top-3.5 flex items-center gap-1.5 rounded-full border border-white/10 bg-black/45 px-2.5 py-1.5 backdrop-blur">
                    <span class="live-dot inline-block h-2 w-2 rounded-full bg-rose-500"></span>
                    <span class="text-[10px] font-black tracking-[0.14em] text-white">ON AIR</span>
                </div>
                <div class="absolute right-3.5 top-3.5 rounded-full border border-white/10 bg-black/45 px-2.5 py-1.5 text-[10px] font-bold text-emerald-50 backdrop-blur">212 watching</div>
                <div class="absolute inset-x-3.5 bottom-3.5 rounded-xl border border-white/10 bg-black/60 p-3.5 backdrop-blur">
                    <p class="text-[13px] font-black text-white">New Life Baptist — Sunday Service</p>
                    <p class="mt-0.5 text-[11px] text-teal-100/70">Broadcasting live from Belize City</p>
                    <div class="mt-2.5 flex items-center justify-between border-t border-dashed border-white/15 pt-2.5">
                        <span class="text-[9px] font-black uppercase tracking-[0.12em] text-teal-300">Suggested offering</span>
                        <span class="text-sm font-black text-white">Free · tips open</span>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-between px-1.5 pt-2.5 text-[11px] font-semibold text-slate-400">
                <span>Browser-based Go Live</span>
                <span>HD · low-latency HLS</span>
            </div>
        </div>
    </div>
</header>

<!-- ── COMPARISON ───────────────────────────────────────────────────────── -->
<section id="compare" class="border-t border-slate-100 bg-slate-50/60 px-6 py-16">
    <div class="mx-auto max-w-5xl">
        <p class="text-[11px] font-black uppercase tracking-[0.16em] text-brand-700">The honest comparison</p>
        <h2 class="mt-2 max-w-2xl text-2xl font-black tracking-tight text-slate-900 md:text-3xl">Facebook and YouTube are free. Here's what that costs you.</h2>
        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-slate-500">They're not wrong for a business that just wants maximum free reach. Centryk TV is for the organization that already has an audience and wants to own what happens when that audience shows up.</p>

        <div class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="grid grid-cols-[1.5fr_1fr_1fr] border-b border-slate-200 bg-slate-50 text-[11px] font-black uppercase tracking-[0.08em] text-slate-400">
                <div class="px-4 py-3">Feature</div>
                <div class="px-4 py-3 text-brand-700">Centryk TV</div>
                <div class="px-4 py-3">Facebook / YouTube</div>
            </div>
            <?php
            $rows = [
                ['Ticket revenue', 'who keeps what a viewer pays', 'yes', 'You keep it, direct to your bank via OneLink', 'no', 'No native ticketing — needs a workaround'],
                ['Viewer data', 'who watched, how long, how they paid', 'yes', 'Yours — real-time counts & history', 'no', 'Belongs to the platform'],
                ['Ads on your stream', '', 'yes', 'None, ever', 'partial', 'Platform-controlled, can appear anytime'],
                ['Who decides your reach', '', 'yes', 'Everyone with your link sees it', 'no', 'An algorithm you don\'t control'],
                ['Setup to go live', '', 'yes', 'Open a browser tab, hit Go Live', 'yes', 'Also just an app'],
                ['Connected to payroll & payments', '', 'yes', 'Same login as OnePay & MyPay', 'no', 'Separate account, separate world'],
            ];
            $markClasses = [
                'yes' => 'bg-brand-50 text-brand-700',
                'no' => 'bg-rose-50 text-rose-600',
                'partial' => 'bg-slate-100 text-slate-400 border border-slate-200',
            ];
            $markGlyph = ['yes' => '&#10003;', 'no' => '&#10005;', 'partial' => '&#8211;'];
            foreach ($rows as $i => $row):
                [$feature, $sub, $tvMark, $tvText, $fbMark, $fbText] = $row;
            ?>
            <div class="grid grid-cols-[1.5fr_1fr_1fr] items-center text-sm <?= $i < count($rows) - 1 ? 'border-b border-slate-100' : '' ?>">
                <div class="px-4 py-3.5">
                    <p class="font-bold text-slate-800"><?= htmlspecialchars($feature) ?></p>
                    <?php if ($sub): ?><p class="mt-0.5 text-xs font-medium text-slate-400"><?= htmlspecialchars($sub) ?></p><?php endif; ?>
                </div>
                <div class="flex items-center gap-2 px-4 py-3.5 text-xs font-semibold text-slate-700">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] <?= $markClasses[$tvMark] ?>"><?= $markGlyph[$tvMark] ?></span>
                    <?= htmlspecialchars($tvText) ?>
                </div>
                <div class="flex items-center gap-2 px-4 py-3.5 text-xs font-semibold text-slate-500">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] <?= $markClasses[$fbMark] ?>"><?= $markGlyph[$fbMark] ?></span>
                    <?= htmlspecialchars($fbText) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ── HOW IT WORKS ─────────────────────────────────────────────────────── -->
<section class="px-6 py-16">
    <div class="mx-auto max-w-5xl">
        <p class="text-[11px] font-black uppercase tracking-[0.16em] text-brand-700">How it works</p>
        <h2 class="mt-2 text-2xl font-black tracking-tight text-slate-900 md:text-3xl">Three steps. No production crew required.</h2>
        <div class="mt-8 grid gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-sm font-black text-brand-700">1</span>
                <h3 class="mt-4 text-base font-black text-slate-900">Go live from a phone's browser</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-500">Point a phone at the game, the pulpit, or the stage and hit Go Live. No OBS, no software to install — a camera and a browser tab is the whole setup.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-sm font-black text-brand-700">2</span>
                <h3 class="mt-4 text-base font-black text-slate-900">Set a price, or leave it free</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-500">Charge per event through OneLink — your existing Belizean payment rail — or leave the channel open. Either way, the money lands in your own account.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-sm font-black text-brand-700">3</span>
                <h3 class="mt-4 text-base font-black text-slate-900">Let the replay keep working</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-500">Once you stop, the recording is ready to watch back on the same link — so a late viewer can still buy in and catch up, days after the event ended.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── MONEY SPOTLIGHT ──────────────────────────────────────────────────── -->
<section class="px-6 py-16">
    <div class="mx-auto max-w-5xl overflow-hidden rounded-3xl bg-brand-900 p-8 md:p-12">
        <div class="grid gap-8 md:grid-cols-2 md:items-center">
            <div>
                <p class="text-[11px] font-black uppercase tracking-[0.16em] text-brand-300">Ticketing, done right</p>
                <h2 class="mt-2 text-2xl font-black tracking-tight text-white md:text-3xl">A BZ$10 ticket should put BZ$10 in your account.</h2>
                <p class="mt-4 text-sm leading-relaxed text-teal-100/80">On a platform built for ads, a "paid livestream" is an afterthought bolted on with a third-party tool that takes its own cut. Centryk TV was built the other way around: pay-per-event access is a first-class feature, settled through the same OneLink connection already wired into your Centryk account.</p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
                <div class="flex items-baseline justify-between py-2 text-sm text-teal-50"><span>212 tickets sold</span><span class="font-bold">BZ$2,120.00</span></div>
                <div class="flex items-baseline justify-between border-t border-dashed border-white/10 py-2 text-sm text-teal-50"><span>Platform ad revenue cut</span><span class="font-bold">BZ$0.00</span></div>
                <div class="flex items-baseline justify-between border-t border-dashed border-white/10 py-2 text-sm text-teal-50"><span>Third-party ticketing fee</span><span class="font-bold">BZ$0.00</span></div>
                <div class="flex items-baseline justify-between border-t border-dashed border-white/10 py-2.5 text-sm font-black text-white"><span>Deposited to your account</span><span class="text-lg text-brand-300">BZ$2,120.00</span></div>
            </div>
        </div>
    </div>
</section>

<!-- ── WHO IT'S FOR ─────────────────────────────────────────────────────── -->
<section class="px-6 py-16">
    <div class="mx-auto max-w-5xl">
        <p class="text-[11px] font-black uppercase tracking-[0.16em] text-brand-700">Who this is actually for</p>
        <h2 class="mt-2 text-2xl font-black tracking-tight text-slate-900 md:text-3xl">Built for organizations that already have an audience.</h2>
        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-slate-500">If you're starting from zero followers, Facebook's network effect still wins. If people already show up for you, Centryk TV lets you keep what happens next.</p>
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <?php
            $audience = [
                ['Churches & congregations', 'Sunday service for homebound members, with a tip jar that isn\'t Facebook\'s donation tool.', 'M12 2 4 6v6c0 5 3.5 8.5 8 10 4.5-1.5 8-5 8-10V6l-8-4Z'],
                ['Schools & sports leagues', 'Graduation, a championship game — free for parents in the stands, ticketed for family watching abroad.', 'M4 4h16v12H4z M9 20h6 M12 16v4'],
                ['Conferences & training', 'Sell seats to a workshop and let it replay for anyone who registered but couldn\'t attend live.', 'M3 21h18 M6 21V9l6-4 6 4v12'],
                ['Businesses going paperless', 'A paid product launch or investor update, tracked with the same login as your payroll and POS.', 'M3 3v18h18 M7 15l4-6 3 4 5-8'],
            ];
            foreach ($audience as [$title, $desc, $path]):
            ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-50 text-brand-700">
                    <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $path ?>"/></svg>
                </span>
                <h3 class="mt-3.5 text-sm font-black text-slate-900"><?= htmlspecialchars($title) ?></h3>
                <p class="mt-1.5 text-xs leading-relaxed text-slate-500"><?= htmlspecialchars($desc) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ── FINAL CTA ────────────────────────────────────────────────────────── -->
<section class="px-6 pb-20">
    <div class="mx-auto max-w-4xl rounded-3xl border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-10 text-center shadow-sm md:p-14">
        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-200 bg-brand-50 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.14em] text-brand-700">
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
            Coming Soon
        </span>
        <h2 class="mt-4 text-2xl font-black tracking-tight text-slate-900 md:text-3xl">Your next event won't need Facebook's permission.</h2>
        <p class="mx-auto mt-3 max-w-md text-sm text-slate-500">Centryk TV is being rolled out gradually to organizations on the platform. Tell us you're interested and we'll reach out when it's your turn.</p>
        <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
            <a href="contact.php" class="rounded-xl bg-brand-700 px-5 py-3 text-xs font-black uppercase tracking-[0.12em] text-white shadow-lg shadow-brand-900/10 transition hover:bg-brand-600">Get notified at launch</a>
            <a href="index.php" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-xs font-black uppercase tracking-[0.12em] text-slate-700 transition hover:bg-slate-50">Back to dashboard</a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/partials/footer.php'; ?>

</body>
</html>
