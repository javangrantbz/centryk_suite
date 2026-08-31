<?php
$companyId = current_company_id();
if (!function_exists('inv_scalar')) { function inv_scalar(PDO $pdo, string $sql, array $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); } }

$cClients     = inv_scalar($pdo, "SELECT COUNT(*) FROM customers WHERE company_id = ?", [$companyId]);
$cInvoices    = inv_scalar($pdo, "SELECT COUNT(*) FROM invoices  WHERE company_id = ?", [$companyId]);
$cQuotes      = inv_scalar($pdo, "SELECT COUNT(*) FROM quotes    WHERE company_id = ?", [$companyId]);
$outstanding  = inv_scalar($pdo, "SELECT COALESCE(SUM(total - amount_paid),0) FROM invoices WHERE company_id = ? AND status != 'paid'", [$companyId]);

// Recent documents (quotes + invoices) for a small activity list.
$recent = $pdo->prepare("
    SELECT 'invoice' AS type, i.id, i.invoice_number AS number, i.status, i.total, i.created_at, c.name AS customer
    FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.company_id = :cid
    UNION ALL
    SELECT 'quote' AS type, q.id, q.quote_number AS number, q.status, q.total, q.created_at, c.name AS customer
    FROM quotes q LEFT JOIN customers c ON c.id = q.customer_id WHERE q.company_id = :cid2
    ORDER BY created_at DESC LIMIT 6
");
$recent->execute(['cid' => $companyId, 'cid2' => $companyId]);
$recentRows = $recent->fetchAll(PDO::FETCH_ASSOC);

$kpis = [
    ['label' => 'Clients',     'value' => $cClients,            'icon' => 'users',          'tint' => 'emerald'],
    ['label' => 'Invoices',    'value' => $cInvoices,           'icon' => 'file-text',      'tint' => 'blue'],
    ['label' => 'Quotes',      'value' => $cQuotes,             'icon' => 'clipboard-list', 'tint' => 'amber'],
    ['label' => 'Outstanding', 'value' => money($outstanding),  'icon' => 'dollar-sign',    'tint' => 'rose'],
];
?>

<div class="biz">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Invoice engine</p>
            <h1 class="mt-0.5">Overview</h1>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <a href="<?= BASE_URL ?>/?page=quotes-create" class="biz-btn biz-btn-ghost"><i data-lucide="plus" class="w-3.5 h-3.5"></i> New quote</a>
            <a href="<?= BASE_URL ?>/?page=invoices-create" class="biz-btn biz-btn-primary"><i data-lucide="plus" class="w-3.5 h-3.5"></i> New invoice</a>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <?php foreach ($kpis as $k): ?>
        <div class="biz-tile">
            <div class="biz-tile-l"><?= e($k['label']) ?></div>
            <div class="biz-tile-v biz-num"><?= $k['value'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="biz-panel mt-3">
        <div class="biz-panel-head">
            <span>Recent</span>
            <a href="<?= BASE_URL ?>/?page=invoices" class="biz-t-green" style="font-size:11px;font-weight:700">View all →</a>
        </div>
        <?php if (empty($recentRows)): ?>
        <div class="biz-panel-empty">No invoices or quotes yet.</div>
        <?php else: ?>
        <div class="biz-list">
        <?php foreach ($recentRows as $r):
            $isInv = $r['type'] === 'invoice';
            $href  = BASE_URL . ($isInv ? '/?page=invoices-view&id=' : '/?page=quotes-view&id=') . (int)$r['id'];
        ?>
        <a href="<?= $href ?>" class="biz-row">
            <span class="biz-chip <?= $isInv ? 'biz-c-blue' : 'biz-c-amber' ?>"><?= $isInv ? 'Invoice' : 'Quote' ?></span>
            <span class="min-w-0 flex-1">
                <span class="font-bold" style="font-family:ui-monospace,monospace"><?= e($r['number']) ?></span>
                <span class="biz-muted"> · <?= e($r['customer'] ?: 'No customer') ?></span>
                <span class="block biz-chip <?= inv_status_chip($r['status']) ?>" style="margin-top:2px"><?= e($r['status']) ?></span>
            </span>
            <span class="shrink-0 font-bold biz-num"><?= money($r['total']) ?></span>
        </a>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
