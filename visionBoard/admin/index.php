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
    'screens'   => $countScoped('vb_screens'),
];
[$activePlaylist, $activeItems] = resolve_active_playlist($cid);
$displayStatus = get_company_display_status($cid);
$activeAnnouncement = get_active_announcement($cid);
$isOnline = (bool) ($displayStatus['is_online'] ?? false);

// Prefer what the TV actually reports; fall back to the server-resolved
// playlist when no screen has ever checked in (so "ready to play" still
// shows something useful instead of a blank state).
$nowTitle = $displayStatus['current_title'] ?? ($activeItems[0]['title'] ?? null);
$nowType  = $displayStatus['current_type']  ?? ($activeItems[0]['type']  ?? null);
$nextTitle = $displayStatus['next_title'] ?? ($activeItems[1]['title'] ?? ($activeItems[0]['title'] ?? null));
$nextType  = $displayStatus['next_type']  ?? ($activeItems[1]['type']  ?? ($activeItems[0]['type']  ?? null));
$lastSeenText = 'No check-in yet';
if ($displayStatus && $displayStatus['seconds_since_seen'] !== null) {
    $seconds = (int) $displayStatus['seconds_since_seen'];
    $lastSeenText = $seconds < 5 ? 'just now' : ($seconds < 60 ? $seconds . ' sec ago' : floor($seconds / 60) . ' min ago');
}
$isLive = $isOnline && $nowTitle;
$hasSetup = $stats['media'] > 0 || $stats['content'] > 0 || $stats['playlists'] > 0;

$recentMedia = $pdo->prepare('SELECT * FROM vb_media WHERE company_id = ? ORDER BY created_at DESC LIMIT 6');
$recentMedia->execute([$cid]);
$recentMedia = $recentMedia->fetchAll();
?>
<div class="flex items-center justify-between mb-3">
  <h1 class="text-xl font-black tracking-tight text-slate-900">Dashboard</h1>
  <a href="screens.php" class="text-xs font-semibold text-slate-400 hover:text-rose-600"><?= $stats['screens'] ?> screen<?= $stats['screens'] === 1 ? '' : 's' ?></a>
</div>

<div class="grid lg:grid-cols-3 gap-4">
  <!-- Live hero -->
  <div class="lg:col-span-2 space-y-4">

    <?php if ($isLive): ?>
    <div class="bg-slate-900 rounded-2xl shadow-sm p-5 text-white relative overflow-hidden">
      <div class="flex items-center justify-between mb-3">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-black uppercase tracking-wider text-emerald-400">
          <span class="relative flex h-2 w-2">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
          </span>
          Live
        </span>
        <span class="text-xs text-slate-400">Checked in <?= e($lastSeenText) ?></span>
      </div>
      <div class="flex items-center gap-2 text-slate-400 text-xs font-semibold uppercase tracking-wide mb-1">
        <?= content_type_icon($nowType ?: 'image', 'h-3.5 w-3.5') ?> Now playing
      </div>
      <div class="text-2xl font-black tracking-tight mb-3"><?= e($nowTitle) ?></div>
      <div class="flex items-center gap-2 text-sm text-slate-300 border-t border-white/10 pt-3">
        <i data-lucide="arrow-right" class="h-4 w-4 text-slate-500 shrink-0"></i>
        <span class="text-slate-500 font-semibold uppercase text-xs">Next</span>
        <span class="font-semibold truncate"><?= e($nextTitle ?: '—') ?></span>
        <?php if (!empty($displayStatus['playlist_name'])): ?>
          <span class="ml-auto text-xs text-slate-500 shrink-0"><?= e($displayStatus['playlist_name']) ?><?php if (!empty($displayStatus['current_index']) && !empty($displayStatus['item_count'])): ?> · <?= (int)$displayStatus['current_index'] ?>/<?= (int)$displayStatus['item_count'] ?><?php endif; ?></span>
        <?php endif; ?>
      </div>
    </div>

    <?php elseif ($activePlaylist): ?>
    <div class="bg-white rounded-2xl border border-amber-200 shadow-sm p-5">
      <div class="flex items-center justify-between mb-3">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-black uppercase tracking-wider text-amber-700">
          <i data-lucide="clock-3" class="h-3 w-3"></i> Ready, waiting for a screen
        </span>
      </div>
      <div class="text-lg font-black text-slate-900 mb-1"><?= e($activePlaylist['name']) ?></div>
      <p class="text-sm text-slate-500 mb-3"><?= count($activeItems) ?> item(s) queued — this will start the moment a TV connects.</p>
      <a href="screens.php" class="inline-flex items-center gap-1.5 bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold rounded-xl px-4 py-2 transition-colors">
        <i data-lucide="monitor" class="h-4 w-4"></i> Set up a screen
      </a>
    </div>

    <?php else: ?>
    <div class="bg-white rounded-2xl border-2 border-dashed border-slate-200 shadow-sm p-6 text-center">
      <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-100 text-rose-600 mb-3">
        <i data-lucide="tv" class="h-6 w-6"></i>
      </span>
      <div class="text-lg font-black text-slate-900">Nothing is playing yet</div>
      <p class="text-sm text-slate-500 mt-1 mb-4 max-w-sm mx-auto">
        <?= $hasSetup ? 'You have content but nothing is scheduled. Build a playlist and schedule it to start displaying.' : 'Set up your content and start displaying.' ?>
      </p>
      <div class="flex items-center justify-center gap-2">
        <a href="content.php" class="inline-flex items-center gap-1.5 bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold rounded-xl px-4 py-2 transition-colors">
          <i data-lucide="plus" class="h-4 w-4"></i> Add content
        </a>
        <a href="playlists.php" class="inline-flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-bold rounded-xl px-4 py-2 transition-colors">
          Build a playlist
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Announcement override (compact) -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
      <?php if ($activeAnnouncement): ?>
        <div class="flex items-center justify-between gap-3 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-900 mb-3">
          <span><i data-lucide="megaphone" class="h-3.5 w-3.5 inline -mt-0.5"></i> Until <?= e(date('g:i A', strtotime($activeAnnouncement['expires_at']))) ?>: <b><?= e($activeAnnouncement['message']) ?></b></span>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clear_announcement">
            <button class="text-xs font-bold text-amber-700 hover:underline shrink-0">Clear</button>
          </form>
        </div>
      <?php endif; ?>
      <form method="post" class="flex flex-wrap items-center gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_announcement">
        <input name="message" maxlength="500" required
          class="flex-1 min-w-[12rem] rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-rose-400 focus:ring-2 focus:ring-rose-500/10"
          placeholder="Push an announcement to the TV — e.g. Zoo closing in 15 min">
        <select name="style" class="rounded-lg border border-slate-300 px-2 py-2 text-xs">
          <option value="notice">Notice</option>
          <option value="warning">Warning</option>
          <option value="emergency">Emergency</option>
        </select>
        <input name="minutes" type="number" min="1" max="240" value="15" class="w-16 rounded-lg border border-slate-300 px-2 py-2 text-xs" aria-label="Minutes" title="Minutes on screen">
        <button class="bg-red-600 hover:bg-red-700 text-white text-sm font-bold rounded-xl px-4 py-2 transition-colors">Push</button>
      </form>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
      <h2 class="font-bold text-sm text-slate-800 mb-2 flex items-center gap-2">
        <i data-lucide="rocket" class="h-4 w-4 text-rose-500"></i> Quick start
      </h2>
      <div class="grid sm:grid-cols-2 gap-x-4 gap-y-1.5 text-xs text-slate-600">
        <p><span class="font-black text-rose-600">1.</span> <a class="text-rose-600 font-semibold hover:underline" href="media.php">Upload</a> photos &amp; videos to the Media Library.</p>
        <p><span class="font-black text-rose-600">2.</span> Create <a class="text-rose-600 font-semibold hover:underline" href="content.php">Content</a> items (image, video, or a biography card).</p>
        <p><span class="font-black text-rose-600">3.</span> Group them into a <a class="text-rose-600 font-semibold hover:underline" href="playlists.php">Playlist</a> and set per-item durations.</p>
        <p><span class="font-black text-rose-600">4.</span> Add a <a class="text-rose-600 font-semibold hover:underline" href="schedule.php">Schedule</a> (date range, time, days, repeat).</p>
        <p><span class="font-black text-rose-600">5.</span> Add a <a class="text-rose-600 font-semibold hover:underline" href="screens.php">Screen</a> — each TV gets its own private display link.</p>
        <p><span class="font-black text-rose-600">6.</span> On the TV, open that link in Chrome/Edge kiosk mode (full-screen).</p>
      </div>
      <p class="text-[11px] text-slate-400 mt-2 pt-2 border-t border-slate-100">Each screen's link and pairing controls live on the <a class="text-rose-600 font-semibold hover:underline" href="screens.php">Screens</a> page.</p>
    </div>
  </div>

  <!-- Right panel: KPIs + recent media -->
  <div class="space-y-4">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-2.5">
      <div class="grid grid-cols-4 gap-1.5">
        <?php
        $kpis = [
          ['Media', $stats['media'], 'image', 'media.php'],
          ['Content', $stats['content'], 'file-text', 'content.php'],
          ['Lists', $stats['playlists'], 'list-video', 'playlists.php'],
          ['Sched.', $stats['schedules'], 'calendar-clock', 'schedule.php'],
        ];
        foreach ($kpis as [$label, $num, $icon, $link]): ?>
        <a href="<?= $link ?>" class="flex flex-col items-center rounded-lg bg-slate-50 hover:bg-rose-50 py-1.5 transition-colors">
          <i data-lucide="<?= $icon ?>" class="h-3 w-3 text-rose-500"></i>
          <div class="text-sm font-black text-slate-900 leading-none mt-1"><?= $num ?></div>
          <div class="text-[9px] font-semibold text-slate-500"><?= $label ?></div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-bold text-sm text-slate-800">Recent media</h2>
        <a href="media.php" class="text-xs font-semibold text-rose-600 hover:underline">All →</a>
      </div>
      <?php if (!$recentMedia): ?>
        <a href="media.php" class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 hover:border-rose-300 py-8 text-center transition-colors">
          <i data-lucide="upload-cloud" class="h-6 w-6 text-slate-300"></i>
          <span class="text-xs font-semibold text-slate-500">Upload your first file</span>
        </a>
      <?php else: ?>
        <div class="grid grid-cols-3 gap-2 mb-3">
          <?php foreach ($recentMedia as $m): ?>
            <a href="media.php" class="aspect-square rounded-lg bg-slate-900 overflow-hidden flex items-center justify-center" title="<?= e($m['original_name']) ?>">
              <?php if ($m['kind'] === 'image'): ?>
                <img src="<?= thumbnail_url($m['thumbnail_filename']) ?: media_url($m['filename']) ?>" class="w-full h-full object-cover">
              <?php else: ?>
                <i data-lucide="film" class="h-5 w-5 text-slate-500"></i>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
        <a href="media.php" class="flex items-center justify-center gap-1.5 text-xs font-bold text-rose-600 hover:underline border-t border-slate-100 pt-3">
          <i data-lucide="upload-cloud" class="h-3.5 w-3.5"></i> Upload more
        </a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
