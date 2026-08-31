<?php
$companyId = current_company_id();
$type = $_GET['type'] ?? 'all';            // all | invoice | quote
if (!in_array($type, ['all', 'invoice', 'quote'], true)) $type = 'all';

$rows = [];
if ($type === 'all' || $type === 'invoice') {
    // POS receipts (source_ref 'sale:%') live under their own "POS Receipts" tab;
    // the Invoices list is receivables only — manual invoices + school batches.
    $s = $pdo->prepare("SELECT 'invoice' AS doc_type, i.id, i.invoice_number AS number, i.status,
                               i.issue_date, i.total, i.amount_paid, i.created_at, i.quote_id,
                               c.name AS customer_name
                        FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                        WHERE i.company_id = ?
                          AND (i.source_ref IS NULL OR i.source_ref NOT LIKE 'sale:%')
                        ORDER BY i.created_at DESC");
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
        default                    => 'bg-gray-100 text-gray-600',
    };
}
}

$tabs = ['all' => 'All', 'invoice' => 'Invoices', 'quote' => 'Quotes'];
$invoiceCount = count(array_filter($rows, fn($row) => $row['doc_type'] === 'invoice'));
$quoteCount = count(array_filter($rows, fn($row) => $row['doc_type'] === 'quote'));
$outstandingTotal = array_reduce($rows, function ($carry, $row) {
    if ($row['doc_type'] !== 'invoice') {
        return $carry;
    }
    return $carry + max(0, ((float)$row['total']) - ((float)$row['amount_paid']));
}, 0.0);
$invoiceStats = [
    ['label' => 'Docs', 'value' => count($rows), 'icon' => 'files', 'tint' => 'slate'],
    ['label' => 'Invoices', 'value' => $invoiceCount, 'icon' => 'file-text', 'tint' => 'blue'],
    ['label' => 'Quotes', 'value' => $quoteCount, 'icon' => 'clipboard-list', 'tint' => 'amber'],
    ['label' => 'Open', 'value' => money($outstandingTotal), 'icon' => 'wallet', 'tint' => 'emerald'],
];
?>

<?php
$_bizStatusChip = static function ($s) {
    return match ($s) {
        'paid', 'accepted'     => 'biz-c-green',
        'sent'                 => 'biz-c-blue',
        'overdue', 'rejected'  => 'biz-c-red',
        'expired', 'cancelled' => 'biz-c-slate',
        default                => 'biz-c-slate',
    };
};
?>
<div class="biz h-full flex flex-col">

    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Invoice engine</p>
            <h1 class="mt-0.5">Invoices &amp; quotes</h1>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <a href="<?= BASE_URL ?>/?page=quotes-create" class="biz-btn biz-btn-ghost"><i data-lucide="plus" class="w-3.5 h-3.5"></i> New quote</a>
            <a href="<?= BASE_URL ?>/?page=invoices-create" class="biz-btn biz-btn-primary"><i data-lucide="plus" class="w-3.5 h-3.5"></i> New invoice</a>
        </div>
    </div>

    <div class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4 flex-shrink-0">
        <?php foreach ($invoiceStats as $stat): ?>
        <div class="biz-tile">
            <div class="biz-tile-l"><?= e($stat['label']) ?></div>
            <div class="biz-tile-v"><?= $stat['value'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="biz-panel flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="biz-panel-head" style="text-transform:none;letter-spacing:0">
            <span class="biz-seg">
                <?php foreach ($tabs as $key => $label): ?>
                <a href="<?= BASE_URL ?>/?page=invoices&type=<?= $key ?>" class="<?= $type === $key ? 'is-active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </span>
            <input type="text" id="doc-search" class="biz-input" style="width:190px" placeholder="Search…">
        </div>
        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <?php if (empty($rows)): ?>
            <div class="biz-panel-empty">Nothing here yet.</div>
            <?php else: ?>
            <table class="w-full biz-num" style="font-size:12px">
                <thead>
                    <tr class="biz-muted" style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:0.06em">
                        <th class="px-3 py-2 font-bold">Type</th>
                        <th class="px-3 py-2 font-bold">Number</th>
                        <th class="px-3 py-2 font-bold">Customer</th>
                        <th class="px-3 py-2 font-bold hidden sm:table-cell">Date</th>
                        <th class="px-3 py-2 font-bold">Status</th>
                        <th class="px-3 py-2 font-bold" style="text-align:right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $isInv = $r['doc_type'] === 'invoice';
                        $href  = BASE_URL . ($isInv ? '/?page=invoices-view&id=' : '/?page=quotes-view&id=') . (int)$r['id'];
                    ?>
                    <tr class="doc-row" style="border-top:1px solid var(--bz-line-soft);cursor:pointer" onclick="window.location='<?= $href ?>'"
                        onmouseover="this.style.background='var(--bz-head)'" onmouseout="this.style.background=''">
                        <td class="px-3 py-1.5"><span class="biz-chip <?= $isInv ? 'biz-c-blue' : 'biz-c-amber' ?>"><?= $isInv ? 'Invoice' : 'Quote' ?></span></td>
                        <td class="px-3 py-1.5 font-bold" style="font-family:ui-monospace,monospace"><?= e($r['number']) ?></td>
                        <td class="px-3 py-1.5 biz-muted truncate" style="max-width:200px"><?= e($r['customer_name'] ?: '—') ?></td>
                        <td class="px-3 py-1.5 biz-faint hidden sm:table-cell" style="color:var(--bz-faint)"><?= $r['issue_date'] ? date('j M Y', strtotime($r['issue_date'])) : '' ?></td>
                        <td class="px-3 py-1.5"><span class="biz-chip <?= $_bizStatusChip($r['status']) ?>"><?= e($r['status']) ?></span></td>
                        <td class="px-3 py-1.5 font-bold" style="text-align:right"><?= money($r['total']) ?></td>
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
