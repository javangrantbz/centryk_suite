<?php
$active = 'content';
$pageTitle = 'Items';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$cid = vb_cid();

$pdo = db();
$panelMode = isset($_GET['panel']) && $_GET['panel'] === '1';
$contentBaseQuery = $panelMode ? ['panel' => '1'] : [];
$contentReturnUrl = 'content.php' . ($contentBaseQuery ? '?' . http_build_query($contentBaseQuery) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) $_POST['id'];
        $pdo->prepare('DELETE FROM vb_content_items WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
        log_activity('deleted', 'content_item', $id);
        flash('Item deleted.');
        redirect($contentReturnUrl);
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $itemKind = ($_POST['item_kind'] ?? 'media') === 'biography' ? 'biography' : 'media';
        $title = trim($_POST['title'] ?? '');
        $subtitle = trim($_POST['subtitle'] ?? '') ?: null;
        $body = trim($_POST['body'] ?? '') ?: null;
        $mediaId = ($_POST['media_id'] ?? '') !== '' ? (int) $_POST['media_id'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $existing = null;

        if ($id) {
            $existingStmt = $pdo->prepare('SELECT * FROM vb_content_items WHERE id = ? AND company_id = ?');
            $existingStmt->execute([$id, $cid]);
            $existing = $existingStmt->fetch() ?: null;
            if (!$existing) {
                http_response_code(404);
                die('Item not found.');
            }
        }

        $duration = (int) ($existing['duration_seconds'] ?? DEFAULT_DURATION);
        $startsOn = $existing['starts_on'] ?? null;
        $endsOn = $existing['ends_on'] ?? null;
        $type = $itemKind === 'biography' ? 'biography' : 'image';

        if ($title === '') {
            flash('Title is required.', 'error');
            redirect($contentReturnUrl);
        }

        if ($itemKind === 'media' && !$mediaId) {
            flash('Choose a media file from the library.', 'error');
            redirect($contentReturnUrl);
        }

        if ($mediaId) {
            $chk = $pdo->prepare('SELECT kind FROM vb_media WHERE id = ? AND company_id = ?');
            $chk->execute([$mediaId, $cid]);
            $mediaKind = $chk->fetchColumn();
            if (!$mediaKind) {
                flash('Selected media is not available.', 'error');
                redirect($contentReturnUrl);
            }
            if ($itemKind === 'media') {
                $type = $mediaKind === 'video' ? 'video' : 'image';
            }
        } else {
            $mediaId = null;
        }

        if ($itemKind === 'biography') {
            $mediaId = null;
        }

        if ($id) {
            $stmt = $pdo->prepare(
                'UPDATE vb_content_items SET title=?, type=?, media_id=?, subtitle=?, body=?, duration_seconds=?, starts_on=?, ends_on=?, is_active=? WHERE id=? AND company_id=?'
            );
            $stmt->execute([$title, $type, $mediaId, $subtitle, $body, $duration, $startsOn, $endsOn, $isActive, $id, $cid]);
            log_activity('updated', 'content_item', $id, $title);
            flash('Item updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO vb_content_items (company_id, title, type, media_id, subtitle, body, duration_seconds, starts_on, ends_on, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$cid, $title, $type, $mediaId, $subtitle, $body, $duration, $startsOn, $endsOn, $isActive]);
            log_activity('created', 'content_item', (int) $pdo->lastInsertId(), $title);
            flash('Item created.');
        }
        redirect($contentReturnUrl);
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
    'SELECT ci.*, m.filename, m.thumbnail_filename, m.kind AS media_kind
     FROM vb_content_items ci
     LEFT JOIN vb_media m ON m.id = ci.media_id
     WHERE ci.company_id = ?
     ORDER BY ci.created_at DESC'
);
$itemsStmt->execute([$cid]);
$items = $itemsStmt->fetchAll();
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
<?php endif; ?>
<h1 class="text-xl font-black tracking-tight text-slate-900 mb-3">Items</h1>

<div class="grid lg:grid-cols-3 gap-4">
  <div class="lg:col-span-1">
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sticky top-4" id="editor">
      <h2 class="font-bold text-slate-800 mb-1"><?= $editing ? 'Edit item' : 'New item' ?></h2>
      <p class="text-sm text-slate-500 mb-4">Choose something from the media library, or create a biography card.</p>
      <form method="post" class="space-y-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>">

        <?php $selectedKind = ($editing['type'] ?? 'image') === 'biography' ? 'biography' : 'media'; ?>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-2">Item type</label>
          <div class="grid grid-cols-2 gap-2">
            <label class="cursor-pointer">
              <input type="radio" name="item_kind" value="media" class="peer sr-only" <?= $selectedKind === 'media' ? 'checked' : '' ?>>
              <span class="flex items-center justify-center rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 peer-checked:border-rose-600 peer-checked:bg-rose-50 peer-checked:text-rose-700">Media item</span>
            </label>
            <label class="cursor-pointer">
              <input type="radio" name="item_kind" value="biography" class="peer sr-only" <?= $selectedKind === 'biography' ? 'checked' : '' ?>>
              <span class="flex items-center justify-center rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 peer-checked:border-rose-600 peer-checked:bg-rose-50 peer-checked:text-rose-700">Biography card</span>
            </label>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Title</label>
          <input name="title" required value="<?= e($editing['title'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>

        <div data-role="subtitle">
          <label class="block text-sm font-medium text-slate-600 mb-1">Subtitle <span class="text-slate-400">(optional)</span></label>
          <input name="subtitle" value="<?= e($editing['subtitle'] ?? '') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
        </div>

        <div data-role="media">
          <label class="block text-sm font-medium text-slate-600 mb-1">Media file</label>
          <select name="media_id" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            <option value="">-- choose from library --</option>
            <?php foreach ($media as $m): ?>
              <option value="<?= $m['id'] ?>" data-kind="<?= $m['kind'] ?>" <?= ($editing['media_id'] ?? '') == $m['id'] ? 'selected' : '' ?>>
                [<?= strtoupper($m['kind']) ?>] <?= e($m['original_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="text-xs text-slate-400 mt-1">The item type is detected automatically from the file you choose.</p>
          <p class="text-xs text-slate-400 mt-1">Need to add one? <a class="text-rose-600 font-semibold hover:underline" href="media.php<?= $panelMode ? '?panel=1' : '' ?>">Upload media</a>.</p>
        </div>

        <div data-role="body">
          <label class="block text-sm font-medium text-slate-600 mb-1">Body text</label>
          <textarea name="body" rows="5" class="w-full rounded-lg border border-slate-300 px-3 py-2"><?= e($editing['body'] ?? '') ?></textarea>
        </div>

        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" name="is_active" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
          Active (available for playlists)
        </label>

        <div class="flex gap-2 pt-2">
          <button class="bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl px-5 py-2 transition-colors"><?= $editing ? 'Update' : 'Create' ?></button>
          <?php if ($editing): ?><a href="<?= e($contentReturnUrl) ?>" class="px-4 py-2 rounded-xl border border-slate-200 text-slate-600 font-semibold hover:bg-slate-50 transition-colors">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="lg:col-span-2 space-y-3">
    <?php if (!$items): ?>
      <p class="text-slate-500">No items yet. Create your first item on the left.</p>
    <?php endif; ?>
    <?php foreach ($items as $it): ?>
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div class="w-24 h-16 rounded-lg bg-slate-900 overflow-hidden flex items-center justify-center shrink-0">
          <?php if ($it['filename'] && $it['media_kind'] === 'image'): ?>
            <img src="<?= thumbnail_url($it['thumbnail_filename']) ?: media_url($it['filename']) ?>" class="w-full h-full object-cover">
          <?php elseif ($it['filename'] && $it['media_kind'] === 'video'): ?>
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
          <p class="text-sm text-slate-500 truncate">
            <?= e($it['subtitle'] ?: ucfirst($it['type'])) ?>
            <?php if (!$it['is_active']): ?><span class="text-red-500"> · inactive</span><?php endif; ?>
          </p>
        </div>
        <div class="flex items-center gap-3 shrink-0">
          <a href="content.php?edit=<?= $it['id'] ?><?= $panelMode ? '&panel=1' : '' ?>#editor" class="text-sm font-semibold text-rose-600 hover:underline">Edit</a>
          <form method="post" onsubmit="return confirm('Delete this item?')">
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
(function () {
  const mediaRadio = document.querySelector('input[name="item_kind"][value="media"]');
  const bioRadio = document.querySelector('input[name="item_kind"][value="biography"]');
  const mediaWrap = document.querySelector('[data-role="media"]');
  const bodyWrap = document.querySelector('[data-role="body"]');
  if (!mediaRadio || !bioRadio || !mediaWrap || !bodyWrap) {
    return;
  }
  function sync() {
    const isBio = bioRadio.checked;
    mediaWrap.style.display = isBio ? 'none' : '';
    bodyWrap.style.display = isBio ? '' : 'none';
  }
  mediaRadio.addEventListener('change', sync);
  bioRadio.addEventListener('change', sync);
  sync();
})();
</script>
<?php if ($panelMode): ?>
</div>
</body>
</html>
<?php else: ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
<?php endif; ?>
