<?php
// POS Receipts — completed OnePay checkouts mirrored into invoices
// (source_app='onepay', source_ref 'sale:%'). Read-only receipt history;
// outstanding invoices and school batches live under the Invoices tab.
//
// Filtering, search and paging are all server-side: a busy store generates
// thousands of receipts, so this must never load the whole history into the
// page (and a client-side search that only sees the current page would be
// quietly wrong about what it isn't finding).
$companyId = current_company_id();

$perPage = 50;
$page    = max(1, (int)($_GET['p'] ?? 1));
$range   = (string)($_GET['range'] ?? 'all');
$search  = trim((string)($_GET['q'] ?? ''));
$rawFrom = trim((string)($_GET['from'] ?? ''));
$rawTo   = trim((string)($_GET['to'] ?? ''));

// Resolve the active window. Custom needs both ends to be a real date,
// otherwise it falls back to unfiltered rather than half-applying.
$from = $to = null;
$isYmd = static fn($v) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
switch ($range) {
    case 'today':
        $from = $to = date('Y-m-d');
        break;
    case 'week':
        $from = date('Y-m-d', strtotime('sunday last week'));
        $to   = date('Y-m-d');
        break;
    case 'month':
        $from = date('Y-m-01');
        $to   = date('Y-m-d');
        break;
    case 'custom':
        if ($isYmd($rawFrom) && $isYmd($rawTo)) {
            $from = $rawFrom;
            $to   = $rawTo;
        } else {
            $range = 'all';
        }
        break;
    default:
        $range = 'all';
}

$where  = ["i.company_id = ?", "i.source_app = 'onepay'", "i.source_ref LIKE 'sale:%'"];
$params = [$companyId];

if ($from !== null && $to !== null) {
    $where[]  = 'i.created_at >= ? AND i.created_at < ?';
    $params[] = $from . ' 00:00:00';
    $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
}

if ($search !== '') {
    $where[]  = '(i.invoice_number LIKE ? OR c.name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);
$joinSql  = 'FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE ' . $whereSql;

// Totals for the whole filtered set, not just the visible page — otherwise
// "Gross" would silently mean "gross on page 1".
$agg = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(i.total), 0) AS gross {$joinSql}");
$agg->execute($params);
$totals     = $agg->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'gross' => 0];
$totalRows  = (int)$totals['n'];
$grossTotal = (float)$totals['gross'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$s = $pdo->prepare("SELECT i.id, i.invoice_number AS number, i.status, i.issue_date,
                           i.total, i.amount_paid, i.created_at, i.source_ref,
                           c.name AS customer_name
                    {$joinSql}
                    ORDER BY i.created_at DESC
                    LIMIT {$perPage} OFFSET {$offset}");
$s->execute($params);
$rows = $s->fetchAll(PDO::FETCH_ASSOC);

// "Today" stays an absolute figure regardless of the active filter — it's a
// glanceable "how are we doing right now", not a slice of the current view.
$td = $pdo->prepare("SELECT COUNT(*) FROM invoices i
                     WHERE i.company_id = ? AND i.source_app = 'onepay'
                       AND i.source_ref LIKE 'sale:%' AND DATE(i.created_at) = CURDATE()");
$td->execute([$companyId]);
$todayCount = (int)$td->fetchColumn();

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

/** Preserve the active filter across paging/search links. */
$rcpLink = static function (array $overrides = []) use ($range, $search, $rawFrom, $rawTo) {
    $q = array_merge([
        'page'  => 'receipts',
        'range' => $range,
        'q'     => $search,
        'from'  => $rawFrom,
        'to'    => $rawTo,
    ], $overrides);
    return BASE_URL . '/?' . http_build_query(array_filter($q, static fn($v) => $v !== '' && $v !== null));
};

$isFiltered   = $range !== 'all' || $search !== '';
$receiptStats = [
    ['label' => $isFiltered ? 'Matching' : 'Receipts', 'value' => number_format($totalRows), 'icon' => 'shopping-cart', 'tint' => 'slate'],
    ['label' => 'Today',  'value' => number_format($todayCount), 'icon' => 'clock',  'tint' => 'blue'],
    ['label' => 'Gross',  'value' => money($grossTotal),         'icon' => 'wallet', 'tint' => 'emerald'],
];

$rangeTabs = ['all' => 'All', 'today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'custom' => 'Custom'];
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

    <div class="mb-3 rounded-2xl border border-gray-100 bg-white px-4 py-3 shadow-sm flex-shrink-0">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-1.5">
                <?php foreach ($rangeTabs as $key => $label): ?>
                    <?php $active = $range === $key; ?>
                    <a href="<?= $key === 'custom' ? '#' : e($rcpLink(['range' => $key, 'p' => null, 'from' => null, 'to' => null])) ?>"
                       <?= $key === 'custom' ? 'id="rcp-custom-toggle"' : '' ?>
                       class="rounded-lg px-2.5 py-1 text-xs font-bold transition <?= $active ? 'bg-slate-900 text-white' : 'text-slate-500 hover:bg-slate-100' ?>">
                        <?= $label ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="GET" action="<?= BASE_URL ?>/" class="flex flex-wrap items-center gap-1.5">
                <input type="hidden" name="page"  value="receipts">
                <input type="hidden" name="range" value="<?= e($range) ?>">
                <div id="rcp-custom-inputs" class="<?= $range === 'custom' ? 'flex' : 'hidden' ?> items-center gap-1.5">
                    <input type="date" name="from" value="<?= e($rawFrom) ?>" class="rounded-lg border border-slate-200 px-2 py-1 text-xs text-slate-700">
                    <span class="text-xs text-slate-400">to</span>
                    <input type="date" name="to" value="<?= e($rawTo) ?>" class="rounded-lg border border-slate-200 px-2 py-1 text-xs text-slate-700">
                </div>
                <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-gray-400"></i>
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search receipt # or customer"
                           class="w-full sm:w-60 pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-xl text-sm focus:ring-emerald-500 focus:border-emerald-500 transition">
                </div>
                <button class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-bold text-white hover:bg-slate-700 transition">Apply</button>
                <?php if ($isFiltered): ?>
                    <a href="<?= e(BASE_URL . '/?page=receipts') ?>" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-500 hover:bg-slate-50 transition">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="flex-1 min-h-0 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden flex flex-col">
        <div class="flex flex-col gap-3 border-b border-gray-100 px-4 py-3 flex-shrink-0 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-sm font-black uppercase tracking-wide text-slate-700">POS Receipts</h2>
            <?php if ($totalRows > 0): ?>
            <span class="text-[11px] font-bold text-slate-400">
                Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalRows)) ?> of <?= number_format($totalRows) ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <?php if (empty($rows)): ?>
            <div class="py-20 text-center text-gray-400">
                <i data-lucide="shopping-cart" class="w-8 h-8 mx-auto mb-2 opacity-20"></i>
                <?php if ($isFiltered): ?>
                    <p class="text-sm font-medium">No receipts match this filter.</p>
                    <a href="<?= e(BASE_URL . '/?page=receipts') ?>" class="text-xs mt-1 text-emerald-600 hover:underline">Clear filters</a>
                <?php else: ?>
                    <p class="text-sm font-medium">No POS receipts yet.</p>
                    <p class="text-xs mt-1 text-gray-300">Completed checkouts from OnePay will appear here.</p>
                <?php endif; ?>
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

        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-between border-t border-gray-100 px-4 py-2.5 flex-shrink-0">
            <span class="text-[11px] font-bold text-slate-400">Page <?= $page ?> of <?= $totalPages ?></span>
            <div class="flex items-center gap-1.5">
                <?php if ($page > 1): ?>
                    <a href="<?= e($rcpLink(['p' => $page - 1])) ?>" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50 transition">Previous</a>
                <?php else: ?>
                    <span class="rounded-lg border border-slate-100 px-2.5 py-1 text-xs font-bold text-slate-300 cursor-not-allowed">Previous</span>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e($rcpLink(['p' => $page + 1])) ?>" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50 transition">Next</a>
                <?php else: ?>
                    <span class="rounded-lg border border-slate-100 px-2.5 py-1 text-xs font-bold text-slate-300 cursor-not-allowed">Next</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// "Custom" only reveals the date inputs — the range itself isn't applied until
// Apply is pressed, so a half-entered range never reloads the page.
document.getElementById('rcp-custom-toggle')?.addEventListener('click', function (e) {
    e.preventDefault();
    const box = document.getElementById('rcp-custom-inputs');
    const rangeField = document.querySelector('input[name="range"]');
    box.classList.toggle('hidden');
    box.classList.toggle('flex');
    if (rangeField) rangeField.value = 'custom';
    box.querySelector('input[name="from"]')?.focus();
});
</script>
