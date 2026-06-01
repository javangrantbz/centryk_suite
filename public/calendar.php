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
$pdo  = DB::pdo();

// ── User's companies ────────────────────────────────────────────────────────
$coStmt = $pdo->prepare("
    SELECT c.id, c.uuid, c.name, cm.role
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.user_id = :uid AND cm.status = 'active' AND c.status = 'active'
    ORDER BY c.name ASC
");
$coStmt->execute(['uid' => (int)$user['id']]);
$companies = $coStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Active company (?company_id, or ?company_uuid from other apps, else first) ─
$activeCompanyId   = null;
$activeCompanyName = '';
$activeCompanyUuid = '';
$activeRole        = null;
if (!empty($companies)) {
    $requestedCid  = isset($_GET['company_id'])   ? (int)$_GET['company_id'] : 0;
    $requestedUuid = isset($_GET['company_uuid']) ? trim((string)$_GET['company_uuid']) : '';
    $picked = null;
    if ($requestedCid) {
        foreach ($companies as $c) {
            if ((int)$c['id'] === $requestedCid) { $picked = $c; break; }
        }
    }
    // Fall back to the cross-app uuid (passed by the dashboard/OnePay/MyPay).
    if (!$picked && $requestedUuid !== '') {
        foreach ($companies as $c) {
            if ((string)($c['uuid'] ?? '') === $requestedUuid) { $picked = $c; break; }
        }
    }
    if (!$picked) $picked = $companies[0];
    $activeCompanyId   = (int)$picked['id'];
    $activeCompanyName = $picked['name'];
    $activeCompanyUuid = (string)($picked['uuid'] ?? '');
    $activeRole        = $picked['role'];
}
$isAdmin = ($activeRole === 'admin');

// ── Month ───────────────────────────────────────────────────────────────────
$ym = $_GET['ym'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = date('Y-m');
}
[$year, $month] = array_map('intval', explode('-', $ym));
$firstOfMonth  = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth   = (int)date('t', $firstOfMonth);
$leadingBlanks = (int)date('w', $firstOfMonth);
$monthLabel    = date('F Y', $firstOfMonth);
$prevMonth     = date('Y-m', strtotime('-1 month', $firstOfMonth));
$nextMonth     = date('Y-m', strtotime('+1 month', $firstOfMonth));
$todayDay      = (int)date('j');
$todayMon      = (int)date('n');
$todayYr       = (int)date('Y');
$firstDate     = sprintf('%04d-%02d-01', $year, $month);
$lastDate      = date('Y-m-t', $firstOfMonth);

// ── Events for the month, grouped by day ────────────────────────────────────
$eventsByDay = [];
if ($activeCompanyId) {
    $eStmt = $pdo->prepare("
        SELECT id, company_id, title, description, event_date, event_type, color, created_by
        FROM events
        WHERE company_id = :cid AND event_date BETWEEN :start AND :end
        ORDER BY event_date ASC, id ASC
    ");
    $eStmt->execute(['cid' => $activeCompanyId, 'start' => $firstDate, 'end' => $lastDate]);
    foreach ($eStmt->fetchAll(PDO::FETCH_ASSOC) as $ev) {
        $day = (int)date('j', strtotime($ev['event_date']));
        $eventsByDay[$day][] = $ev;
    }
}

// Helper: querystring-preserving link to another month / company
function calLink(int $companyId, string $ym): string {
    $params = ['ym' => $ym];
    if ($companyId > 0) $params['company_id'] = $companyId;
    return 'calendar.php?' . http_build_query($params);
}
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
        <a href="index.php" class="flex shrink-0 items-center">
            <img src="../centryk_logo.png" alt="Centryk" class="h-14 w-auto">
        </a>
        <div class="h-5 w-px bg-slate-200 shrink-0"></div>
        <div class="flex items-center gap-2">
            <i data-lucide="calendar" class="h-4 w-4 text-slate-400"></i>
            <span class="text-sm font-bold text-slate-700">Calendar</span>
        </div>
        <div class="flex-1"></div>
        <?php if (!empty($user['is_admin'])): ?>
        <a href="requests.php" class="hidden sm:flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
            <i data-lucide="users" class="h-3.5 w-3.5"></i> New Users
        </a>
        <a href="audit.php" class="hidden sm:flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
            <i data-lucide="history" class="h-3.5 w-3.5"></i> Audit Trail
        </a>
        <?php endif; ?>
        <?php $awAlign = 'right'; $awMode = 'launch'; include __DIR__ . '/partials/app_switcher.php'; ?>
        <div class="h-5 w-px bg-slate-200 shrink-0"></div>
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
                        <i data-lucide="layout-grid" class="h-4 w-4 shrink-0"></i> Dashboard
                    </a>
                    <a href="profile.php" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                        <i data-lucide="user-cog" class="h-4 w-4 shrink-0"></i> Manage Profile
                    </a>
                    <button id="logoutBtn" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 transition text-left">
                        <i data-lucide="log-out" class="h-4 w-4 shrink-0"></i> Sign out
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
        <div class="flex items-center gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Calendar</p>
                <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900"><?= htmlspecialchars($monthLabel) ?></h1>
            </div>
            <?php if (count($companies) > 1): ?>
            <div class="ml-2 flex items-center gap-2">
                <i data-lucide="building-2" class="h-4 w-4 text-slate-400 shrink-0"></i>
                <select id="companyPicker" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm font-bold text-slate-700 outline-none transition focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                    <?php foreach ($companies as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $activeCompanyId) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php elseif (!empty($activeCompanyName)): ?>
            <span class="ml-2 inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm font-bold text-slate-700">
                <i data-lucide="building-2" class="h-4 w-4 text-slate-400"></i>
                <?= htmlspecialchars($activeCompanyName) ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <a href="<?= htmlspecialchars(calLink((int)$activeCompanyId, $prevMonth)) ?>" class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-800" title="Previous month">
                <i data-lucide="chevron-left" class="h-4 w-4"></i>
            </a>
            <a href="<?= htmlspecialchars(calLink((int)$activeCompanyId, date('Y-m'))) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                Today
            </a>
            <a href="<?= htmlspecialchars(calLink((int)$activeCompanyId, $nextMonth)) ?>" class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-800" title="Next month">
                <i data-lucide="chevron-right" class="h-4 w-4"></i>
            </a>
            <?php if ($activeCompanyId): ?>
            <button id="newEventBtn" type="button" class="ml-2 inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-xs font-black uppercase tracking-[0.12em] text-white shadow transition hover:bg-slate-700">
                <i data-lucide="plus" class="h-3.5 w-3.5"></i> New Event
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($companies)): ?>
    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm font-semibold text-amber-700">
        You're not part of any company yet. Once you're added to one, events you create will live under that company.
    </div>
    <?php else: ?>

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
            <div class="min-h-[110px] border-b border-r border-slate-100 bg-slate-50/50"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $isToday  = ($d === $todayDay && $month === $todayMon && $year === $todayYr);
                $dateStr  = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $dayEvts  = $eventsByDay[$d] ?? [];
            ?>
            <div class="day-cell group relative min-h-[110px] border-b border-r border-slate-100 p-2 transition hover:bg-slate-50 cursor-pointer" data-date="<?= $dateStr ?>">
                <div class="flex items-center justify-between">
                    <span class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-black <?= $isToday ? 'bg-slate-900 text-white' : 'text-slate-700' ?>"><?= $d ?></span>
                    <i data-lucide="plus" class="h-3.5 w-3.5 text-slate-300 opacity-0 group-hover:opacity-100 transition"></i>
                </div>
                <?php if (!empty($dayEvts)): ?>
                <div class="mt-1 space-y-1">
                    <?php foreach ($dayEvts as $ev):
                        $bg = match ($ev['color']) {
                            'blue'   => 'bg-blue-500   hover:bg-blue-600',
                            'teal'   => 'bg-teal-500   hover:bg-teal-600',
                            'green'  => 'bg-green-500  hover:bg-green-600',
                            'amber'  => 'bg-amber-500  hover:bg-amber-600',
                            'red'    => 'bg-red-500    hover:bg-red-600',
                            'purple' => 'bg-purple-500 hover:bg-purple-600',
                            default  => 'bg-slate-500  hover:bg-slate-600',
                        };
                    ?>
                    <button type="button" class="event-pill block w-full truncate rounded-md px-2 py-1 text-left text-[11px] font-bold text-white shadow-sm transition <?= $bg ?>"
                            data-event='<?= htmlspecialchars(json_encode($ev), ENT_QUOTES, "UTF-8") ?>'>
                        <?= htmlspecialchars($ev['title']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>

            <?php
            $totalCells = $leadingBlanks + $daysInMonth;
            $trailing   = (7 - ($totalCells % 7)) % 7;
            for ($i = 0; $i < $trailing; $i++): ?>
            <div class="min-h-[110px] border-b border-r border-slate-100 bg-slate-50/50"></div>
            <?php endfor; ?>
        </div>
    </div>

    <p class="mt-10 text-center text-[11px] font-bold uppercase tracking-[0.18em] text-slate-300">
        Calendar &middot; Centryk &copy; <?= date('Y') ?>
    </p>

    <?php endif; ?>

</main>

<!-- ── Event modal ─────────────────────────────────────────────────────────── -->
<div id="eventModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="relative w-full max-w-md rounded-3xl bg-white shadow-2xl overflow-hidden">
        <div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>
        <div class="px-6 py-5">

            <div class="mb-4 flex items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Calendar</p>
                    <h3 id="eventModalTitle" class="mt-0.5 text-lg font-black tracking-tight text-slate-900">New Event</h3>
                </div>
                <button id="evtCloseBtn" type="button" class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>
            </div>

            <div id="evtAlert" class="hidden mb-3 rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600"></div>

            <form id="eventForm" class="space-y-3">
                <input type="hidden" id="evtId">

                <div>
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Title</label>
                    <input id="evtTitle" type="text" required maxlength="180" placeholder="Team standup"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                </div>

                <div>
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Description (optional)</label>
                    <textarea id="evtDescription" rows="3" placeholder="Notes, agenda, location…"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-4 focus:ring-teal-100 resize-none"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Date</label>
                        <input id="evtDate" type="date" required
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Type</label>
                        <select id="evtType" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                            <option value="meeting">Meeting</option>
                            <option value="holiday">Holiday</option>
                            <option value="deadline">Deadline</option>
                            <option value="training">Training</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Color</label>
                    <div id="evtColorPicker" class="flex flex-wrap items-center gap-2">
                        <?php $palette = [
                            'slate'  => '#64748b',
                            'blue'   => '#3b82f6',
                            'teal'   => '#14b8a6',
                            'green'  => '#22c55e',
                            'amber'  => '#f59e0b',
                            'red'    => '#ef4444',
                            'purple' => '#a855f7',
                        ]; ?>
                        <?php foreach ($palette as $name => $hex): ?>
                        <button type="button" data-color="<?= $name ?>" class="color-swatch flex h-7 w-7 items-center justify-center rounded-full transition" style="background:<?= $hex ?>" title="<?= ucfirst($name) ?>">
                            <i data-lucide="check" class="h-3.5 w-3.5 text-white opacity-0"></i>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-2 pt-2">
                    <button id="evtDeleteBtn" type="button" class="hidden rounded-xl border border-red-200 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-red-600 transition hover:bg-red-50">
                        Delete
                    </button>
                    <div class="flex items-center gap-2 ml-auto">
                        <button id="evtCancelBtn" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-50">
                            Cancel
                        </button>
                        <button id="evtSaveBtn" type="submit" class="rounded-xl bg-slate-900 px-5 py-2 text-xs font-black uppercase tracking-[0.12em] text-white shadow transition hover:bg-slate-700">
                            Save Event
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>

<script>
(function () {
    // ── Header dropdowns ──────────────────────────────────────────────────────
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

    // ── Company picker → reload with new company_id ──────────────────────────
    var coPicker = document.getElementById('companyPicker');
    if (coPicker) {
        coPicker.addEventListener('change', function () {
            var url = new URL(window.location.href);
            url.searchParams.set('company_id', coPicker.value);
            window.location.href = url.toString();
        });
    }

    // ── App switcher (waffle) → launch other apps via switch.php ─────────────
    var activeCompanyUuid = <?= json_encode($activeCompanyUuid ?? '') ?>;
    document.querySelectorAll('.aw-app').forEach(function (tile) {
        tile.addEventListener('click', function (e) {
            e.stopPropagation();
            var dd = document.getElementById('appSwitcherDropdown');
            if (dd) dd.classList.add('hidden');
            var url = 'switch.php?app=' + encodeURIComponent(tile.dataset.app);
            if (activeCompanyUuid) {
                url += '&company_uuid=' + encodeURIComponent(activeCompanyUuid);
            }
            window.location.href = url;
        });
    });

    // ── Event modal ───────────────────────────────────────────────────────────
    var activeCompanyId = <?= (int)($activeCompanyId ?? 0) ?>;
    var currentUserId   = <?= (int)$user['id'] ?>;
    var isAdmin         = <?= $isAdmin ? 'true' : 'false' ?>;
    if (!activeCompanyId) return; // nothing more to wire up

    var modal       = document.getElementById('eventModal');
    var form        = document.getElementById('eventForm');
    var modalTitle  = document.getElementById('eventModalTitle');
    var alertBox    = document.getElementById('evtAlert');
    var idField     = document.getElementById('evtId');
    var titleField  = document.getElementById('evtTitle');
    var descField   = document.getElementById('evtDescription');
    var dateField   = document.getElementById('evtDate');
    var typeField   = document.getElementById('evtType');
    var deleteBtn   = document.getElementById('evtDeleteBtn');
    var cancelBtn   = document.getElementById('evtCancelBtn');
    var closeBtn    = document.getElementById('evtCloseBtn');
    var saveBtn     = document.getElementById('evtSaveBtn');
    var newBtn      = document.getElementById('newEventBtn');
    var colorPicker = document.getElementById('evtColorPicker');

    var selectedColor = 'slate';

    function refreshColorUI() {
        colorPicker.querySelectorAll('.color-swatch').forEach(function (sw) {
            var on = sw.dataset.color === selectedColor;
            sw.classList.toggle('ring-4', on);
            sw.classList.toggle('ring-offset-2', on);
            sw.classList.toggle('ring-slate-300', on);
            var check = sw.querySelector('i, svg');
            if (check) check.style.opacity = on ? '1' : '0';
        });
    }

    function openModal(mode, prefill, canDelete) {
        modalTitle.textContent = mode === 'edit' ? 'Edit Event' : 'New Event';
        alertBox.classList.add('hidden');
        idField.value     = prefill.id || '';
        titleField.value  = prefill.title || '';
        descField.value   = prefill.description || '';
        dateField.value   = prefill.event_date || (new Date()).toISOString().slice(0, 10);
        typeField.value   = prefill.event_type || 'meeting';
        selectedColor     = prefill.color || 'slate';
        refreshColorUI();
        deleteBtn.classList.toggle('hidden', !canDelete);
        saveBtn.textContent = mode === 'edit' ? 'Save Changes' : 'Save Event';
        saveBtn.disabled    = false;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        if (window.lucide) lucide.createIcons();
        setTimeout(function () { titleField.focus(); }, 30);
    }
    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    // Color picker
    colorPicker.querySelectorAll('.color-swatch').forEach(function (sw) {
        sw.addEventListener('click', function () {
            selectedColor = sw.dataset.color;
            refreshColorUI();
        });
    });

    // Click a day cell → open Create
    document.querySelectorAll('.day-cell').forEach(function (cell) {
        cell.addEventListener('click', function () {
            openModal('create', { event_date: cell.dataset.date }, false);
        });
    });

    // Click an event pill → open Edit (and stop the day-cell bubble)
    document.querySelectorAll('.event-pill').forEach(function (pill) {
        pill.addEventListener('click', function (e) {
            e.stopPropagation();
            var ev = JSON.parse(pill.dataset.event);
            var canDelete = isAdmin || (Number(ev.created_by) === currentUserId);
            openModal('edit', ev, canDelete);
        });
    });

    // "+ New Event" header button
    if (newBtn) {
        newBtn.addEventListener('click', function () {
            openModal('create', { event_date: (new Date()).toISOString().slice(0, 10) }, false);
        });
    }

    cancelBtn.addEventListener('click', closeModal);
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    // Submit (create or update)
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var id = idField.value;
        var payload = {
            company_id:  activeCompanyId,
            title:       titleField.value.trim(),
            description: descField.value.trim(),
            event_date:  dateField.value,
            event_type:  typeField.value,
            color:       selectedColor,
        };
        if (id) payload.id = Number(id);

        if (!payload.title)      { showAlert('Title is required.'); return; }
        if (!payload.event_date) { showAlert('Date is required.'); return; }

        alertBox.classList.add('hidden');
        saveBtn.disabled    = true;
        saveBtn.textContent = id ? 'Saving…' : 'Adding…';

        var url = id ? 'api/events/update.php' : 'api/events/create.php';
        fetch(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                saveBtn.disabled    = false;
                saveBtn.textContent = id ? 'Save Changes' : 'Save Event';
                showAlert(data.message || 'Could not save event.');
            }
        })
        .catch(function () {
            saveBtn.disabled    = false;
            saveBtn.textContent = id ? 'Save Changes' : 'Save Event';
            showAlert('Network error. Please try again.');
        });
    });

    // Delete
    deleteBtn.addEventListener('click', function () {
        if (!idField.value) return;
        if (!confirm('Delete this event?')) return;
        deleteBtn.disabled    = true;
        deleteBtn.textContent = 'Deleting…';
        fetch('api/events/delete.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ id: Number(idField.value) }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                window.location.reload();
            } else {
                deleteBtn.disabled    = false;
                deleteBtn.textContent = 'Delete';
                showAlert(data.message || 'Could not delete event.');
            }
        })
        .catch(function () {
            deleteBtn.disabled    = false;
            deleteBtn.textContent = 'Delete';
            showAlert('Network error. Please try again.');
        });
    });

    function showAlert(msg) {
        alertBox.textContent = msg;
        alertBox.classList.remove('hidden');
    }
}());
</script>

<script src="https://unpkg.com/lucide@latest"></script>
<script>if (window.lucide) { lucide.createIcons(); }</script>

</body>
</html>
