<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();

if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    header('Location: login.php');
    exit;
}

$user = $me['user'];

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();

$pageTitle = 'OneLink API Accounts';
$headerMaxW = 'max-w-7xl';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>OneLink API Accounts - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>
        [data-lucide] { display: inline-block; }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased text-slate-900">
<?php include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-7xl px-6 py-8">
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-950 text-white shadow-sm">
        <div class="grid gap-6 px-6 py-6 lg:grid-cols-[1.4fr_0.9fr] lg:px-8">
            <div>
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <span class="rounded-full border border-cyan-300/20 bg-cyan-300/10 px-3 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-cyan-200">OneLink APIs</span>
                    <span class="rounded-full border border-emerald-300/20 bg-emerald-300/10 px-3 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-emerald-200">Admin View</span>
                </div>
                <h1 class="max-w-3xl text-3xl font-black tracking-tight md:text-4xl">Online and card payment settlement control.</h1>
                <p class="mt-3 max-w-2xl text-sm font-semibold leading-relaxed text-white/55">
                    Template view for payments accepted through OneLink APIs from Centryk POS. OneLink processes funds into the Heritage Bank clearing account, then settlement moves to each company account.
                </p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/6 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/35">Settlement Pipeline</p>
                <div class="mt-4 space-y-3">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-cyan-400/15 text-cyan-200"><i data-lucide="terminal" class="h-4 w-4"></i></span>
                        <div><p class="text-sm font-black">OneLink API capture</p><p class="text-xs font-semibold text-white/40">Online and card payments accepted</p></div>
                    </div>
                    <div class="h-4 border-l border-dashed border-white/20 ml-4"></div>
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-400/15 text-violet-200"><i data-lucide="landmark" class="h-4 w-4"></i></span>
                        <div><p class="text-sm font-black">Heritage Bank clearing</p><p class="text-xs font-semibold text-white/40">Funds held before merchant settlement</p></div>
                    </div>
                    <div class="h-4 border-l border-dashed border-white/20 ml-4"></div>
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-400/15 text-emerald-200"><i data-lucide="badge-dollar-sign" class="h-4 w-4"></i></span>
                        <div><p class="text-sm font-black">Company payout</p><p class="text-xs font-semibold text-white/40">Settlement to the user's bank account</p></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Gross Processed</p>
            <p class="mt-3 text-2xl font-black">$128,430.75</p>
            <p class="mt-1 text-xs font-semibold text-slate-400">Template total for selected period</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">OneLink Revenue</p>
            <p class="mt-3 text-2xl font-black text-emerald-600">$3,852.92</p>
            <p class="mt-1 text-xs font-semibold text-slate-400">Fees retained by OneLink</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Outstanding</p>
            <p class="mt-3 text-2xl font-black text-amber-600">$18,220.00</p>
            <p class="mt-1 text-xs font-semibold text-slate-400">Captured, not settled</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Settled</p>
            <p class="mt-3 text-2xl font-black text-sky-600">$110,210.75</p>
            <p class="mt-1 text-xs font-semibold text-slate-400">Paid to merchant accounts</p>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-black tracking-tight">Payment Ledger</h2>
                <p class="mt-1 text-xs font-semibold text-slate-400">Design template only. API-backed data can be wired in later.</p>
            </div>
            <button type="button" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-50">
                <i data-lucide="download" class="h-3.5 w-3.5"></i> Export
            </button>
        </div>

        <div class="grid gap-3 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]">
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">From</label>
                <input type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">To</label>
                <input type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Status</label>
                <select class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
                    <option>All payments</option>
                    <option>Paid</option>
                    <option>Outstanding</option>
                    <option>Settled</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Company</label>
                <select class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
                    <option>All companies</option>
                    <option>BHI Retail</option>
                    <option>Northside Pharmacy</option>
                    <option>Belize Service Depot</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="button" class="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">Apply</button>
            </div>
        </div>

        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200">
            <div class="hidden grid-cols-[1.1fr_1.2fr_0.8fr_0.8fr_0.8fr_0.9fr] gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em] text-slate-400 lg:grid">
                <span>Date</span><span>Company</span><span>Amount</span><span>OneLink Fee</span><span>Status</span><span>Settlement</span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php
                $rows = [
                    ['Jul 08, 2026', 'BHI Retail', '$4,820.00', '$144.60', 'Settled', 'Heritage -> Merchant'],
                    ['Jul 08, 2026', 'Northside Pharmacy', '$1,250.75', '$37.52', 'Paid', 'In Heritage clearing'],
                    ['Jul 07, 2026', 'Belize Service Depot', '$8,410.00', '$252.30', 'Outstanding', 'Awaiting settlement'],
                    ['Jul 06, 2026', 'BHI Retail', '$2,100.00', '$63.00', 'Settled', 'Heritage -> Merchant'],
                ];
                foreach ($rows as $row):
                    $tone = $row[4] === 'Settled' ? 'bg-sky-50 text-sky-700 border-sky-200' : ($row[4] === 'Paid' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200');
                ?>
                <div class="grid gap-3 px-4 py-4 text-sm lg:grid-cols-[1.1fr_1.2fr_0.8fr_0.8fr_0.8fr_0.9fr] lg:items-center">
                    <div class="font-bold text-slate-700"><?= htmlspecialchars($row[0]) ?></div>
                    <div class="font-black text-slate-900"><?= htmlspecialchars($row[1]) ?></div>
                    <div class="font-black text-slate-900"><?= htmlspecialchars($row[2]) ?></div>
                    <div class="font-bold text-emerald-600"><?= htmlspecialchars($row[3]) ?></div>
                    <div><span class="inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] <?= $tone ?>"><?= htmlspecialchars($row[4]) ?></span></div>
                    <div class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($row[5]) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>if (window.lucide) { lucide.createIcons(); }</script>
</body>
</html>
