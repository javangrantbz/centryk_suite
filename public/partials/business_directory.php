<?php
if (!isset($directoryBusinesses) || !is_array($directoryBusinesses)) {
    $directoryBusinesses = [];
    try {
        $directoryStmt = DB::pdo()->query("
            SELECT uuid, name, address, phone, phone2, phone3, business_type
            FROM companies
            WHERE status = 'active'
              AND directory_visible = 1
            ORDER BY name ASC
        ");
        $directoryBusinesses = $directoryStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $directoryBusinesses = [];
    }
}

$directoryUserKey = isset($user['id']) ? 'centrykDirectorySeen_' . (int)$user['id'] : '';
$directoryAutoOpen = $directoryUserKey !== '';
$directoryTypeLabels = [
    'school' => 'Education',
    'gym' => 'Fitness',
    'clinic' => 'Health',
    'salon' => 'Salon / Spa',
    'services' => 'Services',
    'property' => 'Property / Rentals',
    'retail' => 'Retail / Shop',
    'restaurant' => 'Restaurant / Food',
    'ice_cream' => 'Ice Cream / Dessert',
    'meat_shop' => 'Butcher / Meat Shop',
    'cafeteria' => 'Cafeteria / Food Service',
    'auto_sales' => 'Auto Sales',
    'auto_rental' => 'Auto Rental',
    'other' => 'Business',
];
?>
<button id="directoryToggle"
        type="button"
        class="fixed right-0 top-1/2 z-50 flex -translate-y-1/2 items-center gap-2 rounded-l-lg bg-slate-950 px-2.5 py-3 text-[11px] font-black uppercase tracking-[0.12em] text-white shadow-xl shadow-slate-900/25 transition hover:bg-slate-800"
        aria-controls="directoryPanel"
        aria-expanded="false">
    <span class="[writing-mode:vertical-rl] rotate-180">Directory</span>
</button>

<div id="directoryBackdrop" class="fixed inset-0 z-50 hidden bg-slate-950/30 backdrop-blur-[1px] md:hidden"></div>

<aside id="directoryPanel"
       class="fixed right-0 top-0 z-[55] flex h-screen w-[min(86vw,300px)] translate-x-full flex-col border-l border-slate-200 bg-white shadow-2xl shadow-slate-950/20 transition-transform duration-300"
       aria-label="Centryk Directory">
    <div class="flex items-start justify-between gap-2 border-b border-slate-200 px-3 py-2.5">
        <div>
            <?php if ($directoryAutoOpen): ?>
                <p id="directoryNewFeature" class="mb-1 inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.16em] text-blue-700">New Feature</p>
            <?php endif; ?>
            <p class="text-[9px] font-black uppercase tracking-[0.16em] text-blue-600">Centryk Directory</p>
            <h2 class="mt-0.5 text-base font-black tracking-tight text-slate-950">Businesses</h2>
        </div>
        <button id="directoryClose"
                type="button"
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                aria-label="Hide directory"
                title="Hide directory">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <div class="border-b border-slate-200 px-3 py-2.5">
        <label for="directorySearch" class="sr-only">Search Centryk Directory</label>
        <input id="directorySearch"
               type="search"
               autocomplete="off"
               placeholder="Search businesses"
               class="w-full rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100">
    </div>

    <div class="flex-1 overflow-y-auto">
        <?php if ($directoryBusinesses): ?>
            <ul id="directoryList" class="divide-y divide-slate-100">
                <?php foreach ($directoryBusinesses as $business): ?>
                    <?php
                        $uuid = trim((string)($business['uuid'] ?? ''));
                        $name = trim((string)($business['name'] ?? ''));
                        $address = trim((string)($business['address'] ?? ''));
                        $addressLine = preg_replace('/\s+/', ' ', $address);
                        $addressLine = trim((string)$addressLine);
                        $phones = array_values(array_filter([
                            trim((string)($business['phone'] ?? '')),
                            trim((string)($business['phone2'] ?? '')),
                            trim((string)($business['phone3'] ?? '')),
                        ], static function (string $phone): bool {
                            return $phone !== '';
                        }));
                        $typeKey = trim((string)($business['business_type'] ?? ''));
                        $typeLabel = $directoryTypeLabels[$typeKey] ?? ($typeKey !== '' ? ucwords(str_replace('_', ' ', $typeKey)) : '');
                        $searchText = trim($name . ' ' . $typeLabel . ' ' . $address . ' ' . implode(' ', $phones));
                        $storeUrl = 'store.php' . ($uuid !== '' ? '?company_uuid=' . urlencode($uuid) : '');
                    ?>
                    <li class="directory-row" data-search="<?= htmlspecialchars(strtolower($searchText)) ?>">
                        <a href="<?= htmlspecialchars($storeUrl) ?>" class="block px-3 py-2 transition hover:bg-slate-50">
                            <div class="min-w-0">
                                <h3 class="truncate text-xs font-black tracking-tight text-slate-950">
                                    <?= htmlspecialchars($name) ?><?php if ($typeLabel !== ''): ?><span class="font-semibold text-slate-400"> . <?= htmlspecialchars($typeLabel) ?></span><?php endif; ?>
                                </h3>
                                <div class="mt-0.5 space-y-0.5 text-[11px] font-semibold leading-snug text-slate-500">
                                    <?php if ($addressLine !== ''): ?>
                                        <p class="truncate"><?= htmlspecialchars($addressLine) ?></p>
                                    <?php endif; ?>
                                    <?php if ($phones): ?>
                                        <p><?= htmlspecialchars(implode(' / ', $phones)) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p id="directoryNoResults" class="hidden px-4 py-8 text-center text-sm font-bold text-slate-500">No matching businesses.</p>
        <?php else: ?>
            <div class="px-4 py-8 text-center">
                <p class="text-sm font-bold text-slate-500">No businesses are listed yet.</p>
            </div>
        <?php endif; ?>
    </div>
</aside>

<script>
(function () {
    var panel = document.getElementById('directoryPanel');
    var toggle = document.getElementById('directoryToggle');
    var closeBtn = document.getElementById('directoryClose');
    var backdrop = document.getElementById('directoryBackdrop');
    var search = document.getElementById('directorySearch');
    var noResults = document.getElementById('directoryNoResults');
    var newFeature = document.getElementById('directoryNewFeature');
    var seenKey = <?= json_encode($directoryUserKey) ?>;
    if (!panel || !toggle || !closeBtn || !backdrop) return;

    function setOpen(open) {
        panel.classList.toggle('translate-x-full', !open);
        backdrop.classList.toggle('hidden', !open);
        backdrop.classList.toggle('md:hidden', open);
        toggle.classList.toggle('hidden', open);
        toggle.classList.toggle('flex', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    toggle.addEventListener('click', function () { setOpen(true); });
    closeBtn.addEventListener('click', function () { setOpen(false); });
    backdrop.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') setOpen(false);
    });
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            var shown = 0;
            document.querySelectorAll('.directory-row').forEach(function (row) {
                var match = q === '' || (row.dataset.search || '').indexOf(q) !== -1;
                row.classList.toggle('hidden', !match);
                if (match) shown++;
            });
            if (noResults) noResults.classList.toggle('hidden', shown !== 0);
        });
    }

    var shouldOpen = false;
    if (seenKey) {
        try {
            shouldOpen = localStorage.getItem(seenKey) !== '1';
            localStorage.setItem(seenKey, '1');
        } catch (e) {
            shouldOpen = true;
        }
    }
    if (!shouldOpen && newFeature) {
        newFeature.classList.add('hidden');
    }
    setOpen(shouldOpen);
}());
</script>
