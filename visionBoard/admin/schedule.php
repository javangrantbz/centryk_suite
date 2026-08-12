<?php
$active = 'schedule';
$pageTitle = 'Scheduling';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo = db();
$companyId = vb_cid();
$DOW = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];
$panelMode = isset($_GET['panel']) && $_GET['panel'] === '1';
$baseReturnQuery = [];
if ($panelMode) {
    $baseReturnQuery['panel'] = '1';
}
if (isset($_GET['playlist_id']) && (int) $_GET['playlist_id'] > 0) {
    $baseReturnQuery['playlist_id'] = (string) ((int) $_GET['playlist_id']);
}
$scheduleReturnUrl = 'schedule.php' . ($baseReturnQuery ? '?' . http_build_query($baseReturnQuery) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['delete', 'toggle', 'save'], true) && (int) ($_POST['id'] ?? 0)) {
        $locked = $pdo->prepare('SELECT source_share_id FROM vb_schedules WHERE id=? AND company_id=?');
        $locked->execute([(int) $_POST['id'], $companyId]);
        $locked = $locked->fetchColumn();
        if ($locked) {
            flash('This schedule is locked by the company that shared it - manage it from Sharing instead.', 'error');
            redirect($scheduleReturnUrl);
        }
    }

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $pdo->prepare('DELETE FROM vb_schedules WHERE id=? AND company_id=?')->execute([$id, $companyId]);
        log_activity('deleted', 'schedule', $id);
        flash('Schedule deleted.');
        redirect($scheduleReturnUrl);
    }
    if ($action === 'toggle') {
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE vb_schedules SET is_enabled = 1 - is_enabled WHERE id=? AND company_id=?')->execute([$id, $companyId]);
        log_activity('toggled', 'schedule', $id);
        redirect($scheduleReturnUrl);
    }
    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '') ?: 'Untitled schedule';
        $playlist = (int) ($_POST['playlist_id'] ?? 0);
        $startDate = $_POST['start_date'] ?: null;
        $endDate = $_POST['end_date'] ?: null;
        $startTime = $_POST['start_time'] ?: null;
        $endTime = $_POST['end_time'] ?: null;
        $days = isset($_POST['days']) ? implode(',', array_map('intval', $_POST['days'])) : null;
        $priority = (int) ($_POST['priority'] ?? 0);
        $enabled = isset($_POST['is_enabled']) ? 1 : 0;

        if (!$playlist) {
            flash('Choose a playlist.', 'error');
            redirect($scheduleReturnUrl);
        }

        $own = $pdo->prepare('SELECT COUNT(*) FROM vb_playlists WHERE id=? AND company_id=?');
        $own->execute([$playlist, $companyId]);
        if (!$own->fetchColumn() && !vb_playlist_is_shared_with($playlist, $companyId)) {
            flash('That playlist is not available.', 'error');
            redirect($scheduleReturnUrl);
        }

        if ($id) {
            $pdo->prepare('UPDATE vb_schedules SET name=?, playlist_id=?, start_date=?, end_date=?, start_time=?, end_time=?, days_of_week=?, priority=?, is_enabled=? WHERE id=? AND company_id=?')
                ->execute([$name, $playlist, $startDate, $endDate, $startTime, $endTime, $days, $priority, $enabled, $id, $companyId]);
            log_activity('updated', 'schedule', $id, $name);
            flash('Schedule updated.');
        } else {
            $pdo->prepare('INSERT INTO vb_schedules (company_id, name, playlist_id, start_date, end_date, start_time, end_time, days_of_week, priority, is_enabled) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$companyId, $name, $playlist, $startDate, $endDate, $startTime, $endTime, $days, $priority, $enabled]);
            log_activity('created', 'schedule', (int) $pdo->lastInsertId(), $name);
            flash('Schedule created.');
        }
        redirect($scheduleReturnUrl);
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM vb_schedules WHERE id=? AND company_id=?');
    $s->execute([(int) $_GET['edit'], $companyId]);
    $editing = $s->fetch() ?: null;
    if ($editing && $editing['source_share_id']) {
        flash('This schedule is locked by the company that shared it - manage it from Sharing instead.', 'error');
        redirect($scheduleReturnUrl);
    }
}
$editDays = $editing && $editing['days_of_week'] !== null && $editing['days_of_week'] !== ''
    ? array_map('intval', explode(',', $editing['days_of_week'])) : array_keys($DOW);
$preselectedPlaylist = !$editing ? (int) ($_GET['playlist_id'] ?? 0) : 0;

$plStmt = $pdo->prepare('SELECT id, name, 0 AS is_shared FROM vb_playlists WHERE company_id=? ORDER BY name ASC');
$plStmt->execute([$companyId]);
$playlists = $plStmt->fetchAll();

$sharedStmt = $pdo->prepare(
    "SELECT p.id, CONCAT(p.name, ' (shared by ', c.name, ')') AS name, 1 AS is_shared
     FROM vb_playlist_shares sh
     JOIN vb_playlists p ON p.id = sh.playlist_id
     JOIN companies c ON c.id = sh.owner_company_id
     WHERE sh.shared_with_company_id = ? AND sh.mode = 'editable' AND sh.status = 'accepted'
     ORDER BY p.name ASC"
);
$sharedStmt->execute([$companyId]);
$playlists = array_merge($playlists, $sharedStmt->fetchAll());
$schStmt = $pdo->prepare('SELECT s.*, p.name AS playlist_name FROM vb_schedules s JOIN vb_playlists p ON p.id=s.playlist_id WHERE s.company_id=? ORDER BY s.priority DESC, s.id DESC');
$schStmt->execute([$companyId]);
$schedules = $schStmt->fetchAll();

function schedule_days(array $schedule, array $allDays): array
{
    if ($schedule['days_of_week'] === null || $schedule['days_of_week'] === '') {
        return array_keys($allDays);
    }
    return array_map('intval', explode(',', $schedule['days_of_week']));
}

function schedule_minutes(?string $time, int $fallback): int
{
    if (!$time) {
        return $fallback;
    }
    [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));
    return max(0, min(1440, $h * 60 + $m));
}

$calendarBlocks = [];
foreach ($DOW as $dayNum => $label) {
    $calendarBlocks[$dayNum] = [];
}
foreach ($schedules as $s) {
    if (!$s['is_enabled']) {
        continue;
    }
    $start = schedule_minutes($s['start_time'] ?? null, 0);
    $end = schedule_minutes($s['end_time'] ?? null, 1440);
    if ($end <= $start) {
        $end = 1440;
    }
    foreach (schedule_days($s, $DOW) as $day) {
        if (!isset($calendarBlocks[$day])) {
            continue;
        }
        $calendarBlocks[$day][] = [
            'name' => $s['name'],
            'playlist' => $s['playlist_name'],
            'priority' => (int) $s['priority'],
            'top' => ($start / 1440) * 100,
            'height' => max(4, (($end - $start) / 1440) * 100),
            'time' => ($s['start_time'] ? substr($s['start_time'], 0, 5) : '00:00') . '-' . ($s['end_time'] ? substr($s['end_time'], 0, 5) : '24:00'),
        ];
    }
}
if (!$panelMode) {
    require __DIR__ . '/../includes/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap'); body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900 font-sans">
<?php
}
?>
<?php if ($panelMode): ?>
<div class="h-full overflow-y-auto bg-[#f8fafc] p-5">
<?php else: ?>
<h1 class="text-xl font-black tracking-tight text-slate-900 mb-3">Scheduling</h1>
<p class="text-slate-500 mb-3 text-sm max-w-2xl">
  Choose which playlist should play, and when. Leave a field blank for always.
  When two schedules overlap, the one with the higher <b>priority</b> wins. If nothing matches, the first active playlist plays as a fallback.
</p>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-4">
  <div class="lg:col-span-1">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sticky top-4">
      <h2 class="font-bold text-slate-800 mb-4"><?= $editing ? 'Edit schedule' : 'New schedule' ?></h2>
      <?php if (!$playlists): ?>
        <p class="text-sm text-slate-500">Create a <a class="text-rose-600 font-semibold hover:underline" href="playlists.php">playlist</a> first.</p>
      <?php else: ?>
      <form method="post" class="space-y-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>">

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
          <input name="name" value="<?= e($editing['name'] ?? '') ?>" placeholder="e.g. Weekday daytime" class="w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Playlist</label>
          <select name="playlist_id" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            <?php foreach ($playlists as $p): ?>
              <option value="<?= $p['id'] ?>" <?= (($editing['playlist_id'] ?? $preselectedPlaylist) == $p['id']) ? 'selected' : '' ?>><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Start date</label>
            <input type="date" name="start_date" value="<?= e($editing['start_date'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">End date</label>
            <input type="date" name="end_date" value="<?= e($editing['end_date'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Start time</label>
            <input type="time" name="start_time" value="<?= e(substr($editing['start_time'] ?? '', 0, 5)) ?>" class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">End time</label>
            <input type="time" name="end_time" value="<?= e(substr($editing['end_time'] ?? '', 0, 5)) ?>" class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Days of week</label>
          <div class="flex flex-wrap gap-1">
            <?php foreach ($DOW as $num => $lbl): ?>
              <label class="cursor-pointer">
                <input type="checkbox" name="days[]" value="<?= $num ?>" class="peer sr-only" <?= in_array($num, $editDays, true) ? 'checked' : '' ?>>
                <span class="inline-block px-2.5 py-1 rounded-lg border text-xs font-semibold peer-checked:bg-rose-600 peer-checked:text-white peer-checked:border-rose-600"><?= $lbl ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Priority</label>
          <input type="number" name="priority" value="<?= (int) ($editing['priority'] ?? 0) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" name="is_enabled" <?= ($editing['is_enabled'] ?? 1) ? 'checked' : '' ?>> Enabled
        </label>
        <div class="flex gap-2 pt-1">
          <button class="bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl px-5 py-2 transition-colors"><?= $editing ? 'Update' : 'Create' ?></button>
          <?php if ($editing): ?><a href="<?= e($scheduleReturnUrl) ?>" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-semibold hover:bg-slate-50 transition-colors">Cancel</a><?php endif; ?>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="lg:col-span-2 space-y-4">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-bold text-slate-800">Weekly calendar</h2>
        <span class="text-xs text-slate-400">Enabled schedules only</span>
      </div>
      <div class="grid grid-cols-7 gap-2 overflow-x-auto min-w-[42rem]">
        <?php foreach ($DOW as $dayNum => $label): ?>
          <div>
            <div class="text-xs font-semibold text-slate-500 text-center mb-1"><?= e($label) ?></div>
            <div class="relative h-96 rounded-lg bg-slate-50 border border-slate-200 overflow-hidden">
              <?php foreach ([25, 50, 75] as $line): ?>
                <div class="absolute left-0 right-0 border-t border-slate-200" style="top:<?= $line ?>%"></div>
              <?php endforeach; ?>
              <?php foreach ($calendarBlocks[$dayNum] as $block): ?>
                <div class="absolute left-1 right-1 rounded-md bg-rose-600/90 text-white px-2 py-1 overflow-hidden shadow-sm" style="top:<?= $block['top'] ?>%; height:<?= $block['height'] ?>%">
                  <div class="text-[11px] font-semibold leading-tight truncate"><?= e($block['name']) ?></div>
                  <div class="text-[10px] leading-tight opacity-90 truncate"><?= e($block['time']) ?> · P<?= (int) $block['priority'] ?></div>
                  <div class="text-[10px] leading-tight opacity-80 truncate"><?= e($block['playlist']) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="space-y-3">
    <?php if (!$schedules): ?><p class="text-slate-500">No schedules yet.</p><?php endif; ?>
    <?php foreach ($schedules as $s):
      $days = ($s['days_of_week'] === null || $s['days_of_week'] === '') ? 'Every day'
            : implode(' ', array_map(fn($d) => $DOW[(int) $d], explode(',', $s['days_of_week'])));
      $dateRange = ($s['start_date'] || $s['end_date']) ? (($s['start_date'] ?: '...') . ' -> ' . ($s['end_date'] ?: '...')) : 'Any date';
      $timeRange = ($s['start_time'] || $s['end_time']) ? (substr($s['start_time'] ?: '...', 0, 5) . ' - ' . substr($s['end_time'] ?: '...', 0, 5)) : 'All day';
    ?>
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 <?= $s['is_enabled'] ? '' : 'opacity-60' ?>">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-start gap-3">
            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600">
              <i data-lucide="calendar-clock" class="h-4 w-4"></i>
            </span>
            <div>
              <p class="font-semibold text-slate-800"><?= e($s['name']) ?>
                <span class="text-xs bg-slate-100 rounded px-1.5 py-0.5 text-slate-500">P<?= (int) $s['priority'] ?></span>
                <?php if (!$s['is_enabled']): ?><span class="text-xs text-red-500">disabled</span><?php endif; ?>
              </p>
              <p class="text-sm text-slate-500 mt-1 flex items-center gap-1">
                <i data-lucide="list-video" class="h-3.5 w-3.5 text-slate-400"></i> <?= e($s['playlist_name']) ?>
              </p>
              <p class="text-xs text-slate-500 mt-1"><?= e($dateRange) ?> · <?= e($timeRange) ?> · <?= e($days) ?></p>
            </div>
          </div>
          <div class="flex items-center gap-3 shrink-0">
            <?php if ($s['source_share_id']): ?>
              <span class="flex items-center gap-1 text-xs font-semibold text-slate-400" title="Locked by the sharing company">
                <i data-lucide="lock" class="h-3 w-3"></i> Shared - locked
              </span>
              <a href="shares.php" class="text-sm font-semibold text-rose-600 hover:underline">Manage</a>
            <?php else: ?>
            <form method="post"><?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button class="text-xs font-semibold text-slate-500 hover:underline"><?= $s['is_enabled'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <a href="schedule.php?edit=<?= $s['id'] ?>" class="text-sm font-semibold text-rose-600 hover:underline">Edit</a>
            <form method="post" onsubmit="return confirm('Delete this schedule?')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button class="text-sm font-semibold text-red-600 hover:underline">Delete</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>
<?php if ($panelMode): ?>
</div>
</body>
</html>
<?php else: ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
