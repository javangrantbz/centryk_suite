<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();

if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    header('Location: login.php');
    exit;
}

$user = $me['user'];
$pdo = DB::pdo();
$companies = $pdo->query("
    SELECT id, name, status
    FROM companies
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

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

<main class="mx-auto max-w-7xl px-6 pt-1 pb-5">
    <section id="onelinkIntroBanner" class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-950 text-white shadow-sm">
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
                <button id="dismissOnelinkIntro" type="button" class="mt-5 inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/8 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white/70 transition hover:bg-white/15 hover:text-white">
                    <i data-lucide="eye-off" class="h-3.5 w-3.5"></i> Hide Overview
                </button>
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

    <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-600">Auto-Provision</p>
                <h2 class="mt-1 text-lg font-black tracking-tight">Create OneLink accounts automatically</h2>
                <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-500">
                    Provisions a real OneLink merchant account (terminal ID, salt, token) via their API for every active
                    company that doesn't have one enabled yet. Each company gets an access code emailed to log into
                    OneLink and finish their own settlement bank setup.
                </p>
            </div>
            <button id="provisionAllBtn" type="button" class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-cyan-600 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-cyan-500">
                <i data-lucide="zap" class="h-3.5 w-3.5"></i> Provision All Active Companies
            </button>
        </div>
        <div id="provisionAllResult" class="mt-4 hidden rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm"></div>
    </section>

    <section id="gatewaySetupPanel" class="mt-4 hidden grid gap-5 lg:grid-cols-[0.85fr_1.15fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-600">Gateway Setup</p>
                    <h2 class="mt-1 text-lg font-black tracking-tight">OneLink API Credentials</h2>
                    <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-500">
                        Configure the terminal credentials Centryk uses to activate online and card payments for a company.
                    </p>
                </div>
                <span id="gatewayStatusPill" class="shrink-0 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">Not loaded</span>
            </div>

            <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Company</label>
            <select id="gatewayCompany" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-bold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
                <?php foreach ($companies as $company): ?>
                <option value="<?= (int)$company['id'] ?>">
                    <?= htmlspecialchars($company['name']) ?><?= ($company['status'] ?? '') !== 'active' ? ' (' . htmlspecialchars((string)$company['status']) . ')' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>

            <div id="gatewayAlert" class="mt-4 hidden rounded-xl px-4 py-2.5 text-sm font-bold"></div>

            <button id="autoProvisionBtn" type="button" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-cyan-600 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-cyan-500">
                <i data-lucide="zap" class="h-3.5 w-3.5"></i> Auto-Create via OneLink API
            </button>
            <p class="mt-1.5 text-[11px] font-semibold text-slate-400">Creates the account automatically. Use the manual fields on the right only to edit or override afterward.</p>

            <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Settlement Bank On File</p>
                <div id="settlementPreview" class="mt-3 text-sm font-semibold text-slate-500">Select a company to view settlement details.</div>
            </div>
        </div>

        <form id="gatewayForm" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" novalidate>
            <input type="hidden" id="gatewayCompanyId" name="company_id" value="">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">API Base URL</label>
                    <input id="gatewayBaseUrl" name="base_url" type="text" placeholder="https://op.onelink.bz"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Terminal ID</label>
                    <input id="gatewayTerminalId" name="terminal_id" type="text" autocomplete="off"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
                </div>
                <div>
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Salt</label>
                    <input id="gatewaySalt" name="salt" type="password" autocomplete="new-password" placeholder="Leave blank to keep saved value"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
                    <p id="gatewaySaltHint" class="mt-1 text-[11px] font-semibold text-slate-400">Not loaded.</p>
                </div>
                <div>
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Token</label>
                    <input id="gatewayToken" name="token" type="password" autocomplete="new-password" placeholder="Leave blank to keep saved value"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
                    <p id="gatewayTokenHint" class="mt-1 text-[11px] font-semibold text-slate-400">Not loaded.</p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input id="gatewayEnabled" name="enabled" type="checkbox" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">
                    <span class="text-sm font-black text-slate-700">Enable OneLink payments for this company</span>
                </label>
                <span class="text-xs font-semibold text-slate-400">Requires terminal ID, salt, and token.</span>
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <button id="gatewaySubmitBtn" type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">
                    <i data-lucide="save" class="h-3.5 w-3.5"></i> Save Credentials
                </button>
                <button id="gatewayRemoveBtn" type="button" class="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-rose-700 transition hover:bg-rose-100">
                    <i data-lucide="trash-2" class="h-3.5 w-3.5"></i> Remove Credentials
                </button>
            </div>
        </form>
    </section>

    <section class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-950 px-5 py-5 text-white">
            <div>
                <h2 class="text-xl font-black tracking-tight">OneLink Operations . Payment Ledger</h2>
                <p class="mt-1 text-xs font-semibold text-white/55">Review payment movement and open gateway setup only when credentials need attention.</p>
            </div>
            <button id="toggleGatewaySetup" type="button" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/8 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-white/15" aria-expanded="false" aria-controls="gatewaySetupPanel">
                <i data-lucide="settings-2" class="h-3.5 w-3.5"></i> Gateway Setup
            </button>
        </div>

        <div class="p-5">
        <div class="mb-4 flex flex-wrap items-center justify-end gap-3">
            <button type="button" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-50">
                <i data-lucide="download" class="h-3.5 w-3.5"></i> Export
            </button>
        </div>

        <div class="grid gap-3 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]">
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">From</label>
                <input id="ledgerFromDate" type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">To</label>
                <input id="ledgerToDate" type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Status</label>
                <select id="ledgerStatusFilter" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
                    <option value="all">All Payments</option>
                    <option value="settled">Settled</option>
                    <option value="unsettled">Unsettled (Outstanding)</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Company</label>
                <select id="ledgerCompanyFilter" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
                    <option value="all">All Companies</option>
                    <option value="BHI Retail">BHI Retail</option>
                    <option value="Northside Pharmacy">Northside Pharmacy</option>
                    <option value="Belize Service Depot">Belize Service Depot</option>
                </select>
            </div>
            <div class="flex items-end">
                <button id="ledgerClearFilters" type="button" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-50">Clear</button>
            </div>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Gross Processed</p>
                <p id="ledgerGrossTotal" class="mt-3 text-2xl font-black">$0.00</p>
                <p class="mt-1 text-xs font-semibold text-slate-400">Visible payments</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">OneLink Revenue</p>
                <p id="ledgerRevenueTotal" class="mt-3 text-2xl font-black text-emerald-600">$0.00</p>
                <p class="mt-1 text-xs font-semibold text-slate-400">Fees retained by OneLink</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Unsettled</p>
                <p id="ledgerUnsettledTotal" class="mt-3 text-2xl font-black text-amber-600">$0.00</p>
                <p class="mt-1 text-xs font-semibold text-slate-400">Paid or outstanding, not settled</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Settled</p>
                <p id="ledgerSettledTotal" class="mt-3 text-2xl font-black text-sky-600">$0.00</p>
                <p class="mt-1 text-xs font-semibold text-slate-400">Paid to merchant accounts</p>
            </div>
        </div>

        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200">
            <div class="hidden grid-cols-[1.1fr_1.2fr_0.8fr_0.8fr_0.8fr_0.9fr] gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em] text-slate-400 lg:grid">
                <span>Date</span><span>Company</span><span>Amount</span><span>OneLink Fee</span><span>Status</span><span>Settlement</span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php
                $rows = [
                    ['2026-07-08', 'Jul 08, 2026', 'BHI Retail', '$4,820.00', '$144.60', 'Settled', 'Heritage -> Merchant'],
                    ['2026-07-08', 'Jul 08, 2026', 'Northside Pharmacy', '$1,250.75', '$37.52', 'Paid', 'In Heritage clearing'],
                    ['2026-07-07', 'Jul 07, 2026', 'Belize Service Depot', '$8,410.00', '$252.30', 'Outstanding', 'Awaiting settlement'],
                    ['2026-07-06', 'Jul 06, 2026', 'BHI Retail', '$2,100.00', '$63.00', 'Settled', 'Heritage -> Merchant'],
                ];
                foreach ($rows as $row):
                    $tone = $row[5] === 'Settled' ? 'bg-sky-50 text-sky-700 border-sky-200' : ($row[5] === 'Paid' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200');
                ?>
                <div class="ledger-row grid gap-3 px-4 py-4 text-sm lg:grid-cols-[1.1fr_1.2fr_0.8fr_0.8fr_0.8fr_0.9fr] lg:items-center"
                     data-date="<?= htmlspecialchars($row[0]) ?>"
                     data-company="<?= htmlspecialchars($row[2]) ?>"
                     data-status="<?= $row[5] === 'Settled' ? 'settled' : 'unsettled' ?>"
                     data-amount="<?= htmlspecialchars(str_replace(['$', ','], '', $row[3])) ?>"
                     data-fee="<?= htmlspecialchars(str_replace(['$', ','], '', $row[4])) ?>">
                    <div class="font-bold text-slate-700"><?= htmlspecialchars($row[1]) ?></div>
                    <div class="font-black text-slate-900"><?= htmlspecialchars($row[2]) ?></div>
                    <div class="font-black text-slate-900"><?= htmlspecialchars($row[3]) ?></div>
                    <div class="font-bold text-emerald-600"><?= htmlspecialchars($row[4]) ?></div>
                    <div><span class="inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] <?= $tone ?>"><?= htmlspecialchars($row[5]) ?></span></div>
                    <div class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($row[6]) ?></div>
                </div>
                <?php endforeach; ?>
                <div id="ledgerEmptyState" class="hidden px-4 py-8 text-center text-sm font-semibold text-slate-400">
                    No payments match the selected filters.
                </div>
            </div>
        </div>
        </div>
    </section>
</main>

<script>
(function () {
    const introKey = 'onelinkApiAccountsIntroDismissed';
    const intro = document.getElementById('onelinkIntroBanner');
    const dismissIntro = document.getElementById('dismissOnelinkIntro');
    const setupPanel = document.getElementById('gatewaySetupPanel');
    const setupToggle = document.getElementById('toggleGatewaySetup');
    if (intro && localStorage.getItem(introKey) === '1') {
        intro.classList.add('hidden');
    }
    dismissIntro?.addEventListener('click', function () {
        localStorage.setItem(introKey, '1');
        intro?.classList.add('hidden');
    });
    setupToggle?.addEventListener('click', function () {
        const isHidden = setupPanel?.classList.toggle('hidden');
        setupToggle.setAttribute('aria-expanded', isHidden ? 'false' : 'true');
        setupToggle.innerHTML = isHidden
            ? '<i data-lucide="settings-2" class="h-3.5 w-3.5"></i> Gateway Setup'
            : '<i data-lucide="x" class="h-3.5 w-3.5"></i> Hide Gateway Setup';
        if (!isHidden) {
            setupPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        if (window.lucide) lucide.createIcons();
    });

    const ledgerStatusFilter = document.getElementById('ledgerStatusFilter');
    const ledgerCompanyFilter = document.getElementById('ledgerCompanyFilter');
    const ledgerFromDate = document.getElementById('ledgerFromDate');
    const ledgerToDate = document.getElementById('ledgerToDate');
    const ledgerClearFilters = document.getElementById('ledgerClearFilters');
    const ledgerEmptyState = document.getElementById('ledgerEmptyState');
    const ledgerRows = Array.from(document.querySelectorAll('.ledger-row'));
    const ledgerTotals = {
        gross: document.getElementById('ledgerGrossTotal'),
        revenue: document.getElementById('ledgerRevenueTotal'),
        unsettled: document.getElementById('ledgerUnsettledTotal'),
        settled: document.getElementById('ledgerSettledTotal'),
    };

    function formatMoney(value) {
        return '$' + value.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function applyLedgerFilters() {
        const status = ledgerStatusFilter?.value || 'all';
        const company = ledgerCompanyFilter?.value || 'all';
        const fromDate = ledgerFromDate?.value || '';
        const toDate = ledgerToDate?.value || '';
        let visibleCount = 0;
        let grossTotal = 0;
        let revenueTotal = 0;
        let unsettledTotal = 0;
        let settledTotal = 0;

        ledgerRows.forEach(function (row) {
            const companyMatches = company === 'all' || row.dataset.company === company;
            const statusMatches = status === 'all' || row.dataset.status === status;
            const rowDate = row.dataset.date || '';
            const fromMatches = !fromDate || rowDate >= fromDate;
            const toMatches = !toDate || rowDate <= toDate;
            const show = companyMatches && statusMatches && fromMatches && toMatches;
            row.classList.toggle('hidden', !show);
            if (show) {
                const amount = Number(row.dataset.amount || 0);
                const fee = Number(row.dataset.fee || 0);
                visibleCount++;
                grossTotal += amount;
                revenueTotal += fee;
                if (row.dataset.status === 'settled') {
                    settledTotal += amount;
                } else {
                    unsettledTotal += amount;
                }
            }
        });

        if (ledgerTotals.gross) ledgerTotals.gross.textContent = formatMoney(grossTotal);
        if (ledgerTotals.revenue) ledgerTotals.revenue.textContent = formatMoney(revenueTotal);
        if (ledgerTotals.unsettled) ledgerTotals.unsettled.textContent = formatMoney(unsettledTotal);
        if (ledgerTotals.settled) ledgerTotals.settled.textContent = formatMoney(settledTotal);
        ledgerEmptyState?.classList.toggle('hidden', visibleCount > 0);
    }

    ledgerStatusFilter?.addEventListener('change', applyLedgerFilters);
    ledgerCompanyFilter?.addEventListener('change', applyLedgerFilters);
    ledgerFromDate?.addEventListener('input', applyLedgerFilters);
    ledgerToDate?.addEventListener('input', applyLedgerFilters);
    ledgerClearFilters?.addEventListener('click', function () {
        if (ledgerFromDate) ledgerFromDate.value = '';
        if (ledgerToDate) ledgerToDate.value = '';
        if (ledgerStatusFilter) ledgerStatusFilter.value = 'all';
        if (ledgerCompanyFilter) ledgerCompanyFilter.value = 'all';
        applyLedgerFilters();
    });
    applyLedgerFilters();

    const companySelect = document.getElementById('gatewayCompany');
    const alertEl = document.getElementById('gatewayAlert');
    const statusPill = document.getElementById('gatewayStatusPill');
    const settlementPreview = document.getElementById('settlementPreview');
    const form = document.getElementById('gatewayForm');
    const fields = {
        companyId: document.getElementById('gatewayCompanyId'),
        baseUrl: document.getElementById('gatewayBaseUrl'),
        terminal: document.getElementById('gatewayTerminalId'),
        salt: document.getElementById('gatewaySalt'),
        token: document.getElementById('gatewayToken'),
        saltHint: document.getElementById('gatewaySaltHint'),
        tokenHint: document.getElementById('gatewayTokenHint'),
        enabled: document.getElementById('gatewayEnabled'),
    };

    if (!companySelect || !form) return;

    function showAlert(message, ok) {
        alertEl.textContent = message;
        alertEl.className = 'mt-4 rounded-xl px-4 py-2.5 text-sm font-bold ' +
            (ok ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200');
    }

    function setStatus(enabled, configured) {
        if (enabled) {
            statusPill.className = 'shrink-0 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-emerald-700';
            statusPill.textContent = 'Enabled';
            return;
        }
        if (configured) {
            statusPill.className = 'shrink-0 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-amber-700';
            statusPill.textContent = 'Configured';
            return;
        }
        statusPill.className = 'shrink-0 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-500';
        statusPill.textContent = 'Not configured';
    }

    function renderSettlement(account) {
        if (!account || (!account.bank_name && !account.account_holder && !account.account_number)) {
            settlementPreview.innerHTML = '<p class="text-slate-500">No settlement bank account has been saved by this company.</p>';
            return;
        }
        settlementPreview.innerHTML =
            '<div class="grid gap-2 sm:grid-cols-2">' +
            '<div><p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">Bank</p><p class="font-black text-slate-800">' + escapeHtml(account.bank_name || 'Not set') + '</p></div>' +
            '<div><p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">Account Holder</p><p class="font-black text-slate-800">' + escapeHtml(account.account_holder || 'Not set') + '</p></div>' +
            '<div><p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">Account Number</p><p class="font-black text-slate-800">' + escapeHtml(account.account_number || 'Not set') + '</p></div>' +
            '<div><p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">Branch</p><p class="font-black text-slate-800">' + escapeHtml(account.branch || 'Not set') + '</p></div>' +
            '</div>';
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
        });
    }

    async function loadGateway() {
        const cid = companySelect.value;
        if (!cid) return;
        fields.companyId.value = cid;
        alertEl.className = 'mt-4 hidden rounded-xl px-4 py-2.5 text-sm font-bold';
        try {
            const res = await fetch('api/banking/get.php?company_id=' + encodeURIComponent(cid));
            const data = await res.json();
            if (!data.success) {
                showAlert(data.message || 'Could not load gateway settings.', false);
                return;
            }
            const gateway = data.gateway || {};
            fields.baseUrl.value = gateway.base_url || 'https://op.onelink.bz';
            fields.terminal.value = gateway.terminal_id || '';
            fields.salt.value = '';
            fields.token.value = '';
            fields.enabled.checked = !!gateway.enabled;
            fields.saltHint.textContent = gateway.salt_set ? 'Saved. Leave blank to keep the current salt.' : 'No salt saved yet.';
            fields.tokenHint.textContent = gateway.token_set ? 'Saved. Leave blank to keep the current token.' : 'No token saved yet.';
            setStatus(!!gateway.enabled, !!gateway.configured);
            renderSettlement(data.account || {});
        } catch (_) {
            showAlert('Network error while loading gateway settings.', false);
        }
    }

    companySelect.addEventListener('change', loadGateway);

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = document.getElementById('gatewaySubmitBtn');
        btn.disabled = true;
        try {
            const res = await fetch('api/banking/save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    company_id: fields.companyId.value,
                    base_url: fields.baseUrl.value.trim(),
                    terminal_id: fields.terminal.value.trim(),
                    salt: fields.salt.value,
                    token: fields.token.value,
                    enabled: fields.enabled.checked ? 1 : 0
                })
            });
            const data = await res.json();
            if (data.success) {
                showAlert('OneLink API credentials saved.', true);
                fields.salt.value = '';
                fields.token.value = '';
                fields.saltHint.textContent = data.salt_set ? 'Saved. Leave blank to keep the current salt.' : 'No salt saved yet.';
                fields.tokenHint.textContent = data.token_set ? 'Saved. Leave blank to keep the current token.' : 'No token saved yet.';
                setStatus(!!data.enabled, true);
            } else {
                showAlert(data.message || 'Could not save credentials.', false);
            }
        } catch (_) {
            showAlert('Network error while saving credentials.', false);
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('gatewayRemoveBtn')?.addEventListener('click', async function () {
        if (!confirm('Remove the OneLink API credentials for this company? Payments will be disabled until credentials are added again.')) return;
        const btn = this;
        btn.disabled = true;
        try {
            const res = await fetch('api/banking/remove.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ company_id: fields.companyId.value })
            });
            const data = await res.json();
            if (data.success) {
                showAlert('OneLink API credentials removed.', true);
                fields.baseUrl.value = 'https://op.onelink.bz';
                fields.terminal.value = '';
                fields.salt.value = '';
                fields.token.value = '';
                fields.enabled.checked = false;
                fields.saltHint.textContent = 'No salt saved yet.';
                fields.tokenHint.textContent = 'No token saved yet.';
                setStatus(false, false);
            } else {
                showAlert(data.message || 'Could not remove credentials.', false);
            }
        } catch (_) {
            showAlert('Network error while removing credentials.', false);
        } finally {
            btn.disabled = false;
        }
    });

    document.getElementById('autoProvisionBtn')?.addEventListener('click', async function () {
        const btn = this;
        const cid = fields.companyId.value;
        if (!cid) return;
        btn.disabled = true;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = 'Provisioning…';
        try {
            const res = await fetch('api/banking/provision.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ company_id: cid })
            });
            const data = await res.json();
            if (data.success) {
                showAlert(data.already ? 'Already provisioned.' : 'OneLink account created. Access code emailed to the company.', true);
                loadGateway();
            } else {
                showAlert(data.message || 'Could not auto-create the OneLink account.', false);
            }
        } catch (_) {
            showAlert('Network error while provisioning.', false);
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            if (window.lucide) lucide.createIcons();
        }
    });

    document.getElementById('provisionAllBtn')?.addEventListener('click', async function () {
        const btn = this;
        const resultEl = document.getElementById('provisionAllResult');
        if (!confirm('Provision OneLink accounts for every active company that doesn\'t have one yet? This creates real accounts on OneLink and emails each company its access code.')) return;
        btn.disabled = true;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = 'Provisioning…';
        resultEl.classList.remove('hidden');
        resultEl.innerHTML = '<p class="text-slate-500">Working through active companies — this can take a moment…</p>';
        try {
            const res = await fetch('api/banking/provision-all.php', { method: 'POST' });
            const data = await res.json();
            if (!data.success) {
                resultEl.innerHTML = '<p class="text-rose-600 font-bold">' + escapeHtml(data.message || 'Could not run bulk provisioning.') + '</p>';
                return;
            }
            let html = '<p class="font-black text-slate-800">' + data.total + ' company(ies) checked.</p>';
            if (data.provisioned.length) {
                html += '<p class="mt-2 font-bold text-emerald-700">Provisioned (' + data.provisioned.length + '):</p><ul class="mt-1 list-disc pl-5 text-slate-600">' +
                    data.provisioned.map(function (p) { return '<li>' + escapeHtml(p.name) + '</li>'; }).join('') + '</ul>';
            }
            if (data.failed.length) {
                html += '<p class="mt-2 font-bold text-rose-700">Failed (' + data.failed.length + '):</p><ul class="mt-1 list-disc pl-5 text-slate-600">' +
                    data.failed.map(function (f) { return '<li>' + escapeHtml(f.name) + ' — ' + escapeHtml(f.message) + '</li>'; }).join('') + '</ul>';
            }
            if (!data.provisioned.length && !data.failed.length) {
                html += '<p class="text-slate-500">Every active company already has OneLink enabled.</p>';
            }
            resultEl.innerHTML = html;
            loadGateway();
        } catch (_) {
            resultEl.innerHTML = '<p class="text-rose-600 font-bold">Network error while provisioning.</p>';
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            if (window.lucide) lucide.createIcons();
        }
    });

    loadGateway();
})();
</script>
<script src="https://unpkg.com/lucide@latest"></script>
<script>if (window.lucide) { lucide.createIcons(); }</script>
</body>
</html>
