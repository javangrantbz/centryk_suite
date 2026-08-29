<?php
/**
 * Consolidated AR aging across a company group (Centryk Business — Enterprise).
 *   groups_aging.php?group_id=3
 * Gated: an active member of the group, group holds the 'enterprise' entitlement.
 * Print -> Save as PDF for the board pack.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/core/Entitlements.php';
require_once __DIR__ . '/../app/services/GroupsService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$groupId = (int)($_GET['group_id'] ?? 0);
$group   = GroupsService::detail($groupId);
$role    = GroupsService::role($groupId, (int)$user['id']);

if (!$group || $role === null || Entitlements::groupLevel($groupId, 'enterprise') === Entitlements::NONE) {
    http_response_code(403);
    echo 'Not available.';
    exit;
}

$data = GroupsService::consolidatedAging($groupId);
$rows = $data['companies'];
$t    = $data['totals'];
$n    = static fn ($v) => number_format((float)$v, 2);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Group AR Aging — <?= htmlspecialchars($group['name']) ?></title>
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
        tfoot td { border-top: 2px solid #333; font-weight: 700; padding-top: 8px; }
        .over td { color: #b00020; }
        .muted td { color: #999; }
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
    <a href="groups.php?group_id=<?= $groupId ?>">Back to group</a>
</div>

<div class="sheet">
    <div class="bar">
        <div class="biz-name"><?= htmlspecialchars($group['name']) ?></div>
        <div>
            <h1>GROUP AR AGING</h1>
            <div class="sub">As of <?= date('j M Y') ?> · consolidated across <?= count($rows) ?> compan<?= count($rows) === 1 ? 'y' : 'ies' ?> · amounts in BZD</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Company</th><th>Accounts</th><th>Current</th><th>1–30</th><th>31–60</th><th>61–90</th><th>90+</th><th>Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $c): ?>
                <?php if (empty($c['entitled'])): ?>
                <tr class="muted">
                    <td><?= htmlspecialchars($c['name']) ?></td>
                    <td colspan="7" style="text-align:left">No Receivables package — not included</td>
                </tr>
                <?php else: ?>
                <tr class="<?= $c['b_90p'] > 0.004 ? 'over' : '' ?>">
                    <td><?= htmlspecialchars($c['name']) ?></td>
                    <td><?= (int)$c['accounts'] ?></td>
                    <td><?= $n($c['current']) ?></td>
                    <td><?= $n($c['b_1_30']) ?></td>
                    <td><?= $n($c['b_31_60']) ?></td>
                    <td><?= $n($c['b_61_90']) ?></td>
                    <td><?= $n($c['b_90p']) ?></td>
                    <td><?= $n($c['balance']) ?></td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
            <tr><td colspan="8" style="text-align:center;color:#888;padding:18px">No companies in this group.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Group total</td>
                <td><?= (int)$t['accounts'] ?></td>
                <td><?= $n($t['current']) ?></td>
                <td><?= $n($t['b_1_30']) ?></td>
                <td><?= $n($t['b_31_60']) ?></td>
                <td><?= $n($t['b_61_90']) ?></td>
                <td><?= $n($t['b_90p']) ?></td>
                <td>BZD <?= $n($t['balance']) ?></td>
            </tr>
        </tfoot>
    </table>

    <p class="sub" style="margin-top:18px">
        <?= $t['overdue'] > 0.004 ? 'BZD ' . $n($t['overdue']) . ' overdue across the group' : 'Nothing overdue across the group' ?>.
    </p>
</div>
</body>
</html>
