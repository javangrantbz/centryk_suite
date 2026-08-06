<?php
// POS Receipts — completed OnePay checkouts mirrored into invoices
// (source_app='onepay', source_ref 'sale:%'). Read-only receipt history;
// outstanding invoices and school batches live under the Invoices tab.
$companyId = current_company_id();

$s = $pdo->prepare("SELECT i.id, i.invoice_number AS number, i.status, i.issue_date,
                           i.total, i.amount_paid, i.created_at, i.source_ref,
                           c.name AS customer_name
                    FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                    WHERE i.company_id = ?
                      AND i.source_app = 'onepay'
                      AND i.source_ref LIKE 'sale:%'
                    ORDER BY i.created_at DESC");
$s->execute([$companyId]);
$rows = $s->fetchAll(PDO::FETCH_ASSOC);

if (!function_exists('inv_status_badge')) {
function inv_status_badge($status) {
    return match ($status) {
        'paid', 'accepted'         => 'bg-emerald-100 text-emerald-700',
        'sent'                     => 'bg-blue-100 text-blue-700',
        'overdue', 'rejected'      => 'bg-rose-100 text-rose-700',
        'expired', 'cancelled'     => 'bg-slate-200 text-slate-600',
        default                    => 'bg-gray-100 text-gray-600',
    };
}
}

$today       = date('Y-m-d');
$grossTotal  = array_reduce($rows, fn($c, $r) => $c + (float)$r['total'], 0.0);
$todayCount  = count(array_filter($rows, fn($r) => strtotime($r['created_at']) !== false && date('Y-m-d', strtotime($r['created_at'])) === $today));
$receiptStats = [
    ['label' => 'Receipts', 'value' => count($rows),       'icon' => 'shopping-cart', 'tint' => 'slate'],
    ['label' => 'Today',    'value' => $todayCount,         'icon' => 'clock',         'tint' => 'blue'],
    ['label' => 'Gross',    'value' => money($grossTotal),  'icon' => 'wallet',        'tint' => 'emerald'],
];
?>

<div class="h-full flex flex-col">
    <div class="mb-3 rounded-2xl border border-gray-100 bg-white px-4 py-3 shadow-sm flex-shrink-0">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <?php foreach ($receiptStats as $i => $stat): ?>
                <?php if ($i > 0): ?><span class="hidden h-4 w-px bg-slate-200 sm:block"></span><?php endif; ?>
                <div class="flex items-center gap-1.5">
                    <i data-lucide="<?= $stat['icon'] ?>" class="w-4 h-4 text-<?= $stat['tint'] ?>-500"></i>
                    <span class="text-sm font-black text-slate-900"><?= $stat['value'] ?></span>
                    <span class="text-[11px] font-bold uppercase tracking-wide text-slate-400"><?= $stat['label'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="flex shrink-0 items-center gap-1.5 text-[11px] font-semibold text-slate-400">
                <i data-lucide="info" class="w-3.5 h-3.5"></i>
                Receipts are created automatically at POS checkout.
            </div>
        </div>
    </div>

    <div class="flex-1 min-h-0 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
        <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-3 flex-shrink-0 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-sm font-black uppercase tracking-wide text-slate-700">POS Receipts</h2>
            <div class="relative">
                <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-gray-400"></i>
                <input type="text" id="receipt-search" placeholder="Search receipts" class="w-full sm:w-56 pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-xl text-sm focus:ring-emerald-500 focus:border-emerald-500 transition">
            </div>
        </div>
        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <?php if (empty($rows)): ?>
            <div class="py-20 text-center text-gray-400">
                <i data-lucide="shopping-cart" class="w-8 h-8 mx-auto mb-2 opacity-20"></i>
                <p class="text-sm font-medium">No POS receipts yet.</p>
                <p class="text-xs mt-1 text-gray-300">Completed checkouts from OnePay will appear here.</p>
            </div>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-gray-50/90 backdrop-blur border-b border-gray-100 text-[10px] uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="text-left font-black px-5 py-3">Receipt #</th>
                        <th class="text-left font-black px-3 py-3">Customer</th>
                        <th class="text-left font-black px-3 py-3 hidden sm:table-cell">Date</th>
                        <th class="text-left font-black px-3 py-3">Status</th>
                        <th class="text-right font-black px-5 py-3">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($rows as $r):
                        $href = BASE_URL . '/?page=invoices-view&id=' . (int)$r['id'];
                    ?>
                    <tr class="receipt-row hover:bg-gray-50 cursor-pointer transition" onclick="window.location='<?= $href ?>'">
                        <td class="px-5 py-3 font-mono font-bold text-slate-900">
                            <span class="inline-flex items-center gap-1.5">
                                <i data-lucide="shopping-cart" class="w-3.5 h-3.5 text-emerald-500"></i><?= e($r['number']) ?>
                            </span>
                        </td>
                        <td class="px-3 py-3 font-semibold text-slate-600 truncate max-w-[180px]"><?= e($r['customer_name'] ?: 'Walk-in Customer') ?></td>
                        <td class="px-3 py-3 text-slate-400 hidden sm:table-cell"><?= $r['issue_date'] ? date('M j, Y', strtotime($r['issue_date'])) : '' ?></td>
                        <td class="px-3 py-3"><span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider <?= inv_status_badge($r['status']) ?>"><?= e($r['status']) ?></span></td>
                        <td class="px-5 py-3 text-right font-black text-slate-900"><?= money($r['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('receipt-search')?.addEventListener('input', function (e) {
    const term = e.target.value.toLowerCase();
    document.querySelectorAll('.receipt-row').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(term) ? '' : 'none';
    });
});
</script>
