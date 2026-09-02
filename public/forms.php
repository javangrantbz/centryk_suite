<?php
/**
 * Centryk Forms — builder home. Lists a company's forms; create / open /
 * close / duplicate / delete. Free-core app (no entitlement gate); company
 * admins and managers only.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/services/FormsService.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated']) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
$user = $me['user'];

$companies = FormsService::companiesFor((int)$user['id']);
$activeCompany = null;
if ($companies) {
    $reqCid  = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    $reqUuid = isset($_GET['company_uuid']) ? trim((string)$_GET['company_uuid']) : '';
    foreach ($companies as $c) {
        if ($reqCid && (int)$c['id'] === $reqCid) { $activeCompany = $c; break; }
        if ($reqUuid !== '' && (string)($c['uuid'] ?? '') === $reqUuid) { $activeCompany = $c; break; }
    }
    if (!$activeCompany) { $activeCompany = $companies[0]; }
}

$forms = $activeCompany ? FormsService::listForms((int)$activeCompany['id']) : [];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$publicBase = $scheme . '://' . $host . rtrim($dir, '/');

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();

$statusChip = static function (string $s): string {
    return [
        'draft'  => 'biz-c-slate',
        'open'   => 'biz-c-green',
        'closed' => 'biz-c-amber',
    ][$s] ?? 'biz-c-slate';
};
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Centryk Forms'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php
$pageTitle = 'Centryk Forms';
$headerMaxW = 'max-w-5xl';
$awCurrent = 'forms';
include __DIR__ . '/partials/account_header.php';
?>

<div class="biz mx-auto max-w-5xl px-4 py-4">

    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="biz-kicker">Centryk Forms</p>
            <h1 class="mt-0.5">Surveys &amp; polls</h1>
        </div>
        <div class="flex items-center gap-2">
            <?php if (count($companies) > 1): ?>
            <select class="biz-select" style="width:auto" onchange="location.href='forms.php?company_id=' + this.value">
                <?php foreach ($companies as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($activeCompany): ?>
            <button onclick="createForm()" class="biz-btn biz-btn-primary biz-btn-sm">+ New form</button>
            <?php endif; ?>
        </div>
    </div>

    <div id="alert" class="biz-notice mb-3 hidden"></div>

    <?php if ($activeCompany): ?>
    <div id="createBox" class="biz-panel mb-3 hidden" style="padding:10px 12px">
        <form onsubmit="submitCreateForm(event)" class="flex items-center gap-2">
            <input id="newFormTitle" class="biz-input" style="flex:1" type="text" placeholder="Form title" maxlength="200" autocomplete="off">
            <button type="submit" class="biz-btn biz-btn-primary biz-btn-sm">Create</button>
            <button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="hideCreateForm()">Cancel</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">
            You need to be an admin or manager of a company to build forms.
        </div>
    <?php elseif (!$forms): ?>
        <div class="biz-panel" style="padding:32px 16px;text-align:center">
            <div style="margin:0 auto;display:flex;height:36px;width:36px;align-items:center;justify-content:center;border-radius:4px;background:#eef2ff;color:#4f46e5">
                <i data-lucide="clipboard-list" style="height:18px;width:18px"></i>
            </div>
            <h2 style="margin-top:10px;font-size:15px">No forms yet</h2>
            <p class="biz-muted" style="margin:4px auto 0;max-width:26rem;font-size:12px">
                Build a survey, poll or feedback form, open it, and share the link.
                Responses collect here with a summary and CSV export.
            </p>
            <button onclick="createForm()" class="biz-btn biz-btn-primary" style="margin-top:12px">Create your first form</button>
        </div>
    <?php else: ?>
        <div class="biz-panel">
            <div class="biz-panel-head"><span><?= count($forms) ?> form<?= count($forms) === 1 ? '' : 's' ?></span></div>
            <div class="biz-list">
                <?php foreach ($forms as $f): ?>
                <div class="biz-row" style="align-items:flex-start" data-form-id="<?= (int)$f['id'] ?>">
                    <div class="min-w-0 flex-1">
                        <div class="fname-view flex items-center gap-1">
                            <a href="form-edit.php?id=<?= (int)$f['id'] ?>&company_id=<?= (int)$activeCompany['id'] ?>" class="fname-text block font-bold" style="text-decoration:none;color:var(--bz-accent-d)">
                                <?= htmlspecialchars($f['title']) ?>
                            </a>
                            <button type="button" onclick="renameForm(<?= (int)$f['id'] ?>)" class="biz-btn biz-btn-ghost biz-btn-sm" title="Rename"><i data-lucide="pencil" class="w-3 h-3"></i></button>
                        </div>
                        <form class="fname-edit hidden flex items-center gap-1" onsubmit="submitRename(event, <?= (int)$f['id'] ?>)">
                            <input class="biz-input fname-input" type="text" value="<?= htmlspecialchars($f['title'], ENT_QUOTES) ?>" maxlength="200" autocomplete="off" style="flex:1;min-width:12rem">
                            <button type="submit" class="biz-btn biz-btn-primary biz-btn-sm">Save</button>
                            <button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="cancelRename(<?= (int)$f['id'] ?>)">Cancel</button>
                        </form>
                        <div class="biz-muted mt-0.5" style="font-size:11px">
                            <span class="biz-chip <?= $statusChip($f['status']) ?>"><?= htmlspecialchars($f['status']) ?></span>
                            · <?= (int)$f['question_count'] ?> question<?= (int)$f['question_count'] === 1 ? '' : 's' ?>
                            · <?= (int)$f['response_count'] ?> response<?= (int)$f['response_count'] === 1 ? '' : 's' ?>
                            · edited <?= htmlspecialchars(date('j M Y', strtotime($f['updated_at']))) ?>
                        </div>
                    </div>
                    <div class="shrink-0 flex items-center gap-1">
                        <?php if ((int)$f['response_count'] > 0): ?>
                        <a href="form-responses.php?id=<?= (int)$f['id'] ?>&company_id=<?= (int)$activeCompany['id'] ?>" class="biz-btn biz-btn-ghost biz-btn-sm">Responses</a>
                        <?php endif; ?>
                        <?php if ($f['status'] === 'open'): ?>
                        <button onclick="copyLink('<?= htmlspecialchars($publicBase) ?>/f.php?t=<?= htmlspecialchars($f['share_token']) ?>')" class="biz-btn biz-btn-ghost biz-btn-sm">Copy link</button>
                        <?php endif; ?>
                        <a href="form-edit.php?id=<?= (int)$f['id'] ?>&company_id=<?= (int)$activeCompany['id'] ?>" class="biz-btn biz-btn-ghost biz-btn-sm">Edit</a>
                        <button onclick="dupForm(<?= (int)$f['id'] ?>)" class="biz-btn biz-btn-ghost biz-btn-sm" title="Duplicate"><i data-lucide="copy" class="w-3 h-3"></i></button>
                        <button onclick="delForm(<?= (int)$f['id'] ?>, <?= (int)$f['response_count'] ?>)" class="biz-btn biz-btn-danger biz-btn-sm" title="Delete"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
const COMPANY_ID = <?= $activeCompany ? (int)$activeCompany['id'] : 0 ?>;

function showAlert(msg, kind) {
    const el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'biz-notice mb-3' + (kind === 'error' ? ' biz-notice-red' : ' biz-notice-green');
    el.classList.remove('hidden');
    setTimeout(() => el.classList.add('hidden'), 4000);
}

async function api(path, body) {
    const res = await fetch('api/forms/' + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ company_id: COMPANY_ID, ...body }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) {
        throw new Error(data.message || 'Something went wrong.');
    }
    return data;
}

function createForm() {
    const box = document.getElementById('createBox');
    if (!box) return;
    box.classList.remove('hidden');
    const input = document.getElementById('newFormTitle');
    input.value = '';
    input.focus();
}

function hideCreateForm() {
    document.getElementById('createBox')?.classList.add('hidden');
}

async function submitCreateForm(e) {
    e.preventDefault();
    const input = document.getElementById('newFormTitle');
    const title = input.value.trim() || 'Untitled form';
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
        const { id } = await api('save.php', { title });
        location.href = 'form-edit.php?id=' + id + '&company_id=' + COMPANY_ID;
    } catch (err) {
        showAlert(err.message, 'error');
        btn.disabled = false;
    }
}

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    hideCreateForm();
    document.querySelectorAll('.biz-row[data-form-id]').forEach((row) => {
        if (!row.querySelector('.fname-edit').classList.contains('hidden')) {
            cancelRename(row.dataset.formId);
        }
    });
});

function formRow(id) {
    return document.querySelector('.biz-row[data-form-id="' + id + '"]');
}

function renameForm(id) {
    const row = formRow(id);
    if (!row) return;
    row.querySelector('.fname-view').classList.add('hidden');
    const edit = row.querySelector('.fname-edit');
    edit.classList.remove('hidden');
    const input = edit.querySelector('.fname-input');
    input.focus();
    input.select();
}

function cancelRename(id) {
    const row = formRow(id);
    if (!row) return;
    row.querySelector('.fname-edit').classList.add('hidden');
    row.querySelector('.fname-view').classList.remove('hidden');
    row.querySelector('.fname-input').value = row.querySelector('.fname-text').textContent.trim();
}

async function submitRename(e, id) {
    e.preventDefault();
    const row = formRow(id);
    const title = row.querySelector('.fname-input').value.trim();
    if (!title) { showAlert('Form title cannot be empty.', 'error'); return; }
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
        await api('save.php', { id, title });
        row.querySelector('.fname-text').textContent = title;
        cancelRename(id);
        showAlert('Renamed.');
    } catch (err) {
        showAlert(err.message, 'error');
    } finally {
        btn.disabled = false;
    }
}

async function dupForm(id) {
    try {
        const { id: newId } = await api('duplicate.php', { id });
        location.href = 'form-edit.php?id=' + newId + '&company_id=' + COMPANY_ID;
    } catch (e) { showAlert(e.message, 'error'); }
}

async function delForm(id, responseCount) {
    const extra = responseCount > 0 ? '\n\nThis will also delete ' + responseCount + ' response(s).' : '';
    if (!confirm('Delete this form?' + extra)) return;
    try {
        await api('delete.php', { id });
        location.reload();
    } catch (e) { showAlert(e.message, 'error'); }
}

function copyToClipboard(text, okMsg) {
    const ok = () => showAlert(okMsg);
    const manual = () => showAlert('Copy this link: ' + text, 'error');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(ok, () => legacyCopy(text) ? ok() : manual());
    } else {
        legacyCopy(text) ? ok() : manual();
    }
}

function legacyCopy(text) {
    const input = document.createElement('input');
    input.value = text;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(input);
    return ok;
}

function copyLink(url) {
    copyToClipboard(url, 'Link copied: ' + url);
}

if (window.lucide) lucide.createIcons();
</script>
<?php include __DIR__ . '/partials/footer_app.php'; ?>
</body>
</html>
