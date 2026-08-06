<?php
$active = 'index';
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$cid = vb_cid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'send_announcement') {
        $message = trim($_POST['message'] ?? '');
        $minutes = min(240, max(1, (int) ($_POST['minutes'] ?? 15)));
        $style = in_array($_POST['style'] ?? '', ['notice','warning','emergency'], true) ? $_POST['style'] : 'notice';
        if ($message === '') {
            flash('Announcement message is required.', 'error');
        } else {
            $expires = (new DateTime('now'))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
            $stmt = db()->prepare(
                'INSERT INTO vb_display_announcements (company_id, message, style, starts_at, expires_at, created_by)
                 VALUES (?, ?, ?, NOW(), ?, ?)'
            );
            $stmt->execute([$cid, $message, $style, $expires, current_user()['id'] ?? null]);
            log_activity('pushed', 'announcement', (int) db()->lastInsertId(), $message);
            flash('Announcement pushed to the TV display.');
        }
        redirect('index.php');
    }
    if ($action === 'clear_announcement') {
        $stmt = db()->prepare('UPDATE vb_display_announcements SET cleared_at = NOW() WHERE company_id = ? AND cleared_at IS NULL AND expires_at > NOW()');
        $stmt->execute([$cid]);
        log_activity('cleared', 'announcement');
        flash('Announcement cleared.');
        redirect('index.php');
    }
}

require_once __DIR__ . '/../includes/header.php';

$pdo = db();
$countScoped = function (string $table, string $extra = '') use ($pdo, $cid): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE company_id = ? $extra");
    $stmt->execute([$cid]);
    return (int) $stmt->fetchColumn();
};
$stats = [
    'media'     => $countScoped('vb_media'),
    'content'   => $countScoped('vb_content_items'),
    'playlists' => $countScoped('vb_playlists'),
    'schedules' => $countScoped('vb_schedules', 'AND is_enabled = 1'),
];
[$activePlaylist, $activeItems] = resolve_active_playlist($cid);
$displayStatus = get_company_display_status($cid);
$activeAnnouncement = get_active_announcement($cid);
$nowTitle = $displayStatus['current_title'] ?? ($activeItems[0]['title'] ?? null);
$nextTitle = $displayStatus['next_title'] ?? ($activeItems[1]['title'] ?? ($activeItems[0]['title'] ?? null));
$lastSeenText = 'No check-in yet';
if ($displayStatus && $displayStatus['seconds_since_seen'] !== null) {
    $seconds = (int) $displayStatus['seconds_since_seen'];
    $lastSeenText = $seconds < 5 ? 'just now' : ($seconds < 60 ? $seconds . ' sec ago' : floor($seconds / 60) . ' min ago');
}
?>
<h1 class="text-2xl font-bold mb-1">Dashboard</h1>
<p class="text-slate-500 mb-6">Overview of your signage system.</p>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
  <?php
  $cards = [
    ['Media files', $stats['media'], '🖼️', 'media.php'],
    ['Content items', $stats['content'], '📄', 'content.php'],
    ['Playlists', $stats['playlists'], '🎞️', 'playlists.php'],
    ['Active schedules', $stats['schedules'], '🗓️', 'schedule.php'],
  ];
  foreach ($cards as [$label, $num, $icon, $link]): ?>
    <a href="<?= $link ?>" class="bg-white rounded-xl shadow-sm p-5 hover:shadow-md transition">
      <div class="text-3xl mb-2"><?= $icon ?></div>
      <div class="text-3xl font-bold text-slate-800"><?= $num ?></div>
      <div class="text-sm text-slate-500"><?= $label ?></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-3 gap-6 mb-6">
  <div class="lg:col-span-2 bg-white rounded-xl shadow-sm p-6">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-4">
      <div>
        <h2 class="font-semibold text-lg flex items-center gap-2">TV status</h2>
        <p class="text-sm text-slate-500">Live heartbeat from the entrance display.</p>
      </div>
      <div class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold <?= ($displayStatus['is_online'] ?? false) ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
        <span class="h-2.5 w-2.5 rounded-full <?= ($displayStatus['is_online'] ?? false) ? 'bg-green-600' : 'bg-red-600' ?>"></span>
        <?= ($displayStatus['is_online'] ?? false) ? 'Display online' : 'Display offline' ?>
      </div>
    </div>

    <div class="grid sm:grid-cols-2 gap-4">
      <div class="rounded-lg border border-slate-200 p-4">
        <div class="text-xs font-semibold uppercase text-slate-400 mb-1">Now</div>
        <div class="text-lg font-semibold text-slate-800"><?= e($nowTitle ?: 'Nothing playing') ?></div>
        <div class="text-sm text-slate-500 mt-1"><?= e($displayStatus['current_type'] ?? ($activeItems[0]['type'] ?? '')) ?></div>
      </div>
      <div class="rounded-lg border border-slate-200 p-4">
        <div class="text-xs font-semibold uppercase text-slate-400 mb-1">Next</div>
        <div class="text-lg font-semibold text-slate-800"><?= e($nextTitle ?: 'No next item') ?></div>
        <div class="text-sm text-slate-500 mt-1"><?= e($displayStatus['next_type'] ?? ($activeItems[1]['type'] ?? '')) ?></div>
      </div>
    </div>

    <div class="mt-4 text-sm text-slate-500">
      Last check-in: <b class="text-slate-700"><?= e($lastSeenText) ?></b>
      <?php if (!empty($displayStatus['playlist_name'])): ?>
        <span class="mx-2 text-slate-300">|</span> Playlist: <b class="text-slate-700"><?= e($displayStatus['playlist_name']) ?></b>
      <?php endif; ?>
      <?php if (!empty($displayStatus['current_index']) && !empty($displayStatus['item_count'])): ?>
        <span class="mx-2 text-slate-300">|</span> Item <?= (int) $displayStatus['current_index'] ?> of <?= (int) $displayStatus['item_count'] ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-sm p-6">
    <h2 class="font-semibold text-lg mb-3">Announcement override</h2>
    <?php if ($activeAnnouncement): ?>
      <div class="mb-4 rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-900">
        Active until <?= e(date('g:i A', strtotime($activeAnnouncement['expires_at']))) ?>:
        <b><?= e($activeAnnouncement['message']) ?></b>
      </div>
      <form method="post" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_announcement">
        <button class="w-full rounded-lg bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 font-medium">Clear now</button>
      </form>
    <?php endif; ?>
    <form method="post" class="space-y-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_announcement">
      <textarea name="message" rows="3" maxlength="500" required
        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-600"
        placeholder="Zoo closing in 15 min"></textarea>
      <div class="grid grid-cols-2 gap-3">
        <select name="style" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option value="notice">Notice</option>
          <option value="warning">Warning</option>
          <option value="emergency">Emergency</option>
        </select>
        <input name="minutes" type="number" min="1" max="240" value="15"
          class="rounded-lg border border-slate-300 px-3 py-2 text-sm" aria-label="Minutes">
      </div>
      <button class="w-full rounded-lg bg-red-700 hover:bg-red-800 text-white px-4 py-2 font-semibold">Push to TV</button>
    </form>
  </div>
</div>

<div class="grid md:grid-cols-2 gap-6">
  <div class="bg-white rounded-xl shadow-sm p-6">
    <h2 class="font-semibold text-lg mb-3 flex items-center gap-2">📺 Playing right now</h2>
    <?php if ($activePlaylist): ?>
      <p class="text-slate-700 mb-1">Playlist: <b><?= e($activePlaylist['name']) ?></b></p>
      <p class="text-sm text-slate-500 mb-3"><?= count($activeItems) ?> item(s) in rotation</p>
      <ol class="text-sm space-y-1 list-decimal list-inside text-slate-600 max-h-48 overflow-auto">
        <?php foreach ($activeItems as $it): ?>
          <li><?= e($it['title']) ?> <span class="text-slate-400">(<?= e($it['type']) ?>)</span></li>
        <?php endforeach; ?>
        <?php if (!$activeItems): ?><li class="list-none text-slate-400">No active items in this playlist.</li><?php endif; ?>
      </ol>
    <?php else: ?>
      <p class="text-slate-500">Nothing scheduled. Create a playlist and schedule to get started.</p>
    <?php endif; ?>
    <a href="screens.php"
       class="inline-block mt-4 bg-green-700 hover:bg-green-800 text-white text-sm font-medium rounded-lg px-4 py-2">
      Manage screens →
    </a>
  </div>

  <div class="bg-white rounded-xl shadow-sm p-6">
    <h2 class="font-semibold text-lg mb-3">🚀 Quick start</h2>
    <ol class="list-decimal list-inside space-y-2 text-slate-600 text-sm">
      <li><a class="text-green-700 underline" href="media.php">Upload</a> photos & videos to the Media Library.</li>
      <li>Create <a class="text-green-700 underline" href="content.php">Content</a> items (image, video, or a biography card).</li>
      <li>Group them into a <a class="text-green-700 underline" href="playlists.php">Playlist</a> and set per-item durations.</li>
      <li>Add a <a class="text-green-700 underline" href="schedule.php">Schedule</a> (date range, time, days, repeat).</li>
      <li>Add a <a class="text-green-700 underline" href="screens.php">Screen</a> — each TV gets its own private display link.</li>
      <li>On the TV, open that link in Chrome/Edge kiosk mode (full-screen).</li>
    </ol>
    <p class="text-xs text-slate-400 mt-4">Each screen's link and pairing controls live on the <a class="text-green-700 underline" href="screens.php">Screens</a> page.</p>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
