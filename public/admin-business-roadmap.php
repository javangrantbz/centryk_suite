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
            ['Dashboard grid cards for each module, shown when the selected company is entitled', 'partials/dashboard.php'],
            ['Dense "desktop tool" UI across every Business page', 'partials/business_head.php'],
            ['Subscription billing — monthly charge cycle, MRR / outstanding, paid/waive/void', 'admin-business-billing.php'],
            ['Receivables collections — overdue work list, drafted reminders, credit-status API for spokes', 'receivables.php'],
            ['Reconciliation payment references — PAY-xxxx per invoice, strong match signal', 'reconciliation.php'],
            ['Routes settlement approval — maker-checker: submit → admin approves → locked', 'routes.php'],
            ['Routes — reorder stops on a trip', 'routes.php'],
            ['Enterprise activity feed — audit across the group', 'groups.php'],
            ['Printable customer statement — chronological ledger + aging, print/Save-as-PDF', 'receivables_statement.php'],
            ['Reconciliation — OFX/QFX + MT940 import, one-click auto-match of confident deposits', 'reconciliation.php'],
            ['Printable whole-company AR aging report — month-end pack', 'receivables_aging.php'],
            ['Proactive alerts — settlement variance + daily sweep for newly-overdue invoices & subscription charges', 'app/services/BusinessNotifier.php'],
            ['Dashboard module cards show a live health number per company (AR overdue, unmatched deposits, cash in transit)', 'partials/dashboard.php'],
            ['Collections reminders — email the reminder to the customer + record it as sent', 'receivables.php'],
            ['Statement — email the full statement of account to the customer', 'receivables.php'],
            ['Billing dunning — overdue subscription → past-due (read-only), auto-recovers on payment', 'admin-business-billing.php'],
            ['Credit control — invoice-maker API blocks issuing to a held / over-limit customer (audited override)', 'invoice-maker/api/invoices.php'],
            ['Month-end statement run — email a statement to every account with a balance in one action', 'receivables.php'],
            ['Reconciliation — export the bank-line list (unmatched / matched / ignored) to CSV', 'reconciliation.php'],
            ['Enterprise — printable consolidated AR aging across the whole group', 'groups_aging.php'],
            ['Routes — phone-first driver view: assigned runs, tick off stops, hand in cash (settlement still admin-approved)', 'routes_field.php'],
            ['Receivables — bulk customer import from CSV (name/limit/terms/opening balance), upsert by name', 'receivables.php'],
            ['This tracker', 'admin-business-roadmap.php'],
        ],
    ],
    'In progress' => [
        'accent' => 'sky',
        'items'  => [
            ['Credit-hold enforcement — hub api/invoice-maker/api/invoices.php blocks issuing to a held / over-limit customer (override is audited); still to wire: the invoice-maker UI form in the sibling repo + OnePay checkout', null],
            ['Billing — generate a real invoice through invoice-maker (dunning → past_due is done)', null],
            ['Reconciliation — live bank feed adapter (needs Centryk Bank / a bank API)', null],
        ],
    ],
    'Planned' => [
        'accent' => 'violet',
        'items'  => [
            ['Routes — driver field view is shipped; still planned: maps / route optimisation, per-driver commission', null],
            ['Enterprise — maker-checker on package grants / write-offs, inter-company transactions', null],
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

$accentDot = [
    'emerald' => '#10b981', 'sky' => '#38bdf8', 'violet' => '#818cf8', 'amber' => '#fbbf24',
];
$accentText = [
    'emerald' => '#047857', 'sky' => '#0369a1', 'violet' => '#4338ca', 'amber' => '#b45309',
];

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Centryk Business'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Centryk Business'; $headerMaxW = 'max-w-5xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="biz mx-auto max-w-5xl px-4 py-4">

    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="biz-kicker">Internal tracker</p>
            <h1 class="mt-0.5">Centryk Business</h1>
            <p class="biz-muted mt-1 max-w-2xl" style="font-size:12px">
                Paid capability tier layered on the free hub. Prompted by Bowen &amp; Bowen's
                Aug 2026 call to modernise Belize's payment system — less cash on delivery
                routes, payments that auto-post to a customer account, less manual reconciliation.
            </p>
        </div>
        <span class="flex gap-2">
            <a href="admin-business-billing.php" class="biz-btn biz-btn-ghost">Billing</a>
            <a href="admin-business-packages.php" class="biz-btn biz-btn-primary">
                Grant console <i data-lucide="arrow-right" style="height:13px;width:13px"></i>
            </a>
        </span>
    </div>

    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <div class="biz-tile"><div class="biz-tile-l">Companies on a plan</div><div class="biz-tile-v"><?= $payingCompanies ?></div></div>
        <div class="biz-tile"><div class="biz-tile-l">Est. MRR</div><div class="biz-tile-v">BZD <?= number_format($mrr, 2) ?></div></div>
        <div class="biz-tile"><div class="biz-tile-l">Pending requests</div><div class="biz-tile-v <?= $pendingRequests ? 'biz-t-amber' : '' ?>"><?= $pendingRequests ?></div></div>
        <div class="biz-tile"><div class="biz-tile-l">Packages in catalog</div><div class="biz-tile-v"><?= count($byPackage) ?></div></div>
    </div>

    <div class="biz-panel mt-3">
        <div class="biz-panel-head">Adoption by package</div>
        <div class="biz-list">
            <?php foreach ($byPackage as $p): ?>
                <div class="biz-row" style="cursor:default">
                    <span class="flex-1" style="font-weight:600"><?= htmlspecialchars($p['label']) ?></span>
                    <span class="biz-muted" style="font-size:12px">
                        <?= (int)$p['active'] ?> active<?php if ((int)$p['suspended']): ?> · <span class="biz-t-amber"><?= (int)$p['suspended'] ?> paused</span><?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="mt-4 grid gap-3 lg:grid-cols-2">
        <?php foreach ($board as $col => $meta): $dot = $accentDot[$meta['accent']] ?? '#94a3b8'; $tx = $accentText[$meta['accent']] ?? '#64748b'; ?>
            <div class="biz-panel">
                <div class="biz-panel-head" style="color:<?= $tx ?>">
                    <span><span style="display:inline-block;width:7px;height:7px;border-radius:999px;background:<?= $dot ?>;margin-right:6px;vertical-align:1px"></span><?= htmlspecialchars($col) ?></span>
                    <span class="biz-muted"><?= count($meta['items']) ?></span>
                </div>
                <ul class="biz-panel-body" style="display:flex;flex-direction:column;gap:6px;margin:0;padding-left:10px;padding-right:10px;list-style:none">
                    <?php foreach ($meta['items'] as $item): ?>
                        <li style="font-size:12px;font-weight:500;color:var(--bz-fg);position:relative;padding-left:12px">
                            <span style="position:absolute;left:0;top:6px;width:4px;height:4px;border-radius:999px;background:#cbd5e1"></span>
                            <?= htmlspecialchars($item[0]) ?>
                            <?php if (!empty($item[1])): ?>
                                <code style="background:#f1f5f9;border-radius:2px;padding:0 4px;font-size:11px;color:var(--bz-muted)"><?= htmlspecialchars($item[1]) ?></code>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="biz-panel">
            <div class="biz-panel-head">How it's gated</div>
            <ul class="biz-panel-body" style="display:flex;flex-direction:column;gap:6px;margin:0;font-size:12px;font-weight:500">
                <li>Capabilities, not new apps — they light up inside invoice-maker / banking. Routes is the exception (its own spoke app).</li>
                <li><code style="background:#f1f5f9;border-radius:2px;padding:0 3px">company_entitlements</code> is the runtime gate; <code style="background:#f1f5f9;border-radius:2px;padding:0 3px">company_subscriptions</code> is the commercial record.</li>
                <li>Human-in-the-loop: an admin grants, or a company requests. No self-serve activation.</li>
                <li>OneLink is transactional; Centryk Business is subscription. Free core stays free.</li>
            </ul>
        </div>
        <div class="biz-panel" style="border-color:#fcd9a5;background:#fffbeb">
            <div class="biz-panel-head" style="background:#fef3c7;border-color:#fcd9a5;color:#b45309">Risks &amp; guardrails</div>
            <ul class="biz-panel-body" style="display:flex;flex-direction:column;gap:6px;margin:0;font-size:12px;font-weight:500;color:#92400e">
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
