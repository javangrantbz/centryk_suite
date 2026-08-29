<?php
/**
 * Printable customer statement (Centryk Business — Receivables).
 *   receivables_statement.php?company_id=1&customer_id=42
 * Gated: an admin/manager of the company, with the 'receivables' entitlement.
 * Use the browser's Print → Save as PDF to send it to the customer.
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

$companyId  = (int)($_GET['company_id'] ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);

$m = DB::pdo()->prepare("
    SELECT 1 FROM company_members
    WHERE user_id = :u AND company_id = :c AND status = 'active' AND role IN ('admin','manager') LIMIT 1
");
$m->execute(['u' => (int)$user['id'], 'c' => $companyId]);
if (!$m->fetch() || Entitlements::level($companyId, 'receivables') === Entitlements::NONE) {
    http_response_code(403);
    echo 'Not available.';
    exit;
}

$doc = ReceivablesService::statementDocument($companyId, $customerId);
if ($doc === null) {
    http_response_code(404);
    echo 'Customer not found.';
    exit;
}

$lh   = $doc['letterhead'];
$cust = $doc['customer'];
$cur  = trim((string)$lh['currency']) ?: 'BZD';
$money = static fn ($v) => $cur . ' ' . number_format((float)$v, 2);
$fdate = static fn ($s) => $s ? date('j M Y', strtotime($s)) : '';
$aging = $doc['aging'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Statement — <?= htmlspecialchars($cust['name']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; background: #eceef1; color: #1a1a1a;
            font-family: "Segoe UI", -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif;
            font-size: 13px; line-height: 1.5;
        }
        .sheet {
            max-width: 800px; margin: 24px auto; background: #fff; padding: 40px 44px;
            box-shadow: 0 1px 4px rgba(0,0,0,.12);
        }
        .bar { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        .biz-name { font-size: 20px; font-weight: 700; letter-spacing: -0.01em; }
        .biz-meta { margin-top: 4px; color: #555; font-size: 12px; white-space: pre-line; }
        .doc-title { text-align: right; }
        .doc-title h1 { margin: 0; font-size: 22px; letter-spacing: 0.14em; font-weight: 700; color: #333; }
        .doc-title p { margin: 2px 0 0; color: #555; font-size: 12px; }
        .parties { margin-top: 28px; display: flex; gap: 40px; }
        .parties h2 { margin: 0 0 4px; font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase; color: #888; }
        .parties .who { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 12.5px; }
        thead th {
            text-align: left; padding: 7px 8px; border-bottom: 2px solid #333;
            font-size: 10px; letter-spacing: 0.06em; text-transform: uppercase; color: #555;
        }
        tbody td { padding: 6px 8px; border-bottom: 1px solid #ededed; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tfoot td { padding: 10px 8px; border-top: 2px solid #333; font-weight: 700; }
        .aging {
            margin-top: 28px; display: grid; grid-template-columns: repeat(5, 1fr); gap: 1px;
            background: #ddd; border: 1px solid #ddd;
        }
        .aging div { background: #fff; padding: 8px 10px; }
        .aging .l { font-size: 10px; letter-spacing: 0.05em; text-transform: uppercase; color: #888; }
        .aging .v { font-weight: 600; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .aging .over .v { color: #b00020; }
        .total { margin-top: 24px; text-align: right; }
        .total .l { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: #666; }
        .total .v { font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .terms { margin-top: 28px; padding-top: 14px; border-top: 1px solid #ededed; color: #666; font-size: 11.5px; white-space: pre-line; }
        .toolbar { max-width: 800px; margin: 16px auto 0; display: flex; gap: 8px; }
        .toolbar button, .toolbar a {
            font: inherit; font-size: 12px; font-weight: 600; padding: 7px 14px; border-radius: 4px;
            border: 1px solid #cbd5e1; background: #fff; color: #334155; cursor: pointer; text-decoration: none;
        }
        .toolbar .primary { background: #4f46e5; border-color: #4f46e5; color: #fff; }
        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; }
            .toolbar { display: none; }
            @page { margin: 18mm; }
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
        <div>
            <div class="biz-name"><?= htmlspecialchars($lh['name']) ?></div>
            <div class="biz-meta"><?php
                $bits = array_filter([$lh['address'], $lh['phone'], $lh['email'], $lh['tax_number'] ? 'TIN ' . $lh['tax_number'] : null]);
                echo htmlspecialchars(implode("\n", $bits));
            ?></div>
        </div>
        <div class="doc-title">
            <h1>STATEMENT</h1>
            <p>As of <?= $fdate($doc['as_of']) ?></p>
        </div>
    </div>

    <div class="parties">
        <div>
            <h2>Account</h2>
            <div class="who"><?= htmlspecialchars($cust['name']) ?></div>
            <?php if (!empty($cust['company']) && $cust['company'] !== $cust['name']): ?><div><?= htmlspecialchars($cust['company']) ?></div><?php endif; ?>
            <?php if (!empty($cust['email'])): ?><div><?= htmlspecialchars($cust['email']) ?></div><?php endif; ?>
        </div>
        <div>
            <h2>Terms</h2>
            <div><?= $cust['payment_terms_days'] ? 'Net ' . (int)$cust['payment_terms_days'] . ' days' : 'On receipt' ?></div>
            <?php if ($cust['credit_limit'] !== null): ?><div>Credit limit <?= $money($cust['credit_limit']) ?></div><?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
            <tr><th>Date</th><th>Reference</th><th>Detail</th><th class="num">Charges</th><th class="num">Payments</th><th class="num">Balance</th></tr>
        </thead>
        <tbody>
            <?php foreach ($doc['entries'] as $e): ?>
            <tr>
                <td><?= $fdate($e['date']) ?></td>
                <td><?= htmlspecialchars($e['ref']) ?></td>
                <td><?= htmlspecialchars($e['detail']) ?></td>
                <td class="num"><?= $e['charge'] > 0.004 ? number_format($e['charge'], 2) : '' ?></td>
                <td class="num"><?= $e['credit'] > 0.004 ? number_format($e['credit'], 2) : '' ?></td>
                <td class="num"><?= number_format($e['balance'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$doc['entries']): ?>
            <tr><td colspan="6" style="text-align:center;color:#888;padding:18px">No activity.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="5">Balance due</td><td class="num"><?= $money($doc['balance']) ?></td></tr>
        </tfoot>
    </table>

    <?php if (array_sum($aging) > 0.004): ?>
    <div class="aging">
        <div><div class="l">Current</div><div class="v"><?= number_format($aging['current'], 2) ?></div></div>
        <div><div class="l">1–30 days</div><div class="v"><?= number_format($aging['b_1_30'], 2) ?></div></div>
        <div><div class="l">31–60 days</div><div class="v"><?= number_format($aging['b_31_60'], 2) ?></div></div>
        <div><div class="l">61–90 days</div><div class="v"><?= number_format($aging['b_61_90'], 2) ?></div></div>
        <div class="over"><div class="l">90+ days</div><div class="v"><?= number_format($aging['b_90p'], 2) ?></div></div>
    </div>
    <?php endif; ?>

    <div class="total">
        <div class="l">Total due</div>
        <div class="v"><?= $money($doc['balance']) ?></div>
    </div>

    <?php if (!empty($lh['terms'])): ?>
    <div class="terms"><?= htmlspecialchars($lh['terms']) ?></div>
    <?php endif; ?>
</div>

</body>
</html>
