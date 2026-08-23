<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/services/MyPayCalendarFeed.php';

Auth::start();

// Embedding from another suite app (MyPay/OnePay): a fresh one-time token logs
// this iframe into Centryk directly, same as login.php does with a password.
if (!Auth::user() && isset($_GET['sso_token'])) {
    $embedUser = Auth::consumeToken((string)$_GET['sso_token'], 'calendar_embed');
    if ($embedUser) {
        Auth::login((int)$embedUser['id']);
    }
}

$me = AuthService::me();
if (!$me['authenticated']) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
$user  = $me['user'];
$pdo   = DB::pdo();
$embed = isset($_GET['embed']); // rendered inside the calendar_drawer.php iframe

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
$isAdmin    = ($activeRole === 'admin');
$isApprover = in_array($activeRole, ['admin', 'manager'], true);

// Active members for attendee selection.
$companyMembers = [];
if ($activeCompanyId) {
    $memStmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, cm.role
        FROM company_members cm
        JOIN users u ON u.id = cm.user_id
        WHERE cm.company_id = :cid
          AND cm.status = 'active'
          AND u.status = 'active'
        ORDER BY FIELD(cm.role, 'admin', 'manager', 'employee'), u.first_name ASC, u.last_name ASC
    ");
    $memStmt->execute(['cid' => $activeCompanyId]);
    $companyMembers = $memStmt->fetchAll(PDO::FETCH_ASSOC);
}

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
        SELECT e.id, e.company_id, e.title, e.description, e.event_date, e.event_type, e.color, e.created_by,
               COALESCE(GROUP_CONCAT(ea.user_id ORDER BY u.first_name ASC, u.last_name ASC SEPARATOR ','), '') AS attendee_ids,
               COALESCE(GROUP_CONCAT(TRIM(CONCAT(u.first_name, ' ', u.last_name)) ORDER BY u.first_name ASC, u.last_name ASC SEPARATOR ', '), '') AS attendee_names
        FROM events e
        LEFT JOIN event_attendees ea ON ea.event_id = e.id
        LEFT JOIN users u ON u.id = ea.user_id
        WHERE e.company_id = :cid
          AND e.event_date BETWEEN :start AND :end
          AND (
              :is_admin = 1
              OR e.created_by = :uid_creator
              OR NOT EXISTS (SELECT 1 FROM event_attendees ea0 WHERE ea0.event_id = e.id)
              OR EXISTS (SELECT 1 FROM event_attendees ea1 WHERE ea1.event_id = e.id AND ea1.user_id = :uid_attendee)
          )
        GROUP BY e.id
        ORDER BY e.event_date ASC, e.id ASC
    ");
    $eStmt->execute([
        'cid' => $activeCompanyId,
        'start' => $firstDate,
        'end' => $lastDate,
        'is_admin' => $isAdmin ? 1 : 0,
        'uid_creator' => (int)$user['id'],
        'uid_attendee' => (int)$user['id'],
    ]);
    foreach ($eStmt->fetchAll(PDO::FETCH_ASSOC) as $ev) {
        $ev['attendee_ids'] = $ev['attendee_ids'] !== '' ? array_map('intval', explode(',', $ev['attendee_ids'])) : [];
        $day = (int)date('j', strtotime($ev['event_date']));
        $eventsByDay[$day][] = $ev;
    }

    // ── Invoice due dates (read-only, admin/manager only — financial data) ──
    if ($isApprover) {
        $invStmt = $pdo->prepare("
            SELECT id, invoice_number, due_date, total, status
            FROM invoices
            WHERE company_id = :cid
              AND due_date BETWEEN :start AND :end
              AND status IN ('sent', 'overdue')
            ORDER BY due_date ASC, id ASC
        ");
        $invStmt->execute(['cid' => $activeCompanyId, 'start' => $firstDate, 'end' => $lastDate]);
        foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            $day = (int)date('j', strtotime($inv['due_date']));
            $eventsByDay[$day][] = [
                'title'  => 'Invoice #' . $inv['invoice_number'] . ' due — $' . number_format((float)$inv['total'], 2),
                'color'  => $inv['status'] === 'overdue' ? 'red' : 'amber',
                'source' => 'invoice',
            ];
        }
    }

    // ── MyPay task due dates + approved leave (read-only, live pull) ────────
    // MyPay owns assignment/approval; Centryk only displays what's decided
    // there. Fails soft — MyPayCalendarFeed::fetch() never throws.
    if ($activeCompanyUuid !== '') {
        $mypayFeed = MyPayCalendarFeed::fetch($activeCompanyUuid, $firstDate, $lastDate);

        $memberIdByEmail = [];
        foreach ($companyMembers as $m) {
            $memberIdByEmail[strtolower((string)$m['email'])] = (int)$m['id'];
        }

        foreach ($mypayFeed['tasks'] as $task) {
            $dueDate = (string)($task['due_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) continue;

            $assigneeEmail = strtolower((string)($task['assignee_email'] ?? ''));
            $assigneeId    = $memberIdByEmail[$assigneeEmail] ?? 0;
            $isAssignee    = $assigneeId > 0 && $assigneeId === (int)$user['id'];
            if (!$isApprover && !$isAssignee) continue; // not this viewer's task

            $name  = trim((string)($task['first_name'] ?? '') . ' ' . (string)($task['last_name'] ?? ''));
            $color = match ($task['priority'] ?? '') {
                'high' => 'red',
                'low'  => 'slate',
                default => 'blue',
            };
            $day = (int)date('j', strtotime($dueDate));
            $eventsByDay[$day][] = [
                'title'  => ($task['title'] ?? 'Task') . ' — ' . ($name !== '' ? $name : 'Unassigned') . ' (MyPay)',
                'color'  => $color,
                'source' => 'mypay-task',
            ];
        }

        $monthStartTs = strtotime($firstDate);
        $monthEndTs   = strtotime($lastDate);
        foreach ($mypayFeed['leave'] as $lv) {
            $status = (string)($lv['status'] ?? 'approved');
            if ($status === 'pending' && !$isApprover) continue; // pending requests are HR-only

            $startTs = strtotime((string)($lv['start_date'] ?? ''));
            $endTs   = strtotime((string)($lv['end_date'] ?? ''));
            if ($startTs === false || $endTs === false) continue;

            $name = trim((string)($lv['first_name'] ?? '') . ' ' . (string)($lv['last_name'] ?? ''));
            if ($name === '') $name = 'Employee';
            $label = (string)($lv['leave_type'] ?? 'Leave');

            $pill = $status === 'pending'
                ? [
                    'title'  => $name . ' — ' . $label . ' (Pending, MyPay)',
                    'color'  => 'amber',
                    'source' => 'mypay-leave-pending',
                    'url'    => (string)($lv['review_url'] ?? ''),
                ]
                : [
                    'title'  => $name . ' — ' . $label . ' (MyPay)',
                    'color'  => 'teal',
                    'source' => 'mypay-leave',
                ];

            $cursor = max($startTs, $monthStartTs);
            $clampedEnd = min($endTs, $monthEndTs);
            while ($cursor <= $clampedEnd) {
                $day = (int)date('j', $cursor);
                $eventsByDay[$day][] = $pill;
                $cursor = strtotime('+1 day', $cursor);
            }
        }
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
    <link rel="icon" type="image/svg+xml" href="favicon-calendar.svg">
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
        @keyframes centryk-logo-settle {
            0%   { opacity: 0; transform: translateY(-2px) scale(0.965); filter: saturate(0.92); }
            62%  { opacity: 1; transform: translateY(0) scale(1.018); filter: saturate(1.03); }
            100% { opacity: 1; transform: translateY(0) scale(1); filter: saturate(1); }
        }
        @keyframes centryk-logo-sheen {
            0%, 14% { opacity: 0; transform: translateX(-135%) skewX(-18deg); }
            32%     { opacity: 0.34; }
            100%    { opacity: 0; transform: translateX(165%) skewX(-18deg); }
        }
        .centryk-logo-lockup {
            position: relative;
            overflow: hidden;
        }
        .centryk-logo-lockup::after {
            content: '';
            position: absolute;
            inset: -10% auto -10% -35%;
            width: 32%;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.72) 50%, transparent 100%);
            opacity: 0;
            pointer-events: none;
            animation: centryk-logo-sheen 900ms cubic-bezier(0.22, 1, 0.36, 1) 420ms 1 both;
        }
        .centryk-logo-mark {
            transform-origin: center left;
            animation: centryk-logo-settle 520ms cubic-bezier(0.22, 1, 0.36, 1) 1 both;
        }
        @media (prefers-reduced-motion: reduce) {
            .centryk-logo-lockup::after,
            .centryk-logo-mark {
                animation: none !important;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased">

<?php if (!$embed): ?>
<!-- Top accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500 sticky top-0 z-50"></div>

<!-- Header (matches Centryk dashboard) -->
<header class="sticky top-[3px] z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-3">
        <a href="index.php" class="centryk-logo-lockup flex shrink-0 items-center">
            <img src="assets/centryk_logo.png" alt="Centryk" class="centryk-logo-mark h-14 w-auto">
        </a>
        <div class="h-5 w-px bg-slate-200 shrink-0"></div>
        <?php if (count($companies) > 1): ?>
        <div class="relative shrink-0" id="companySwitcherWrap">
            <button id="companySwitcherBtn" type="button"
                    class="flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 hover:bg-teal-100 transition-colors">
                <i data-lucide="building-2" class="h-4 w-4 text-teal-500 shrink-0"></i>
                <span><?= htmlspecialchars($activeCompanyName) ?></span>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="h-3.5 w-3.5 text-teal-400">
                    <path fill-rule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                </svg>
            </button>
            <div id="companySwitcherDropdown"
                 class="absolute left-0 top-full z-50 mt-1.5 hidden w-56 rounded-xl border border-slate-200 bg-white py-1 shadow-lg shadow-slate-200/60">
                <?php foreach ($companies as $c): $isActive = ((int)$c['id'] === $activeCompanyId); ?>
                <a href="<?= htmlspecialchars(calLink((int)$c['id'], $ym)) ?>"
                   class="flex w-full items-center gap-2.5 px-4 py-2.5 text-left text-sm transition-colors <?= $isActive ? 'bg-teal-50 font-semibold text-teal-700' : 'text-slate-700 hover:bg-slate-50' ?>">
                    <?php if ($isActive): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="h-3.5 w-3.5 text-teal-500 shrink-0">
                        <path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd"/>
                    </svg>
                    <?php else: ?>
                    <span class="h-3.5 w-3.5 shrink-0"></span>
                    <?php endif; ?>
                    <?= htmlspecialchars($c['name']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php elseif (!empty($activeCompanyName)): ?>
        <span class="inline-flex items-center gap-1.5 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-700 shrink-0">
            <i data-lucide="building-2" class="h-4 w-4 text-teal-500"></i>
            <?= htmlspecialchars($activeCompanyName) ?>
        </span>
        <?php endif; ?>
        <div class="flex-1"></div>
        <?php include __DIR__ . '/partials/admin_tools_dropdown.php'; ?>
        <div class="relative shrink-0" id="notifWrap">
            <button id="notifBtn" type="button" title="Notifications"
                    class="relative flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-orange-50 hover:text-orange-600">
                <i data-lucide="bell" class="h-5 w-5"></i>
                <span id="notifBadge" class="hidden absolute -top-1 -right-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">0</span>
            </button>
            <div id="notifDropdown" class="absolute right-0 top-full z-50 mt-1.5 hidden w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Notifications</span>
                    <a href="notifications.php" class="text-[11px] font-bold text-orange-600 hover:text-orange-700">View all &rarr;</a>
                </div>
                <div id="notifBody" class="max-h-96 overflow-y-auto p-2">
                    <p class="px-3 py-6 text-center text-xs text-slate-400">Loading...</p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <i data-lucide="calendar" class="h-4 w-4 text-teal-500"></i>
            <span class="hidden text-sm font-bold text-slate-700 sm:inline">Calendar</span>
        </div>
        <?php $awAlign = 'right'; $awMode = 'launch'; $awCurrent = 'calendar'; include __DIR__ . '/partials/app_switcher.php'; ?>
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
                        <i data-lucide="user-cog" class="h-4 w-4 shrink-0"></i> Manage your Centryk Account
                    </a>
                    <button id="logoutBtn" class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-red-50 hover:text-red-600 transition text-left">
                        <i data-lucide="log-out" class="h-4 w-4 shrink-0"></i> Sign out
                    </button>
                </div>
            </div>
        </div>
    </div>
</header>
<?php endif; ?>

<!-- Main -->
<main class="mx-auto <?= $embed ? 'max-w-none px-3 pb-4 pt-3' : 'max-w-7xl px-6 pb-6 pt-1' ?>">

    <?php $dayCellMinH = $embed ? '82px' : '110px'; ?>
    <!-- Title bar -->
    <div class="<?= $embed ? 'mb-3' : 'mb-6' ?> flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div>
                <?php if (!$embed): ?>
                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Calendar</p>
                <?php endif; ?>
                <h1 class="<?= $embed ? 'text-lg' : 'mt-1 text-2xl' ?> font-black tracking-tight text-slate-900"><?= htmlspecialchars($monthLabel) ?></h1>
            </div>
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

    <div id="calendarWorkspace" class="items-start gap-4 lg:flex">

    <!-- Calendar card -->
    <div id="calendarCard" class="min-w-0 flex-1 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

        <!-- Weekday header -->
        <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50">
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $w): ?>
            <div class="px-3 py-2 text-center text-[10px] font-black uppercase tracking-[0.18em] text-slate-400"><?= $w ?></div>
            <?php endforeach; ?>
        </div>

        <!-- Day grid -->
        <div class="grid grid-cols-7">
            <?php for ($i = 0; $i < $leadingBlanks; $i++): ?>
            <div class="min-h-[<?= $dayCellMinH ?>] border-b border-r border-slate-100 bg-slate-50/50"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $isToday  = ($d === $todayDay && $month === $todayMon && $year === $todayYr);
                $dateStr  = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $dayEvts  = $eventsByDay[$d] ?? [];
            ?>
            <div class="day-cell group relative min-h-[<?= $dayCellMinH ?>] border-b border-r border-slate-100 p-2 transition hover:bg-slate-50 cursor-pointer" data-date="<?= $dateStr ?>">
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
                    <?php if (!empty($ev['url'])): ?>
                    <a href="<?= htmlspecialchars($ev['url']) ?>" target="_blank" rel="noopener"
                       class="event-pill-readonly flex items-center gap-1 truncate rounded-md px-2 py-1 text-left text-[11px] font-bold text-white shadow-sm transition <?= $bg ?>"
                       title="<?= htmlspecialchars($ev['title']) ?> — review in MyPay">
                        <i data-lucide="external-link" class="h-3 w-3 shrink-0 opacity-80"></i>
                        <span class="truncate"><?= htmlspecialchars($ev['title']) ?></span>
                    </a>
                    <?php elseif (!empty($ev['source'])): ?>
                    <div class="event-pill-readonly flex items-center gap-1 truncate rounded-md px-2 py-1 text-left text-[11px] font-bold text-white shadow-sm <?= $bg ?>"
                         title="<?= htmlspecialchars($ev['title']) ?><?= $ev['source'] !== 'invoice' ? ' — managed in MyPay' : '' ?>">
                        <i data-lucide="<?= $ev['source'] === 'invoice' ? 'receipt' : 'external-link' ?>" class="h-3 w-3 shrink-0 opacity-80"></i>
                        <span class="truncate"><?= htmlspecialchars($ev['title']) ?></span>
                    </div>
                    <?php else: ?>
                    <button type="button" class="event-pill block w-full truncate rounded-md px-2 py-1 text-left text-[11px] font-bold text-white shadow-sm transition <?= $bg ?>"
                            data-event='<?= htmlspecialchars(json_encode($ev), ENT_QUOTES, "UTF-8") ?>'>
                        <?= htmlspecialchars($ev['title']) ?>
                    </button>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>

            <?php
            $totalCells = $leadingBlanks + $daysInMonth;
            $trailing   = (7 - ($totalCells % 7)) % 7;
            for ($i = 0; $i < $trailing; $i++): ?>
            <div class="min-h-[<?= $dayCellMinH ?>] border-b border-r border-slate-100 bg-slate-50/50"></div>
            <?php endfor; ?>
        </div>
    </div>

    </div>

    <p class="mt-10 text-center text-[11px] font-bold uppercase tracking-[0.18em] text-slate-300">
        Calendar &middot; Centryk &copy; <?= date('Y') ?>
    </p>

    <?php endif; ?>

</main>

<!-- ── Event modal ─────────────────────────────────────────────────────────── -->
<div id="eventPanel" class="mt-4 hidden w-full shrink-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:mt-0 lg:w-[360px]">
    <div class="relative w-full">
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

                <div>
                    <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Employees</label>
                    <div id="evtAttendees" class="max-h-40 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-2">
                        <?php foreach ($companyMembers as $member):
                            $memberId = (int)$member['id'];
                            $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                            if ($memberName === '') $memberName = $member['email'] ?? 'Employee';
                            $initials = strtoupper(substr($member['first_name'] ?: $memberName, 0, 1) . substr($member['last_name'] ?: '', 0, 1));
                        ?>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 transition hover:bg-white">
                            <input type="checkbox" class="attendee-check h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500" value="<?= $memberId ?>">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-black text-slate-500 shadow-sm"><?= htmlspecialchars($initials) ?></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-xs font-bold text-slate-800"><?= htmlspecialchars($memberName) ?><?= $memberId === (int)$user['id'] ? ' (you)' : '' ?></span>
                                <span class="block truncate text-[10px] font-semibold text-slate-400"><?= htmlspecialchars($member['email'] ?? '') ?></span>
                            </span>
                        </label>
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

<?php if (!$embed): ?>
<?php include __DIR__ . '/partials/footer_app.php'; ?>
<?php endif; ?>

<script>
(function () {
    // ── Header dropdowns ──────────────────────────────────────────────────────
    var btn  = document.getElementById('userMenuBtn');
    var menu = document.getElementById('userMenu');
    if (btn && menu) {
        btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('hidden'); });
        document.addEventListener('click', function () { menu.classList.add('hidden'); });
    }
    var adminToolsBtn = document.getElementById('adminToolsBtn');
    var adminToolsMenu = document.getElementById('adminToolsMenu');
    if (adminToolsBtn && adminToolsMenu) {
        adminToolsBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            adminToolsMenu.classList.toggle('hidden');
        });
        document.addEventListener('click', function () { adminToolsMenu.classList.add('hidden'); });
    }
    var logout = document.getElementById('logoutBtn');
    if (logout) {
        logout.addEventListener('click', function () {
            fetch('api/auth/logout.php', { method: 'POST' }).finally(function () { window.location.href = 'index.php'; });
        });
    }

    // ── Company switcher dropdown (rows are links that reload) ────────────────
    var notifBtn = document.getElementById('notifBtn');
    var notifDropdown = document.getElementById('notifDropdown');
    var notifBadge = document.getElementById('notifBadge');
    var notifBody = document.getElementById('notifBody');
    function notifEsc(value) {
        return String(value == null ? '' : value).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function notifSetBadge(count) {
        count = parseInt(count, 10) || 0;
        if (count > 0) {
            notifBadge.textContent = count > 99 ? '99+' : String(count);
            notifBadge.classList.remove('hidden');
        } else {
            notifBadge.classList.add('hidden');
        }
    }
    function notifTimeAgo(ts) {
        var d = new Date(String(ts || '').replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        var seconds = Math.max(1, Math.floor((Date.now() - d.getTime()) / 1000));
        if (seconds < 60) return seconds + 's ago';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ago';
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h ago';
        var days = Math.floor(hours / 24);
        if (days < 7) return days + 'd ago';
        return d.toLocaleDateString();
    }
    function notifRow(n) {
        var unread = !n.read_at;
        var accent = n.color && /^#/.test(n.color) ? n.color : '#f97316';
        var href = n.url ? notifEsc(n.url) : 'notifications.php';
        return '<a href="' + href + '" class="flex gap-3 rounded-lg px-3 py-2.5 hover:bg-slate-50 ' + (unread ? 'bg-orange-50/40' : '') + '">' +
            '<span class="mt-1 h-2 w-2 shrink-0 rounded-full" style="background:' + (unread ? accent : 'transparent') + '"></span>' +
            '<span class="min-w-0 flex-1">' +
                '<span class="block truncate text-sm font-semibold text-slate-800">' + notifEsc(n.title) + '</span>' +
                (n.body ? '<span class="block text-[11px] text-slate-500">' + notifEsc(n.body) + '</span>' : '') +
                '<span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">' + notifEsc(n.app_key || '') + ' · ' + notifTimeAgo(n.created_at) + '</span>' +
            '</span>' +
        '</a>';
    }
    function notifRefreshCount() {
        if (!notifBtn || !notifBadge) return;
        fetch('api/notifications/count.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.success) notifSetBadge(d.unread_count); })
            .catch(function () {});
    }
    function notifLoadList() {
        notifBody.innerHTML = '<p class="px-3 py-6 text-center text-xs text-slate-400">Loading...</p>';
        fetch('api/notifications/list.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var items = (d && d.notifications) || [];
                notifBody.innerHTML = items.length
                    ? items.map(notifRow).join('')
                    : "<p class=\"px-3 py-6 text-center text-xs text-slate-400\">You're all caught up.</p>";
                if (d && d.unread_count > 0) {
                    fetch('api/notifications/read.php', { method: 'POST', credentials: 'same-origin' })
                        .then(function () { notifSetBadge(0); })
                        .catch(function () {});
                } else {
                    notifSetBadge(0);
                }
            })
            .catch(function () {
                notifBody.innerHTML = '<p class="px-3 py-6 text-center text-xs text-slate-400">Could not load notifications.</p>';
            });
    }
    if (notifBtn && notifDropdown && notifBadge && notifBody) {
        notifBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var opening = notifDropdown.classList.contains('hidden');
            notifDropdown.classList.add('hidden');
            if (opening) {
                notifDropdown.classList.remove('hidden');
                notifLoadList();
            }
        });
        document.addEventListener('click', function () { notifDropdown.classList.add('hidden'); });
        notifRefreshCount();
        setInterval(notifRefreshCount, 60000);
    }

    var coBtn = document.getElementById('companySwitcherBtn');
    var coDd  = document.getElementById('companySwitcherDropdown');
    if (coBtn && coDd) {
        coBtn.addEventListener('click', function (e) { e.stopPropagation(); coDd.classList.toggle('hidden'); });
        document.addEventListener('click', function () { coDd.classList.add('hidden'); });
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

    var modal       = document.getElementById('eventPanel');
    var workspace   = document.getElementById('calendarWorkspace');
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
    var attendeeChecks = Array.prototype.slice.call(document.querySelectorAll('.attendee-check'));

    var selectedColor = 'slate';
    var currentCanEdit = true;
    if (workspace && modal) {
        workspace.appendChild(modal);
    }

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

    function setReadOnly(readOnly) {
        [titleField, descField, dateField, typeField].forEach(function (field) {
            field.disabled = readOnly;
            field.classList.toggle('bg-slate-50', readOnly);
            field.classList.toggle('text-slate-500', readOnly);
        });
        attendeeChecks.forEach(function (check) { check.disabled = readOnly; });
        colorPicker.querySelectorAll('.color-swatch').forEach(function (sw) {
            sw.disabled = readOnly;
            sw.classList.toggle('opacity-60', readOnly);
            sw.classList.toggle('cursor-not-allowed', readOnly);
        });
        saveBtn.classList.toggle('hidden', readOnly);
    }

    function openModal(mode, prefill, canEdit) {
        var attendeeIds = Array.isArray(prefill.attendee_ids) ? prefill.attendee_ids.map(Number) : [];
        currentCanEdit = !!canEdit;
        modalTitle.textContent = mode === 'edit' ? (currentCanEdit ? 'Edit Event' : 'Event Details') : 'New Event';
        alertBox.classList.add('hidden');
        idField.value     = prefill.id || '';
        titleField.value  = prefill.title || '';
        descField.value   = prefill.description || '';
        dateField.value   = prefill.event_date || (new Date()).toISOString().slice(0, 10);
        typeField.value   = prefill.event_type || 'meeting';
        selectedColor     = prefill.color || 'slate';
        refreshColorUI();
        attendeeChecks.forEach(function (check) {
            check.checked = attendeeIds.indexOf(Number(check.value)) !== -1;
        });
        setReadOnly(!currentCanEdit);
        deleteBtn.classList.toggle('hidden', !(mode === 'edit' && currentCanEdit));
        saveBtn.textContent = mode === 'edit' ? 'Save Changes' : 'Save Event';
        saveBtn.disabled    = false;
        modal.classList.remove('hidden');
        if (window.lucide) lucide.createIcons();
        setTimeout(function () { titleField.focus(); }, 30);
    }
    function closeModal() {
        modal.classList.add('hidden');
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
            openModal('create', { event_date: cell.dataset.date }, true);
        });
    });

    // Click an event pill → open Edit (and stop the day-cell bubble)
    document.querySelectorAll('.event-pill').forEach(function (pill) {
        pill.addEventListener('click', function (e) {
            e.stopPropagation();
            var ev = JSON.parse(pill.dataset.event);
            var canEdit = Number(ev.created_by) === currentUserId;
            openModal('edit', ev, canEdit);
        });
    });

    // Read-only pills (invoices, MyPay tasks/leave) — just stop the bubble, no modal.
    document.querySelectorAll('.event-pill-readonly').forEach(function (pill) {
        pill.addEventListener('click', function (e) { e.stopPropagation(); });
    });

    // "+ New Event" header button
    if (newBtn) {
        newBtn.addEventListener('click', function () {
            openModal('create', { event_date: (new Date()).toISOString().slice(0, 10) }, true);
        });
    }

    cancelBtn.addEventListener('click', closeModal);
    closeBtn.addEventListener('click', closeModal);
    // Submit (create or update)
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var id = idField.value;
        if (id && !currentCanEdit) {
            showAlert('Only the creator can edit this event.');
            return;
        }
        var payload = {
            company_id:  activeCompanyId,
            title:       titleField.value.trim(),
            description: descField.value.trim(),
            event_date:  dateField.value,
            event_type:  typeField.value,
            color:       selectedColor,
            attendee_ids: attendeeChecks.filter(function (check) { return check.checked; }).map(function (check) { return Number(check.value); }),
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
