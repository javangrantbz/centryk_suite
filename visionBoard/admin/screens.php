<?php
$active = 'screens';
$pageTitle = 'Screens';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();
$companyId = vb_cid();
$pdo = db();

/** True if the screen belongs to the active company. */
function screen_owned(PDO $pdo, int $id, int $companyId): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM vb_screens WHERE id=? AND company_id=?');
    $s->execute([$id, $companyId]);
    return (bool) $s->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action !== 'add' && !screen_owned($pdo, $id, $companyId)) {
        http_response_code(404);
        die('Screen not found.');
    }

    switch ($action) {
        case 'add':
            $name = trim($_POST['name'] ?? '') ?: 'New screen';
            $location = trim($_POST['location'] ?? '') ?: null;
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO vb_screens (company_id, name, location, pair_token) VALUES (?,?,?,?)')
                ->execute([$companyId, $name, $location, $token]);
            log_activity('created', 'screen', (int) $pdo->lastInsertId(), $name);
            flash('Screen added.');
            break;

        case 'rename':
            $name = trim($_POST['name'] ?? '') ?: 'Screen';
            $location = trim($_POST['location'] ?? '') ?: null;
            $pdo->prepare('UPDATE vb_screens SET name=?, location=? WHERE id=? AND company_id=?')
                ->execute([$name, $location, $id, $companyId]);
            log_activity('updated', 'screen', $id, $name);
            flash('Screen updated.');
            break;

        case 'regenerate':
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE vb_screens SET pair_token=? WHERE id=? AND company_id=?')
                ->execute([$token, $id, $companyId]);
            log_activity('regenerated_token', 'screen', $id);
            flash('A new pairing link was generated. The old link will stop working.');
            break;

        case 'toggle':
            $pdo->prepare('UPDATE vb_screens SET is_active = 1 - is_active WHERE id=? AND company_id=?')
                ->execute([$id, $companyId]);
            log_activity('toggled', 'screen', $id);
            break;

        case 'delete':
            $pdo->prepare('DELETE FROM vb_screens WHERE id=? AND company_id=?')->execute([$id, $companyId]);
            log_activity('deleted', 'screen', $id);
            flash('Screen removed.');
            break;
    }
    redirect('screens.php');
}

$screensStmt = $pdo->prepare(
    'SELECT s.*, ds.player_state, ds.playlist_name
     FROM vb_screens s
     LEFT JOIN vb_display_status ds ON ds.screen_id = s.id
     WHERE s.company_id = ? ORDER BY s.created_at ASC'
);
$screensStmt->execute([$companyId]);
$screens = $screensStmt->fetchAll();

$scheme = (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http');
$displayBase = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . app_base() . '/display/';

require __DIR__ . '/../includes/header.php';
?>
<h1 class="text-xl font-black tracking-tight text-slate-900 mb-1">Screens</h1>
<p class="text-slate-500 mb-3 text-sm max-w-2xl">
  Each TV is a screen with its own private link. Open that link in the TV's browser (full-screen / kiosk)
  and it will play this company's scheduled content. Keep the link private — anyone with it can view the screen's feed.
</p>

<div class="grid lg:grid-cols-3 gap-4">
  <div class="lg:col-span-2 space-y-4">
    <?php if (!$screens): ?><p class="text-slate-500">No screens yet. Add one on the right.</p><?php endif; ?>
    <?php foreach ($screens as $s):
      $url = $displayBase . '?screen=' . rawurlencode($s['pair_token']);
      $online = $s['last_seen_at'] && (time() - strtotime($s['last_seen_at'])) <= 45;
      $lastSeen = $s['last_seen_at'] ? date('M j, g:i A', strtotime($s['last_seen_at'])) : 'never';
    ?>
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 <?= $s['is_active'] ? '' : 'opacity-60' ?>">
        <div class="flex items-start justify-between gap-3 mb-3">
          <div class="flex items-start gap-3">
            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
              <i data-lucide="monitor" class="h-5 w-5"></i>
            </span>
            <div>
              <p class="font-bold text-slate-800"><?= e($s['name']) ?>
                <?php if (!$s['is_active']): ?><span class="text-xs text-red-500">disabled</span><?php endif; ?>
              </p>
              <p class="text-sm text-slate-500"><?= e($s['location'] ?: 'No location set') ?></p>
            </div>
          </div>
          <div class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold <?= $online ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500' ?>">
            <span class="h-2 w-2 rounded-full <?= $online ? 'bg-emerald-600' : 'bg-slate-400' ?>"></span>
            <?= $online ? 'Online' : 'Offline' ?>
          </div>
        </div>

        <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Display link</label>
        <div class="flex gap-2 mb-3">
          <input readonly value="<?= e($url) ?>" onclick="this.select()"
                 class="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs bg-slate-50 font-mono">
          <a href="<?= e($url) ?>" target="_blank" class="flex items-center gap-1.5 rounded-lg bg-slate-800 hover:bg-slate-900 text-white text-sm px-3 py-2 whitespace-nowrap transition-colors">
            Open <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
          </a>
          <button type="button" class="qr-btn flex items-center gap-1.5 rounded-lg border border-slate-300 hover:border-rose-300 hover:bg-rose-50 text-slate-700 text-sm px-3 py-2 whitespace-nowrap transition-colors"
                  data-url="<?= e($url) ?>" data-name="<?= e($s['name']) ?>">
            <i data-lucide="qr-code" class="h-3.5 w-3.5"></i> QR
          </button>
        </div>
        <p class="text-xs text-slate-400 mb-3">Last check-in: <b class="text-slate-600"><?= e($lastSeen) ?></b>
          <?php if (!empty($s['playlist_name'])): ?>· Playing: <b class="text-slate-600"><?= e($s['playlist_name']) ?></b><?php endif; ?>
        </p>

        <div class="flex flex-wrap items-center gap-2">
          <form method="post" class="flex flex-wrap items-center gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <input name="name" value="<?= e($s['name']) ?>" class="rounded border border-slate-300 px-2 py-1 text-sm" placeholder="Name">
            <input name="location" value="<?= e($s['location']) ?>" class="rounded border border-slate-300 px-2 py-1 text-sm" placeholder="Location">
            <button class="text-xs bg-slate-100 hover:bg-slate-200 rounded px-3 py-1.5">Save</button>
          </form>
          <form method="post" onsubmit="return confirm('Generate a new link? The current link will stop working immediately.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="regenerate">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="text-xs text-amber-700 hover:underline">Regenerate link</button>
          </form>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="text-xs text-slate-500 hover:underline"><?= $s['is_active'] ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" onsubmit="return confirm('Delete this screen?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="text-xs text-red-600 hover:underline">Delete</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div>
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sticky top-4">
      <h2 class="font-bold text-slate-800 mb-3">Add a screen</h2>
      <form method="post" class="space-y-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input name="name" placeholder="Screen name (e.g. Lobby TV)" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
        <input name="location" placeholder="Location (optional)" class="w-full rounded-lg border border-slate-300 px-3 py-2">
        <button class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl py-2 transition-colors">Add screen</button>
      </form>
      <p class="text-xs text-slate-400 mt-3">A private display link is generated for each screen. On the TV, open Chrome/Edge in kiosk mode pointed at that link.</p>
    </div>
  </div>
</div>

<!-- QR modal: scan with a phone to open a screen's display link without typing it on the TV -->
<div id="qrModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4" onclick="if (event.target === this) this.classList.add('hidden')">
  <div class="bg-white rounded-2xl shadow-xl p-6 w-full max-w-xs text-center">
    <p id="qrModalName" class="font-bold text-slate-800 mb-3"></p>
    <div id="qrModalImg" class="mx-auto mb-3 flex justify-center"></div>
    <p class="text-xs text-slate-400 mb-4">Scan with a phone camera, then open the link on the TV.</p>
    <button type="button" onclick="document.getElementById('qrModal').classList.add('hidden')" class="text-sm font-semibold text-slate-500 hover:text-slate-700">Close</button>
  </div>
</div>

<script src="<?= app_base() ?>/assets/js/qrcode.min.js"></script>
<script>
document.querySelectorAll('.qr-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var modal = document.getElementById('qrModal');
    document.getElementById('qrModalName').textContent = btn.dataset.name;
    var qr = qrcode(0, 'M');
    qr.addData(btn.dataset.url);
    qr.make();
    document.getElementById('qrModalImg').innerHTML = qr.createImgTag(6, 8);
    modal.classList.remove('hidden');
    modal.classList.add('flex');
  });
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
