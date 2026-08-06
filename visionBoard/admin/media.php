<?php
$active = 'media';
$pageTitle = 'Media Library';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$cid = vb_cid();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT * FROM vb_media WHERE id = ? AND company_id = ?');
        $row->execute([$id, $cid]);
        if ($m = $row->fetch()) {
            @unlink(UPLOAD_DIR . '/' . $m['filename']);
            if (!empty($m['thumbnail_filename'])) {
                @unlink(UPLOAD_DIR . '/' . $m['thumbnail_filename']);
            }
            $pdo->prepare('DELETE FROM vb_media WHERE id = ? AND company_id = ?')->execute([$id, $cid]);
            log_activity('deleted', 'media', $id, $m['original_name']);
            flash('Media deleted.');
        }
        redirect('media.php');
    }

    if ($action === 'upload') {
        $files = $_FILES['files'] ?? null;
        $ok = 0; $fail = 0;
        if ($files && is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) { if ($files['name'][$i]) $fail++; continue; }
                $orig = $files['name'][$i];
                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                $allowed = $GLOBALS['ALLOWED_TYPES'];
                if (!isset($allowed[$ext])) { $fail++; continue; }
                if ($files['size'][$i] > MAX_UPLOAD_BYTES) { $fail++; continue; }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($files['tmp_name'][$i]);
                [$expectedMime, $kind] = $allowed[$ext];
                // Accept any image/* or video/* matching the declared kind.
                if (strpos($mime, $kind . '/') !== 0) { $fail++; continue; }

                $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $thumbName = null;
                $dest = UPLOAD_DIR . '/' . $newName;
                $stored = false;
                if ($kind === 'image' && in_array($ext, ['jpg','jpeg','png','webp'], true)) {
                    $stored = write_resized_image($files['tmp_name'][$i], $dest, $ext, 1920, 86);
                    if ($stored) {
                        $thumbName = 'thumb_' . $newName;
                        if (!write_resized_image($files['tmp_name'][$i], UPLOAD_DIR . '/' . $thumbName, $ext, 520, 78)) {
                            $thumbName = null;
                        }
                    }
                }
                if (!$stored) {
                    $stored = move_uploaded_file($files['tmp_name'][$i], $dest);
                }
                if ($stored) {
                    $storedSize = is_file($dest) ? filesize($dest) : $files['size'][$i];
                    $stmt = $pdo->prepare(
                        'INSERT INTO vb_media (company_id, filename, thumbnail_filename, original_name, kind, mime, size_bytes, uploaded_by)
                         VALUES (?,?,?,?,?,?,?,?)'
                    );
                    $stmt->execute([$cid, $newName, $thumbName, $orig, $kind, $mime, $storedSize, current_user()['id']]);
                    log_activity('uploaded', 'media', (int) $pdo->lastInsertId(), $orig);
                    $ok++;
                } else { $fail++; }
            }
        }
        flash("$ok file(s) uploaded" . ($fail ? ", $fail failed (unsupported type or too large)." : '.'),
              $fail && !$ok ? 'error' : 'success');
        redirect('media.php');
    }
}

$mediaStmt = $pdo->prepare('SELECT * FROM vb_media WHERE company_id = ? ORDER BY created_at DESC');
$mediaStmt->execute([$cid]);
$media = $mediaStmt->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<h1 class="text-2xl font-bold mb-4">Media Library</h1>

<form method="post" enctype="multipart/form-data"
      class="bg-white rounded-xl shadow-sm p-6 mb-6 border-2 border-dashed border-slate-200 transition"
      data-dropzone>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="upload">
  <label class="block font-medium text-slate-700 mb-2">Drop photos & videos here, or choose files</label>
  <input type="file" name="files[]" multiple accept="image/*,video/*"
         class="block w-full text-sm text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-green-700 file:text-white hover:file:bg-green-800 mb-3">
  <p class="text-xs text-slate-400 mb-3">JPG, PNG, and WEBP images are resized to 1920px wide with thumbnails. GIFs and videos are stored as uploaded. Max <?= human_size(MAX_UPLOAD_BYTES) ?> each.</p>
  <?php if (!function_exists('imagecreatetruecolor')): ?>
    <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
      PHP GD is not enabled, so images will upload without automatic resizing until GD is turned on.
    </p>
  <?php endif; ?>
  <button class="bg-green-700 hover:bg-green-800 text-white font-medium rounded-lg px-5 py-2">Upload</button>
</form>

<?php if (!$media): ?>
  <p class="text-slate-500">No media yet. Upload some files above.</p>
<?php else: ?>
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
  <?php foreach ($media as $m): ?>
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
      <div class="aspect-video bg-slate-900 flex items-center justify-center">
        <?php if ($m['kind'] === 'image'): ?>
          <img src="<?= thumbnail_url($m['thumbnail_filename']) ?: media_url($m['filename']) ?>" class="w-full h-full object-cover" alt="">
        <?php else: ?>
          <video src="<?= media_url($m['filename']) ?>" class="w-full h-full object-cover" muted></video>
        <?php endif; ?>
      </div>
      <div class="p-3">
        <p class="text-sm font-medium text-slate-700 truncate" title="<?= e($m['original_name']) ?>"><?= e($m['original_name']) ?></p>
        <p class="text-xs text-slate-400"><?= e(strtoupper($m['kind'])) ?> · <?= human_size((int)$m['size_bytes']) ?></p>
        <form method="post" onsubmit="return confirm('Delete this file? Content items using it will lose their media.')" class="mt-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $m['id'] ?>">
          <button class="text-xs text-red-600 hover:underline">Delete</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
