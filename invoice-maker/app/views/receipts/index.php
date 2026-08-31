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

<div class="biz h-full flex flex-col">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Invoice engine</p>
            <h1 class="mt-0.5">POS receipts</h1>
        </div>
        <span class="biz-muted" style="font-size:11px">Created automatically at OnePay checkout.</span>
    </div>

    <div class="mb-3 grid grid-cols-3 gap-2 flex-shrink-0">
        <?php foreach ($receiptStats as $stat): ?>
        <div class="biz-tile"><div class="biz-tile-l"><?= e($stat['label']) ?></div><div class="biz-tile-v biz-num"><?= $stat['value'] ?></div></div>
        <?php endforeach; ?>
    </div>

    <div class="biz-panel flex-1 min-h-0 flex flex-col overflow-hidden">
        <div class="biz-panel-head" style="text-transform:none;letter-spacing:0">
            <span class="biz-seg">
                <?php foreach ($rangeTabs as $key => $label): ?>
                    <a href="<?= $key === 'custom' ? '#' : e($rcpLink(['range' => $key, 'p' => null, 'from' => null, 'to' => null])) ?>"
                       <?= $key === 'custom' ? 'id="rcp-custom-toggle"' : '' ?> class="<?= $range === $key ? 'is-active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </span>
            <form method="GET" action="<?= BASE_URL ?>/" class="flex flex-wrap items-center gap-1.5">
                <input type="hidden" name="page" value="receipts">
                <input type="hidden" name="range" value="<?= e($range) ?>">
                <div id="rcp-custom-inputs" class="<?= $range === 'custom' ? 'flex' : 'hidden' ?> items-center gap-1.5">
                    <input type="date" name="from" value="<?= e($rawFrom) ?>" class="biz-input" style="width:auto">
                    <span class="biz-muted" style="font-size:11px">to</span>
                    <input type="date" name="to" value="<?= e($rawTo) ?>" class="biz-input" style="width:auto">
                </div>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Receipt # or client" class="biz-input" style="width:170px">
                <button class="biz-btn biz-btn-primary biz-btn-sm">Apply</button>
                <?php if ($isFiltered): ?><a href="<?= e(BASE_URL . '/?page=receipts') ?>" class="biz-btn biz-btn-ghost biz-btn-sm">Clear</a><?php endif; ?>
            </form>
        </div>
        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <?php if (empty($rows)): ?>
            <div class="biz-panel-empty"><?= $isFiltered ? 'No receipts match this filter.' : 'No POS receipts yet.' ?></div>
            <?php else: ?>
            <table class="w-full biz-num" style="font-size:12px">
                <thead>
                    <tr class="biz-muted" style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:0.06em">
                        <th class="px-3 py-2 font-bold">Receipt #</th>
                        <th class="px-3 py-2 font-bold">Client</th>
                        <th class="px-3 py-2 font-bold hidden sm:table-cell">Date</th>
                        <th class="px-3 py-2 font-bold">Status</th>
                        <th class="px-3 py-2 font-bold" style="text-align:right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $href = BASE_URL . '/?page=invoices-view&id=' . (int)$r['id'];
                    ?>
                    <tr class="receipt-row" style="border-top:1px solid var(--bz-line-soft);cursor:pointer" onclick="window.location='<?= $href ?>'"
                        onmouseover="this.style.background='var(--bz-head)'" onmouseout="this.style.background=''">
                        <td class="px-3 py-1.5 font-bold" style="font-family:ui-monospace,monospace"><?= e($r['number']) ?></td>
                        <td class="px-3 py-1.5 biz-muted truncate" style="max-width:200px"><?= e($r['customer_name'] ?: 'Walk-in') ?></td>
                        <td class="px-3 py-1.5 hidden sm:table-cell" style="color:var(--bz-faint)"><?= $r['issue_date'] ? date('j M Y', strtotime($r['issue_date'])) : '' ?></td>
                        <td class="px-3 py-1.5"><span class="biz-chip <?= inv_status_chip($r['status']) ?>"><?= e($r['status']) ?></span></td>
                        <td class="px-3 py-1.5 font-bold" style="text-align:right"><?= money($r['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-between px-3 py-2 flex-shrink-0" style="border-top:1px solid var(--bz-line)">
            <span class="biz-muted" style="font-size:11px">Page <?= $page ?> of <?= $totalPages ?></span>
            <div class="flex items-center gap-1.5">
                <?php if ($page > 1): ?><a href="<?= e($rcpLink(['p' => $page - 1])) ?>" class="biz-btn biz-btn-ghost biz-btn-sm">Previous</a>
                <?php else: ?><span class="biz-btn biz-btn-ghost biz-btn-sm" style="opacity:.4">Previous</span><?php endif; ?>
                <?php if ($page < $totalPages): ?><a href="<?= e($rcpLink(['p' => $page + 1])) ?>" class="biz-btn biz-btn-ghost biz-btn-sm">Next</a>
                <?php else: ?><span class="biz-btn biz-btn-ghost biz-btn-sm" style="opacity:.4">Next</span><?php endif; ?>
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
