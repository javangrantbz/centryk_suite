<?php
$companyId = current_company_id();
function inv_scalar(PDO $pdo, string $sql, array $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

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

<!-- Header + actions -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h2 class="text-xl font-black text-slate-900">Overview</h2>
        <p class="text-xs font-semibold text-slate-400">Snapshot of your invoicing</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="<?= BASE_URL ?>/?page=quotes-create" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition">
            <i data-lucide="plus" class="w-4 h-4"></i> New Quote
        </a>
        <a href="<?= BASE_URL ?>/?page=invoices-create" class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-emerald-700 transition shadow-sm shadow-emerald-200">
            <i data-lucide="plus" class="w-4 h-4"></i> New Invoice
        </a>
    </div>
</div>

<!-- Compact KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
    <?php foreach ($kpis as $k): ?>
    <div class="bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
        <div class="flex items-center gap-2">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-<?= $k['tint'] ?>-50 text-<?= $k['tint'] ?>-600">
                <i data-lucide="<?= $k['icon'] ?>" class="w-4 h-4"></i>
            </span>
            <span class="text-[10px] font-black uppercase tracking-widest text-gray-400"><?= $k['label'] ?></span>
        </div>
        <p class="mt-2 text-2xl font-black text-slate-900"><?= $k['value'] ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Recent activity -->
<div class="mt-6 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="flex items-center justify-between px-5 py-3 border-b border-gray-50">
        <h3 class="text-sm font-black text-slate-900">Recent</h3>
        <a href="<?= BASE_URL ?>/?page=invoices" class="text-xs font-bold text-emerald-600 hover:text-emerald-700">View all →</a>
    </div>
    <?php if (empty($recentRows)): ?>
    <p class="px-5 py-10 text-center text-sm text-slate-400">No invoices or quotes yet.</p>
    <?php else: ?>
    <div class="divide-y divide-gray-50">
        <?php foreach ($recentRows as $r):
            $isInv = $r['type'] === 'invoice';
            $href  = BASE_URL . ($isInv ? '/?page=invoices-view&id=' : '/?page=quotes-view&id=') . (int)$r['id'];
        ?>
        <a href="<?= $href ?>" class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50 transition">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg <?= $isInv ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600' ?>">
                <i data-lucide="<?= $isInv ? 'file-text' : 'clipboard-list' ?>" class="w-4 h-4"></i>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-slate-800 truncate"><?= e($r['number']) ?> <span class="text-slate-300">·</span> <?= e($r['customer'] ?: 'No customer') ?></p>
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400"><?= e($r['type']) ?> · <?= e($r['status']) ?></p>
            </div>
            <span class="shrink-0 text-sm font-black text-slate-900"><?= money($r['total']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
