<?php
/**
 * Centryk Business — internal tracker.
 *
 * A living status page for the paid-tier build: what's live, what's in flight,
 * what's still to do, and the dependencies that gate it. Live adoption numbers
 * come from the entitlement tables; the roadmap board is hand-maintained in the
 * $board array below — edit it as things move.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/core/DB.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
$user = $me['user'];
$pdo  = DB::pdo();

/* ── Live adoption ─────────────────────────────────────────────────────── */
$byPackage = $pdo->query("
    SELECT bp.`key`, bp.label,
           SUM(e.state = 'active')    AS active,
           SUM(e.state = 'suspended') AS suspended
    FROM business_packages bp
    LEFT JOIN company_entitlements e ON e.package_key = bp.`key`
    WHERE bp.status = 'active'
    GROUP BY bp.`key`, bp.label, bp.sort_order
    ORDER BY bp.sort_order
")->fetchAll(PDO::FETCH_ASSOC);

$pendingRequests = (int)$pdo->query("
    SELECT COUNT(*) FROM business_package_requests WHERE status = 'pending'
")->fetchColumn();

$payingCompanies = (int)$pdo->query("
    SELECT COUNT(DISTINCT company_id) FROM company_entitlements WHERE state IN ('active','suspended')
")->fetchColumn();

$mrr = (float)$pdo->query("
    SELECT COALESCE(SUM(CASE WHEN billing_interval = 'annual' THEN price / 12 ELSE price END), 0)
    FROM company_subscriptions
    WHERE status IN ('active', 'trialing')
")->fetchColumn();

/* ── Roadmap board (hand-maintained) ──────────────────────────────────── */
$board = [
    'Shipped' => [
        'accent' => 'emerald',
        'items'  => [
            ['company_entitlements / company_subscriptions / business_packages schema', 'database/add_business_packages.sql'],
            ['Entitlements enforcement class — FULL / READ / NONE, lifecycle, audit', 'app/core/Entitlements.php'],
            ['Admin grant console — grant, suspend, resume, cancel, triage', 'admin-business-packages.php'],
            ['Customer "Explore more services" page + request → lead + notify admins', 'business.php'],
            ['Indicative package pricing', 'database/set_business_package_prices.sql'],
            ['Receivables v1 — customer ledger, aging, credit limit/terms/hold, receipts auto-applied oldest-due-first', 'receivables.php'],
            ['Reconciliation v1 — CSV bank import, dedupe, suggested matches, one-click match posts a receipt', 'reconciliation.php'],
            ['Field Sales & Routes v1 — routes, trips, per-stop collections, driver cash settlement w/ variance, cash-in-transit', 'routes.php'],
            ['Enterprise v1 — company_groups + companies.group_id, group members, group-level entitlements members inherit, consolidated view', 'groups.php'],
            ['This tracker', 'admin-business-roadmap.php'],
        ],
    ],
    'In progress' => [
        'accent' => 'sky',
        'items'  => [
            ['Receivables — statement PDF / email, collections reminders, credit-hold enforcement on new invoices', null],
            ['Reconciliation — OFX/MT940 formats, per-invoice payment references, live bank feed adapter', null],
            ['Routes — mobile/field UI, route sequencing/maps, per-driver commission, supervisor approval step', null],
            ['Enterprise — maker-checker approvals, group-scoped audit view, inter-company transactions', null],
            ['Surface Receivables/Reconciliation/Routes/Groups cards on the dashboard grid when entitled', null],
        ],
    ],
    'Planned' => [
        'accent' => 'violet',
        'items'  => [
            ['Billing — company_subscription_charges, invoice via invoice-maker, dunning cron → past_due', null],
            ['Standalone public pricing / marketing page (once prices are locked)', null],
        ],
    ],
    'Deferred / blocked' => [
        'accent' => 'amber',
        'items'  => [
            ['Real money movement — needs Centryk Bank as a settlement rail (sponsor bank + Central Bank)', null],
            ['XFER settlement — not yet tied to a bank, no real funds move', null],
            ['Live bank feeds — depends on banks / Central Bank exposing data (the B&B ask itself)', null],
            ['Shared cross-entity customer directory + inter-company AR — bigger data-model change', null],
        ],
    ],
];

$risks = [
    'Production hardening — XAMPP, hand-run migrations, single box. Enterprise procurement (e.g. Bowen & Bowen) will scrutinise hosting, uptime and data terms more than features.',
    'Never move an existing free feature behind the paywall — Centryk Business is net-new only.',
    'Import-based reconciliation delivers value before the bank rail exists — ship that first.',
    'Any role/entitlement write is high-risk (see the 2026-06-03 provision role-downgrade incident) — every grant/revoke is already audited; keep it that way.',
];

$accentClass = static function (string $a, string $part): string {
    $map = [
        'emerald' => ['bar' => 'bg-emerald-400', 'text' => 'text-emerald-700', 'chip' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'sky'     => ['bar' => 'bg-sky-400',     'text' => 'text-sky-700',     'chip' => 'bg-sky-50 text-sky-700 border-sky-200'],
        'violet'  => ['bar' => 'bg-violet-400',  'text' => 'text-violet-700',  'chip' => 'bg-violet-50 text-violet-700 border-violet-200'],
        'amber'   => ['bar' => 'bg-amber-400',   'text' => 'text-amber-700',   'chip' => 'bg-amber-50 text-amber-700 border-amber-200'],
    ];
    return $map[$a][$part] ?? '';
};

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Centryk Business - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Centryk Business'; $headerMaxW = 'max-w-5xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-5xl px-4 pt-4 pb-14">

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-600">Internal tracker</p>
            <h1 class="mt-0.5 text-2xl font-black tracking-tight text-slate-950">Centryk Business</h1>
            <p class="mt-1 max-w-2xl text-sm font-semibold text-slate-500">
                Paid capability tier layered on the free hub. Prompted by Bowen &amp; Bowen's
                Aug 2026 call to modernise Belize's payment system — less cash on delivery
                routes, payments that auto-post to a customer account, less manual reconciliation.
            </p>
        </div>
        <a href="admin-business-packages.php" class="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white hover:bg-slate-800">
            Grant console <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
        </a>
    </div>

    <!-- Live adoption -->
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Companies on a plan</p>
            <p class="mt-1 text-2xl font-black"><?= $payingCompanies ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Est. MRR</p>
            <p class="mt-1 text-2xl font-black">BZD <?= number_format($mrr, 2) ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Pending requests</p>
            <p class="mt-1 text-2xl font-black <?= $pendingRequests ? 'text-amber-600' : '' ?>"><?= $pendingRequests ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Packages in catalog</p>
            <p class="mt-1 text-2xl font-black"><?= count($byPackage) ?></p>
        </div>
    </div>

    <div class="mt-3 overflow-hidden rounded-2xl border border-slate-200 bg-white">
        <div class="bg-slate-50 px-4 py-2 text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Adoption by package</div>
        <?php foreach ($byPackage as $p): ?>
            <div class="flex items-center justify-between border-t border-slate-100 px-4 py-2.5 first:border-t-0">
                <span class="text-sm font-bold"><?= htmlspecialchars($p['label']) ?></span>
                <span class="text-xs font-semibold text-slate-500">
                    <?= (int)$p['active'] ?> active<?php if ((int)$p['suspended']): ?> · <span class="text-amber-600"><?= (int)$p['suspended'] ?> paused</span><?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Roadmap board -->
    <div class="mt-8 grid gap-4 lg:grid-cols-2">
        <?php foreach ($board as $col => $meta): ?>
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full <?= $accentClass($meta['accent'], 'bar') ?>"></span>
                    <h2 class="text-sm font-black uppercase tracking-[0.1em] <?= $accentClass($meta['accent'], 'text') ?>"><?= htmlspecialchars($col) ?></h2>
                    <span class="ml-auto text-xs font-black text-slate-300"><?= count($meta['items']) ?></span>
                </div>
                <ul class="mt-3 space-y-2.5">
                    <?php foreach ($meta['items'] as $item): ?>
                        <li class="flex gap-2 text-sm font-semibold text-slate-600">
                            <i data-lucide="dot" class="mt-0.5 h-4 w-4 shrink-0 text-slate-300"></i>
                            <span>
                                <?= htmlspecialchars($item[0]) ?>
                                <?php if (!empty($item[1])): ?>
                                    <code class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-bold text-slate-500"><?= htmlspecialchars($item[1]) ?></code>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Model -->
    <div class="mt-8 grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-black uppercase tracking-[0.1em] text-slate-500">How it's gated</h2>
            <ul class="mt-3 space-y-2 text-sm font-semibold text-slate-600">
                <li>Capabilities, not new apps — they light up inside invoice-maker / banking. Routes is the exception (its own spoke app).</li>
                <li><code class="rounded bg-slate-100 px-1 text-[12px]">company_entitlements</code> is the runtime gate; <code class="rounded bg-slate-100 px-1 text-[12px]">company_subscriptions</code> is the commercial record.</li>
                <li>Human-in-the-loop: an admin grants, or a company requests. No self-serve activation.</li>
                <li>OneLink is transactional; Centryk Business is subscription. Free core stays free.</li>
            </ul>
        </div>
        <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-5">
            <h2 class="text-sm font-black uppercase tracking-[0.1em] text-amber-700">Risks &amp; guardrails</h2>
            <ul class="mt-3 space-y-2 text-sm font-semibold text-amber-900/80">
                <?php foreach ($risks as $r): ?>
                    <li><?= htmlspecialchars($r) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

</div>

<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
