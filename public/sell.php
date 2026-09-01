<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/OnePayStoreInventory.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}

$pdo = DB::pdo();
$stmt = $pdo->prepare("
    SELECT c.id, c.uuid, c.name, c.logo, cm.role
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.user_id = :uid AND cm.status = 'active' AND c.status = 'active'
      AND cm.role IN ('owner', 'admin', 'manager')
    ORDER BY c.name ASC
");
$stmt->execute(['uid' => (int)$user['id']]);
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$companies) {
    header('Location: index.php');
    exit;
}

$requestedUuid = trim((string)($_POST['company_uuid'] ?? $_GET['company_uuid'] ?? ''));
$activeCompany = $companies[0];
if ($requestedUuid !== '') {
    foreach ($companies as $company) {
        if ((string)$company['uuid'] === $requestedUuid) {
            $activeCompany = $company;
            break;
        }
    }
}

$canUseOnelink = !empty($user['is_admin']) || in_array($activeCompany['role'], ['owner', 'admin'], true);

$notice = null;
$inventoryError = '';
$inventoryItems = [];
try {
    $inventoryItems = OnePayStoreInventory::fetch((string)$activeCompany['uuid']);
    $inventoryError = OnePayStoreInventory::lastError();
} catch (Throwable $e) {
    $inventoryItems = [];
    $inventoryError = 'Could not load OnePay inventory: ' . $e->getMessage();
}

function sell_date_value(?string $value, bool $endOfDay = false): ?string
{
    $value = trim((string)$value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
}

function sell_price_label($value): string
{
    return '$' . number_format((float)$value, 2);
}

/**
 * Plain-language label for a store_listings.audience enum value. The stored
 * values (employee|market|both) never change — this is display only.
 */
function sell_audience_label(string $enum): string
{
    switch ($enum) {
        case 'employee': return 'Employees only';
        case 'market':   return 'Centryk Market';
        case 'both':     return 'Everyone';
        default:         return 'Published';
    }
}

function sell_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['item_ids'] ?? [])))));

    if (!$selectedIds) {
        $notice = ['type' => 'error', 'text' => 'Select at least one inventory item first.'];
    } elseif ($action === 'unpublish') {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = [(int)$activeCompany['id'], 'onepay'];
        foreach ($selectedIds as $id) {
            $params[] = $id;
        }

        $unpublishStmt = $pdo->prepare("
            UPDATE store_listings
            SET enabled = 0
            WHERE company_id = ?
              AND source_app = ?
              AND source_item_id IN ($placeholders)
        ");
        $unpublishStmt->execute($params);
        $notice = ['type' => 'success', 'text' => 'Selected items were removed from Centryk Store.'];
    } elseif ($action === 'publish' || $action === 'update') {
        $audience = (string)($_POST['audience'] ?? 'employee');
        if (!in_array($audience, ['employee', 'market', 'both'], true)) {
            $audience = 'employee';
        }
        $startsAt = sell_date_value($_POST['starts_at'] ?? null);
        $endsAt = sell_date_value($_POST['ends_at'] ?? null, true);

        $inventoryById = [];
        foreach ($inventoryItems as $item) {
            $inventoryById[(int)$item['id']] = $item;
        }

        $selectedItems = [];
        foreach ($selectedIds as $id) {
            if (isset($inventoryById[$id])) {
                $selectedItems[] = $inventoryById[$id];
            }
        }

        $upsertStmt = $pdo->prepare("
            INSERT INTO store_listings
                (company_id, source_app, source_item_id, title, sku, price, summary, image_url, audience, enabled, starts_at, ends_at)
            VALUES
                (:company_id, 'onepay', :source_item_id, :title, :sku, :price, :summary, :image_url, :audience, 1, :starts_at, :ends_at)
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                sku = VALUES(sku),
                price = VALUES(price),
                summary = VALUES(summary),
                image_url = VALUES(image_url),
                audience = VALUES(audience),
                enabled = 1,
                starts_at = VALUES(starts_at),
                ends_at = VALUES(ends_at)
        ");

        foreach ($selectedItems as $item) {
            $upsertStmt->execute([
                'company_id' => (int)$activeCompany['id'],
                'source_item_id' => (int)$item['id'],
                'title' => (string)$item['name'],
                'sku' => trim((string)($item['sku'] ?? '')) ?: null,
                'price' => sell_price_label($item['price'] ?? 0),
                'summary' => trim((string)($item['description'] ?? '')) ?: null,
                'image_url' => trim((string)($item['image_url'] ?? '')) ?: null,
                'audience' => $audience,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        }

        $notice = ['type' => 'success', 'text' => count($selectedItems) . ' item(s) saved to Centryk Store.'];
    }
}

if (!$inventoryItems && $inventoryError === '') {
    $inventoryError = 'Could not load OnePay inventory for this company.';
}

$listingStmt = $pdo->prepare('
    SELECT source_item_id, id AS listing_id, audience AS listing_audience,
           enabled AS listing_enabled, starts_at AS listing_starts_at, ends_at AS listing_ends_at
    FROM store_listings
    WHERE company_id = :company_id AND source_app = "onepay" AND source_item_id IS NOT NULL
');
$listingStmt->execute(['company_id' => (int)$activeCompany['id']]);
$listingByItemId = [];
foreach ($listingStmt->fetchAll(PDO::FETCH_ASSOC) as $listing) {
    $listingByItemId[(int)$listing['source_item_id']] = $listing;
}
foreach ($inventoryItems as &$item) {
    $listing = $listingByItemId[(int)$item['id']] ?? [];
    $item['listing_id'] = $listing['listing_id'] ?? null;
    $item['listing_audience'] = $listing['listing_audience'] ?? null;
    $item['listing_enabled'] = $listing['listing_enabled'] ?? 0;
    $item['listing_starts_at'] = $listing['listing_starts_at'] ?? null;
    $item['listing_ends_at'] = $listing['listing_ends_at'] ?? null;
}
unset($item);

$publishedItems = [];
$unpublishedItems = [];
$storeNames = [];
foreach ($inventoryItems as $item) {
    if ((int)($item['listing_enabled'] ?? 0) === 1) {
        $publishedItems[] = $item;
    } else {
        $unpublishedItems[] = $item;
    }
    $sn = trim((string)($item['store_name'] ?? ''));
    if ($sn !== '' && !in_array($sn, $storeNames, true)) {
        $storeNames[] = $sn;
    }
}
sort($storeNames);
// Single list, published first so the "already live" items lead.
$orderedItems = array_merge($publishedItems, $unpublishedItems);

$pageTitle = 'Sell on Store';
$headerMaxW = 'max-w-7xl';
ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Sell on Centryk Store - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>
        [data-lucide] { display: inline-block; }
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased text-slate-900">
<?php include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-7xl px-4 pt-1 pb-4">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Centryk Store</p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900">Sell on Centryk Store</h1>
            <p class="mt-1 text-sm font-semibold text-slate-500">Choose which OnePay inventory items appear in Store.</p>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($canUseOnelink): ?>
            <a href="onelink-payments.php?company_uuid=<?= urlencode((string)$activeCompany['uuid']) ?>" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-cyan-700 transition hover:bg-cyan-100">
                <i data-lucide="credit-card" class="h-4 w-4"></i> OneLink Payments
            </a>
            <?php endif; ?>
            <form method="get" class="flex items-center gap-2">
                <select name="company_uuid" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 shadow-sm outline-none focus:border-violet-500">
                    <?php foreach ($companies as $company): ?>
                    <option value="<?= sell_h($company['uuid']) ?>" <?= $company['uuid'] === $activeCompany['uuid'] ? 'selected' : '' ?>>
                        <?= sell_h($company['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button class="rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">Switch</button>
            </form>
        </div>
    </div>

    <?php if ($notice): ?>
        <div class="mb-5 rounded-xl border px-4 py-3 text-sm font-bold <?= $notice['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
            <?= sell_h($notice['text']) ?>
        </div>
    <?php endif; ?>

    <?php if ($inventoryError !== ''): ?>
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
            <?= sell_h($inventoryError) ?>
        </div>
    <?php else: ?>
    <form method="post" id="storePublishForm" class="space-y-5">
        <input type="hidden" name="company_uuid" value="<?= sell_h($activeCompany['uuid']) ?>">
        <input type="hidden" name="action" id="publishAction" value="publish">

        <section id="listingSetup" class="hidden rounded-2xl border border-violet-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-black tracking-tight">Store Listing Setup</h2>
                    <p id="selectedCountText" class="text-xs font-semibold text-slate-400">0 items selected.</p>
                </div>
                <a href="store.php<?= '?company_uuid=' . urlencode((string)$activeCompany['uuid']) ?>" class="inline-flex items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-violet-700 transition hover:bg-violet-100">
                    <i data-lucide="store" class="h-4 w-4"></i> Preview
                </a>
            </div>
            <div class="grid gap-4 lg:grid-cols-[1.2fr_1fr_auto] lg:items-end">
                <div>
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Audience</label>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-700">
                            <input type="radio" name="audience" value="employee" checked class="mr-2"> Employees only
                        </label>
                        <label class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-700">
                            <input type="radio" name="audience" value="market" class="mr-2"> Centryk Market
                        </label>
                        <label class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-700">
                            <input type="radio" name="audience" value="both" class="mr-2"> Everyone
                        </label>
                    </div>
                    <p class="mt-1.5 text-[11px] font-semibold leading-relaxed text-slate-400">
                        <b class="text-slate-500">Employees only</b> &mdash; signed-in members of your company &middot;
                        <b class="text-slate-500">Centryk Market</b> &mdash; anyone browsing the public store &middot;
                        <b class="text-slate-500">Everyone</b> &mdash; the public store and your members
                    </p>
                </div>
                <div>
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Visibility Window</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" name="starts_at" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-violet-500">
                        <input type="date" name="ends_at" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-violet-500">
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" data-action="publish" class="rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">Publish</button>
                    <button type="submit" data-action="update" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-slate-700 transition hover:bg-slate-50">Update</button>
                    <button type="submit" data-action="unpublish" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-rose-700 transition hover:bg-rose-100">Unpublish</button>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                <div>
                    <span class="block text-lg font-black tracking-tight">Inventory</span>
                    <span class="block text-xs font-semibold text-slate-400">
                        <?= count($publishedItems) ?> on store &middot; <?= count($inventoryItems) ?> active item<?= count($inventoryItems) === 1 ? '' : 's' ?> total
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <label class="relative">
                        <i data-lucide="search" class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"></i>
                        <input id="sellSearch" type="search" placeholder="Search name or SKU"
                               class="w-52 rounded-lg border border-slate-200 bg-slate-50 py-1.5 pl-8 pr-3 text-sm font-semibold text-slate-700 outline-none focus:border-violet-500 focus:bg-white">
                    </label>
                    <div id="sellStatusChips" class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5">
                        <button type="button" data-status="all" class="rounded-md px-3 py-1.5 text-xs font-black transition bg-white text-slate-900 shadow-sm">All</button>
                        <button type="button" data-status="listed" class="rounded-md px-3 py-1.5 text-xs font-black transition text-slate-500 hover:text-slate-800">On store</button>
                        <button type="button" data-status="unlisted" class="rounded-md px-3 py-1.5 text-xs font-black transition text-slate-500 hover:text-slate-800">Not listed</button>
                    </div>
                    <select id="sellAudience" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-bold text-slate-700 outline-none focus:border-violet-500">
                        <option value="all">Any audience</option>
                        <option value="employee">Employees only</option>
                        <option value="market">Centryk Market</option>
                        <option value="both">Everyone</option>
                    </select>
                    <?php if (count($storeNames) > 1): ?>
                    <select id="sellStore" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-bold text-slate-700 outline-none focus:border-violet-500">
                        <option value="all">All stores</option>
                        <?php foreach ($storeNames as $sn): ?>
                        <option value="<?= sell_h($sn) ?>"><?= sell_h($sn) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-4">
                <?php if ($orderedItems): ?>
                    <div id="sellGrid" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <?php foreach ($orderedItems as $item): ?>
                            <?php include __DIR__ . '/partials/sell_inventory_row.php'; ?>
                        <?php endforeach; ?>
                    </div>
                    <div id="sellEmpty" class="hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm font-bold text-slate-600">No items match these filters.</div>
                    <div id="sellPager" class="mt-4 flex flex-wrap items-center justify-between gap-3"></div>
                <?php else: ?>
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm font-bold text-slate-600">No active OnePay inventory items found for this company.</div>
                <?php endif; ?>
            </div>
        </section>
    </form>
    <?php endif; ?>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
if (window.lucide) { lucide.createIcons(); }

(function () {
    var form = document.getElementById('storePublishForm');
    var setup = document.getElementById('listingSetup');
    var actionInput = document.getElementById('publishAction');
    var selectedText = document.getElementById('selectedCountText');
    if (!form || !setup || !actionInput || !selectedText) { return; }

    function selectedBoxes() {
        return Array.prototype.slice.call(form.querySelectorAll('input[name="item_ids[]"]:checked'));
    }

    function syncSetup() {
        var selected = selectedBoxes();
        setup.classList.toggle('hidden', selected.length === 0);
        selectedText.textContent = selected.length + ' item(s) selected.';
    }

    form.querySelectorAll('input[name="item_ids[]"]').forEach(function (box) {
        box.addEventListener('change', syncSetup);
    });

    form.querySelectorAll('button[data-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            actionInput.value = button.dataset.action || 'publish';
        });
    });

    syncSetup();

    // ── Filters + pagination (client-side; all rows are already in the DOM) ──
    var grid = document.getElementById('sellGrid');
    if (!grid) { return; }
    var rows = Array.prototype.slice.call(grid.querySelectorAll('.sell-row'));
    var searchInput = document.getElementById('sellSearch');
    var audienceSel = document.getElementById('sellAudience');
    var storeSel = document.getElementById('sellStore');
    var statusChips = document.getElementById('sellStatusChips');
    var pager = document.getElementById('sellPager');
    var emptyEl = document.getElementById('sellEmpty');
    var PAGE_SIZE = 15;
    var statusFilter = 'all';
    var page = 1;

    function chipClass(on) {
        return 'rounded-md px-3 py-1.5 text-xs font-black transition ' +
            (on ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800');
    }

    function rowMatches(row) {
        if (statusFilter !== 'all' && row.dataset.status !== statusFilter) { return false; }
        var a = audienceSel ? audienceSel.value : 'all';
        if (a !== 'all' && row.dataset.audience !== a) { return false; }
        var s = storeSel ? storeSel.value : 'all';
        if (s !== 'all' && row.dataset.store !== s) { return false; }
        var q = (searchInput ? searchInput.value : '').trim().toLowerCase();
        if (q && (row.dataset.search || '').indexOf(q) === -1) { return false; }
        return true;
    }

    function renderPager(total, pages) {
        if (!pager) { return; }
        if (total <= PAGE_SIZE) { pager.innerHTML = ''; return; }
        var from = (page - 1) * PAGE_SIZE + 1;
        var to = Math.min(page * PAGE_SIZE, total);
        var nav = '<button type="button" data-nav="prev" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-black uppercase tracking-[0.1em] text-slate-600 transition hover:bg-slate-50 disabled:opacity-40"' + (page <= 1 ? ' disabled' : '') + '>Prev</button>';
        for (var p = 1; p <= pages; p++) {
            nav += '<button type="button" data-nav="' + p + '" class="rounded-lg px-3 py-1.5 text-xs font-black transition ' +
                (p === page ? 'bg-slate-950 text-white' : 'border border-slate-200 text-slate-600 hover:bg-slate-50') + '">' + p + '</button>';
        }
        nav += '<button type="button" data-nav="next" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-black uppercase tracking-[0.1em] text-slate-600 transition hover:bg-slate-50 disabled:opacity-40"' + (page >= pages ? ' disabled' : '') + '>Next</button>';
        pager.innerHTML =
            '<span class="text-xs font-semibold text-slate-400">' + from + '–' + to + ' of ' + total + '</span>' +
            '<span class="flex flex-wrap items-center gap-1.5">' + nav + '</span>';
    }

    function apply() {
        var visible = rows.filter(rowMatches);
        var pages = Math.max(1, Math.ceil(visible.length / PAGE_SIZE));
        if (page > pages) { page = pages; }
        var start = (page - 1) * PAGE_SIZE;
        var shown = visible.slice(start, start + PAGE_SIZE);
        rows.forEach(function (r) { r.classList.add('hidden'); });
        shown.forEach(function (r) { r.classList.remove('hidden'); });
        if (emptyEl) { emptyEl.classList.toggle('hidden', visible.length > 0); }
        renderPager(visible.length, pages);
        if (window.lucide) { lucide.createIcons(); }
    }

    function resetAndApply() { page = 1; apply(); }

    if (searchInput) { searchInput.addEventListener('input', resetAndApply); }
    if (audienceSel) { audienceSel.addEventListener('change', resetAndApply); }
    if (storeSel) { storeSel.addEventListener('change', resetAndApply); }
    if (statusChips) {
        statusChips.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-status]');
            if (!btn) { return; }
            statusFilter = btn.dataset.status;
            Array.prototype.forEach.call(statusChips.children, function (c) {
                c.className = chipClass(c.dataset.status === statusFilter);
            });
            resetAndApply();
        });
    }
    if (pager) {
        pager.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-nav]');
            if (!btn) { return; }
            var nav = btn.dataset.nav;
            if (nav === 'prev') { page = Math.max(1, page - 1); }
            else if (nav === 'next') { page = page + 1; }
            else { page = parseInt(nav, 10) || 1; }
            apply();
            grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    apply();
})();
</script>
</body>
</html>
