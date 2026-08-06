<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/OnePayStoreInventory.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
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
foreach ($inventoryItems as $item) {
    if ((int)($item['listing_enabled'] ?? 0) === 1) {
        $publishedItems[] = $item;
    } else {
        $unpublishedItems[] = $item;
    }
}

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

<main class="mx-auto max-w-7xl px-6 pt-1 pb-5">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Centryk Store</p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900">Sell on Centryk Store</h1>
            <p class="mt-1 text-sm font-semibold text-slate-500">Choose which OnePay inventory items appear in Store.</p>
        </div>
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
                            <input type="radio" name="audience" value="employee" checked class="mr-2"> Employees
                        </label>
                        <label class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-700">
                            <input type="radio" name="audience" value="market" class="mr-2"> Centryk Market
                        </label>
                        <label class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-700">
                            <input type="radio" name="audience" value="both" class="mr-2"> Both
                        </label>
                    </div>
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

        <details class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" open>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4">
                <span>
                    <span class="block text-lg font-black tracking-tight">Published Items</span>
                    <span class="block text-xs font-semibold text-slate-400"><?= count($publishedItems) ?> item(s) currently visible through Centryk Store rules.</span>
                </span>
                <i data-lucide="chevron-down" class="h-5 w-5 text-slate-400"></i>
            </summary>
            <div class="border-t border-slate-100 p-5">
                <?php if ($publishedItems): ?>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <?php foreach ($publishedItems as $item): ?>
                            <?php include __DIR__ . '/partials/sell_inventory_row.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm font-bold text-slate-600">No published items yet.</div>
                <?php endif; ?>
            </div>
        </details>

        <details class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" open>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4">
                <span>
                    <span class="block text-lg font-black tracking-tight">Non-published Items</span>
                    <span class="block text-xs font-semibold text-slate-400"><?= count($unpublishedItems) ?> active OnePay inventory item(s) not currently listed.</span>
                </span>
                <i data-lucide="chevron-down" class="h-5 w-5 text-slate-400"></i>
            </summary>
            <div class="border-t border-slate-100 p-5">
                <?php if ($unpublishedItems): ?>
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <?php foreach ($unpublishedItems as $item): ?>
                            <?php include __DIR__ . '/partials/sell_inventory_row.php'; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm font-bold text-slate-600">All active inventory items are published.</div>
                <?php endif; ?>
            </div>
        </details>
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
})();
</script>
</body>
</html>
