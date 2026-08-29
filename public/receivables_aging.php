<?php
/**
 * Printable AR aging report (Centryk Business — Receivables).
 *   receivables_aging.php?company_id=1
 * Gated: an admin/manager of the company, with the 'receivables' entitlement.
 * Print → Save as PDF for the month-end pack.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/core/Entitlements.php';
require_once __DIR__ . '/../app/services/ReceivablesService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$companyId = (int)($_GET['company_id'] ?? 0);

$m = DB::pdo()->prepare("
    SELECT c.name FROM companies c
    JOIN company_members cm ON cm.company_id = c.id
    WHERE cm.user_id = :u AND cm.company_id = :c AND cm.status = 'active' AND cm.role IN ('admin','manager') LIMIT 1
");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
$company = $m->fetch(PDO::FETCH_ASSOC);
if (!$company || Entitlements::level($companyId, 'receivables') === Entitlements::NONE) {
    http_response_code(403);
    echo 'Not available.';
    exit;
}

$lh = DB::pdo()->prepare("
    SELECT COALESCE(NULLIF(TRIM(v.business_name),''), c.name) AS name,
           COALESCE(NULLIF(TRIM(v.currency_symbol),''), 'BZD ') AS currency
    FROM companies c LEFT JOIN invoice_settings v ON v.company_id = c.id WHERE c.id = :c LIMIT 1
");
$lh->execute(['c' => $companyId]);
$lh = $lh->fetch(PDO::FETCH_ASSOC) ?: ['name' => $company['name'], 'currency' => 'BZD '];
$cur = trim((string)$lh['currency']) ?: 'BZD';

$p = ReceivablesService::portfolio($companyId);
$rows = array_values(array_filter($p['customers'], static fn ($c) => abs($c['balance']) > 0.004));
usort($rows, static fn ($a, $b) => $b['balance'] <=> $a['balance']);
$t = $p['totals'];
$bd = ReceivablesService::badDebtReport($companyId, date('Y-01-01'), date('Y-m-d'));

$n = static fn ($v) => number_format((float)$v, 2);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>AR Aging — <?= htmlspecialchars($lh['name']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; background: #eceef1; color: #1a1a1a;
            font-family: "Segoe UI", -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 12.5px; line-height: 1.45;
        }
        .sheet { max-width: 960px; margin: 24px auto; background: #fff; padding: 36px 40px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
        .bar { display: flex; justify-content: space-between; align-items: flex-start; }
        .biz-name { font-size: 18px; font-weight: 700; }
        h1 { margin: 0; font-size: 18px; letter-spacing: 0.1em; text-align: right; }
        .sub { color: #666; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 22px; font-size: 12px; }
        th { text-align: right; padding: 6px 8px; border-bottom: 2px solid #333; font-size: 10px; letter-spacing: 0.05em; text-transform: uppercase; color: #555; }
        th:first-child, td:first-child { text-align: left; }
        td { padding: 5px 8px; border-bottom: 1px solid #eee; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tbody tr:hover { background: #f8f8f8; }
        .flag { color: #b00020; font-size: 10px; font-weight: 700; margin-left: 6px; }
        tfoot td { border-top: 2px solid #333; font-weight: 700; padding-top: 8px; }
        .over td { color: #b00020; }
        .toolbar { max-width: 960px; margin: 16px auto 0; display: flex; gap: 8px; }
        .toolbar button, .toolbar a { font: inherit; font-size: 12px; font-weight: 600; padding: 7px 14px; border-radius: 4px; border: 1px solid #cbd5e1; background: #fff; color: #334155; cursor: pointer; text-decoration: none; }
        .toolbar .primary { background: #4f46e5; border-color: #4f46e5; color: #fff; }
        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; }
            .toolbar { display: none; }
            @page { margin: 14mm; size: landscape; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button class="primary" onclick="window.print()">Print / Save as PDF</button>
    <a href="receivables.php?company_id=<?= $companyId ?>">Back to ledger</a>
</div>

<div class="sheet">
    <div class="bar">
        <div class="biz-name"><?= htmlspecialchars($lh['name']) ?></div>
        <div>
            <h1>AR AGING</h1>
            <div class="sub">As of <?= date('j M Y') ?> · amounts in <?= htmlspecialchars($cur) ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Customer</th><th>Current</th><th>1–30</th><th>31–60</th><th>61–90</th><th>90+</th><th>Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $c): $a = $c['aging']; ?>
            <tr class="<?= $c['aging']['b_90p'] > 0.004 ? 'over' : '' ?>">
                <td>
                    <?= htmlspecialchars($c['name']) ?>
                    <?php if ($c['on_hold']): ?><span class="flag">HOLD</span><?php endif; ?>
                    <?php if ($c['over_limit']): ?><span class="flag">OVER LIMIT</span><?php endif; ?>
                </td>
                <td><?= $n($a['current']) ?></td>
                <td><?= $n($a['b_1_30']) ?></td>
                <td><?= $n($a['b_31_60']) ?></td>
                <td><?= $n($a['b_61_90']) ?></td>
                <td><?= $n($a['b_90p']) ?></td>
                <td><?= $n($c['balance']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
            <tr><td colspan="7" style="text-align:center;color:#888;padding:18px">Nothing outstanding.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total (<?= count($rows) ?> account<?= count($rows) === 1 ? '' : 's' ?>)</td>
                <td><?= $n($t['current']) ?></td>
                <td><?= $n($t['b_1_30']) ?></td>
                <td><?= $n($t['b_31_60']) ?></td>
                <td><?= $n($t['b_61_90']) ?></td>
                <td><?= $n($t['b_90p']) ?></td>
                <td><?= $cur . ' ' . $n($t['balance']) ?></td>
            </tr>
        </tfoot>
    </table>

    <p class="sub" style="margin-top:18px">
        <?= $t['overdue'] > 0.004 ? $cur . ' ' . $n($t['overdue']) . ' overdue' : 'Nothing overdue' ?>.
        <?php if ($t['on_hold']): ?> <?= (int)$t['on_hold'] ?> account<?= $t['on_hold'] === 1 ? '' : 's' ?> on credit hold.<?php endif; ?>
        <?php if ($t['over_limit']): ?> <?= (int)$t['over_limit'] ?> over credit limit.<?php endif; ?>
        <?php if ($bd['total'] > 0.004): ?> <?= $cur . ' ' . $n($bd['total']) ?> written off year-to-date (<?= (int)$bd['count'] ?>).<?php endif; ?>
        <?php if ($bd['pending_total'] > 0.004): ?> <?= $cur . ' ' . $n($bd['pending_total']) ?> in write-offs awaiting approval.<?php endif; ?>
    </p>
</div>
</body>
</html>
