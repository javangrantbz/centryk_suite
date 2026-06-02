<?php
$companyId = current_company_id();
$type = $_GET['type'] ?? 'all';            // all | invoice | quote
if (!in_array($type, ['all', 'invoice', 'quote'], true)) $type = 'all';

$rows = [];
if ($type === 'all' || $type === 'invoice') {
    $s = $pdo->prepare("SELECT 'invoice' AS doc_type, i.id, i.invoice_number AS number, i.status,
                               i.issue_date, i.total, i.amount_paid, i.created_at, i.quote_id,
                               c.name AS customer_name
                        FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                        WHERE i.company_id = ? ORDER BY i.created_at DESC");
    $s->execute([$companyId]);
    $rows = array_merge($rows, $s->fetchAll(PDO::FETCH_ASSOC));
}
if ($type === 'all' || $type === 'quote') {
    $s = $pdo->prepare("SELECT 'quote' AS doc_type, q.id, q.quote_number AS number, q.status,
                               q.issue_date, q.total, 0 AS amount_paid, q.created_at, NULL AS quote_id,
                               c.name AS customer_name
                        FROM quotes q LEFT JOIN customers c ON c.id = q.customer_id
                        WHERE q.company_id = ? ORDER BY q.created_at DESC");
    $s->execute([$companyId]);
    $rows = array_merge($rows, $s->fetchAll(PDO::FETCH_ASSOC));
}
usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

if (!function_exists('inv_status_badge')) {
function inv_status_badge($status) {
    return match ($status) {
        'paid', 'accepted'         => 'bg-emerald-100 text-emerald-700',
        'sent'                     => 'bg-blue-100 text-blue-700',
        'overdue', 'rejected'      => 'bg-rose-100 text-rose-700',
        'expired', 'cancelled'     => 'bg-slate-200 text-slate-600',
        default                    => 'bg-gray-100 text-gray-600',  // draft
    };
}
}
$tabs = ['all' => 'All', 'invoice' => 'Invoices', 'quote' => 'Quotes'];
?>

<div class="h-full flex flex-col">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5 flex-shrink-0">
        <div>
            <h2 class="text-xl font-black text-slate-900">Invoices &amp; Quotes</h2>
            <p class="text-xs font-semibold text-slate-400"><?= count($rows) ?> document<?= count($rows) === 1 ? '' : 's' ?></p>
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

    <!-- Filter tabs + search -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4 flex-shrink-0">
        <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1">
            <?php foreach ($tabs as $key => $label): ?>
            <a href="<?= BASE_URL ?>/?page=invoices&type=<?= $key ?>"
               class="px-4 py-1.5 rounded-lg text-xs font-bold transition <?= $type === $key ? 'bg-slate-900 text-white' : 'text-slate-500 hover:text-slate-800' ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="relative">
            <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-gray-400"></i>
            <input type="text" id="doc-search" placeholder="Search…" class="w-56 pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-xl text-sm focus:ring-emerald-500 focus:border-emerald-500 transition">
        </div>
    </div>

    <!-- Table -->
    <div class="flex-1 min-h-0 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <?php if (empty($rows)): ?>
            <div class="py-20 text-center text-gray-400">
                <i data-lucide="file-text" class="w-8 h-8 mx-auto mb-2 opacity-20"></i>
                <p class="text-sm font-medium">Nothing here yet.</p>
            </div>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-gray-50/90 backdrop-blur border-b border-gray-100 text-[10px] uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="text-left font-black px-5 py-3">Type</th>
                        <th class="text-left font-black px-3 py-3">Number</th>
                        <th class="text-left font-black px-3 py-3">Customer</th>
                        <th class="text-left font-black px-3 py-3 hidden sm:table-cell">Date</th>
                        <th class="text-left font-black px-3 py-3">Status</th>
                        <th class="text-right font-black px-5 py-3">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($rows as $r):
                        $isInv = $r['doc_type'] === 'invoice';
                        $href  = BASE_URL . ($isInv ? '/?page=invoices-view&id=' : '/?page=quotes-view&id=') . (int)$r['id'];
                    ?>
                    <tr class="doc-row hover:bg-gray-50 cursor-pointer transition" onclick="window.location='<?= $href ?>'">
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider <?= $isInv ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600' ?>">
                                <i data-lucide="<?= $isInv ? 'file-text' : 'clipboard-list' ?>" class="w-3 h-3"></i><?= $isInv ? 'Invoice' : 'Quote' ?>
                            </span>
                        </td>
                        <td class="px-3 py-3 font-mono font-bold text-slate-900"><?= e($r['number']) ?></td>
                        <td class="px-3 py-3 font-semibold text-slate-600 truncate max-w-[180px]"><?= e($r['customer_name'] ?: '—') ?></td>
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
document.getElementById('doc-search')?.addEventListener('input', function (e) {
    const term = e.target.value.toLowerCase();
    document.querySelectorAll('.doc-row').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(term) ? '' : 'none';
    });
});
</script>
