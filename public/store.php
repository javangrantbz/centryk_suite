<?php
require_once __DIR__ . '/../app/core/Auth.php';
Auth::start();
$user = Auth::user();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Store - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased text-slate-900">
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>
<?php if ($user): ?>
<?php $pageTitle = 'Store'; $headerMaxW = 'max-w-7xl'; $awCurrent = 'store'; require_once __DIR__ . '/../app/services/AuthService.php'; include __DIR__ . '/partials/account_header.php'; ?>
<?php else: ?>
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
        <a href="index.php" class="flex items-center"><img src="../centryk_logo.png" alt="Centryk" class="h-12 w-auto"></a>
        <a href="login.php" class="rounded-xl bg-slate-950 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">Sign In</a>
    </div>
</header>
<?php endif; ?>

<main class="mx-auto max-w-7xl px-6 py-8">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Centryk Store</p>
            <h1 class="mt-1 text-3xl font-black tracking-tight text-slate-900">Advertised Items</h1>
            <p class="mt-1 text-sm font-semibold text-slate-500">Employee-only offers and Centryk Market listings from participating companies.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if (!empty($user['is_admin'])): ?>
            <a href="advertise.php" class="rounded-xl border border-violet-200 bg-violet-50 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-violet-700 transition hover:bg-violet-100">
                Advertise
            </a>
            <button class="rounded-xl bg-slate-950 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white">My Company Listing</button>
            <button class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600">Centryk Listing</button>
            <?php else: ?>
            <button class="rounded-xl bg-slate-950 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white">All</button>
            <button class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600">Employees</button>
            <button class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600">Centryk Market</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php
        $items = [
            ['Wireless Barcode Scanner', 'BHI Retail', '$185.00', 'Centryk Market', 'Fast checkout hardware for growing stores.'],
            ['Receipt Printer Roll Pack', 'BHI Retail', '$22.50', 'Employees only', 'Internal supply listing for branches.'],
            ['Countertop Card Reader', 'OneLink Partner', '$310.00', 'Centryk Market', 'Accept card payments at the counter.'],
            ['Thermal Printer', 'BHI Retail', '$420.00', 'Employees only', 'POS-ready thermal printer.'],
        ];
        foreach ($items as $item):
            $global = $item[3] === 'Centryk Market';
        ?>
        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex aspect-[4/3] items-center justify-center bg-slate-100">
                <span class="flex h-16 w-16 items-center justify-center rounded-2xl <?= $global ? 'bg-cyan-100 text-cyan-700' : 'bg-violet-100 text-violet-700' ?>">
                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7h-9m9 0v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7m16 0-2-4H6L4 7m5 4h6"/></svg>
                </span>
            </div>
            <div class="p-4">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] <?= $global ? 'bg-cyan-50 text-cyan-700' : 'bg-violet-50 text-violet-700' ?>"><?= htmlspecialchars($item[3]) ?></span>
                    <span class="text-sm font-black text-slate-900"><?= htmlspecialchars($item[2]) ?></span>
                </div>
                <h2 class="text-sm font-black text-slate-900"><?= htmlspecialchars($item[0]) ?></h2>
                <p class="mt-1 text-xs font-semibold text-slate-400"><?= htmlspecialchars($item[1]) ?></p>
                <p class="mt-3 text-xs font-semibold leading-relaxed text-slate-500"><?= htmlspecialchars($item[4]) ?></p>
                <button class="mt-4 w-full rounded-xl border border-slate-200 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-50">View Item</button>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</main>
</body>
</html>
