<?php
$active = 'shares';
$pageTitle = 'Sharing';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin(); // crosses company boundaries, so admin-only
$companyId = vb_cid();
$pdo = db();
$DOW = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];

/** True if the playlist belongs to the active company. */
function shares_playlist_owned(PDO $pdo, int $pid, int $companyId): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM vb_playlists WHERE id=? AND company_id=?');
    $s->execute([$pid, $companyId]);
    return (bool) $s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'share') {
        $playlistId = (int) ($_POST['playlist_id'] ?? 0);
        $targetId   = (int) ($_POST['target_company_id'] ?? 0);
        $mode       = ($_POST['mode'] ?? '') === 'editable' ? 'editable' : 'locked';

        if (!shares_playlist_owned($pdo, $playlistId, $companyId)) {
            flash('Choose one of your own playlists to share.', 'error');
            redirect('shares.php');
        }
        if ($targetId === $companyId || !$targetId) {
            flash('Choose a different company to share with.', 'error');
            redirect('shares.php');
        }
        $target = $pdo->prepare("SELECT id, name FROM companies WHERE id=? AND status='active'");
        $target->execute([$targetId]);
        $target = $target->fetch();
        if (!$target) {
            flash('That company was not found.', 'error');
            redirect('shares.php');
        }

        // (playlist_id, shared_with_company_id) is unique at the DB level even
        // across history, so a prior revoked/declined share must be reused
        // (reset to pending) rather than inserted as a second row.
        $existing = $pdo->prepare('SELECT id, status FROM vb_playlist_shares WHERE playlist_id=? AND shared_with_company_id=?');
        $existing->execute([$playlistId, $targetId]);
        $existing = $existing->fetch();
        if ($existing && in_array($existing['status'], ['pending', 'accepted'], true)) {
            flash('This playlist is already shared with ' . $target['name'] . '.', 'error');
            redirect('shares.php');
        }

        $days = isset($_POST['days']) ? implode(',', array_map('intval', $_POST['days'])) : null;
        $values = [
            $mode,
            $mode === 'locked' ? ($_POST['start_date'] ?: null) : null,
            $mode === 'locked' ? ($_POST['end_date'] ?: null) : null,
            $mode === 'locked' ? ($_POST['start_time'] ?: null) : null,
            $mode === 'locked' ? ($_POST['end_time'] ?: null) : null,
            $mode === 'locked' ? $days : null,
            $mode === 'locked' ? (int) ($_POST['priority'] ?? 0) : 0,
        ];
        if ($existing) {
            $pdo->prepare(
                "UPDATE vb_playlist_shares SET mode=?, status='pending', locked_start_date=?, locked_end_date=?, locked_start_time=?, locked_end_time=?, locked_days_of_week=?, locked_priority=?, responded_at=NULL
                 WHERE id=?"
            )->execute([...$values, $existing['id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO vb_playlist_shares
                 (playlist_id, owner_company_id, shared_with_company_id, mode, locked_start_date, locked_end_date, locked_start_time, locked_end_time, locked_days_of_week, locked_priority)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([$playlistId, $companyId, $targetId, ...$values]);
        }
        log_activity('shared', 'playlist', $playlistId, 'shared with ' . $target['name'] . ' (' . $mode . ')');
        flash('Playlist shared with ' . $target['name'] . '.');
        redirect('shares.php');
    }

    if ($action === 'revoke') {
        $shareId = (int) ($_POST['share_id'] ?? 0);
        $share = $pdo->prepare('SELECT * FROM vb_playlist_shares WHERE id=? AND owner_company_id=?');
        $share->execute([$shareId, $companyId]);
        $share = $share->fetch();
        if ($share) {
            $pdo->prepare("UPDATE vb_playlist_shares SET status='revoked' WHERE id=?")->execute([$shareId]);
            vb_unlink_share_schedules($shareId, (int) $share['shared_with_company_id'], (int) $share['playlist_id']);
            log_activity('revoked', 'playlist', (int) $share['playlist_id'], 'share revoked');
            flash('Share revoked.');
        }
        redirect('shares.php');
    }

    if (in_array($action, ['accept', 'decline', 'remove'], true)) {
        $shareId = (int) ($_POST['share_id'] ?? 0);
        $share = $pdo->prepare('SELECT * FROM vb_playlist_shares WHERE id=? AND shared_with_company_id=?');
        $share->execute([$shareId, $companyId]);
        $share = $share->fetch();
        if (!$share) {
            redirect('shares.php');
        }

        if ($action === 'accept' && $share['status'] === 'pending') {
            $pdo->prepare("UPDATE vb_playlist_shares SET status='accepted', responded_at=NOW() WHERE id=?")->execute([$shareId]);
            if ($share['mode'] === 'locked') {
                $share['status'] = 'accepted';
                $share['playlist_name'] = $pdo->prepare('SELECT name FROM vb_playlists WHERE id=?');
                $share['playlist_name']->execute([$share['playlist_id']]);
                $share['playlist_name'] = $share['playlist_name']->fetchColumn();
                vb_apply_locked_schedule($share);
            }
            log_activity('accepted', 'playlist', (int) $share['playlist_id'], 'share accepted');
            flash('Share accepted.');
        } elseif ($action === 'decline' && $share['status'] === 'pending') {
            $pdo->prepare("UPDATE vb_playlist_shares SET status='declined', responded_at=NOW() WHERE id=?")->execute([$shareId]);
            log_activity('declined', 'playlist', (int) $share['playlist_id'], 'share declined');
            flash('Share declined.');
        } elseif ($action === 'remove' && $share['status'] === 'accepted') {
            $pdo->prepare("UPDATE vb_playlist_shares SET status='declined', responded_at=NOW() WHERE id=?")->execute([$shareId]);
            vb_unlink_share_schedules($shareId, $companyId, (int) $share['playlist_id']);
            log_activity('declined', 'playlist', (int) $share['playlist_id'], 'share removed by recipient');
            flash('Removed.');
        }
        redirect('shares.php');
    }
}

$ownPlaylists = $pdo->prepare('SELECT id, name FROM vb_playlists WHERE company_id=? ORDER BY name ASC');
$ownPlaylists->execute([$companyId]);
$ownPlaylists = $ownPlaylists->fetchAll();

$companies = $pdo->prepare("SELECT id, name FROM companies WHERE status='active' AND directory_visible=1 AND id != ? ORDER BY name ASC");
$companies->execute([$companyId]);
$companies = $companies->fetchAll();

$outgoing = vb_shares_outgoing($companyId);
$incoming = vb_shares_incoming($companyId);
$preselectPlaylist = (int) ($_GET['playlist_id'] ?? 0);

require __DIR__ . '/../includes/header.php';
?>
<h1 class="text-xl font-black tracking-tight text-slate-900 mb-1">Sharing</h1>
<p class="text-slate-500 mb-3 text-sm max-w-2xl">
  Share one of your playlists with another company. <b>Locked</b> means they get your exact play window and can't change it.
  <b>Editable</b> means they schedule it on their own screens with their own dates, times, and priority.
</p>

<div class="grid lg:grid-cols-3 gap-4">
  <div class="lg:col-span-2 space-y-6">

    <div>
      <h2 class="font-bold text-slate-800 mb-2">Shared by me</h2>
      <?php if (!$outgoing): ?><p class="text-slate-500 text-sm">You haven't shared any playlists yet.</p><?php endif; ?>
      <div class="space-y-2">
        <?php foreach ($outgoing as $sh):
          $badge = [
              'pending'  => 'bg-amber-100 text-amber-800',
              'accepted' => 'bg-emerald-100 text-emerald-800',
              'declined' => 'bg-slate-100 text-slate-500',
              'revoked'  => 'bg-slate-100 text-slate-500',
          ][$sh['status']];
        ?>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="font-semibold text-slate-800 truncate"><?= e($sh['playlist_name']) ?>
              <span class="text-slate-400 font-normal">&rarr;</span> <?= e($sh['recipient_company_name']) ?>
            </p>
            <p class="text-xs text-slate-500 mt-0.5">
              <?= $sh['mode'] === 'locked' ? 'Locked' : 'Editable' ?>
              · <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase <?= $badge ?>"><?= e($sh['status']) ?></span>
            </p>
          </div>
          <?php if (in_array($sh['status'], ['pending', 'accepted'], true)): ?>
          <form method="post" onsubmit="return confirm('Revoke this share? It will stop playing immediately.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="share_id" value="<?= (int) $sh['id'] ?>">
            <button class="text-xs text-red-600 hover:underline whitespace-nowrap">Revoke</button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div>
      <h2 class="font-bold text-slate-800 mb-2">Shared with me</h2>
      <?php if (!$incoming): ?><p class="text-slate-500 text-sm">No one has shared a playlist with you yet.</p><?php endif; ?>
      <div class="space-y-2">
        <?php foreach ($incoming as $sh): ?>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
          <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
              <p class="font-semibold text-slate-800 truncate"><?= e($sh['owner_company_name']) ?>
                <span class="text-slate-400 font-normal">&rarr;</span> <?= e($sh['playlist_name']) ?>
              </p>
              <p class="text-xs text-slate-500 mt-0.5"><?= $sh['mode'] === 'locked' ? 'Locked (fixed play window)' : 'Editable (schedule it yourself)' ?></p>
            </div>
            <?php if ($sh['status'] === 'pending'): ?>
            <div class="flex items-center gap-2 shrink-0">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="accept">
                <input type="hidden" name="share_id" value="<?= (int) $sh['id'] ?>">
                <button class="text-xs bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-lg px-3 py-1.5">Accept</button>
              </form>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="decline">
                <input type="hidden" name="share_id" value="<?= (int) $sh['id'] ?>">
                <button class="text-xs text-slate-500 hover:underline">Decline</button>
              </form>
            </div>
            <?php elseif ($sh['status'] === 'accepted'): ?>
            <form method="post" onsubmit="return confirm('Remove this shared playlist?')" class="shrink-0">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="share_id" value="<?= (int) $sh['id'] ?>">
              <button class="text-xs text-red-600 hover:underline">Remove</button>
            </form>
            <?php else: ?>
            <span class="text-xs text-slate-400 shrink-0"><?= e(ucfirst($sh['status'])) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($sh['status'] === 'accepted' && $sh['mode'] === 'locked'): ?>
          <p class="text-xs text-slate-400 mt-2 pt-2 border-t border-slate-100">
            Playing automatically — <?= e($sh['owner_company_name']) ?> controls when.
            <?php if ($sh['locked_start_date'] || $sh['locked_end_date']): ?>
              <?= e($sh['locked_start_date'] ?: '…') ?> to <?= e($sh['locked_end_date'] ?: '…') ?>.
            <?php endif; ?>
            <?php if ($sh['locked_start_time'] && $sh['locked_end_time']): ?>
              <?= e(substr($sh['locked_start_time'], 0, 5)) ?>–<?= e(substr($sh['locked_end_time'], 0, 5)) ?>.
            <?php endif; ?>
          </p>
          <?php elseif ($sh['status'] === 'accepted'): ?>
          <p class="text-xs text-slate-400 mt-2 pt-2 border-t border-slate-100">
            Available in your <a href="schedule.php" class="text-rose-600 hover:underline">Schedules</a> playlist picker — set your own dates, times, and priority.
          </p>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <div>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sticky top-4">
      <h2 class="font-bold text-slate-800 mb-3">Share a playlist</h2>
      <?php if (!$ownPlaylists): ?>
      <p class="text-slate-500 text-sm">Create a playlist first.</p>
      <?php elseif (!$companies): ?>
      <p class="text-slate-500 text-sm">No other companies are available to share with.</p>
      <?php else: ?>
      <form method="post" class="space-y-3" id="shareForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="share">

        <label class="block text-xs font-semibold uppercase text-slate-400">Playlist</label>
        <select name="playlist_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
          <?php foreach ($ownPlaylists as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= $preselectPlaylist === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="block text-xs font-semibold uppercase text-slate-400">Share with</label>
        <select name="target_company_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
          <option value="">Choose a company…</option>
          <?php foreach ($companies as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="block text-xs font-semibold uppercase text-slate-400">Mode</label>
        <div class="space-y-1.5">
          <label class="flex items-start gap-2 text-sm">
            <input type="radio" name="mode" value="locked" checked onchange="document.getElementById('lockedFields').classList.remove('hidden')" class="mt-0.5">
            <span><b>Locked</b> — plays on your schedule, they can't change it</span>
          </label>
          <label class="flex items-start gap-2 text-sm">
            <input type="radio" name="mode" value="editable" onchange="document.getElementById('lockedFields').classList.add('hidden')" class="mt-0.5">
            <span><b>Editable</b> — they set their own dates, times, priority</span>
          </label>
        </div>

        <div id="lockedFields" class="space-y-2 rounded-lg bg-slate-50 p-3">
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-[10px] font-semibold uppercase text-slate-400">Start date</label>
              <input type="date" name="start_date" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <div>
              <label class="block text-[10px] font-semibold uppercase text-slate-400">End date</label>
              <input type="date" name="end_date" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <div>
              <label class="block text-[10px] font-semibold uppercase text-slate-400">Start time</label>
              <input type="time" name="start_time" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <div>
              <label class="block text-[10px] font-semibold uppercase text-slate-400">End time</label>
              <input type="time" name="end_time" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
            </div>
          </div>
          <div>
            <label class="block text-[10px] font-semibold uppercase text-slate-400 mb-1">Days (leave blank for every day)</label>
            <div class="flex flex-wrap gap-2">
              <?php foreach ($DOW as $num => $label): ?>
              <label class="flex items-center gap-1 text-xs bg-white border border-slate-300 rounded-lg px-2 py-1">
                <input type="checkbox" name="days[]" value="<?= $num ?>"> <?= $label ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <label class="block text-[10px] font-semibold uppercase text-slate-400">Priority</label>
            <input type="number" name="priority" value="0" class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
          </div>
        </div>

        <button class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl py-2 transition-colors">Share</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
