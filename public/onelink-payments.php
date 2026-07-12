<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}
if (empty($user['is_admin'])) {
    header('Location: index.php');
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

<main class="mx-auto max-w-7xl px-6 py-8">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
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
                    <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-400/15 text-violet-200"><i data-lucide="landmark" class="h-4 w-4"></i></span><span class="text-sm font-black">Heritage Bank clearing</span></div>
                    <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-400/15 text-emerald-200"><i data-lucide="badge-dollar-sign" class="h-4 w-4"></i></span><span class="text-sm font-black">Settlement to business</span></div>
                </div>
            </div>
            <div class="rounded-2xl border border-white/10 bg-white/6 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/35">Settlement Account</p>
                <div class="mt-4 rounded-xl border border-white/10 bg-slate-900/70 p-4">
                    <p class="text-sm font-black"><?= htmlspecialchars($activeCompany['name']) ?></p>
                    <p class="mt-1 text-xs font-semibold text-white/45">Heritage Bank</p>
                    <p class="mt-3 font-mono text-sm font-black tracking-wider text-cyan-200">**** 4821</p>
                    <p class="mt-2 text-[10px] font-bold uppercase tracking-[0.14em] text-white/30">Template placeholder</p>
                </div>
            </div>
        </div>
        <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4"><p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Received</p><p class="mt-2 text-2xl font-black">$18,540.25</p></div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4"><p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Paid</p><p class="mt-2 text-2xl font-black text-emerald-600">$14,220.25</p></div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4"><p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Outstanding</p><p class="mt-2 text-2xl font-black text-amber-600">$4,320.00</p></div>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4"><p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Settled</p><p class="mt-2 text-2xl font-black text-sky-600">$12,980.00</p></div>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-black tracking-tight">Payments</h2>
                <p class="mt-1 text-xs font-semibold text-slate-400">Template data only until OneLink payment APIs are connected.</p>
            </div>
        </div>
        <div class="grid gap-3 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]">
            <input type="date" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500">
            <input type="date" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500">
            <select class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500">
                <option>All statuses</option><option>Paid</option><option>Outstanding</option><option>Settled</option>
            </select>
            <select class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-cyan-500">
                <option>All sources</option><option>POS</option><option>Invoice</option><option>Payment form</option>
            </select>
            <button class="rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white">Filter</button>
        </div>

        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200">
            <div class="hidden grid-cols-[1fr_1fr_1fr_1fr_1fr] gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 text-[10px] font-black uppercase tracking-[0.16em] text-slate-400 lg:grid">
                <span>Date</span><span>Source</span><span>Amount</span><span>Status</span><span>Settlement</span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php
                $rows = [
                    ['Jul 08, 2026', 'POS', '$840.00', 'Settled', 'Paid to bank account'],
                    ['Jul 08, 2026', 'Invoice', '$1,250.00', 'Paid', 'In Heritage clearing'],
                    ['Jul 07, 2026', 'Payment form', '$420.00', 'Outstanding', 'Pending settlement'],
                ];
                foreach ($rows as $row):
                    $tone = $row[3] === 'Settled' ? 'bg-sky-50 text-sky-700 border-sky-200' : ($row[3] === 'Paid' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200');
                ?>
                <div class="grid gap-3 px-4 py-4 text-sm lg:grid-cols-[1fr_1fr_1fr_1fr_1fr] lg:items-center">
                    <div class="font-bold text-slate-700"><?= htmlspecialchars($row[0]) ?></div>
                    <div class="font-black text-slate-900"><?= htmlspecialchars($row[1]) ?></div>
                    <div class="font-black text-slate-900"><?= htmlspecialchars($row[2]) ?></div>
                    <div><span class="inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] <?= $tone ?>"><?= htmlspecialchars($row[3]) ?></span></div>
                    <div class="text-xs font-semibold text-slate-500"><?= htmlspecialchars($row[4]) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
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
