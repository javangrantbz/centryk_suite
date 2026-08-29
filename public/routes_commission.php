<?php
/**
 * Printable per-driver commission statement (Centryk Business — Routes).
 *   routes_commission.php?company_id=1&from=2026-08-01&to=2026-08-31
 * Gated: an admin/manager of the company, with the 'routes' entitlement.
 * Print -> Save as PDF for the payroll pack.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/core/Entitlements.php';
require_once __DIR__ . '/../app/services/RoutesService.php';

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
if (!$company || Entitlements::level($companyId, 'routes') === Entitlements::NONE) {
    http_response_code(403);
    echo 'Not available.';
    exit;
}

$from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : date('Y-m-01');
$to   = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])   ? $_GET['to']   : date('Y-m-d');

$st = RoutesService::commissionStatement($companyId, $from, $to);
$n  = static fn ($v) => number_format((float)$v, 2);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Route Commission — <?= htmlspecialchars($company['name']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #eceef1; color: #1a1a1a;
            font-family: "Segoe UI", -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 12.5px; line-height: 1.45; }
        .sheet { max-width: 900px; margin: 24px auto; background: #fff; padding: 36px 40px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
        .bar { display: flex; justify-content: space-between; align-items: flex-start; }
        .biz-name { font-size: 18px; font-weight: 700; }
        h1 { margin: 0; font-size: 18px; letter-spacing: 0.1em; text-align: right; }
        .sub { color: #666; font-size: 11px; }
        h2 { font-size: 13px; margin: 22px 0 4px; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 12px; }
        th { text-align: right; padding: 5px 8px; border-bottom: 1.5px solid #333; font-size: 10px; letter-spacing: 0.04em; text-transform: uppercase; color: #555; }
        th:first-child, td:first-child { text-align: left; }
        td { padding: 4px 8px; border-bottom: 1px solid #eee; text-align: right; font-variant-numeric: tabular-nums; }
        tfoot td { border-top: 1.5px solid #333; font-weight: 700; }
        .grand { margin-top: 26px; padding-top: 8px; border-top: 2px solid #333; display: flex; justify-content: space-between; font-size: 14px; font-weight: 700; }
        .toolbar { max-width: 900px; margin: 16px auto 0; display: flex; gap: 8px; align-items: center; }
        .toolbar button, .toolbar a, .toolbar input { font: inherit; font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 4px; border: 1px solid #cbd5e1; background: #fff; color: #334155; text-decoration: none; }
        .toolbar .primary { background: #4f46e5; border-color: #4f46e5; color: #fff; cursor: pointer; }
        @media print { body { background: #fff; } .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; } .toolbar { display: none; } @page { margin: 14mm; } }
    </style>
</head>
<body>
<div class="toolbar">
    <form method="get" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="company_id" value="<?= $companyId ?>">
        <label>From <input type="date" name="from" value="<?= htmlspecialchars($from) ?>"></label>
        <label>To <input type="date" name="to" value="<?= htmlspecialchars($to) ?>"></label>
        <button class="primary" type="submit">Apply</button>
    </form>
    <button class="primary" onclick="window.print()">Print / Save as PDF</button>
    <a href="routes.php?company_id=<?= $companyId ?>">Back to routes</a>
</div>

<div class="sheet">
    <div class="bar">
        <div class="biz-name"><?= htmlspecialchars($company['name']) ?></div>
        <div>
            <h1>ROUTE COMMISSION</h1>
            <div class="sub"><?= date('j M Y', strtotime($from)) ?> – <?= date('j M Y', strtotime($to)) ?> · settled trips · amounts in BZD</div>
        </div>
    </div>

    <?php if (!$st['drivers']): ?>
        <p class="sub" style="margin-top:24px">No settled trips in this period.</p>
    <?php else: ?>
        <?php foreach ($st['drivers'] as $d): ?>
        <h2><?= htmlspecialchars($d['driver']) ?> — <?= $n($d['commission']) ?></h2>
        <table>
            <thead>
                <tr><th>Date</th><th>Route</th><th>Collections</th><th>Stops</th><th>Basis</th><th>Commission</th></tr>
            </thead>
            <tbody>
                <?php foreach ($d['lines'] as $l): ?>
                <tr>
                    <td><?= date('j M', strtotime($l['date'])) ?></td>
                    <td><?= htmlspecialchars($l['route']) ?></td>
                    <td><?= $n($l['collections']) ?></td>
                    <td><?= (int)$l['stops'] ?></td>
                    <td><?= htmlspecialchars($l['rule']) ?></td>
                    <td><?= $n($l['commission']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2"><?= count($d['lines']) ?> trip<?= count($d['lines']) === 1 ? '' : 's' ?></td>
                    <td><?= $n($d['collections']) ?></td>
                    <td colspan="2"></td>
                    <td><?= $n($d['commission']) ?></td>
                </tr>
            </tfoot>
        </table>
        <?php endforeach; ?>

        <div class="grand">
            <span>Total commission — <?= count($st['drivers']) ?> driver<?= count($st['drivers']) === 1 ? '' : 's' ?></span>
            <span>BZD <?= $n($st['total']) ?></span>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
