<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();

if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}

$user = $me['user'];
$pdo = DB::pdo();
$companies = $pdo->query("
    SELECT id, name, status
    FROM companies
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Provisioning status for every company, for the stats table below.
$provisioning = $pdo->query("
    SELECT c.id, c.name, c.status AS company_status,
           o.enabled, o.terminal_id, o.access_code, o.provisioned_at, o.provision_error
    FROM companies c
    LEFT JOIN onelink_credentials o ON o.company_id = c.id
    ORDER BY
        (o.enabled = 1) DESC,
        (o.provision_error IS NOT NULL AND (o.enabled IS NULL OR o.enabled = 0)) DESC,
        c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);
$provisionedCount = count(array_filter($provisioning, static fn($r) => (int)($r['enabled'] ?? 0) === 1));
$failedCount      = count(array_filter($provisioning, static fn($r) => (int)($r['enabled'] ?? 0) !== 1 && !empty($r['provision_error'])));
$notSetUpCount    = count($provisioning) - $provisionedCount - $failedCount;

// Real payment ledger: Gross Processed and Refunded/Transactions come straight
// from OnePay's own sale_payments records (via OnePayLedger, one company at a
// time). OneLink Revenue is now pulled live from OneLink's own
// /user/transactions API (via OneLinkPaymentsService) for any company with a
// working onelink_uuid-based credential set; companies provisioned through
// the older manual-paste-in flow have no onelink_uuid on file (that flow only
// ever collected a self-reported "salt"), so those still fall back to the
// gross x fee_percentage estimate, clearly marked as such per row.
require_once __DIR__ . '/../app/services/OnePayLedger.php';
require_once __DIR__ . '/../app/services/OneLinkPaymentsService.php';

$ledgerEnd   = date('Y-m-d');
$ledgerStart = date('Y-m-d', strtotime('-29 days'));

$ledgerCompanies = $pdo->query("
    SELECT c.id, c.uuid, c.name, o.fee_percentage
    FROM companies c
    JOIN onelink_credentials o ON o.company_id = c.id
    WHERE o.enabled = 1
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

$ledgerRows          = [];
$ledgerGrossTotal    = 0.0;
$ledgerRevenueTotal  = 0.0;
$ledgerRefundedTotal = 0.0;
$ledgerCountTotal    = 0;

foreach ($ledgerCompanies as $lc) {
    if (empty($lc['uuid'])) {
        continue;
    }
    $feePct = $lc['fee_percentage'] !== null ? (float)$lc['fee_percentage'] : null;

    $liveByDay = [];
    $liveOk    = false;
    $creds = OneLinkPaymentsService::credentials($pdo, (int)$lc['id']);
    if ($creds !== null) {
        $liveResult = OneLinkPaymentsService::transactionsByDayInRange($creds, $ledgerStart, $ledgerEnd);
        if (!empty($liveResult['success'])) {
            $liveByDay = $liveResult['byDay'];
            $liveOk    = true;
        }
    }

    $result = OnePayLedger::fetch((string)$lc['uuid'], $ledgerStart, $ledgerEnd);
    foreach ($result['days'] as $day) {
        $gross    = (float)($day['gross'] ?? 0);
        $refunded = (float)($day['refunded'] ?? 0);
        $count    = (int)($day['count'] ?? 0);
        if ($gross <= 0 && $refunded <= 0) {
            continue;
        }
        $date = (string)($day['day'] ?? '');
        if ($liveOk && isset($liveByDay[$date])) {
            $revenue = (float)$liveByDay[$date]['amount'];
            $revenueSource = 'live';
        } elseif ($feePct !== null) {
            $revenue = $gross * $feePct;
            $revenueSource = 'est';
        } else {
            $revenue = null;
            $revenueSource = null;
        }
        $ledgerRows[] = [
            'date'          => $date,
            'company'       => (string)$lc['name'],
            'gross'         => $gross,
            'revenue'       => $revenue,
            'revenueSource' => $revenueSource,
            'refunded'      => $refunded,
            'count'         => $count,
        ];
        $ledgerGrossTotal    += $gross;
        $ledgerRefundedTotal += $refunded;
        $ledgerCountTotal    += $count;
        if ($revenue !== null) {
            $ledgerRevenueTotal += $revenue;
        }
    }
}
usort($ledgerRows, static fn($a, $b) => strcmp($b['date'], $a['date']));

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

    <section class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-5">
            <div>
                <h2 class="text-lg font-black tracking-tight">Provisioning Status</h2>
                <p class="mt-1 text-xs font-semibold text-slate-500">Every company and whether it has a OneLink account.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-emerald-700"><?= $provisionedCount ?> Provisioned</span>
                <span class="rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-rose-700"><?= $failedCount ?> Failed</span>
                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-500"><?= $notSetUpCount ?> Not Set Up</span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <div class="hidden min-w-[720px] grid-cols-[1.4fr_0.8fr_1fr_1fr_1.4fr] gap-3 border-b border-slate-200 bg-slate-50 px-5 py-3 text-[10px] font-black uppercase tracking-[0.16em] text-slate-400 lg:grid">
                <span>Company</span><span>Status</span><span>Terminal ID</span><span>Provisioned</span><span>Notes</span>
            </div>
            <div class="min-w-[720px] divide-y divide-slate-100">
                <?php foreach ($provisioning as $p):
                    $isEnabled = (int)($p['enabled'] ?? 0) === 1;
                    $hasError  = !$isEnabled && !empty($p['provision_error']);
                    if ($isEnabled) {
                        $badge = '<span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-emerald-700">Provisioned</span>';
                    } elseif ($hasError) {
                        $badge = '<span class="inline-flex rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-rose-700">Failed</span>';
                    } else {
                        $badge = '<span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-500">Not Set Up</span>';
                    }
                ?>
                <div class="grid grid-cols-1 gap-1 px-5 py-3.5 text-sm lg:grid-cols-[1.4fr_0.8fr_1fr_1fr_1.4fr] lg:items-center lg:gap-3">
                    <div class="font-bold text-slate-900">
                        <?= htmlspecialchars($p['name']) ?>
                        <?php if (($p['company_status'] ?? 'active') !== 'active'): ?>
                        <span class="ml-1 text-xs font-semibold text-slate-400">(<?= htmlspecialchars($p['company_status']) ?>)</span>
                        <?php endif; ?>
                    </div>
                    <div><?= $badge ?></div>
                    <div>
                        <?php if ($isEnabled): ?>
                        <button type="button"
                            class="cred-toggle inline-flex items-center gap-1 font-mono text-xs font-semibold text-cyan-700 underline decoration-dotted underline-offset-2 transition hover:text-cyan-900"
                            data-cid="<?= (int)$p['id'] ?>" aria-expanded="false" title="Show OneLink API credentials">
                            <?= htmlspecialchars((string)$p['terminal_id']) ?>
                            <i data-lucide="chevron-down" class="cred-caret h-3 w-3 transition-transform"></i>
                        </button>
                        <?php else: ?>
                        <span class="font-mono text-xs text-slate-600">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-xs font-semibold text-slate-500"><?= $p['provisioned_at'] ? htmlspecialchars(date('M j, Y g:ia', strtotime((string)$p['provisioned_at']))) : '—' ?></div>
                    <div class="text-xs font-semibold <?= $hasError ? 'text-rose-600' : 'text-slate-400' ?>"><?= $hasError ? htmlspecialchars((string)$p['provision_error']) : ($isEnabled ? 'Access code on file' : 'Not attempted yet') ?></div>
                </div>
                <?php if ($isEnabled): ?>
                <div class="cred-reveal hidden bg-slate-50 px-5 py-4" data-cid="<?= (int)$p['id'] ?>" data-loaded="0">
                    <p class="text-xs font-semibold text-slate-400">Loading credentials…</p>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if (empty($provisioning)): ?>
                <div class="px-5 py-8 text-center text-sm font-semibold text-slate-400">No companies found.</div>
                <?php endif; ?>
            </div>
        </div>
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

        <div class="grid gap-3 lg:grid-cols-[1fr_1fr_1fr_auto]">
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">From</label>
                <input id="ledgerFromDate" type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">To</label>
                <input id="ledgerToDate" type="date" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
            </div>
            <div>
                <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Company</label>
                <select id="ledgerCompanyFilter" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500 focus:bg-white">
                    <option value="all">All Companies</option>
                    <?php foreach ($ledgerCompanies as $lc): ?>
                    <option value="<?= htmlspecialchars($lc['name']) ?>"><?= htmlspecialchars($lc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end">
                <button id="ledgerClearFilters" type="button" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-50">Clear</button>
            </div>
        </div>
        <p class="mt-3 text-[11px] font-semibold text-slate-400">Showing card payments from <?= htmlspecialchars(date('M j, Y', strtotime($ledgerStart))) ?> to <?= htmlspecialchars(date('M j, Y', strtotime($ledgerEnd))) ?>, pulled live from OnePay's own records. OneLink Revenue is pulled live from OneLink's own transaction API where a company has a working credential set (marked <span class="font-black text-sky-600">Live</span> below); companies still on the older manual-credential flow fall back to an estimate (gross x fee % on file, marked <span class="font-black text-slate-500">Est.</span>).</p>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Gross Processed</p>
                <p id="ledgerGrossTotal" class="mt-3 text-2xl font-black">$0.00</p>
                <p class="mt-1 text-xs font-semibold text-slate-400">From OnePay's own records</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">OneLink Revenue</p>
                <p id="ledgerRevenueTotal" class="mt-3 text-2xl font-black text-emerald-600">$0.00</p>
                <p class="mt-1 text-xs font-semibold text-slate-400">Live where connected, else estimated</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Refunded</p>
                <p id="ledgerRefundedTotal" class="mt-3 text-2xl font-black text-amber-600">$0.00</p>
                <p class="mt-1 text-xs font-semibold text-slate-400">Refunded card payments</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Transactions</p>
                <p id="ledgerCountTotal" class="mt-3 text-2xl font-black text-sky-600">0</p>
                <p class="mt-1 text-xs font-semibold text-slate-400">Paid card transactions</p>
            </div>
        </div>

        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200">
            <div class="hidden grid-cols-[1.1fr_1.2fr_0.9fr_0.9fr_0.8fr_0.8fr] gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em] text-slate-400 lg:grid">
                <span>Date</span><span>Company</span><span>Gross</span><span>OneLink Fee</span><span>Refunded</span><span>Txns</span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php foreach ($ledgerRows as $row):
                    $displayDate = $row['date'] !== '' ? date('M j, Y', strtotime($row['date'])) : '—';
                    $feeDisplay  = $row['revenue'] !== null ? '$' . number_format($row['revenue'], 2) : '—';
                ?>
                <div class="ledger-row grid gap-3 px-4 py-4 text-sm lg:grid-cols-[1.1fr_1.2fr_0.9fr_0.9fr_0.8fr_0.8fr] lg:items-center"
                     data-date="<?= htmlspecialchars($row['date']) ?>"
                     data-company="<?= htmlspecialchars($row['company']) ?>"
                     data-amount="<?= htmlspecialchars((string)$row['gross']) ?>"
                     data-fee="<?= htmlspecialchars((string)($row['revenue'] ?? 0)) ?>"
                     data-refunded="<?= htmlspecialchars((string)$row['refunded']) ?>"
                     data-count="<?= (int)$row['count'] ?>">
                    <div class="font-bold text-slate-700"><?= htmlspecialchars($displayDate) ?></div>
                    <div class="font-black text-slate-900"><?= htmlspecialchars($row['company']) ?></div>
                    <div class="font-black text-slate-900">$<?= number_format($row['gross'], 2) ?></div>
                    <div class="font-bold text-emerald-600">
                        <?= htmlspecialchars($feeDisplay) ?>
                        <?php if ($row['revenueSource'] === 'live'): ?>
                        <span class="ml-1 rounded-full bg-sky-100 px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wide text-sky-600">Live</span>
                        <?php elseif ($row['revenueSource'] === 'est'): ?>
                        <span class="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wide text-slate-500">Est.</span>
                        <?php endif; ?>
                    </div>
                    <div class="font-semibold text-amber-600"><?= $row['refunded'] > 0 ? '$' . number_format($row['refunded'], 2) : '—' ?></div>
                    <div class="text-xs font-semibold text-slate-500"><?= (int)$row['count'] ?></div>
                </div>
                <?php endforeach; ?>
                <div id="ledgerEmptyState" class="<?= empty($ledgerRows) ? '' : 'hidden' ?> px-4 py-8 text-center text-sm font-semibold text-slate-400">
                    No card payments in this window for any provisioned company.
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

    const ledgerCompanyFilter = document.getElementById('ledgerCompanyFilter');
    const ledgerFromDate = document.getElementById('ledgerFromDate');
    const ledgerToDate = document.getElementById('ledgerToDate');
    const ledgerClearFilters = document.getElementById('ledgerClearFilters');
    const ledgerEmptyState = document.getElementById('ledgerEmptyState');
    const ledgerRows = Array.from(document.querySelectorAll('.ledger-row'));
    const ledgerTotals = {
        gross: document.getElementById('ledgerGrossTotal'),
        revenue: document.getElementById('ledgerRevenueTotal'),
        refunded: document.getElementById('ledgerRefundedTotal'),
        count: document.getElementById('ledgerCountTotal'),
    };

    function formatMoney(value) {
        return '$' + value.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function applyLedgerFilters() {
        const company = ledgerCompanyFilter?.value || 'all';
        const fromDate = ledgerFromDate?.value || '';
        const toDate = ledgerToDate?.value || '';
        let visibleCount = 0;
        let grossTotal = 0;
        let revenueTotal = 0;
        let refundedTotal = 0;
        let countTotal = 0;

        ledgerRows.forEach(function (row) {
            const companyMatches = company === 'all' || row.dataset.company === company;
            const rowDate = row.dataset.date || '';
            const fromMatches = !fromDate || rowDate >= fromDate;
            const toMatches = !toDate || rowDate <= toDate;
            const show = companyMatches && fromMatches && toMatches;
            row.classList.toggle('hidden', !show);
            if (show) {
                visibleCount++;
                grossTotal += Number(row.dataset.amount || 0);
                revenueTotal += Number(row.dataset.fee || 0);
                refundedTotal += Number(row.dataset.refunded || 0);
                countTotal += Number(row.dataset.count || 0);
            }
        });

        if (ledgerTotals.gross) ledgerTotals.gross.textContent = formatMoney(grossTotal);
        if (ledgerTotals.revenue) ledgerTotals.revenue.textContent = formatMoney(revenueTotal);
        if (ledgerTotals.refunded) ledgerTotals.refunded.textContent = formatMoney(refundedTotal);
        if (ledgerTotals.count) ledgerTotals.count.textContent = countTotal.toLocaleString('en-US');
        ledgerEmptyState?.classList.toggle('hidden', visibleCount > 0);
    }

    ledgerCompanyFilter?.addEventListener('change', applyLedgerFilters);
    ledgerFromDate?.addEventListener('input', applyLedgerFilters);
    ledgerToDate?.addEventListener('input', applyLedgerFilters);
    ledgerClearFilters?.addEventListener('click', function () {
        if (ledgerFromDate) ledgerFromDate.value = '';
        if (ledgerToDate) ledgerToDate.value = '';
        if (ledgerCompanyFilter) ledgerCompanyFilter.value = 'all';
        applyLedgerFilters();
    });
    applyLedgerFilters();

    // Click-to-reveal OneLink API credentials from the Provisioning Status table.
    // Fetched on demand (admin-only api/banking/get.php) rather than baked into
    // the page source, and only one row's panel is open at a time.
    function credLine(label, value) {
        const v = value || '';
        return '<div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2">' +
            '<div class="min-w-0"><p class="text-[9px] font-black uppercase tracking-[0.16em] text-slate-400">' + escapeHtml(label) + '</p>' +
            '<p class="truncate font-mono text-xs font-semibold text-slate-800">' + (v ? escapeHtml(v) : '<span class="text-slate-400">—</span>') + '</p></div>' +
            (v ? '<button type="button" class="cred-copy shrink-0 rounded-md border border-slate-200 px-2 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-500 transition hover:bg-slate-100" data-copy="' + escapeHtml(v) + '">Copy</button>' : '') +
            '</div>';
    }
    function renderCreds(g) {
        return '<div class="grid gap-2 sm:grid-cols-2">' +
            credLine('Base URL', g.base_url) +
            credLine('Terminal ID', g.terminal_id) +
            credLine('Salt', g.salt) +
            credLine('Token (bp_token)', g.token) +
            (g.access_code ? credLine('Access Code', g.access_code) : '') +
            '</div>' +
            '<p class="mt-3 text-[11px] font-semibold leading-relaxed text-amber-700">Terminal ID, salt and token together are all any system needs to charge cards that settle to this company’s account — treat them like a password.</p>';
    }
    document.querySelectorAll('.cred-toggle').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const cid = btn.dataset.cid;
            const panel = document.querySelector('.cred-reveal[data-cid="' + cid + '"]');
            if (!panel) return;
            const willShow = panel.classList.contains('hidden');
            document.querySelectorAll('.cred-reveal').forEach(function (p) { p.classList.add('hidden'); });
            document.querySelectorAll('.cred-toggle').forEach(function (b) {
                b.setAttribute('aria-expanded', 'false');
                const c = b.querySelector('.cred-caret');
                if (c) c.classList.remove('rotate-180');
            });
            if (!willShow) return;
            panel.classList.remove('hidden');
            btn.setAttribute('aria-expanded', 'true');
            const caret = btn.querySelector('.cred-caret');
            if (caret) caret.classList.add('rotate-180');
            if (panel.dataset.loaded === '1') return;
            try {
                const res = await fetch('api/banking/get.php?company_id=' + encodeURIComponent(cid));
                const data = await res.json();
                if (!data.success || !data.gateway || data.gateway.terminal_id === undefined) {
                    panel.innerHTML = '<p class="text-xs font-bold text-rose-600">Could not load credentials for this company.</p>';
                    return;
                }
                panel.innerHTML = renderCreds(data.gateway);
                panel.dataset.loaded = '1';
                if (window.lucide) lucide.createIcons();
            } catch (_) {
                panel.innerHTML = '<p class="text-xs font-bold text-rose-600">Network error while loading credentials.</p>';
            }
        });
    });
    document.addEventListener('click', function (e) {
        const copyBtn = e.target.closest('.cred-copy');
        if (!copyBtn || !navigator.clipboard) return;
        navigator.clipboard.writeText(copyBtn.dataset.copy || '').then(function () {
            const original = copyBtn.textContent;
            copyBtn.textContent = 'Copied';
            setTimeout(function () { copyBtn.textContent = original; }, 1200);
        });
    });

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
                showAlert(data.already ? 'Already provisioned.' : 'OneLink account created. Access code emailed to the company. Refreshing status…', true);
                loadGateway();
                if (!data.already) setTimeout(function () { window.location.reload(); }, 1200);
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
            if (data.provisioned.length) {
                html += '<button type="button" onclick="window.location.reload()" class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-slate-700">Refresh Status Table</button>';
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
