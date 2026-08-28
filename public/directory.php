<?php
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/core/AppLinks.php';

$pdo = DB::pdo();

$typeLabels = [
    'school' => 'Education', 'gym' => 'Fitness', 'clinic' => 'Health', 'salon' => 'Salon / Spa',
    'grocery' => 'Groceries', 'services' => 'Services', 'property' => 'Property / Rentals',
    'retail' => 'Retail / Shop', 'restaurant' => 'Restaurant / Food', 'ice_cream' => 'Ice Cream / Dessert',
    'meat_shop' => 'Butcher / Meat Shop', 'cafeteria' => 'Cafeteria / Food Service',
    'auto_sales' => 'Auto Sales', 'auto_rental' => 'Auto Rental', 'other' => 'Business',
];
$typeLabel = static function (?string $t) use ($typeLabels): string {
    $t = trim((string)$t);
    if ($t === '') return 'Business';
    return $typeLabels[$t] ?? ucwords(str_replace('_', ' ', $t));
};
$initials = static function (string $name): string {
    $p = preg_split('/\s+/', trim($name));
    return strtoupper(($p[0][0] ?? '?') . (count($p) > 1 ? ($p[count($p) - 1][0] ?? '') : ''));
};

$businesses = [];
try {
    $businesses = $pdo->query("
        SELECT uuid, store_slug, name, business_type, address, phone, email, logo
        FROM companies
        WHERE status = 'active' AND directory_visible = 1
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $businesses = [];
}

$jobBoardUrl = AppLinks::jobBoard();

// One batched, client-side lookup of which listed companies have open roles, so
// a "Jobs" button only appears where it leads somewhere. Empty if MyPay isn't
// registered.
$openingsBatchUrl = '';
$_mpBase = AppLinks::base('mypay');
if ($_mpBase !== '' && $businesses) {
    $_uuids = array_values(array_filter(array_map(static function ($b) {
        return trim((string)($b['uuid'] ?? ''));
    }, $businesses)));
    if ($_uuids) {
        $openingsBatchUrl = $_mpBase . '/api/public/company-openings.php?company=' . implode(',', $_uuids);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Business Directory — Centryk</title>
    <meta name="description" content="Every business on Centryk — visit their store or see who's hiring.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-900 antialiased">
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>

<nav class="border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto flex max-w-5xl items-center gap-4 px-5 py-2.5">
        <a href="index.php"><img src="assets/centryk_logo.png" alt="Centryk" class="h-9 w-auto"></a>
        <div class="flex-1"></div>
        <a href="store.php" class="px-3 py-1.5 text-sm font-semibold text-slate-500 transition hover:text-slate-900">Store</a>
        <?php if ($jobBoardUrl !== ''): ?>
        <a href="<?= htmlspecialchars($jobBoardUrl) ?>" class="px-3 py-1.5 text-sm font-semibold text-slate-500 transition hover:text-slate-900">Jobs</a>
        <?php endif; ?>
        <a href="login.php" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white transition hover:bg-slate-700">Sign In</a>
    </div>
</nav>

<div class="mx-auto max-w-5xl px-4 py-8">
    <header class="mb-5">
        <h1 class="text-2xl font-black tracking-tight text-slate-950">Business Directory</h1>
        <p class="mt-1 text-sm font-semibold text-slate-500">
            <?= count($businesses) ?> <?= count($businesses) === 1 ? 'business' : 'businesses' ?> on Centryk. Visit a store or see who's hiring.
        </p>
    </header>

    <?php if ($businesses): ?>
    <div class="mb-4">
        <input id="dirSearch" type="search" placeholder="Search by name, type, or location…"
               class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200">
    </div>

    <div id="dirGrid" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($businesses as $b): ?>
            <?php
                $logo = trim((string)($b['logo'] ?? ''));
                // Relative links so the page works whether it's served at
                // /directory or /public/directory.php.
                $storeHref = 'store.php?company_uuid=' . urlencode((string)$b['uuid']);
                $jobsHref  = $jobBoardUrl !== '' ? ('jobs.php?company=' . urlencode((string)$b['uuid'])) : '';
                $addr = trim((string)preg_replace('/\s+/', ' ', (string)($b['address'] ?? '')));
                $hay = strtolower(trim($b['name'] . ' ' . $typeLabel($b['business_type']) . ' ' . $addr));
            ?>
            <article class="dir-card flex flex-col rounded-xl border border-slate-200 bg-white p-3 shadow-sm" data-search="<?= htmlspecialchars($hay, ENT_QUOTES) ?>">
                <div class="flex items-center gap-2.5">
                    <?php if ($logo !== ''): ?>
                        <img src="<?= htmlspecialchars($logo) ?>" alt="" class="h-9 w-9 shrink-0 rounded-lg object-cover ring-1 ring-slate-200">
                    <?php else: ?>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-[11px] font-bold text-slate-500 ring-1 ring-slate-200"><?= htmlspecialchars($initials((string)$b['name'])) ?></span>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <h2 class="truncate text-sm font-black text-slate-900">
                            <a href="<?= htmlspecialchars($storeHref) ?>" class="hover:text-violet-700 hover:underline"><?= htmlspecialchars($b['name']) ?></a>
                        </h2>
                        <p class="text-[11px] font-bold uppercase tracking-wide text-slate-400"><?= htmlspecialchars($typeLabel($b['business_type'])) ?></p>
                    </div>
                </div>
                <?php if ($addr !== '' || !empty($b['phone'])): ?>
                <p class="mt-2 line-clamp-2 text-xs font-medium text-slate-500">
                    <?= htmlspecialchars($addr) ?><?php if ($addr !== '' && !empty($b['phone'])): ?> &middot; <?php endif; ?><?= htmlspecialchars((string)($b['phone'] ?? '')) ?>
                </p>
                <?php endif; ?>
                <div class="mt-3 flex items-center gap-2 border-t border-slate-100 pt-2.5">
                    <a href="<?= htmlspecialchars($storeHref) ?>" class="flex-1 rounded-lg bg-violet-600 px-3 py-1.5 text-center text-xs font-black text-white transition hover:bg-violet-700">Store</a>
                    <?php if ($jobsHref !== ''): ?>
                    <a href="<?= htmlspecialchars($jobsHref) ?>" data-uuid="<?= htmlspecialchars((string)$b['uuid'], ENT_QUOTES) ?>" hidden
                       class="dir-jobs-link flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-center text-xs font-black text-slate-600 transition hover:border-orange-400 hover:text-orange-600">Jobs</a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <p id="dirNoMatch" class="hidden py-10 text-center text-sm text-slate-400">No businesses match your search.</p>
    <?php else: ?>
    <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
        <p class="text-sm font-bold text-slate-500">No businesses are listed yet.</p>
    </div>
    <?php endif; ?>

    <p class="mt-8 text-center text-xs text-slate-400">Powered by <span class="font-semibold text-slate-500">Centryk</span></p>
</div>

<style>.line-clamp-2 { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }</style>

<?php if ($businesses): ?>
<script>
(function () {
    // Reveal a company's "Jobs" button only if it actually has open roles.
    <?php if ($openingsBatchUrl !== ''): ?>
    var jobsLinks = document.querySelectorAll('.dir-jobs-link');
    if (jobsLinks.length) {
        fetch(<?= json_encode($openingsBatchUrl) ?>)
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                var counts = d && d.success && d.counts ? d.counts : {};
                jobsLinks.forEach(function (a) {
                    if ((parseInt(counts[a.dataset.uuid], 10) || 0) > 0) { a.hidden = false; }
                });
            })
            .catch(function () {});
    }
    <?php endif; ?>

    var input = document.getElementById('dirSearch');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.dir-card'));
    var grid  = document.getElementById('dirGrid');
    var none  = document.getElementById('dirNoMatch');
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase(), shown = 0;
        cards.forEach(function (c) {
            var hit = q === '' || c.dataset.search.indexOf(q) !== -1;
            c.classList.toggle('hidden', !hit);
            if (hit) shown++;
        });
        none.classList.toggle('hidden', shown > 0);
        grid.classList.toggle('hidden', shown === 0);
    });
})();
</script>
<?php endif; ?>
</body>
</html>
