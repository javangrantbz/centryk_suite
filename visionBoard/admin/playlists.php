<?php
$active = 'playlists';
$pageTitle = 'Playlists';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pdo = db();
$companyId = vb_cid();

/** Renumber a playlist's items 0..n after any structural change. */
function resequence(PDO $pdo, int $playlistId): void
{
    $ids = $pdo->prepare('SELECT id FROM vb_playlist_items WHERE playlist_id=? ORDER BY position ASC, id ASC');
    $ids->execute([$playlistId]);
    $pos = 0;
    $upd = $pdo->prepare('UPDATE vb_playlist_items SET position=? WHERE id=?');
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $piId) {
        $upd->execute([$pos++, $piId]);
    }
}

/** True if the playlist belongs to the active company. */
function playlist_owned(PDO $pdo, int $pid, int $companyId): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM vb_playlists WHERE id=? AND company_id=?');
    $s->execute([$pid, $companyId]);
    return (bool) $s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $pid = (int) ($_POST['playlist_id'] ?? 0);

    // Every action except creating a new playlist operates on an existing
    // playlist — reject any that isn't owned by the active company.
    if ($action !== 'create_playlist' && !playlist_owned($pdo, $pid, $companyId)) {
        http_response_code(404);
        die('Playlist not found.');
    }

    switch ($action) {
        case 'create_playlist':
            $stmt = $pdo->prepare('INSERT INTO vb_playlists (company_id, name, description) VALUES (?,?,?)');
            $stmt->execute([$companyId, trim($_POST['name'] ?: 'Untitled playlist'), trim($_POST['description'] ?? '')]);
            log_activity('created', 'playlist', (int) $pdo->lastInsertId(), trim($_POST['name'] ?: 'Untitled playlist'));
            flash('Playlist created.');
            redirect('playlists.php?edit=' . $pdo->lastInsertId());

        case 'update_playlist':
            $pdo->prepare('UPDATE vb_playlists SET name=?, description=?, is_active=? WHERE id=? AND company_id=?')
                ->execute([trim($_POST['name']), trim($_POST['description'] ?? ''), isset($_POST['is_active'])?1:0, $pid, $companyId]);
            log_activity('updated', 'playlist', $pid, trim($_POST['name']));
            flash('Playlist saved.');
            redirect('playlists.php?edit=' . $pid);

        case 'delete_playlist':
            $pdo->prepare('DELETE FROM vb_playlists WHERE id=? AND company_id=?')->execute([$pid, $companyId]);
            log_activity('deleted', 'playlist', $pid);
            flash('Playlist deleted.');
            redirect('playlists.php');

        case 'duplicate_playlist':
            $src = $pdo->prepare('SELECT * FROM vb_playlists WHERE id=? AND company_id=?');
            $src->execute([$pid, $companyId]);
            $playlist = $src->fetch();
            if ($playlist) {
                $pdo->prepare('INSERT INTO vb_playlists (company_id, name, description, is_active) VALUES (?,?,?,?)')
                    ->execute([$companyId, $playlist['name'] . ' copy', $playlist['description'], $playlist['is_active']]);
                $newId = (int) $pdo->lastInsertId();
                $copyItems = $pdo->prepare('SELECT * FROM vb_playlist_items WHERE playlist_id=? ORDER BY position ASC, id ASC');
                $copyItems->execute([$pid]);
                $ins = $pdo->prepare('INSERT INTO vb_playlist_items (playlist_id, content_item_id, position, duration_override) VALUES (?,?,?,?)');
                foreach ($copyItems as $row) {
                    $ins->execute([$newId, $row['content_item_id'], $row['position'], $row['duration_override']]);
                }
                log_activity('duplicated', 'playlist', $newId, 'from #' . $pid);
                flash('Playlist duplicated.');
                redirect('playlists.php?edit=' . $newId);
            }
            redirect('playlists.php');

        case 'add_item':
            $contentId = (int) $_POST['content_item_id'];
            // The content item must belong to this company.
            $own = $pdo->prepare('SELECT COUNT(*) FROM vb_content_items WHERE id=? AND company_id=?');
            $own->execute([$contentId, $companyId]);
            if (!$own->fetchColumn()) {
                flash('That content item is not available.', 'error');
                redirect('playlists.php?edit=' . $pid);
            }
            $dur = ($_POST['duration_override'] ?? '') !== '' ? max(1,(int)$_POST['duration_override']) : null;
            $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM vb_playlist_items WHERE playlist_id=?');
            $maxStmt->execute([$pid]);
            $maxPos = (int) $maxStmt->fetchColumn();
            $pdo->prepare('INSERT INTO vb_playlist_items (playlist_id, content_item_id, position, duration_override) VALUES (?,?,?,?)')
                ->execute([$pid, $contentId, $maxPos, $dur]);
            log_activity('added_item', 'playlist', $pid, 'content #' . $contentId);
            flash('Item added to playlist.');
            redirect('playlists.php?edit=' . $pid);

        case 'remove_item':
            $pdo->prepare('DELETE FROM vb_playlist_items WHERE id=? AND playlist_id=?')->execute([(int)$_POST['item_id'], $pid]);
            resequence($pdo, $pid);
            log_activity('removed_item', 'playlist', $pid, 'playlist item #' . (int) $_POST['item_id']);
            redirect('playlists.php?edit=' . $pid);

        case 'set_duration':
            $dur = ($_POST['duration_override'] ?? '') !== '' ? max(1,(int)$_POST['duration_override']) : null;
            $pdo->prepare('UPDATE vb_playlist_items SET duration_override=? WHERE id=? AND playlist_id=?')
                ->execute([$dur, (int)$_POST['item_id'], $pid]);
            flash('Duration updated.');
            redirect('playlists.php?edit=' . $pid);

        case 'reorder_items':
            $order = array_filter(array_map('intval', explode(',', $_POST['order'] ?? '')));
            $upd = $pdo->prepare('UPDATE vb_playlist_items SET position=? WHERE id=? AND playlist_id=?');
            foreach ($order as $pos => $itemId) {
                $upd->execute([$pos, $itemId, $pid]);
            }
            resequence($pdo, $pid);
            log_activity('reordered', 'playlist', $pid);
            flash('Playlist order saved.');
            redirect('playlists.php?edit=' . $pid);

        case 'move':
            // Swap position with neighbour.
            $itemId = (int) $_POST['item_id'];
            $dir = $_POST['dir'] === 'up' ? 'up' : 'down';
            $rows = $pdo->prepare('SELECT id, position FROM vb_playlist_items WHERE playlist_id=? ORDER BY position ASC, id ASC');
            $rows->execute([$pid]);
            $list = $rows->fetchAll();
            foreach ($list as $i => $r) {
                if ($r['id'] == $itemId) {
                    $j = $dir === 'up' ? $i - 1 : $i + 1;
                    if ($j >= 0 && $j < count($list)) {
                        $upd = $pdo->prepare('UPDATE vb_playlist_items SET position=? WHERE id=?');
                        $upd->execute([$list[$j]['position'], $list[$i]['id']]);
                        $upd->execute([$list[$i]['position'], $list[$j]['id']]);
                    }
                    break;
                }
            }
            resequence($pdo, $pid);
            log_activity('moved_item', 'playlist', $pid, 'playlist item #' . $itemId . ' ' . $dir);
            redirect('playlists.php?edit=' . $pid);
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM vb_playlists WHERE id=? AND company_id=?');
    $s->execute([(int) $_GET['edit'], $companyId]);
    $editing = $s->fetch() ?: null;
}
require __DIR__ . '/../includes/header.php';
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-xl font-black tracking-tight text-slate-900">Playlists</h1>
  <a href="playlists.php" class="flex items-center gap-1 text-sm font-semibold text-rose-600 hover:underline">
    <i data-lucide="arrow-left" class="h-3.5 w-3.5"></i> All playlists
  </a>
</div>

<?php if (!$editing): ?>
  <!-- List + create -->
  <div class="grid md:grid-cols-3 gap-4">
    <div class="md:col-span-2 space-y-3">
      <?php $plsStmt = $pdo->prepare('SELECT p.*, (SELECT COUNT(*) FROM vb_playlist_items pi WHERE pi.playlist_id=p.id) AS cnt FROM vb_playlists p WHERE p.company_id=? ORDER BY p.created_at DESC');
      $plsStmt->execute([$companyId]);
      $pls = $plsStmt->fetchAll();
      if (!$pls): ?><p class="text-slate-500">No playlists yet.</p><?php endif;
      foreach ($pls as $p): ?>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center justify-between hover:shadow-md transition-shadow">
          <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
              <i data-lucide="list-video" class="h-5 w-5"></i>
            </span>
            <div>
              <p class="font-semibold text-slate-800"><?= e($p['name']) ?>
                <?php if(!$p['is_active']): ?><span class="text-xs text-red-500">(inactive)</span><?php endif; ?></p>
              <p class="text-sm text-slate-500"><?= (int)$p['cnt'] ?> item(s) · <?= e($p['description'] ?: 'No description') ?></p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <a href="shares.php?playlist_id=<?= $p['id'] ?>" class="flex items-center gap-1.5 border border-slate-300 hover:border-rose-300 hover:bg-rose-50 text-slate-700 text-sm font-semibold rounded-xl px-3 py-2 transition-colors">
              <i data-lucide="share-2" class="h-3.5 w-3.5"></i> Share
            </a>
            <a href="playlists.php?edit=<?= $p['id'] ?>" class="bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold rounded-xl px-4 py-2 transition-colors">Edit</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div>
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
        <h2 class="font-bold text-slate-800 mb-3">New playlist</h2>
        <form method="post" class="space-y-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_playlist">
          <input name="name" placeholder="Playlist name" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
          <textarea name="description" rows="2" placeholder="Description (optional)" class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
          <button class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl py-2 transition-colors">Create</button>
        </form>
      </div>
    </div>
  </div>

<?php else:
  $itemsStmt = $pdo->prepare(
    'SELECT pi.*, ci.title, ci.type, ci.duration_seconds, ci.is_active, ci.starts_on, ci.ends_on, m.filename, m.thumbnail_filename, m.kind AS media_kind
     FROM vb_playlist_items pi JOIN vb_content_items ci ON ci.id=pi.content_item_id
     LEFT JOIN vb_media m ON m.id=ci.media_id WHERE pi.playlist_id=? ORDER BY pi.position ASC, pi.id ASC');
  $itemsStmt->execute([$editing['id']]);
  $pItems = $itemsStmt->fetchAll();
  $allContentStmt = $pdo->prepare('SELECT * FROM vb_content_items WHERE company_id=? AND is_active=1 ORDER BY title ASC');
  $allContentStmt->execute([$companyId]);
  $allContent = $allContentStmt->fetchAll();
?>
  <!-- Edit one playlist -->
  <div class="grid lg:grid-cols-3 gap-4">
    <div class="lg:col-span-2 space-y-4">
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
        <form method="post" class="space-y-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_playlist">
          <input type="hidden" name="playlist_id" value="<?= $editing['id'] ?>">
          <div class="flex gap-3">
            <input name="name" value="<?= e($editing['name']) ?>" class="flex-1 rounded-lg border border-slate-300 px-3 py-2 font-medium">
            <label class="flex items-center gap-2 text-sm whitespace-nowrap">
              <input type="checkbox" name="is_active" <?= $editing['is_active']?'checked':'' ?>> Active
            </label>
          </div>
          <input name="description" value="<?= e($editing['description']) ?>" placeholder="Description" class="w-full rounded-lg border border-slate-300 px-3 py-2">
          <button class="bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold rounded-xl px-4 py-2 transition-colors">Save details</button>
        </form>
      </div>

      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
        <div class="flex items-center justify-between gap-3 mb-3">
          <h2 class="font-bold text-slate-800">Items in play order</h2>
          <?php if ($pItems): ?>
            <form method="post" data-sort-form>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reorder_items">
              <input type="hidden" name="playlist_id" value="<?= $editing['id'] ?>">
              <input type="hidden" name="order" value="">
              <button class="text-sm bg-slate-100 hover:bg-slate-200 font-semibold rounded-lg px-3 py-1.5 transition-colors">Save order</button>
            </form>
          <?php endif; ?>
        </div>
        <?php if (!$pItems): ?><p class="text-slate-500 text-sm">No items yet — add some on the right.</p><?php endif; ?>
        <ol class="space-y-2" data-sortable-list>
          <?php foreach ($pItems as $idx => $pi):
            $eff = $pi['duration_override'] ?: $pi['duration_seconds']; ?>
            <li class="flex items-center gap-3 border border-slate-100 rounded-xl p-2 bg-white" draggable="true" data-sort-id="<?= $pi['id'] ?>">
              <button type="button" class="drag-handle text-slate-400 hover:text-slate-700 cursor-grab px-1" title="Drag to reorder">
                <i data-lucide="grip-vertical" class="h-4 w-4"></i>
              </button>
              <span class="text-slate-400 w-6 text-center text-sm" data-sort-index><?= $idx+1 ?></span>
              <div class="w-14 h-10 rounded-lg bg-slate-900 overflow-hidden flex items-center justify-center shrink-0">
                <?php if ($pi['filename'] && $pi['media_kind']==='image'): ?>
                  <img src="<?= thumbnail_url($pi['thumbnail_filename']) ?: media_url($pi['filename']) ?>" class="w-full h-full object-cover">
                <?php elseif ($pi['filename'] && $pi['media_kind']==='video'): ?>
                  <video src="<?= media_url($pi['filename']) ?>" class="w-full h-full object-cover" muted></video>
                <?php else: ?><span class="text-rose-400"><?= content_type_icon($pi['type'], 'h-4 w-4') ?></span><?php endif; ?>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold truncate flex items-center gap-1.5">
                  <span class="text-slate-400 shrink-0"><?= content_type_icon($pi['type'], 'h-3 w-3') ?></span>
                  <?= e($pi['title']) ?>
                </p>
                <form method="post" class="flex items-center gap-1 mt-0.5">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_duration">
                  <input type="hidden" name="playlist_id" value="<?= $editing['id'] ?>">
                  <input type="hidden" name="item_id" value="<?= $pi['id'] ?>">
                  <input name="duration_override" type="number" min="1" value="<?= (int)$eff ?>" class="w-16 text-xs rounded border border-slate-300 px-1 py-0.5">
                  <span class="text-xs text-slate-400">sec</span>
                  <button class="text-xs font-semibold text-rose-600 hover:underline ml-1">set</button>
                </form>
              </div>
              <div class="flex items-center gap-0.5 shrink-0">
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="move">
                  <input type="hidden" name="playlist_id" value="<?= $editing['id'] ?>">
                  <input type="hidden" name="item_id" value="<?= $pi['id'] ?>">
                  <input type="hidden" name="dir" value="up">
                  <button class="text-slate-400 hover:text-slate-700 p-1" title="Move up"><i data-lucide="chevron-up" class="h-4 w-4"></i></button>
                </form>
                <form method="post"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="move">
                  <input type="hidden" name="playlist_id" value="<?= $editing['id'] ?>">
                  <input type="hidden" name="item_id" value="<?= $pi['id'] ?>">
                  <input type="hidden" name="dir" value="down">
                  <button class="text-slate-400 hover:text-slate-700 p-1" title="Move down"><i data-lucide="chevron-down" class="h-4 w-4"></i></button>
                </form>
                <form method="post" onsubmit="return confirm('Remove this item from the playlist?')"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="remove_item">
                  <input type="hidden" name="playlist_id" value="<?= $editing['id'] ?>">
                  <input type="hidden" name="item_id" value="<?= $pi['id'] ?>">
                  <button class="text-red-500 hover:text-red-700 p-1" title="Remove"><i data-lucide="x" class="h-4 w-4"></i></button>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>

      <div class="flex items-center gap-4">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="duplicate_playlist">
          <input type="hidden" name="playlist_id" value="<?= $editing['id'] ?>">
          <button class="text-sm font-semibold text-rose-600 hover:underline">Duplicate this playlist</button>
        </form>
        <form method="post" onsubmit="return confirm('Delete the whole playlist?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_playlist">
          <input type="hidden" name="playlist_id" value="<?= $editing['id'] ?>">
          <button class="text-sm font-semibold text-red-600 hover:underline">Delete this playlist</button>
        </form>
      </div>
    </div>

    <div>
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sticky top-4">
        <h2 class="font-bold text-slate-800 mb-3">Add content</h2>
        <?php if (!$allContent): ?>
          <p class="text-sm text-slate-500">No active content. <a class="text-rose-600 font-semibold hover:underline" href="content.php">Create some</a>.</p>
        <?php else: ?>
        <form method="post" class="space-y-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_item">
          <input type="hidden" name="playlist_id" value="<?= $editing['id'] ?>">
          <select name="content_item_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
            <?php foreach ($allContent as $c): ?>
              <option value="<?= $c['id'] ?>">[<?= strtoupper($c['type']) ?>] <?= e($c['title']) ?></option>
            <?php endforeach; ?>
          </select>
          <input name="duration_override" type="number" min="1" placeholder="Duration override (optional)" class="w-full rounded-lg border border-slate-300 px-3 py-2">
          <button class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl py-2 transition-colors">Add to playlist</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
