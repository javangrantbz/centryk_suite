<?php
$active = 'settings';
$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();
$panelMode = isset($_GET['panel']) && $_GET['panel'] === '1';
$settingsReturnUrl = 'settings.php' . ($panelMode ? '?panel=1' : '');

$pdo = db();
$companyId = vb_cid();
$companyName = trim((string) (vb_company()['name'] ?? 'Your Company'));
$defaultWeatherLabel = $companyName;
$defaultDonationCaption = 'Support ' . $companyName;
$defaultFeedbackCaption = 'Share feedback';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'display') {
        set_setting('show_clock', isset($_POST['show_clock']) ? '1' : '0');
        set_setting('weather_widget_enabled', isset($_POST['weather_widget_enabled']) ? '1' : '0');
        set_setting('weather_label', trim($_POST['weather_label'] ?? '') ?: $defaultWeatherLabel);
        set_setting('weather_latitude', trim($_POST['weather_latitude'] ?? '') ?: '17.3536');
        set_setting('weather_longitude', trim($_POST['weather_longitude'] ?? '') ?: '-88.5497');
        set_setting('marquee_scroll_seconds', (string) max(8, (int) ($_POST['marquee_scroll_seconds'] ?? 22)));
        set_setting('qr_enabled', isset($_POST['qr_enabled']) ? '1' : '0');
        set_setting('qr_rotate_seconds', (string) max(3, (int) ($_POST['qr_rotate_seconds'] ?? 10)));
        set_setting('donation_qr_enabled', isset($_POST['donation_qr_enabled']) ? '1' : '0');
        set_setting('donation_qr_caption', trim($_POST['donation_qr_caption'] ?? '') ?: $defaultDonationCaption);
        set_setting('donation_qr_url', trim($_POST['donation_qr_url'] ?? ''));
        set_setting('feedback_qr_enabled', isset($_POST['feedback_qr_enabled']) ? '1' : '0');
        set_setting('feedback_qr_caption', trim($_POST['feedback_qr_caption'] ?? '') ?: $defaultFeedbackCaption);
        set_setting('feedback_qr_url', trim($_POST['feedback_qr_url'] ?? ''));
        set_setting('theme', $_POST['theme'] ?? 'jungle');
        $trans = in_array($_POST['transition'] ?? '', ['fade','slide','zoom','kenburns'], true) ? $_POST['transition'] : 'fade';
        set_setting('transition', $trans);
        log_activity('updated', 'settings', null, 'display settings');
        flash('Display settings saved.');
        redirect($settingsReturnUrl);
    }

    // ---- Centryk Connect sharing ----
    if ($action === 'sharing') {
        set_setting('accept_shares', isset($_POST['accept_shares']) ? '1' : '0');
        log_activity('updated', 'settings', null, 'sharing settings');
        flash('Sharing settings saved.');
        redirect($settingsReturnUrl);
    }

    // ---- Visitor QR codes ----
    if ($action === 'qr_add') {
        $url = trim($_POST['url'] ?? '');
        if ($url === '') {
            flash('A link is required for a QR code.', 'error');
        } else {
            $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM vb_qr_codes WHERE company_id=?');
            $maxStmt->execute([$companyId]);
            $pos = (int) $maxStmt->fetchColumn();
            $displaySeconds = ($_POST['display_seconds'] ?? '') !== '' ? max(3, (int) $_POST['display_seconds']) : null;
            $pdo->prepare('INSERT INTO vb_qr_codes (company_id, caption, url, position, display_seconds) VALUES (?,?,?,?,?)')
                ->execute([$companyId, trim($_POST['caption'] ?? '') ?: null, $url, $pos, $displaySeconds]);
            log_activity('created', 'qr_code', (int) $pdo->lastInsertId(), $url);
            flash('QR code added.');
        }
        redirect($settingsReturnUrl);
    }
    if ($action === 'qr_save') {
        $url = trim($_POST['url'] ?? '');
        if ($url !== '') {
            $displaySeconds = ($_POST['display_seconds'] ?? '') !== '' ? max(3, (int) $_POST['display_seconds']) : null;
            $pdo->prepare('UPDATE vb_qr_codes SET caption=?, url=?, display_seconds=?, is_active=? WHERE id=? AND company_id=?')
                ->execute([trim($_POST['caption'] ?? '') ?: null, $url, $displaySeconds, isset($_POST['is_active'])?1:0, (int)$_POST['id'], $companyId]);
            log_activity('updated', 'qr_code', (int) $_POST['id'], $url);
            flash('QR code updated.');
        }
        redirect($settingsReturnUrl);
    }
    if ($action === 'qr_del') {
        $pdo->prepare('DELETE FROM vb_qr_codes WHERE id=? AND company_id=?')->execute([(int)$_POST['id'], $companyId]);
        log_activity('deleted', 'qr_code', (int) $_POST['id']);
        flash('QR code removed.');
        redirect($settingsReturnUrl);
    }
    if ($action === 'qr_move') {
        $itemId = (int) $_POST['id'];
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
        $listStmt = $pdo->prepare('SELECT id, position FROM vb_qr_codes WHERE company_id=? ORDER BY position ASC, id ASC');
        $listStmt->execute([$companyId]);
        $list = $listStmt->fetchAll();
        foreach ($list as $i => $r) {
            if ($r['id'] == $itemId) {
                $j = $dir === 'up' ? $i - 1 : $i + 1;
                if ($j >= 0 && $j < count($list)) {
                    $upd = $pdo->prepare('UPDATE vb_qr_codes SET position=? WHERE id=? AND company_id=?');
                    $upd->execute([$list[$j]['position'], $list[$i]['id'], $companyId]);
                    $upd->execute([$list[$i]['position'], $list[$j]['id'], $companyId]);
                }
                break;
            }
        }
        log_activity('moved', 'qr_code', $itemId, $dir);
        redirect($settingsReturnUrl);
    }
    if ($action === 'qr_reorder') {
        $order = array_filter(array_map('intval', explode(',', $_POST['order'] ?? '')));
        $upd = $pdo->prepare('UPDATE vb_qr_codes SET position=? WHERE id=? AND company_id=?');
        foreach ($order as $pos => $id) {
            $upd->execute([$pos, $id, $companyId]);
        }
        log_activity('reordered', 'qr_code');
        flash('QR order saved.');
        redirect($settingsReturnUrl);
    }

    // ---- Marquee messages ----
    if ($action === 'marquee_add') {
        $message = trim($_POST['message'] ?? '');
        if ($message !== '') {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM vb_marquee_messages WHERE company_id=?');
            $countStmt->execute([$companyId]);
            if ((int) $countStmt->fetchColumn() >= 5) {
                flash('You can add up to five scrolling marquee messages.', 'error');
            } else {
                $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM vb_marquee_messages WHERE company_id=?');
                $maxStmt->execute([$companyId]);
                $pos = (int) $maxStmt->fetchColumn();
                $pdo->prepare('INSERT INTO vb_marquee_messages (company_id, message, position) VALUES (?,?,?)')->execute([$companyId, $message, $pos]);
                log_activity('created', 'marquee_message', (int) $pdo->lastInsertId(), $message);
                flash('Marquee message added.');
            }
        }
        redirect($settingsReturnUrl);
    }
    if ($action === 'marquee_save') {
        $message = trim($_POST['message'] ?? '');
        if ($message !== '') {
            $pdo->prepare('UPDATE vb_marquee_messages SET message=?, is_active=? WHERE id=? AND company_id=?')
                ->execute([$message, isset($_POST['is_active']) ? 1 : 0, (int) $_POST['id'], $companyId]);
            log_activity('updated', 'marquee_message', (int) $_POST['id'], $message);
            flash('Marquee message saved.');
        }
        redirect($settingsReturnUrl);
    }
    if ($action === 'marquee_del') {
        $pdo->prepare('DELETE FROM vb_marquee_messages WHERE id=? AND company_id=?')->execute([(int) $_POST['id'], $companyId]);
        log_activity('deleted', 'marquee_message', (int) $_POST['id']);
        flash('Marquee message removed.');
        redirect($settingsReturnUrl);
    }
    if ($action === 'marquee_move') {
        $itemId = (int) $_POST['id'];
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
        $listStmt = $pdo->prepare('SELECT id, position FROM vb_marquee_messages WHERE company_id=? ORDER BY position ASC, id ASC');
        $listStmt->execute([$companyId]);
        $list = $listStmt->fetchAll();
        foreach ($list as $i => $r) {
            if ($r['id'] == $itemId) {
                $j = $dir === 'up' ? $i - 1 : $i + 1;
                if ($j >= 0 && $j < count($list)) {
                    $upd = $pdo->prepare('UPDATE vb_marquee_messages SET position=? WHERE id=? AND company_id=?');
                    $upd->execute([$list[$j]['position'], $list[$i]['id'], $companyId]);
                    $upd->execute([$list[$i]['position'], $list[$j]['id'], $companyId]);
                }
                break;
            }
        }
        log_activity('moved', 'marquee_message', $itemId, $dir);
        flash('Marquee order updated.');
        redirect($settingsReturnUrl);
    }
    if ($action === 'marquee_reorder') {
        $order = array_filter(array_map('intval', explode(',', $_POST['order'] ?? '')));
        $upd = $pdo->prepare('UPDATE vb_marquee_messages SET position=? WHERE id=? AND company_id=?');
        foreach ($order as $pos => $id) {
            $upd->execute([$pos, $id, $companyId]);
        }
        log_activity('reordered', 'marquee_message');
        flash('Marquee order saved.');
        redirect($settingsReturnUrl);
    }
    // Note: user accounts and roles are managed in Centryk (company members),
    // not here — this app no longer has its own users table.
}

$qrStmt = $pdo->prepare('SELECT * FROM vb_qr_codes WHERE company_id=? ORDER BY position ASC, id ASC');
$qrStmt->execute([$companyId]);
$qrList = $qrStmt->fetchAll();
$mqStmt = $pdo->prepare('SELECT * FROM vb_marquee_messages WHERE company_id=? ORDER BY position ASC, id ASC');
$mqStmt->execute([$companyId]);
$marqueeList = $mqStmt->fetchAll();
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
<div class="p-4 md:p-5">
<?php
}
?>
<?php if ($panelMode): ?>
<div class="grid gap-5 lg:grid-cols-[220px_minmax(0,1fr)]">
  <aside class="lg:sticky lg:top-0 lg:self-start">
    <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
      <nav class="space-y-1 text-sm">
        <a href="#display-banner" class="block rounded-xl px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50">Display Banner</a>
        <a href="#time-weather" class="block rounded-xl px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50">Time and Weather</a>
        <a href="#qr-shortcuts" class="block rounded-xl px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50">QR Shortcuts</a>
        <a href="#sharing-settings" class="block rounded-xl px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50">Sharing</a>
        <a href="#users-access" class="block rounded-xl px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50">Users and Access</a>
        <a href="#marquee-messages" class="block rounded-xl px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50">Marquee Messages</a>
        <a href="#qr-display" class="block rounded-xl px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50">QR Display</a>
      </nav>
    </div>
  </aside>
  <div>
<?php else: ?>
<?php endif; ?>

<div class="grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.9fr)]">
  <div class="space-y-4">
  <!-- Display settings -->
  <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <h2 class="font-bold text-slate-800 mb-4 flex items-center gap-2">
      <i data-lucide="tv" class="h-5 w-5 text-rose-500"></i> Display
    </h2>
    <form method="post" id="display-settings-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="display">
    </form>
    <div class="space-y-3">
      <div id="marquee-messages" class="scroll-mt-6 space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 class="text-sm font-semibold text-slate-700">Scrolling marquee messages</h3>
            <p class="text-sm text-slate-500">Add up to five posted scrolling messages.</p>
          </div>
          <div class="flex flex-wrap items-center gap-3 text-sm">
            <label class="inline-flex items-center gap-2 text-slate-600">
              <span class="font-medium">Theme</span>
              <select name="theme" form="display-settings-form" class="min-w-[8.5rem] rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm text-slate-700">
                <?php foreach (['jungle'=>'Jungle green','midnight'=>'Midnight','sand'=>'Sand'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= get_setting('theme','jungle')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="inline-flex items-center gap-2 text-slate-600">
              <span class="font-medium">Slide transition</span>
              <select name="transition" form="display-settings-form" class="min-w-[9.5rem] rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm text-slate-700">
                <?php foreach (['fade'=>'Fade','slide'=>'Slide','zoom'=>'Zoom','kenburns'=>'Ken Burns (pan &amp; zoom)'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= get_setting('transition','fade')===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
        </div>
        <div class="grid gap-2 sm:grid-cols-2">
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Scroll speed</label>
            <input
              type="number"
              min="8"
              name="marquee_scroll_seconds"
              form="display-settings-form"
              value="<?= e(get_setting('marquee_scroll_seconds', '22')) ?>"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            >
            <p class="mt-1 text-xs text-slate-400">Lower seconds scroll faster. Default is 22 seconds.</p>
          </div>
        </div>
        <form method="post" class="flex flex-wrap items-center gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="marquee_add">
          <input name="message" placeholder="Message" required class="flex-1 min-w-[16rem] rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <button class="bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold rounded-xl px-4 py-2 transition-colors">Add message</button>
        </form>
        <div class="space-y-2 mb-2">
          <?php foreach ($marqueeList as $m): ?>
            <div class="flex flex-wrap items-center gap-2 border border-slate-100 rounded-xl p-2 bg-white <?= $m['is_active']?'':'opacity-60' ?>">
              <form method="post" class="flex flex-1 flex-wrap items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="marquee_save">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <input name="message" value="<?= e($m['message']) ?>" required class="flex-1 min-w-[16rem] rounded border border-slate-300 px-2 py-1 text-sm">
                <label class="flex items-center gap-1 text-xs text-slate-500">
                  <input type="checkbox" name="is_active" <?= $m['is_active']?'checked':'' ?>> on
                </label>
                <button class="text-xs font-semibold bg-slate-100 hover:bg-slate-200 rounded px-2 py-1 transition-colors">Save</button>
              </form>
              <form method="post" onsubmit="return confirm('Remove this marquee message?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="marquee_del">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <button class="text-xs font-semibold text-red-600 hover:underline">Delete</button>
              </form>
              <div class="flex items-center gap-0.5">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="marquee_move">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
                  <input type="hidden" name="dir" value="up">
                  <button class="text-slate-400 hover:text-slate-700 p-1" title="Move up"><i data-lucide="chevron-up" class="h-3.5 w-3.5"></i></button>
                </form>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="marquee_move">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
                  <input type="hidden" name="dir" value="down">
                  <button class="text-slate-400 hover:text-slate-700 p-1" title="Move down"><i data-lucide="chevron-down" class="h-3.5 w-3.5"></i></button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$marqueeList): ?><p class="text-sm text-slate-500">No marquee messages yet.</p><?php endif; ?>
        </div>
      </div>
      <div class="border-t border-slate-200"></div>

      <div id="time-weather" class="scroll-mt-6 space-y-3">
        <h3 class="text-sm font-semibold text-slate-700">Time and weather</h3>
        <div class="space-y-2">
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input type="checkbox" name="show_clock" form="display-settings-form" <?= get_setting('show_clock','1')==='1'?'checked':'' ?>>
            Show clock on display
          </label>
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input type="checkbox" name="weather_widget_enabled" form="display-settings-form" <?= get_setting('weather_widget_enabled','0')==='1'?'checked':'' ?>>
            Show Belize weather + local time widget
          </label>
        </div>
        <div class="grid sm:grid-cols-3 gap-2">
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Label</label>
            <input name="weather_label" form="display-settings-form" value="<?= e(get_setting('weather_label', $defaultWeatherLabel)) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Latitude</label>
            <input name="weather_latitude" form="display-settings-form" value="<?= e(get_setting('weather_latitude','17.3536')) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Longitude</label>
            <input name="weather_longitude" form="display-settings-form" value="<?= e(get_setting('weather_longitude','-88.5497')) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          </div>
        </div>
        <p class="text-xs text-slate-400">Uses Belize coordinates by default and falls back to local time if weather cannot load.</p>
      </div>

      <button type="submit" form="display-settings-form" class="bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl px-5 py-2 transition-colors">Save</button>
    </div>
  </div>

  <!-- Visitor QR codes -->
  <div id="qr-display" class="scroll-mt-6 bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <h2 class="font-bold text-slate-800 mb-1 flex items-center gap-2">
      <i data-lucide="qr-code" class="h-5 w-5 text-rose-500"></i> Visitor QR codes
    </h2>
    <p class="text-sm text-slate-500 mb-4">
      Show one or more QR codes on the TV (website, map, tickets...). With several codes, the display
      rotates through them, fading from one to the next. Codes are generated on the TV itself, so they
      work offline; visitors only need internet to open the link.
    </p>

    <div class="space-y-3">
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
            <input type="checkbox" name="qr_enabled" form="display-settings-form" <?= get_setting('qr_enabled','1')==='1'?'checked':'' ?>>
            Show visitor QR codes on display
          </label>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Rotate every</label>
            <input
              type="number"
              min="3"
              name="qr_rotate_seconds"
              form="display-settings-form"
              value="<?= e(get_setting('qr_rotate_seconds', '10')) ?>"
              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            >
            <p class="mt-1 text-xs text-slate-400">Used when an individual QR code does not have its own display time.</p>
          </div>
        </div>
        <div class="space-y-3">
          <?php foreach ($qrList as $q): ?>
            <div class="flex flex-wrap items-center gap-2 border border-slate-100 rounded-xl p-2 bg-white <?= $q['is_active']?'':'opacity-60' ?>">
              <div class="qr-admin-preview bg-white border border-slate-200 rounded p-1" data-qr-url="<?= e($q['url']) ?>"></div>
              <form method="post" class="flex flex-1 flex-wrap items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="qr_save">
                <input type="hidden" name="id" value="<?= $q['id'] ?>">
                <input name="caption" value="<?= e($q['caption']) ?>" placeholder="Caption"
                       class="flex-1 min-w-[8rem] rounded border border-slate-300 px-2 py-1 text-sm">
                <input name="url" value="<?= e($q['url']) ?>" placeholder="https://..." required
                       class="flex-[2] min-w-[12rem] rounded border border-slate-300 px-2 py-1 text-sm">
                <input name="display_seconds" type="number" min="3" value="<?= e($q['display_seconds'] ?? '') ?>" placeholder="sec"
                       class="w-20 rounded border border-slate-300 px-2 py-1 text-sm" title="Seconds this QR shows">
                <label class="flex items-center gap-1 text-xs text-slate-500">
                  <input type="checkbox" name="is_active" <?= $q['is_active']?'checked':'' ?>> on
                </label>
                <button class="text-xs font-semibold bg-slate-100 hover:bg-slate-200 rounded px-2 py-1 transition-colors">Save</button>
              </form>
              <form method="post" onsubmit="return confirm('Remove this QR code?')"><?= csrf_field() ?>
                <input type="hidden" name="action" value="qr_del">
                <input type="hidden" name="id" value="<?= $q['id'] ?>">
                <button class="text-red-500 hover:text-red-700 text-xs font-semibold px-1">Delete</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (!$qrList): ?><p class="text-sm text-slate-500">No QR codes yet - add one below.</p><?php endif; ?>

        <form method="post" class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="qr_add">
          <input name="caption" placeholder="Caption (e.g. Company website)" class="flex-1 min-w-[8rem] rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <input name="url" placeholder="https://..." required class="flex-[2] min-w-[12rem] rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <input name="display_seconds" type="number" min="3" placeholder="Seconds" class="w-28 rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <button class="bg-rose-600 hover:bg-rose-700 text-white text-sm font-bold rounded-xl px-4 py-2 transition-colors">Add a New QR</button>
        </form>
    </div>
  </div>
  </div>

  <div class="space-y-4">
  <!-- Centryk Connect sharing -->
  <div id="sharing-settings" class="scroll-mt-6 bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <h2 class="font-bold text-slate-800 mb-2 flex items-center gap-2">
      <i data-lucide="handshake" class="h-5 w-5 text-rose-500"></i> Sharing
    </h2>
    <p class="text-sm text-slate-500 mb-3">Other companies can only offer to share a playlist with you if you're connected on
      <a href="<?= e(centryk_public_url()) ?>/connections.php" class="text-rose-600 hover:underline">Centryk Connect</a> and this is turned on.</p>
    <form method="post" class="space-y-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sharing">
      <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="accept_shares" <?= get_setting('accept_shares','0')==='1'?'checked':'' ?>>
        Accept playlist shares from companies I'm connected with
      </label>
      <button class="bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl px-5 py-2 transition-colors">Save</button>
    </form>
  </div>

  <!-- Account / users -->
  <div id="users-access" class="scroll-mt-6 bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
    <h2 class="font-bold text-slate-800 mb-2 flex items-center gap-2">
      <i data-lucide="users" class="h-5 w-5 text-rose-500"></i> Users &amp; access
    </h2>
    <p class="text-sm text-slate-500">Your password and who can access this company are managed in Centryk.
      Add or remove team members from the company's member list in Centryk, and their access to Signage follows.</p>
    <a href="<?= e(centryk_public_url()) ?>/companies.php" class="inline-flex items-center gap-1 mt-3 text-sm font-semibold text-rose-600 hover:underline">
      Manage members in Centryk <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
    </a>
  </div>
  </div>
</div>
<?php if ($panelMode): ?>
  </div>
</div>
<?php endif; ?>
<script src="<?= app_base() ?>/assets/js/qrcode.min.js"></script>
<?php if ($panelMode): ?>
</div>
<script>lucide.createIcons();</script>
</body>
</html>
<?php else: require __DIR__ . '/../includes/footer.php'; endif; ?>



