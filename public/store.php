<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$pdo = DB::pdo();
$typeLabels = [
    'school' => 'Education',
    'gym' => 'Fitness',
    'clinic' => 'Health',
    'salon' => 'Salon / Spa',
    'grocery' => 'Groceries',
    'services' => 'Services',
    'property' => 'Property / Rentals',
    'retail' => 'Retail / Shop',
    'restaurant' => 'Restaurant / Food',
    'auto_sales' => 'Auto Sales',
    'auto_rental' => 'Auto Rental',
    'other' => 'Business',
];

$companyUuid = trim((string)($_GET['company_uuid'] ?? ''));
$company = null;
$isFeed = $companyUuid === '';

if (!$isFeed) {
    $stmt = $pdo->prepare('
        SELECT id, uuid, name, email, phone, phone2, phone3, address, logo, store_theme,
               business_type, customer_noun_plural
        FROM companies
        WHERE uuid = :uuid AND status = "active"
        LIMIT 1
    ');
    $stmt->execute(['uuid' => $companyUuid]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$isFeed && !$company) {
    header('Location: index.php');
    exit;
}

$memberCompanyIds = [];
$memberStmt = $pdo->prepare('
    SELECT company_id
    FROM company_members
    WHERE user_id = :uid AND status = "active"
');
$memberStmt->execute(['uid' => (int)$user['id']]);
foreach ($memberStmt->fetchAll(PDO::FETCH_COLUMN) as $memberCompanyId) {
    $memberCompanyIds[(int)$memberCompanyId] = true;
}

$isMember = $company ? isset($memberCompanyIds[(int)$company['id']]) : false;

// Centryk Connect status, for the Connect button when viewing another company's store.
$connectFromCompanyId = null;
$connectionStatus = null; // null | 'pending_out' | 'pending_in' | 'accepted'
$connectionId = null;
if ($company && !$isMember) {
    $adminStmt = $pdo->prepare("SELECT company_id FROM company_members WHERE user_id=? AND status='active' AND role='admin' LIMIT 1");
    $adminStmt->execute([(int)$user['id']]);
    $connectFromCompanyId = $adminStmt->fetchColumn() ?: null;
    if ($connectFromCompanyId) {
        $connectFromCompanyId = (int)$connectFromCompanyId;
        $connStmt = $pdo->prepare(
            'SELECT id, status, requester_company_id FROM company_connections
             WHERE (requester_company_id=? AND recipient_company_id=?) OR (requester_company_id=? AND recipient_company_id=?)'
        );
        $connStmt->execute([$connectFromCompanyId, (int)$company['id'], (int)$company['id'], $connectFromCompanyId]);
        $connRow = $connStmt->fetch(PDO::FETCH_ASSOC);
        if ($connRow) {
            $connectionId = (int)$connRow['id'];
            if ($connRow['status'] === 'accepted') {
                $connectionStatus = 'accepted';
            } elseif ($connRow['status'] === 'pending') {
                $connectionStatus = ((int)$connRow['requester_company_id'] === $connectFromCompanyId) ? 'pending_out' : 'pending_in';
            }
        }
    }
}

$onePayBaseUrl = '';
try {
    $onePayUrlStmt = $pdo->prepare('SELECT url_local, url_production FROM apps WHERE `key` = "onepay" AND status = "active" LIMIT 1');
    $onePayUrlStmt->execute();
    $onePayApp = $onePayUrlStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalHost = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host) === 1;
    $onePayLaunchUrl = ($isLocalHost || empty($onePayApp['url_production'])) ? (string)($onePayApp['url_local'] ?? '') : (string)($onePayApp['url_production'] ?? '');
    if ($onePayLaunchUrl !== '') {
        $parts = parse_url($onePayLaunchUrl);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $onePayBaseUrl = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
        }
    }
} catch (Throwable $e) {
    $onePayBaseUrl = '';
}

$onePayImageUrl = static function (?string $imageUrl, $itemId = null) use ($onePayBaseUrl): string {
    $imageUrl = trim((string)$imageUrl);
    if ($imageUrl === '') {
        return '';
    }
    if ((int)$itemId > 0 && str_starts_with($imageUrl, '/uploads/catalog/')) {
        return 'store_item_image.php?item_id=' . urlencode((string)(int)$itemId);
    }
    if (preg_match('#^https?://#i', $imageUrl)) {
        return $imageUrl;
    }
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalHost = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host) === 1;
    if ($isLocalHost && str_starts_with($imageUrl, '/uploads/')) {
        $localOnePayFile = dirname(__DIR__, 2) . '/onepay/public' . $imageUrl;
        if (is_file($localOnePayFile)) {
            return '/onepay/public' . $imageUrl;
        }
    }
    if ($onePayBaseUrl === '') {
        return $imageUrl;
    }
    return rtrim($onePayBaseUrl, '/') . '/' . ltrim($imageUrl, '/');
};

$listings = [];
$feedStores = [];
try {
    if ($isFeed) {
        $listingStmt = $pdo->query('
            SELECT sl.title, sl.sku, sl.price, sl.summary, sl.audience, sl.source_item_id, sl.image_url,
                   c.id AS company_id, c.uuid AS company_uuid, c.name AS company_name,
                   c.logo AS company_logo, c.store_theme AS company_store_theme, c.business_type, c.address
            FROM store_listings sl
            JOIN companies c ON c.id = sl.company_id
            WHERE c.status = "active"
              AND sl.enabled = 1
              AND sl.source_app = "onepay"
              AND sl.source_item_id IS NOT NULL
              AND (sl.starts_at IS NULL OR sl.starts_at <= NOW())
              AND (sl.ends_at IS NULL OR sl.ends_at >= NOW())
            ORDER BY c.name ASC, sl.created_at DESC, sl.title ASC
        ');

        foreach ($listingStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $companyId = (int)$item['company_id'];
            if (!in_array(($item['audience'] ?? ''), ['market', 'both'], true) && !isset($memberCompanyIds[$companyId])) {
                continue;
            }
            if (!isset($feedStores[$companyId])) {
                $feedStores[$companyId] = [
                    'id' => $companyId,
                    'uuid' => (string)$item['company_uuid'],
                    'name' => (string)$item['company_name'],
                    'logo' => (string)($item['company_logo'] ?? ''),
                    'store_theme' => (string)($item['company_store_theme'] ?? ''),
                    'business_type' => (string)($item['business_type'] ?? ''),
                    'address' => (string)($item['address'] ?? ''),
                    'items' => [],
                ];
            }
            if (count($feedStores[$companyId]['items']) < 18) {
                $feedStores[$companyId]['items'][] = $item;
            }
        }
    } else {
        $listingSql = '
            SELECT sl.title, sl.sku, sl.price, sl.summary, sl.audience, sl.source_item_id, sl.image_url
            FROM store_listings sl
            WHERE sl.company_id = :cid
              AND sl.enabled = 1
              AND sl.source_app = "onepay"
              AND sl.source_item_id IS NOT NULL
              AND (sl.starts_at IS NULL OR sl.starts_at <= NOW())
              AND (sl.ends_at IS NULL OR sl.ends_at >= NOW())
              AND (sl.audience IN ("market", "both") ' . ($isMember ? 'OR sl.audience = "employee"' : '') . ')
            ORDER BY sl.created_at DESC, sl.title ASC
        ';
        $listingStmt = $pdo->prepare($listingSql);
        $listingStmt->execute(['cid' => (int)$company['id']]);
        $listings = $listingStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $listings = [];
    $feedStores = [];
}

$name = $company ? trim((string)($company['name'] ?? '')) : 'Centryk Store';
$address = trim((string)($company['address'] ?? ''));
$email = trim((string)($company['email'] ?? ''));
$logo = trim((string)($company['logo'] ?? ''));
$theme = trim((string)($company['store_theme'] ?? ''));
$phones = array_values(array_filter([
    trim((string)($company['phone'] ?? '')),
    trim((string)($company['phone2'] ?? '')),
    trim((string)($company['phone3'] ?? '')),
], static function (string $phone): bool {
    return $phone !== '';
}));
$typeKey = trim((string)($company['business_type'] ?? ''));
$typeLabel = $typeLabels[$typeKey] ?? ($typeKey !== '' ? ucwords(str_replace('_', ' ', $typeKey)) : 'Business');
$initial = strtoupper(substr($name !== '' ? $name : 'S', 0, 1));

$businessLabel = static function (?string $typeKey) use ($typeLabels): string {
    $typeKey = trim((string)$typeKey);
    return $typeLabels[$typeKey] ?? ($typeKey !== '' ? ucwords(str_replace('_', ' ', $typeKey)) : 'Business');
};

$storeInitial = static function (string $storeName): string {
    return strtoupper(substr(trim($storeName) !== '' ? trim($storeName) : 'S', 0, 1));
};

$safeTheme = static function (?string $themePath): string {
    $themePath = trim((string)$themePath);
    if ($themePath === '' || !preg_match('#^assets/store_theme/[a-z0-9][a-z0-9_-]{1,80}\.(png|jpe?g|webp)$#i', $themePath)) {
        return '';
    }
    return is_file(__DIR__ . '/' . $themePath) ? $themePath : '';
};
$theme = $safeTheme($theme);
$hasLogo = $logo !== '';
$hasTheme = $theme !== '';
$hasContact = $address !== '' || !empty($phones) || $email !== '';

$pageTitle = 'Store';
$headerMaxW = 'max-w-7xl';
$awCurrent = 'store';
ob_start();
?>
<div class="flex items-center gap-2">
    <label for="storeHeaderSearch" class="sr-only">Search store items</label>
    <div class="relative">
        <i data-lucide="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
        <input id="storeHeaderSearch"
               type="search"
               autocomplete="off"
               placeholder="Search items"
               class="h-9 w-32 rounded-xl border border-slate-200 bg-slate-50 pl-8 pr-3 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-violet-400 focus:bg-white focus:ring-4 focus:ring-violet-100 sm:w-48 md:w-64">
    </div>
    <button id="storeCartBtn"
            type="button"
            class="relative flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-950"
            aria-label="Cart"
            title="Cart">
        <i data-lucide="shopping-cart" class="h-4.5 w-4.5"></i>
        <span id="storeCartCount" class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-violet-600 px-1 text-[9px] font-black leading-none text-white">0</span>
    </button>
</div>
<?php
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title><?= htmlspecialchars($isFeed ? 'Centryk Store' : $name . ' - Store') ?> - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>
        [data-lucide] { display: inline-block; }
        .line-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        @media (min-width: 640px) {
            .store-fit-sm-1 { max-width: calc(50% - 0.5rem); }
            .store-fit-sm-2 { max-width: 100%; }
        }
        @media (min-width: 768px) {
            .store-fit-md-1 { max-width: calc(33.333333% - 0.666667rem); }
            .store-fit-md-2 { max-width: calc(66.666667% - 0.333333rem); }
            .store-fit-md-3 { max-width: 100%; }
        }
        @media (min-width: 1280px) {
            .store-fit-xl-1 { max-width: calc(20% - 0.8rem); }
            .store-fit-xl-2 { max-width: calc(40% - 0.6rem); }
            .store-fit-xl-3 { max-width: calc(60% - 0.4rem); }
            .store-fit-xl-4 { max-width: calc(80% - 0.2rem); }
            .store-fit-xl-5 { max-width: 100%; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased text-slate-900">
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>
<?php include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-7xl px-6 pt-1 pb-5">
    <?php if ($isFeed): ?>
    <section class="mb-4">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-600">Centryk Store</p>
        <h1 class="mt-1 text-3xl font-black tracking-tight text-slate-950">Store Feed</h1>
        <p class="mt-2 max-w-2xl text-sm font-semibold leading-relaxed text-slate-500">Browse active goods grouped by business. Open any business to view its dedicated store page.</p>
    </section>

    <?php if ($feedStores): ?>
        <div class="space-y-6">
            <?php foreach ($feedStores as $store): ?>
                <?php
                    $storeName = trim((string)$store['name']);
                    $storeLogo = trim((string)$store['logo']);
                    $storeTheme = $safeTheme((string)$store['store_theme']);
                    $storeType = $businessLabel((string)$store['business_type']);
                    $storeHref = 'store.php?company_uuid=' . urlencode((string)$store['uuid']);
                    $storeColumnCount = max(1, min(5, count($store['items'])));
                    $storeFitClass = 'store-fit-sm-' . max(1, min(2, count($store['items'])))
                        . ' store-fit-md-' . max(1, min(3, count($store['items'])))
                        . ' store-fit-xl-' . $storeColumnCount;
                    $storeGridClass = [
                        1 => 'sm:grid-cols-1 md:grid-cols-1 xl:grid-cols-1',
                        2 => 'sm:grid-cols-2 md:grid-cols-2 xl:grid-cols-2',
                        3 => 'sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-3',
                        4 => 'sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4',
                        5 => 'sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5',
                    ][$storeColumnCount];
                ?>
                <section class="store-section <?= htmlspecialchars($storeFitClass) ?> w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <a href="<?= htmlspecialchars($storeHref) ?>" class="flex items-center gap-2.5 border-b border-slate-100 px-3 py-2.5 transition hover:bg-slate-50">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 text-sm font-black text-slate-600">
                            <?php if ($storeLogo !== ''): ?>
                                <img src="<?= htmlspecialchars($storeLogo) ?>" alt="" class="h-full w-full object-cover">
                            <?php else: ?>
                                <?= htmlspecialchars($storeInitial($storeName)) ?>
                            <?php endif; ?>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-black text-slate-950"><?= htmlspecialchars($storeName) ?></span>
                            <span class="mt-0.5 block text-[11px] font-bold text-slate-500"><?= htmlspecialchars($storeType) ?></span>
                        </span>
                        <span class="hidden items-center gap-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-violet-700 sm:inline-flex">
                            View Store <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                        </span>
                    </a>
                    <div class="grid gap-px bg-slate-100 <?= htmlspecialchars($storeGridClass) ?>">
                        <?php foreach ($store['items'] as $item): ?>
                            <?php $itemImage = $onePayImageUrl($item['image_url'] ?? '', $item['source_item_id'] ?? null); ?>
                            <article class="store-item min-h-44 cursor-pointer bg-white p-3 transition hover:bg-slate-50"
                                     tabindex="0"
                                     role="button"
                                     data-item-id="<?= (int)($item['source_item_id'] ?? 0) ?>"
                                     data-title="<?= htmlspecialchars((string)($item['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                     data-price="<?= htmlspecialchars((string)($item['price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                     data-sku="<?= htmlspecialchars((string)($item['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                     data-summary="<?= htmlspecialchars((string)($item['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                     data-image="<?= htmlspecialchars($itemImage, ENT_QUOTES, 'UTF-8') ?>"
                                     data-store="<?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?>"
                                     data-type="<?= htmlspecialchars($storeType, ENT_QUOTES, 'UTF-8') ?>"
                                     data-search="<?= htmlspecialchars(strtolower(trim(($item['title'] ?? '') . ' ' . ($item['sku'] ?? '') . ' ' . ($item['summary'] ?? '') . ' ' . $storeName . ' ' . $storeType))) ?>">
                                <?php if ($itemImage !== ''): ?>
                                    <div class="mb-3 aspect-[4/3] overflow-hidden rounded-xl bg-slate-100">
                                        <img src="<?= htmlspecialchars($itemImage) ?>" alt="" class="h-full w-full object-cover">
                                    </div>
                                <?php else: ?>
                                    <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-xl bg-violet-50 text-violet-700">
                                        <i data-lucide="package" class="h-6 w-6"></i>
                                    </div>
                                <?php endif; ?>
                                <?php if (trim((string)($item['price'] ?? '')) !== ''): ?>
                                    <p class="mb-1 text-sm font-black text-slate-950"><?= htmlspecialchars((string)$item['price']) ?></p>
                                <?php endif; ?>
                                <h2 class="text-sm font-black leading-snug text-slate-900"><?= htmlspecialchars((string)$item['title']) ?></h2>
                                <?php if (trim((string)($item['summary'] ?? '')) !== ''): ?>
                                    <p class="mt-2 line-clamp-3 text-xs font-semibold leading-relaxed text-slate-500"><?= htmlspecialchars((string)$item['summary']) ?></p>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="rounded-xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center">
            <p class="text-sm font-bold text-slate-500">No store items are listed right now.</p>
        </div>
    <?php endif; ?>

    <?php else: ?>
    <?php if ($hasTheme): ?>
        <section class="relative mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-slate-950 shadow-sm">
            <img src="<?= htmlspecialchars($theme) ?>" alt="" class="absolute inset-0 h-full w-full object-cover">
            <div class="absolute inset-0 bg-gradient-to-r from-white/92 via-white/72 to-white/25"></div>
            <div class="relative flex flex-col md:min-h-56 md:flex-row md:items-stretch">
                <div class="flex h-44 w-full shrink-0 items-center justify-center overflow-hidden border-b border-slate-200 bg-white text-5xl font-black text-slate-600 shadow-sm md:h-auto md:w-64 md:border-b-0 md:border-r">
                    <?php if ($hasLogo): ?>
                        <img src="<?= htmlspecialchars($logo) ?>" alt="" class="h-full w-full object-cover">
                    <?php else: ?>
                        <?= htmlspecialchars($initial) ?>
                    <?php endif; ?>
                </div>
                <div class="flex min-w-0 flex-1 flex-col justify-center p-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="truncate text-3xl font-black tracking-tight text-slate-950"><?= htmlspecialchars($name) ?></h1>
                        <span class="rounded-full bg-white/75 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-800 ring-1 ring-slate-200"><?= htmlspecialchars($typeLabel) ?></span>
                    </div>
                    <?php if ($hasContact): ?>
                        <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-bold text-slate-800">
                            <?php if ($address !== ''): ?>
                                <p class="flex items-center gap-1.5"><i data-lucide="map-pin" class="h-4 w-4 shrink-0 text-slate-600"></i><span><?= htmlspecialchars(str_replace(["\r", "\n"], ' ', $address)) ?></span></p>
                            <?php endif; ?>
                            <?php if ($phones): ?>
                                <?php if ($address !== ''): ?><span class="text-slate-500">.</span><?php endif; ?>
                                <p class="flex items-center gap-1.5"><i data-lucide="phone" class="h-4 w-4 shrink-0 text-slate-600"></i><span><?= htmlspecialchars(implode(' / ', $phones)) ?></span></p>
                            <?php endif; ?>
                            <?php if ($email !== ''): ?>
                                <?php if ($address !== '' || $phones): ?><span class="text-slate-500">.</span><?php endif; ?>
                                <p class="flex items-center gap-1.5"><i data-lucide="mail" class="h-4 w-4 shrink-0 text-slate-600"></i><a href="mailto:<?= htmlspecialchars($email) ?>" class="break-all text-slate-900 hover:text-slate-700"><?= htmlspecialchars($email) ?></a></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php elseif ($hasLogo): ?>
        <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col md:min-h-32 md:flex-row md:items-stretch">
                <div class="flex h-32 w-full shrink-0 items-center justify-center overflow-hidden border-b border-slate-200 bg-white text-4xl font-black text-slate-600 md:h-auto md:w-44 md:border-b-0 md:border-r">
                    <img src="<?= htmlspecialchars($logo) ?>" alt="" class="h-full w-full object-cover">
                </div>
                <div class="flex min-w-0 flex-1 flex-col justify-center p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="truncate text-2xl font-black tracking-tight text-slate-950"><?= htmlspecialchars($name) ?></h1>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-700 ring-1 ring-slate-200"><?= htmlspecialchars($typeLabel) ?></span>
                    </div>
                    <?php if ($hasContact): ?>
                        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-bold text-slate-700">
                            <?php if ($address !== ''): ?>
                                <p class="flex items-center gap-1.5"><i data-lucide="map-pin" class="h-4 w-4 shrink-0 text-slate-500"></i><span><?= htmlspecialchars(str_replace(["\r", "\n"], ' ', $address)) ?></span></p>
                            <?php endif; ?>
                            <?php if ($phones): ?>
                                <?php if ($address !== ''): ?><span class="text-slate-400">.</span><?php endif; ?>
                                <p class="flex items-center gap-1.5"><i data-lucide="phone" class="h-4 w-4 shrink-0 text-slate-500"></i><span><?= htmlspecialchars(implode(' / ', $phones)) ?></span></p>
                            <?php endif; ?>
                            <?php if ($email !== ''): ?>
                                <?php if ($address !== '' || $phones): ?><span class="text-slate-400">.</span><?php endif; ?>
                                <p class="flex items-center gap-1.5"><i data-lucide="mail" class="h-4 w-4 shrink-0 text-slate-500"></i><a href="mailto:<?= htmlspecialchars($email) ?>" class="break-all text-slate-900 hover:text-slate-700"><?= htmlspecialchars($email) ?></a></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php else: ?>
        <section class="mb-6">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-3xl font-black tracking-tight text-slate-950"><?= htmlspecialchars($name) ?></h1>
                <span class="rounded-full bg-white px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-700 ring-1 ring-slate-200"><?= htmlspecialchars($typeLabel) ?></span>
            </div>
            <?php if ($hasContact): ?>
                <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-bold text-slate-700">
                    <?php if ($address !== ''): ?>
                        <p class="flex items-center gap-1.5"><i data-lucide="map-pin" class="h-4 w-4 shrink-0 text-slate-500"></i><span><?= htmlspecialchars(str_replace(["\r", "\n"], ' ', $address)) ?></span></p>
                    <?php endif; ?>
                    <?php if ($phones): ?>
                        <?php if ($address !== ''): ?><span class="text-slate-400">.</span><?php endif; ?>
                        <p class="flex items-center gap-1.5"><i data-lucide="phone" class="h-4 w-4 shrink-0 text-slate-500"></i><span><?= htmlspecialchars(implode(' / ', $phones)) ?></span></p>
                    <?php endif; ?>
                    <?php if ($email !== ''): ?>
                        <?php if ($address !== '' || $phones): ?><span class="text-slate-400">.</span><?php endif; ?>
                        <p class="flex items-center gap-1.5"><i data-lucide="mail" class="h-4 w-4 shrink-0 text-slate-500"></i><a href="mailto:<?= htmlspecialchars($email) ?>" class="break-all text-slate-900 hover:text-slate-700"><?= htmlspecialchars($email) ?></a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($company && !$isMember): ?>
    <section class="mb-6" id="connectWidget">
        <?php if (!$connectFromCompanyId): ?>
        <?php elseif ($connectionStatus === 'accepted'): ?>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700">
                <i data-lucide="check-circle-2" class="h-3.5 w-3.5"></i> Connected on Centryk
            </span>
        <?php elseif ($connectionStatus === 'pending_out'): ?>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700">
                <i data-lucide="clock" class="h-3.5 w-3.5"></i> Connect request sent
            </span>
        <?php elseif ($connectionStatus === 'pending_in'): ?>
            <a href="connections.php?company_id=<?= (int)$connectFromCompanyId ?>" class="inline-flex items-center gap-1.5 rounded-full border border-violet-200 bg-violet-50 px-3 py-1.5 text-xs font-bold text-violet-700 hover:bg-violet-100">
                <i data-lucide="handshake" class="h-3.5 w-3.5"></i> <?= htmlspecialchars($name) ?> wants to connect &mdash; respond
            </a>
        <?php else: ?>
            <button id="connectBtn" data-from="<?= (int)$connectFromCompanyId ?>" data-to="<?= (int)$company['id'] ?>"
                    class="inline-flex items-center gap-1.5 rounded-full border border-violet-200 bg-violet-50 px-3 py-1.5 text-xs font-bold text-violet-700 transition hover:bg-violet-100">
                <i data-lucide="handshake" class="h-3.5 w-3.5"></i> Connect with <?= htmlspecialchars($name) ?>
            </button>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section>
        <?php if ($listings): ?>
            <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-5">
                <?php foreach ($listings as $item): ?>
                    <?php $itemImage = $onePayImageUrl($item['image_url'] ?? '', $item['source_item_id'] ?? null); ?>
                    <article class="store-item cursor-pointer overflow-hidden rounded-2xl bg-white shadow-sm transition hover:ring-1 hover:ring-slate-200 hover:shadow-md"
                             tabindex="0"
                             role="button"
                             data-item-id="<?= (int)($item['source_item_id'] ?? 0) ?>"
                             data-title="<?= htmlspecialchars((string)($item['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                             data-price="<?= htmlspecialchars((string)($item['price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                             data-sku="<?= htmlspecialchars((string)($item['sku'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                             data-summary="<?= htmlspecialchars((string)($item['summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                             data-image="<?= htmlspecialchars($itemImage, ENT_QUOTES, 'UTF-8') ?>"
                             data-store="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                             data-type="<?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>"
                             data-search="<?= htmlspecialchars(strtolower(trim(($item['title'] ?? '') . ' ' . ($item['sku'] ?? '') . ' ' . ($item['summary'] ?? '') . ' ' . $name . ' ' . $typeLabel))) ?>">
                        <?php if ($itemImage !== ''): ?>
                            <div class="aspect-[4/3] overflow-hidden bg-slate-100">
                                <img src="<?= htmlspecialchars($itemImage) ?>" alt="" class="h-full w-full object-cover">
                            </div>
                        <?php else: ?>
                            <div class="flex aspect-[4/3] items-center justify-center bg-slate-100">
                                <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-violet-100 text-violet-700">
                                    <i data-lucide="package" class="h-8 w-8"></i>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="p-4">
                            <?php if (trim((string)($item['price'] ?? '')) !== ''): ?>
                                <p class="mb-2 text-sm font-black text-slate-900"><?= htmlspecialchars((string)$item['price']) ?></p>
                            <?php endif; ?>
                            <h3 class="text-sm font-black text-slate-900"><?= htmlspecialchars((string)$item['title']) ?></h3>
                            <?php if (trim((string)($item['sku'] ?? '')) !== ''): ?>
                                <p class="mt-1 text-xs font-semibold text-slate-400"><?= htmlspecialchars((string)$item['sku']) ?></p>
                            <?php endif; ?>
                            <?php if (trim((string)($item['summary'] ?? '')) !== ''): ?>
                                <p class="mt-3 text-xs font-semibold leading-relaxed text-slate-500"><?= htmlspecialchars((string)$item['summary']) ?></p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-5 py-10 text-center">
                <p class="text-sm font-bold text-slate-500">No items are listed right now.</p>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>

<div id="storeItemBackdrop" class="fixed inset-0 z-40 hidden bg-slate-950/25 opacity-0 transition-opacity duration-200"></div>
<aside id="storeItemPanel" class="fixed left-0 top-0 z-50 flex h-dvh w-[min(92vw,420px)] -translate-x-full flex-col bg-white shadow-2xl shadow-slate-950/20 transition-transform duration-300" aria-hidden="true">
    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <div class="min-w-0">
            <p id="panelStoreName" class="truncate text-[10px] font-black uppercase tracking-[0.18em] text-violet-600"></p>
            <h2 id="panelItemTitle" class="truncate text-lg font-black tracking-tight text-slate-950"></h2>
        </div>
        <button id="panelCloseBtn" type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-950" aria-label="Close item panel">
            <i data-lucide="x" class="h-4.5 w-4.5"></i>
        </button>
    </div>
    <div class="flex-1 overflow-y-auto p-4">
        <div id="panelImageWrap" class="mb-4 hidden overflow-hidden rounded-2xl bg-slate-100">
            <img id="panelItemImage" src="" alt="" class="aspect-[4/3] h-full w-full object-cover">
        </div>
        <div id="panelIconWrap" class="mb-4 flex aspect-[4/3] items-center justify-center rounded-2xl bg-violet-50 text-violet-700">
            <i data-lucide="package" class="h-12 w-12"></i>
        </div>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p id="panelItemPrice" class="text-2xl font-black text-slate-950"></p>
            <span id="panelItemType" class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-600"></span>
        </div>

        <dl class="space-y-3 text-sm">
            <div id="panelSkuRow" class="hidden">
                <dt class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">SKU</dt>
                <dd id="panelItemSku" class="mt-1 font-bold text-slate-700"></dd>
            </div>
            <div id="panelSummaryRow" class="hidden">
                <dt class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Details</dt>
                <dd id="panelItemSummary" class="mt-1 whitespace-pre-line font-semibold leading-relaxed text-slate-600"></dd>
            </div>
        </dl>
    </div>
    <div class="border-t border-slate-200 bg-white p-4">
        <label for="panelQuantity" class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Quantity</label>
        <div class="mb-3 flex items-center gap-2">
            <input id="panelQuantity" type="number" min="1" value="1" class="h-11 w-24 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-bold text-slate-900 outline-none focus:border-violet-500">
            <button id="panelCartBtn" type="button" class="flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-violet-600 px-4 text-sm font-black text-white transition hover:bg-violet-700">
                <i data-lucide="shopping-cart" class="h-4.5 w-4.5"></i>
                <span id="panelCartBtnText">Add to Cart</span>
            </button>
        </div>
    </div>
</aside>

<div id="storeCartBackdrop" class="fixed inset-0 z-40 hidden bg-slate-950/25 opacity-0 transition-opacity duration-200"></div>
<aside id="storeCartPanel" class="fixed right-0 top-0 z-50 flex h-dvh w-[min(92vw,440px)] translate-x-full flex-col bg-white shadow-2xl shadow-slate-950/20 transition-transform duration-300" aria-hidden="true">
    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-600">Centryk Store</p>
            <h2 class="text-lg font-black tracking-tight text-slate-950">Cart</h2>
        </div>
        <button id="cartCloseBtn" type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-950" aria-label="Close cart">
            <i data-lucide="x" class="h-4.5 w-4.5"></i>
        </button>
    </div>
    <div id="cartEmptyState" class="flex flex-1 flex-col items-center justify-center px-6 text-center">
        <span class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
            <i data-lucide="shopping-cart" class="h-7 w-7"></i>
        </span>
        <p class="text-sm font-black text-slate-800">Your cart is empty</p>
        <p class="mt-1 text-xs font-semibold leading-relaxed text-slate-500">Open an item and add it to cart.</p>
    </div>
    <div id="cartContent" class="hidden flex-1 overflow-y-auto">
        <div id="cartItemList" class="divide-y divide-slate-100"></div>
    </div>
    <div id="cartSummary" class="hidden border-t border-slate-200 bg-slate-50 p-4">
        <div class="mb-3 flex items-center justify-between">
            <span class="text-xs font-black uppercase tracking-[0.16em] text-slate-400">Grand Total</span>
            <span id="cartGrandTotal" class="text-2xl font-black text-slate-950">$0.00</span>
        </div>
        <button type="button" class="flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-black text-white transition hover:bg-slate-800">
            <i data-lucide="credit-card" class="h-4.5 w-4.5"></i>
            Checkout
        </button>
    </div>
</aside>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
if (window.lucide) { lucide.createIcons(); }

document.getElementById('connectBtn')?.addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    fetch('api/connections/send.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ company_id: parseInt(btn.dataset.from, 10), target_company_id: parseInt(btn.dataset.to, 10) }),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            document.getElementById('connectWidget').innerHTML =
                '<span class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-700">' +
                '<i data-lucide="clock" class="h-3.5 w-3.5"></i> Connect request sent</span>';
            if (window.lucide) { lucide.createIcons(); }
        } else {
            btn.disabled = false;
            alert(data.message || 'Failed to send request.');
        }
    })
    .catch(function () { btn.disabled = false; alert('Network error.'); });
});

(function () {
    var search = document.getElementById('storeHeaderSearch');
    var cartCount = document.getElementById('storeCartCount');
    var cartButton = document.getElementById('storeCartBtn');
    var cartPanel = document.getElementById('storeCartPanel');
    var cartBackdrop = document.getElementById('storeCartBackdrop');
    var cartCloseBtn = document.getElementById('cartCloseBtn');
    var cartEmptyState = document.getElementById('cartEmptyState');
    var cartContent = document.getElementById('cartContent');
    var cartItemList = document.getElementById('cartItemList');
    var cartSummary = document.getElementById('cartSummary');
    var cartGrandTotal = document.getElementById('cartGrandTotal');
    var panel = document.getElementById('storeItemPanel');
    var backdrop = document.getElementById('storeItemBackdrop');
    var closeBtn = document.getElementById('panelCloseBtn');
    var qtyInput = document.getElementById('panelQuantity');
    var panelCartBtn = document.getElementById('panelCartBtn');
    var panelCartText = document.getElementById('panelCartBtnText');
    var activeItem = null;
    var storageKey = 'centrykStoreCart';

    function getCart() {
        try {
            var parsed = JSON.parse(localStorage.getItem(storageKey) || '{}');
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function saveCart(cart) {
        try { localStorage.setItem(storageKey, JSON.stringify(cart)); } catch (e) {}
    }

    function cartSize(cart) {
        return Object.keys(cart).reduce(function (total, key) {
            return total + Math.max(1, parseInt(cart[key].quantity || 1, 10));
        }, 0);
    }

    function syncCartCount() {
        if (cartCount) {
            cartCount.textContent = String(cartSize(getCart()));
        }
    }

    function priceNumber(value) {
        var cleaned = String(value || '').replace(/[^0-9.-]/g, '');
        var parsed = parseFloat(cleaned);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function money(value) {
        return '$' + Number(value || 0).toFixed(2);
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch];
        });
    }

    function setCartButtonOpen(open) {
        if (!cartButton) { return; }
        cartButton.classList.toggle('border-emerald-300', open);
        cartButton.classList.toggle('bg-emerald-50', open);
        cartButton.classList.toggle('text-emerald-700', open);
        cartButton.classList.toggle('border-slate-200', !open);
        cartButton.classList.toggle('bg-white', !open);
        cartButton.classList.toggle('text-slate-600', !open);
    }

    function renderCart() {
        var cart = getCart();
        var keys = Object.keys(cart);
        var grandTotal = 0;
        if (cartEmptyState) { cartEmptyState.classList.toggle('hidden', keys.length > 0); }
        if (cartContent) { cartContent.classList.toggle('hidden', keys.length === 0); }
        if (cartSummary) { cartSummary.classList.toggle('hidden', keys.length === 0); }
        if (cartItemList) {
            cartItemList.innerHTML = keys.map(function (key) {
                var item = cart[key] || {};
                var quantity = Math.max(1, parseInt(item.quantity || 1, 10));
                var unitPrice = priceNumber(item.price);
                var lineTotal = unitPrice * quantity;
                grandTotal += lineTotal;
                var image = item.image ? '<img src="' + escapeHtml(item.image) + '" alt="" class="h-full w-full object-cover">' : '<i data-lucide="package" class="h-5 w-5"></i>';
                return '' +
                    '<div class="cart-row px-4 py-3" data-cart-id="' + escapeHtml(key) + '">' +
                        '<div class="flex gap-3">' +
                            '<div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-violet-50 text-violet-700">' + image + '</div>' +
                            '<div class="min-w-0 flex-1">' +
                                '<div class="flex items-start justify-between gap-3">' +
                                    '<div class="min-w-0">' +
                                        '<p class="truncate text-sm font-black text-slate-950">' + escapeHtml(item.title) + '</p>' +
                                        '<p class="mt-0.5 truncate text-[11px] font-bold text-slate-400">' + escapeHtml(item.store) + '</p>' +
                                    '</div>' +
                                    '<button type="button" class="cart-remove flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-rose-50 hover:text-rose-600" aria-label="Remove item">' +
                                        '<i data-lucide="trash-2" class="h-4 w-4"></i>' +
                                    '</button>' +
                                '</div>' +
                                '<div class="mt-3 grid grid-cols-[auto_1fr] items-end gap-3">' +
                                    '<label class="block">' +
                                        '<span class="mb-1 block text-[9px] font-black uppercase tracking-[0.14em] text-slate-400">Qty</span>' +
                                        '<input type="number" min="1" value="' + quantity + '" class="cart-qty h-9 w-20 rounded-lg border border-slate-200 bg-slate-50 px-2 text-sm font-bold text-slate-900 outline-none focus:border-violet-500">' +
                                    '</label>' +
                                    '<div class="text-right">' +
                                        '<p class="text-[11px] font-bold text-slate-400">' + money(unitPrice) + ' each</p>' +
                                        '<p class="text-sm font-black text-slate-950">' + money(lineTotal) + '</p>' +
                                    '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
            }).join('');
        }
        if (cartGrandTotal) { cartGrandTotal.textContent = money(grandTotal); }
        if (window.lucide) { lucide.createIcons(); }
    }

    function openCart() {
        if (!cartPanel || !cartBackdrop) { return; }
        renderCart();
        cartBackdrop.classList.remove('hidden');
        cartPanel.setAttribute('aria-hidden', 'false');
        setCartButtonOpen(true);
        requestAnimationFrame(function () {
            cartBackdrop.classList.remove('opacity-0');
            cartBackdrop.classList.add('opacity-100');
            cartPanel.classList.remove('translate-x-full');
        });
    }

    function closeCart() {
        if (!cartPanel || !cartBackdrop) { return; }
        cartPanel.classList.add('translate-x-full');
        cartPanel.setAttribute('aria-hidden', 'true');
        cartBackdrop.classList.remove('opacity-100');
        cartBackdrop.classList.add('opacity-0');
        setCartButtonOpen(false);
        setTimeout(function () {
            cartBackdrop.classList.add('hidden');
        }, 220);
    }

    function isInCart(itemId) {
        var cart = getCart();
        return !!cart[String(itemId)];
    }

    function setPanelCartText(text) {
        if (panelCartText) { panelCartText.textContent = text; }
    }

    function syncPanelCartButton() {
        if (!activeItem || !panelCartBtn) { return; }
        var inCart = isInCart(activeItem.id);
        panelCartBtn.classList.toggle('bg-violet-600', !inCart);
        panelCartBtn.classList.toggle('hover:bg-violet-700', !inCart);
        panelCartBtn.classList.toggle('bg-emerald-600', inCart);
        panelCartBtn.classList.toggle('hover:bg-rose-600', inCart);
        setPanelCartText(inCart ? 'Item in Cart' : 'Add to Cart');
    }

    function fillText(id, value) {
        var el = document.getElementById(id);
        if (el) { el.textContent = value || ''; }
    }

    function toggleRow(rowId, value) {
        var row = document.getElementById(rowId);
        if (row) { row.classList.toggle('hidden', !value); }
    }

    function openPanel(card) {
        var itemId = parseInt(card.dataset.itemId || '0', 10);
        if (!itemId || !panel || !backdrop) { return; }
        activeItem = {
            id: itemId,
            title: card.dataset.title || '',
            price: card.dataset.price || '',
            sku: card.dataset.sku || '',
            summary: card.dataset.summary || '',
            image: card.dataset.image || '',
            store: card.dataset.store || '',
            type: card.dataset.type || ''
        };

        fillText('panelStoreName', activeItem.store);
        fillText('panelItemTitle', activeItem.title);
        fillText('panelItemPrice', activeItem.price);
        fillText('panelItemType', activeItem.type);
        fillText('panelItemSku', activeItem.sku);
        fillText('panelItemSummary', activeItem.summary);
        toggleRow('panelSkuRow', activeItem.sku);
        toggleRow('panelSummaryRow', activeItem.summary);
        if (qtyInput) { qtyInput.value = '1'; }

        var img = document.getElementById('panelItemImage');
        var imageWrap = document.getElementById('panelImageWrap');
        var iconWrap = document.getElementById('panelIconWrap');
        if (img && imageWrap && iconWrap) {
            if (activeItem.image) {
                img.src = activeItem.image;
                imageWrap.classList.remove('hidden');
                iconWrap.classList.add('hidden');
            } else {
                img.removeAttribute('src');
                imageWrap.classList.add('hidden');
                iconWrap.classList.remove('hidden');
            }
        }

        syncPanelCartButton();
        backdrop.classList.remove('hidden');
        panel.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(function () {
            backdrop.classList.remove('opacity-0');
            backdrop.classList.add('opacity-100');
            panel.classList.remove('-translate-x-full');
        });
    }

    function closePanel() {
        if (!panel || !backdrop) { return; }
        panel.classList.add('-translate-x-full');
        panel.setAttribute('aria-hidden', 'true');
        backdrop.classList.remove('opacity-100');
        backdrop.classList.add('opacity-0');
        setTimeout(function () {
            backdrop.classList.add('hidden');
        }, 220);
    }

    function filterItems() {
        if (!search) { return; }
        var q = search.value.trim().toLowerCase();
        document.querySelectorAll('.store-item').forEach(function (item) {
            var match = !q || (item.dataset.search || '').indexOf(q) !== -1;
            item.classList.toggle('hidden', !match);
        });
        document.querySelectorAll('.store-section').forEach(function (section) {
            var visible = Array.prototype.some.call(section.querySelectorAll('.store-item'), function (item) {
                return !item.classList.contains('hidden');
            });
            section.classList.toggle('hidden', !visible);
        });
    }

    document.querySelectorAll('.store-item').forEach(function (card) {
        card.addEventListener('click', function () { openPanel(card); });
        card.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openPanel(card);
            }
        });
    });

    if (search) { search.addEventListener('input', filterItems); }
    if (backdrop) { backdrop.addEventListener('click', closePanel); }
    if (closeBtn) { closeBtn.addEventListener('click', closePanel); }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closePanel(); }
    });

    if (panelCartBtn) {
        panelCartBtn.addEventListener('mouseenter', function () {
            if (activeItem && isInCart(activeItem.id)) {
                setPanelCartText('Remove from Cart');
            }
        });
        panelCartBtn.addEventListener('mouseleave', syncPanelCartButton);
        panelCartBtn.addEventListener('click', function () {
            if (!activeItem) { return; }
            var cart = getCart();
            var key = String(activeItem.id);
            if (cart[key]) {
                delete cart[key];
            } else {
                cart[key] = {
                    id: activeItem.id,
                    title: activeItem.title,
                    price: activeItem.price,
                    sku: activeItem.sku,
                    image: activeItem.image,
                    store: activeItem.store,
                    quantity: Math.max(1, parseInt((qtyInput && qtyInput.value) || '1', 10))
                };
            }
            saveCart(cart);
            syncCartCount();
            renderCart();
            syncPanelCartButton();
        });
    }

    if (cartButton) { cartButton.addEventListener('click', openCart); }
    if (cartBackdrop) { cartBackdrop.addEventListener('click', closeCart); }
    if (cartCloseBtn) { cartCloseBtn.addEventListener('click', closeCart); }
    if (cartItemList) {
        cartItemList.addEventListener('click', function (event) {
            var remove = event.target.closest('.cart-remove');
            if (!remove) { return; }
            var row = remove.closest('.cart-row');
            if (!row) { return; }
            var cart = getCart();
            delete cart[row.dataset.cartId];
            saveCart(cart);
            syncCartCount();
            renderCart();
            syncPanelCartButton();
        });
        cartItemList.addEventListener('change', function (event) {
            if (!event.target.classList.contains('cart-qty')) { return; }
            var row = event.target.closest('.cart-row');
            if (!row) { return; }
            var cart = getCart();
            if (cart[row.dataset.cartId]) {
                cart[row.dataset.cartId].quantity = Math.max(1, parseInt(event.target.value || '1', 10));
                saveCart(cart);
                syncCartCount();
                renderCart();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeCart(); }
    });

    syncCartCount();
    renderCart();
})();
</script>
</body>
</html>
