<?php
$active = 'content';
$pageTitle = 'Content';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$cid = vb_cid();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $pdo->prepare('DELETE FROM vb_content_items WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
        log_activity('deleted', 'content_item', $id);
        flash('Content item deleted.');
        redirect('content.php');
    }

    if ($action === 'save') {
        $id       = (int) ($_POST['id'] ?? 0);
        $type     = in_array($_POST['type'] ?? '', ['image','video','biography'], true) ? $_POST['type'] : 'image';
        $title    = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '') ?: null;
        $body     = trim($_POST['body'] ?? '') ?: null;
        $mediaId  = ($_POST['media_id'] ?? '') !== '' ? (int) $_POST['media_id'] : null;
        $duration = max(1, (int) ($_POST['duration_seconds'] ?? DEFAULT_DURATION));
        $startsOn = ($_POST['starts_on'] ?? '') !== '' ? $_POST['starts_on'] : null;
        $endsOn   = ($_POST['ends_on'] ?? '') !== '' ? $_POST['ends_on'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($title === '') {
            flash('Title is required.', 'error');
            redirect('content.php');
        }
        // Media required for image/video.
        if (in_array($type, ['image','video'], true) && !$mediaId) {
            flash('Please choose a media file for image/video content.', 'error');
            redirect('content.php');
        }
        // A referenced media file must belong to this company.
        if ($mediaId) {
            $chk = $pdo->prepare('SELECT COUNT(*) FROM vb_media WHERE id = ? AND company_id = ?');
            $chk->execute([$mediaId, $cid]);
            if (!$chk->fetchColumn()) {
                flash('Selected media is not available.', 'error');
                redirect('content.php');
            }
        }

        if ($id) {
            $stmt = $pdo->prepare(
                'UPDATE vb_content_items SET title=?, type=?, media_id=?, subtitle=?, body=?, duration_seconds=?, starts_on=?, ends_on=?, is_active=? WHERE id=? AND company_id=?'
            );
            $stmt->execute([$title, $type, $mediaId, $subtitle, $body, $duration, $startsOn, $endsOn, $isActive, $id, $cid]);
            log_activity('updated', 'content_item', $id, $title);
            flash('Content updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO vb_content_items (company_id, title, type, media_id, subtitle, body, duration_seconds, starts_on, ends_on, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$cid, $title, $type, $mediaId, $subtitle, $body, $duration, $startsOn, $endsOn, $isActive]);
            log_activity('created', 'content_item', (int) $pdo->lastInsertId(), $title);
            flash('Content created.');
        }
        redirect('content.php');
    }
}

$editing = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM vb_content_items WHERE id = ? AND company_id = ?');
    $s->execute([(int) $_GET['edit'], $cid]);
    $editing = $s->fetch() ?: null;
}

$mediaStmt = $pdo->prepare('SELECT * FROM vb_media WHERE company_id = ? ORDER BY created_at DESC');
$mediaStmt->execute([$cid]);
$media = $mediaStmt->fetchAll();
$itemsStmt = $pdo->prepare(
    'SELECT ci.*, m.filename, m.thumbnail_filename, m.kind AS media_kind FROM vb_content_items ci
     LEFT JOIN vb_media m ON m.id = ci.media_id WHERE ci.company_id = ? ORDER BY ci.created_at DESC'
);
$itemsStmt->execute([$cid]);
$items = $itemsStmt->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<h1 class="text-xl font-black tracking-tight text-slate-900 mb-3">Content</h1>

<div class="grid lg:grid-cols-3 gap-4">
  <!-- Editor -->
  <div class="lg:col-span-1">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sticky top-4" id="editor">
      <h2 class="font-bold text-slate-800 mb-4"><?= $editing ? 'Edit content' : 'New content' ?></h2>
      <form method="post" class="space-y-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>">

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Type</label>
          <select name="type" id="typeSelect" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            <?php foreach (['image'=>'Image','video'=>'Video','biography'=>'Biography card'] as $v=>$l): ?>
              <option value="<?= $v ?>" <?= ($editing['type'] ?? 'image') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Title</label>
          <input name="title" required value="<?= e($editing['title'] ?? '') ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>

        <div data-role="subtitle">
          <label class="block text-sm font-medium text-slate-600 mb-1">Subtitle / animal name <span class="text-slate-400">(optional)</span></label>
          <input name="subtitle" value="<?= e($editing['subtitle'] ?? '') ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>

        <div data-role="media">
          <label class="block text-sm font-medium text-slate-600 mb-1">Media file</label>
          <select name="media_id" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            <option value="">— none —</option>
            <?php foreach ($media as $m): ?>
              <option value="<?= $m['id'] ?>" data-kind="<?= $m['kind'] ?>"
                <?= ($editing['media_id'] ?? '') == $m['id'] ? 'selected' : '' ?>>
                [<?= strtoupper($m['kind']) ?>] <?= e($m['original_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-slate-400 mt-1">Need to add one? <a class="text-rose-600 font-semibold hover:underline" href="media.php">Upload media</a>.</p>
        </div>

        <div data-role="body">
          <label class="block text-sm font-medium text-slate-600 mb-1">Body text</label>
          <textarea name="body" rows="5" class="w-full rounded-lg border border-slate-300 px-3 py-2"><?= e($editing['body'] ?? '') ?></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">On-screen duration (seconds)</label>
          <input name="duration_seconds" type="number" min="1" value="<?= e($editing['duration_seconds'] ?? DEFAULT_DURATION) ?>"
                 class="w-full rounded-lg border border-slate-300 px-3 py-2">
          <p class="text-xs text-slate-400 mt-1">Videos play their full length regardless.</p>
        </div>

        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Start date</label>
            <input name="starts_on" type="date" value="<?= e($editing['starts_on'] ?? '') ?>"
                   class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">End date</label>
            <input name="ends_on" type="date" value="<?= e($editing['ends_on'] ?? '') ?>"
                   class="w-full rounded-lg border border-slate-300 px-2 py-2 text-sm">
          </div>
        </div>
        <p class="text-xs text-slate-400 -mt-2">Leave blank for always available.</p>

        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" name="is_active" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
          Active (available for playlists)
        </label>

        <div class="flex gap-2 pt-2">
          <button class="bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl px-5 py-2 transition-colors"><?= $editing ? 'Update' : 'Create' ?></button>
          <?php if ($editing): ?><a href="content.php" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-semibold hover:bg-slate-50 transition-colors">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- List -->
  <div class="lg:col-span-2 space-y-3">
    <?php if (!$items): ?>
      <p class="text-slate-500">No content yet. Create your first item on the left.</p>
    <?php endif; ?>
    <?php foreach ($items as $it): ?>
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div class="w-24 h-16 rounded-lg bg-slate-900 overflow-hidden flex items-center justify-center shrink-0">
          <?php if ($it['filename'] && $it['media_kind']==='image'): ?>
            <img src="<?= thumbnail_url($it['thumbnail_filename']) ?: media_url($it['filename']) ?>" class="w-full h-full object-cover">
          <?php elseif ($it['filename'] && $it['media_kind']==='video'): ?>
            <video src="<?= media_url($it['filename']) ?>" class="w-full h-full object-cover" muted></video>
          <?php else: ?>
            <span class="text-rose-400"><?= content_type_icon($it['type'], 'h-6 w-6') ?></span>
          <?php endif; ?>
        </div>
        <div class="flex-1 min-w-0">
          <p class="font-semibold text-slate-800 truncate flex items-center gap-1.5">
            <span class="text-slate-400 shrink-0"><?= content_type_icon($it['type'], 'h-3.5 w-3.5') ?></span>
            <?= e($it['title']) ?>
          </p>
          <p class="text-sm text-slate-500 truncate"><?= e($it['subtitle'] ?: ucfirst($it['type'])) ?> · <?= (int)$it['duration_seconds'] ?>s
             <?php if ($it['starts_on'] || $it['ends_on']): ?>· <?= e(($it['starts_on'] ?: 'now') . ' to ' . ($it['ends_on'] ?: 'always')) ?><?php endif; ?>
             <?php if (!$it['is_active']): ?><span class="text-red-500">· inactive</span><?php endif; ?></p>
        </div>
        <div class="flex items-center gap-3 shrink-0">
          <a href="content.php?edit=<?= $it['id'] ?>#editor" class="text-sm font-semibold text-rose-600 hover:underline">Edit</a>
          <form method="post" onsubmit="return confirm('Delete this content item?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $it['id'] ?>">
            <button class="text-sm font-semibold text-red-600 hover:underline">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
// Show/hide fields based on content type.
(function(){
  const sel = document.getElementById('typeSelect');
  function sync(){
    const t = sel.value;
    document.querySelector('[data-role=media]').style.display  = (t==='image'||t==='video') ? '' : 'none';
    document.querySelector('[data-role=body]').style.display   = (t==='biography') ? '' : 'none';
  }
  sel.addEventListener('change', sync); sync();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
