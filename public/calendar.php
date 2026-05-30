<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();

if (!$me['authenticated']) {
    header('Location: login.php');
    exit;
}

$user = $me['user'];

// Month to render — default to current month, allow ?ym=YYYY-MM
$ym = $_GET['ym'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = date('Y-m');
}
[$year, $month] = array_map('intval', explode('-', $ym));

$firstOfMonth   = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth    = (int)date('t', $firstOfMonth);
$leadingBlanks  = (int)date('w', $firstOfMonth);   // 0 = Sunday … 6 = Saturday
$monthLabel     = date('F Y', $firstOfMonth);

$prevMonth = date('Y-m', strtotime('-1 month', $firstOfMonth));
$nextMonth = date('Y-m', strtotime('+1 month', $firstOfMonth));
$todayYm   = date('Y-m');
$todayDay  = (int)date('j');
$todayMon  = (int)date('n');
$todayYr   = (int)date('Y');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calendar — Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }
    </script>
    <style>
        [data-lucide] { display: inline-block; }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased">

<!-- Top accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500 sticky top-0 z-50"></div>

<!-- Header (matches Centryk dashboard) -->
<header class="sticky top-[3px] z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-3">

        <!-- Logo -->
        <a href="index.php" class="flex shrink-0 items-center">
            <img src="../centryk_logo.png" alt="Centryk" class="h-14 w-auto">
        </a>

        <!-- Divider -->
        <div class="h-5 w-px bg-slate-200 shrink-0"></div>

        <!-- Page title -->
        <div class="flex items-center gap-2">
            <i data-lucide="calendar" class="h-4 w-4 text-slate-400"></i>
            <span class="text-sm font-bold text-slate-700">Calendar</span>
        </div>

        <!-- Spacer -->
        <div class="flex-1"></div>

        <!-- Admin links -->
        <?php if (!empty($user['is_admin'])): ?>
        <a href="requests.php" class="hidden sm:flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
            <i data-lucide="users" class="h-3.5 w-3.5"></i>
            New Users
        </a>
        <a href="audit.php" class="hidden sm:flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
            <i data-lucide="history" class="h-3.5 w-3.5"></i>
            Audit Trail
        </a>
        <?php endif; ?>

        <!-- Waffle app switcher -->
        <?php $awAlign = 'right'; $awMode = 'links'; include __DIR__ . '/partials/app_switcher.php'; ?>

        <!-- Divider -->
        <div class="h-5 w-px bg-slate-200 shrink-0"></div>

        <!-- User dropdown -->
        <div class="relative shrink-0" id="userMenuWrapper">
            <button id="userMenuBtn" class="flex items-center gap-2.5 rounded-xl px-3 py-2 transition hover:bg-slate-100">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[12px] font-black text-slate-700">
                    <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
                </div>
                <div class="hidden text-left sm:block">
                    <p class="text-sm font-semibold text-slate-800 leading-tight"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></p>
                    <p class="text-[10px] text-slate-400 leading-tight"><?= htmlspecialchars($user['email']) ?></p>
                </div>
                <i data-lucide="chevron-down" class="h-3.5 w-3.5 text-slate-400 shrink-0"></i>
            </button>

            <div id="userMenu" class="absolute right-0 top-full mt-2 w-60 hidden rounded-2xl border border-slate-200 bg-white shadow-xl z-50 overflow-hidden">
                <div class="px-4 py-3.5 border-b border-slate-100">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-bold text-slate-900 leading-tight truncate"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></p>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.1em] <?= !empty($user['is_admin']) ? 'bg-violet-100 text-violet-600' : 'bg-slate-100 text-slate-500' ?>">
                            <?= !empty($user['is_admin']) ? 'Admin' : 'Member' ?>
                        </span>
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5 truncate"><?= htmlspecialchars($user['email']) ?></p>
                </div>
                <div class="p-2 space-y-0.5">
                    <a href="index.php" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="layout-grid" class="h-4 w-4 shrink-0"></i>
                        Dashboard
                    </a>
                    <a href="profile.php" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="user-cog" class="h-4 w-4 shrink-0"></i>
                        Manage Profile
                    </a>
                    <button id="logoutBtn" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 transition text-left">
                        <i data-lucide="log-out" class="h-4 w-4 shrink-0"></i>
                        Sign out
                    </button>
                </div>
            </div>
        </div>

    </div>
</header>

<!-- Main -->
<main class="mx-auto max-w-6xl px-6 py-10">

    <!-- Title bar -->
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Calendar</p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900"><?= htmlspecialchars($monthLabel) ?></h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="?ym=<?= urlencode($prevMonth) ?>" class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-800" title="Previous month">
                <i data-lucide="chevron-left" class="h-4 w-4"></i>
            </a>
            <a href="?ym=<?= urlencode(date('Y-m')) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                Today
            </a>
            <a href="?ym=<?= urlencode($nextMonth) ?>" class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-800" title="Next month">
                <i data-lucide="chevron-right" class="h-4 w-4"></i>
            </a>
        </div>
    </div>

    <!-- Status pill -->
    <div class="mb-4 inline-flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 py-1.5 text-[11px] font-black uppercase tracking-[0.14em] text-blue-700">
        <i data-lucide="info" class="h-3 w-3"></i>
        Early preview — events will be added soon
    </div>

    <!-- Calendar card -->
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

        <!-- Weekday header -->
        <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50">
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $w): ?>
            <div class="px-3 py-2 text-center text-[10px] font-black uppercase tracking-[0.18em] text-slate-400"><?= $w ?></div>
            <?php endforeach; ?>
        </div>

        <!-- Day grid -->
        <div class="grid grid-cols-7">
            <?php for ($i = 0; $i < $leadingBlanks; $i++): ?>
            <div class="aspect-square border-b border-r border-slate-100 bg-slate-50/50"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $isToday = ($d === $todayDay && $month === $todayMon && $year === $todayYr);
            ?>
            <div class="aspect-square border-b border-r border-slate-100 p-2 transition hover:bg-slate-50">
                <div class="flex items-center justify-between">
                    <span class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-black <?= $isToday ? 'bg-slate-900 text-white' : 'text-slate-700' ?>"><?= $d ?></span>
                </div>
            </div>
            <?php endfor; ?>

            <?php
            $totalCells   = $leadingBlanks + $daysInMonth;
            $trailing     = (7 - ($totalCells % 7)) % 7;
            for ($i = 0; $i < $trailing; $i++): ?>
            <div class="aspect-square border-b border-r border-slate-100 bg-slate-50/50"></div>
            <?php endfor; ?>
        </div>
    </div>

    <p class="mt-10 text-center text-[11px] font-bold uppercase tracking-[0.18em] text-slate-300">
        Calendar &middot; Centryk &copy; <?= date('Y') ?>
    </p>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>

<script>
(function () {
    var btn  = document.getElementById('userMenuBtn');
    var menu = document.getElementById('userMenu');
    if (btn && menu) {
        btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('hidden'); });
        document.addEventListener('click', function () { menu.classList.add('hidden'); });
    }
    var logout = document.getElementById('logoutBtn');
    if (logout) {
        logout.addEventListener('click', function () {
            fetch('api/auth/logout.php', { method: 'POST' }).finally(function () { window.location.href = 'index.php'; });
        });
    }
}());
</script>

<script src="https://unpkg.com/lucide@latest"></script>
<script>if (window.lucide) { lucide.createIcons(); }</script>

</body>
</html>
