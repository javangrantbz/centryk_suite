<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/OneLinkPaymentsService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$pdo = DB::pdo();
$isCentrykAdmin = !empty($user['is_admin']);

if ($isCentrykAdmin) {
    $coStmt = $pdo->query("
        SELECT c.id, c.uuid, c.name, 'admin' AS role
        FROM companies c
        WHERE c.status = 'active'
        ORDER BY c.name ASC
    ");
} else {
    $coStmt = $pdo->prepare("
        SELECT c.id, c.uuid, c.name, cm.role
        FROM company_members cm
        JOIN companies c ON c.id = cm.company_id
        WHERE cm.user_id = :uid
          AND cm.status = 'active'
          AND c.status = 'active'
          AND cm.role = 'admin'
        ORDER BY c.name ASC
    ");
    $coStmt->execute(['uid' => (int)$user['id']]);
}
$companies = $coStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$companies) {
    http_response_code(403);
    echo 'You do not have access to OneLink Payments.';
    exit;
}

$requestedUuid = trim((string)($_GET['company_uuid'] ?? ''));
$activeCompany = $companies[0];
if ($requestedUuid !== '') {
    foreach ($companies as $company) {
        if ((string)$company['uuid'] === $requestedUuid) {
            $activeCompany = $company;
            break;
        }
    }
}

// ── Live OneLink data ──────────────────────────────────────────────────────
// Filter: 'all' (paginated /user/transactions) or a settlement status
// (/user/transactions/status, unpaginated). Summary tiles always reflect the
// unsettled/settled totals regardless of which list is showing.
$statusFilter = $_GET['status'] ?? '';
$statusFilter = in_array($statusFilter, ['0', '2'], true) ? (int)$statusFilter : null;
$listPage = max(1, (int)($_GET['page'] ?? 1));

$onelinkCreds   = OneLinkPaymentsService::credentials($pdo, (int)$activeCompany['id']);
$onelinkConnected = $onelinkCreds !== null;
$listError      = null;
$transactions   = [];
$pagination     = null;
$unsettled      = null;
$settled        = null;

if ($onelinkConnected) {
    $unsettled = OneLinkPaymentsService::byStatus($onelinkCreds, 0);
    $settled   = OneLinkPaymentsService::byStatus($onelinkCreds, 2);

    if ($statusFilter === 0) {
        $listResult = $unsettled;
    } elseif ($statusFilter === 2) {
        $listResult = $settled;
    } else {
        $listResult = OneLinkPaymentsService::transactions($onelinkCreds, $listPage);
        $pagination = $listResult['pagination'] ?? null;
    }
    $transactions = $listResult['transactions'] ?? [];
    if (empty($listResult['success'])) {
        $listError = (string)($listResult['message'] ?? 'Could not reach OneLink.');
    }
}

function onelink_money($value): string
{
    return '$' . number_format((float)$value, 2);
}

$pageTitle = 'OneLink Payments';
$headerMaxW = 'max-w-7xl';
ob_start();
if ($isCentrykAdmin) {
    include __DIR__ . '/partials/admin_tools_dropdown.php';
}
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>OneLink Payments - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>[data-lucide] { display: inline-block; }</style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased text-slate-900">
<?php include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-7xl px-6 pt-1 pb-5">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Company Payments</p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900">OneLink Payments</h1>
            <p class="mt-1 text-sm font-semibold text-slate-500">Company-scoped payment activity for <?= htmlspecialchars($activeCompany['name']) ?>.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
        <button type="button" id="showIntroBtn" class="hidden rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-slate-600 shadow-sm transition hover:bg-slate-50">
            How OneLink works
        </button>
        <form method="get" class="flex items-center gap-2">
            <select name="company_uuid" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 shadow-sm outline-none focus:border-cyan-500">
                <?php foreach ($companies as $company): ?>
                <option value="<?= htmlspecialchars($company['uuid']) ?>" <?= $company['uuid'] === $activeCompany['uuid'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($company['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button class="rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">View</button>
        </form>
        </div>
    </div>

    <section id="introPanel" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="grid gap-6 bg-slate-950 px-6 py-6 text-white lg:grid-cols-[1.2fr_0.8fr_0.8fr]">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex rounded-full border border-cyan-300/20 bg-cyan-300/10 px-3 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-cyan-200">OneLink</span>
                    <button type="button" id="dismissIntroBtn" class="rounded-full border border-white/10 px-3 py-1 text-[10px] font-black uppercase tracking-[0.14em] text-white/45 transition hover:bg-white/10 hover:text-white/80">Got it</button>
                </div>
                <h2 class="mt-4 max-w-2xl text-3xl font-black tracking-tight">Track POS, invoice, and online payment collections.</h2>
                <p class="mt-3 max-w-2xl text-sm font-semibold leading-relaxed text-white/55">
                    Payments can be accepted at POS, from invoices, or through a OneLink payment form. Each company keeps its own settlement destination even when one Centryk account manages multiple companies.
                </p>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/6 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/35">Money Flow</p>
                <div class="mt-4 grid gap-3">
                    <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-cyan-400/15 text-cyan-200"><i data-lucide="shopping-cart" class="h-4 w-4"></i></span><span class="text-sm font-black">Customer pays</span></div>
                    <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-400/15 text-violet-200"><i data-lucide="landmark" class="h-4 w-4"></i></span><span class="text-sm font-black">OneLink clearing</span></div>
                    <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-400/15 text-emerald-200"><i data-lucide="badge-dollar-sign" class="h-4 w-4"></i></span><span class="text-sm font-black">Settlement to business</span></div>
                </div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/6 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/35">OneLink Terminal</p>
                <?php if ($onelinkConnected): ?>
                <div class="mt-4 rounded-xl border border-white/10 bg-slate-900/70 p-4">
                    <p class="text-sm font-black"><?= htmlspecialchars($activeCompany['name']) ?></p>
                    <p class="mt-3 font-mono text-sm font-black tracking-wider text-cyan-200"><?= htmlspecialchars((string)$onelinkCreds['terminal_id']) ?></p>
                    <p class="mt-2 text-[10px] font-bold uppercase tracking-[0.14em] text-emerald-300/80">Connected</p>
                </div>
                <?php else: ?>
                <div class="mt-4 rounded-xl border border-white/10 bg-slate-900/70 p-4">
                    <p class="text-sm font-black text-white/70">Not connected yet</p>
                    <?php if ($isCentrykAdmin): ?>
                    <a href="onelink-api-accounts.php" class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-cyan-500 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-slate-950 transition hover:bg-cyan-400">Provision in OneLink API Accounts</a>
                    <?php else: ?>
                    <p class="mt-2 text-[11px] font-semibold text-white/40">Ask a Centryk admin to connect OneLink for this company.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($onelinkConnected): ?>
        <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Total Volume</p>
                <p class="mt-2 text-2xl font-black">
                    <?= (!empty($unsettled['success']) && !empty($settled['success'])) ? onelink_money((float)($unsettled['totalAmount'] ?? 0) + (float)($settled['totalAmount'] ?? 0)) : '—' ?>
                </p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Unsettled</p>
                <p class="mt-2 text-2xl font-black text-amber-600"><?= !empty($unsettled['success']) ? onelink_money($unsettled['totalAmount'] ?? 0) : '—' ?></p>
                <p class="mt-1 text-[11px] font-bold text-slate-400"><?= !empty($unsettled['success']) ? (int)($unsettled['count'] ?? 0) . ' transaction(s)' : 'Unavailable' ?></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Settled</p>
                <p class="mt-2 text-2xl font-black text-sky-600"><?= !empty($settled['success']) ? onelink_money($settled['totalAmount'] ?? 0) : '—' ?></p>
                <p class="mt-1 text-[11px] font-bold text-slate-400"><?= !empty($settled['success']) ? (int)($settled['count'] ?? 0) . ' transaction(s)' : 'Unavailable' ?></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Transactions</p>
                <p class="mt-2 text-2xl font-black text-slate-900"><?= $pagination ? (int)($pagination['total'] ?? 0) : (((int)($unsettled['count'] ?? 0)) + ((int)($settled['count'] ?? 0))) ?></p>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($onelinkConnected): ?>
    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-black tracking-tight">Payments</h2>
                <p class="mt-1 text-xs font-semibold text-slate-400">Live from OneLink — newest first.</p>
            </div>
            <?php $qs = ['company_uuid' => $activeCompany['uuid']]; ?>
            <div class="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 p-1">
                <a href="onelink-payments.php?<?= http_build_query($qs) ?>" class="rounded-lg px-3 py-1.5 text-xs font-black uppercase tracking-[0.1em] <?= $statusFilter === null ? 'bg-slate-950 text-white' : 'text-slate-500 hover:text-slate-800' ?>">All</a>
                <a href="onelink-payments.php?<?= http_build_query($qs + ['status' => '0']) ?>" class="rounded-lg px-3 py-1.5 text-xs font-black uppercase tracking-[0.1em] <?= $statusFilter === 0 ? 'bg-amber-500 text-white' : 'text-slate-500 hover:text-slate-800' ?>">Unsettled</a>
                <a href="onelink-payments.php?<?= http_build_query($qs + ['status' => '2']) ?>" class="rounded-lg px-3 py-1.5 text-xs font-black uppercase tracking-[0.1em] <?= $statusFilter === 2 ? 'bg-sky-600 text-white' : 'text-slate-500 hover:text-slate-800' ?>">Settled</a>
            </div>
        </div>

        <?php if ($listError): ?>
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">
            OneLink error: <?= htmlspecialchars($listError) ?>
        </div>
        <?php endif; ?>

        <div class="overflow-hidden rounded-2xl border border-slate-200">
            <div class="hidden grid-cols-[1fr_1fr_1fr_1fr_1fr] gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em] text-slate-400 lg:grid">
                <span>Date</span><span>Customer</span><span>Amount</span><span>Status</span><span>Reference</span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php if (!$transactions && !$listError):
                    $knownTotal = (int)($pagination['total'] ?? 0);
                ?>
                <div class="px-4 py-8 text-center text-sm font-bold text-slate-400">
                    <?php if ($knownTotal > 0): ?>
                    OneLink reports <?= $knownTotal ?> transaction(s) for this terminal, but didn't return the details on this request — try refreshing.
                    <?php else: ?>
                    No transactions yet.
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php foreach ($transactions as $t):
                    $tone = ((int)($t['status'] ?? -1)) === 2 ? 'bg-sky-50 text-sky-700 border-sky-200' : 'bg-amber-50 text-amber-700 border-amber-200';
                ?>
                <div class="grid gap-3 px-4 py-4 text-sm lg:grid-cols-[1fr_1fr_1fr_1fr_1fr] lg:items-center">
                    <div class="font-bold text-slate-700"><?= htmlspecialchars((string)($t['dateCreated'] ?? '')) ?></div>
                    <div class="font-black text-slate-900"><?= htmlspecialchars((string)($t['customerName'] ?? '—')) ?></div>
                    <div class="font-black text-slate-900"><?= onelink_money($t['amount'] ?? 0) ?></div>
                    <div><span class="inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] <?= $tone ?>"><?= htmlspecialchars((string)($t['statusLabel'] ?? '')) ?></span></div>
                    <div class="text-xs font-semibold text-slate-500"><?= htmlspecialchars((string)($t['refnumber'] ?? $t['orderId'] ?? '—')) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($pagination && (int)($pagination['totalPages'] ?? 1) > 1): ?>
        <div class="mt-4 flex items-center justify-between">
            <p class="text-xs font-bold text-slate-400">Page <?= (int)$pagination['page'] ?> of <?= (int)$pagination['totalPages'] ?> · <?= (int)$pagination['total'] ?> total</p>
            <div class="flex items-center gap-2">
                <?php if ($listPage > 1): ?>
                <a href="onelink-payments.php?<?= http_build_query($qs + ['page' => $listPage - 1]) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-slate-600 hover:bg-slate-50">Prev</a>
                <?php endif; ?>
                <?php if (!empty($pagination['hasMore'])): ?>
                <a href="onelink-payments.php?<?= http_build_query($qs + ['page' => $listPage + 1]) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-slate-600 hover:bg-slate-50">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
if (window.lucide) { lucide.createIcons(); }
(function () {
    const key = 'onelinkPaymentsIntroDismissed';
    const intro = document.getElementById('introPanel');
    const dismiss = document.getElementById('dismissIntroBtn');
    const show = document.getElementById('showIntroBtn');

    function setIntroVisible(visible) {
        if (!intro || !show) return;
        intro.classList.toggle('hidden', !visible);
        show.classList.toggle('hidden', visible);
    }

    setIntroVisible(localStorage.getItem(key) !== '1');

    dismiss && dismiss.addEventListener('click', function () {
        localStorage.setItem(key, '1');
        setIntroVisible(false);
    });

    show && show.addEventListener('click', function () {
        localStorage.removeItem(key);
        setIntroVisible(true);
    });
})();
</script>
</body>
</html>
