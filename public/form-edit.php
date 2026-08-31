<?php
/**
 * Centryk Forms — the builder for one form: settings, questions (add / edit /
 * reorder / delete), status, and the public share link.
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
$companyId = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
$activeCompany = null;
foreach ($companies as $c) {
    if ((int)$c['id'] === $companyId) { $activeCompany = $c; break; }
}
if (!$activeCompany && $companies) {
    $activeCompany = $companies[0];
    $companyId = (int)$activeCompany['id'];
}

$formId = (int)($_GET['id'] ?? 0);
$form = $activeCompany ? FormsService::getForm($formId, $companyId) : null;
if (!$form) {
    header('Location: forms.php' . ($companyId ? '?company_id=' . $companyId : ''));
    exit;
}
$questions = FormsService::questions($formId);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$publicBase = $scheme . '://' . $host . rtrim($dir, '/');
$shareUrl = $publicBase . '/f.php?t=' . $form['share_token'];

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();

$TYPE_LABELS = [
    'short_text'      => 'Short text',
    'long_text'       => 'Paragraph',
    'single_choice'   => 'Single choice',
    'multiple_choice' => 'Multiple choice',
    'dropdown'        => 'Dropdown',
    'rating'          => 'Rating scale',
    'yes_no'          => 'Yes / No',
    'number'          => 'Number',
    'date'            => 'Date',
    'section'         => 'Section heading',
];
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = htmlspecialchars($form['title']); include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php
$pageTitle = 'Centryk Forms';
$headerMaxW = 'max-w-5xl';
$awCurrent = 'forms';
include __DIR__ . '/partials/account_header.php';
?>

<div class="biz mx-auto max-w-5xl px-4 py-4">

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p class="biz-kicker"><a href="forms.php?company_id=<?= $companyId ?>" class="biz-t-green">Forms</a> · editing</p>
        <div class="flex items-center gap-1.5">
            <a href="f.php?t=<?= htmlspecialchars($form['share_token']) ?>&preview=1" target="_blank" rel="noopener" class="biz-btn biz-btn-ghost biz-btn-sm">Preview</a>
            <?php if ((int)$form['response_count'] > 0): ?>
            <a href="form-responses.php?id=<?= $formId ?>&company_id=<?= $companyId ?>" class="biz-btn biz-btn-ghost biz-btn-sm">Responses (<?= (int)$form['response_count'] ?>)</a>
            <?php endif; ?>
            <button id="statusBtn" class="biz-btn biz-btn-primary biz-btn-sm"></button>
        </div>
    </div>

    <div id="alert" class="biz-notice mb-3 hidden"></div>

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_300px] items-start">

        <!-- Questions -->
        <div class="biz-panel">
            <div class="biz-panel-head">
                <span>Questions</span>
                <div class="flex items-center gap-1.5">
                    <select id="newType" class="biz-select" style="width:auto;font-size:11px">
                        <?php foreach ($TYPE_LABELS as $k => $v): ?>
                        <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button onclick="addQuestion()" class="biz-btn biz-btn-ghost biz-btn-sm">+ Add</button>
                </div>
            </div>
            <div id="questionList" class="biz-panel-body space-y-2"></div>
        </div>

        <!-- Settings -->
        <div class="space-y-3">
            <div class="biz-panel biz-panel-body space-y-2">
                <label class="block"><span class="biz-label">Form title</span>
                    <input id="fTitle" class="biz-input" value="<?= htmlspecialchars($form['title']) ?>"></label>
                <label class="block"><span class="biz-label">Description <span class="biz-muted">(optional)</span></span>
                    <textarea id="fDescr" rows="3" class="biz-input" placeholder="Shown above the questions"><?= htmlspecialchars((string)$form['description']) ?></textarea></label>
                <label class="block"><span class="biz-label">Confirmation message</span>
                    <input id="fConfirm" class="biz-input" placeholder="Thanks — your response has been recorded." value="<?= htmlspecialchars($form['confirmation_message']) ?>"></label>
                <button onclick="saveSettings()" class="biz-btn biz-btn-primary biz-btn-sm" style="width:100%">Save settings</button>
            </div>

            <div class="biz-panel biz-panel-body space-y-2">
                <p class="biz-label" style="margin:0">Who can respond</p>
                <label class="flex items-center gap-2" style="font-size:12px">
                    <input type="radio" name="access" value="public" <?= $form['access'] === 'public' ? 'checked' : '' ?>> Anyone with the link
                </label>
                <label class="flex items-center gap-2" style="font-size:12px">
                    <input type="radio" name="access" value="login_required" <?= $form['access'] === 'login_required' ? 'checked' : '' ?>> Signed-in Centryk users only
                </label>
                <label class="flex items-center gap-2 pt-1" style="font-size:12px">
                    <input type="checkbox" id="fOneResponse" <?= $form['one_response_per_person'] ? 'checked' : '' ?>> One response per person
                </label>
                <button onclick="saveAccess()" class="biz-btn biz-btn-ghost biz-btn-sm" style="width:100%">Save</button>
            </div>

            <div class="biz-panel biz-panel-body space-y-1.5">
                <p class="biz-label" style="margin:0">Share link</p>
                <div class="biz-muted" id="shareState" style="font-size:11px"></div>
                <input id="shareUrl" class="biz-input biz-num" style="font-size:11px" readonly value="<?= htmlspecialchars($shareUrl) ?>">
                <button onclick="copyShare()" class="biz-btn biz-btn-ghost biz-btn-sm" style="width:100%">Copy link</button>
            </div>
        </div>
    </div>
</div>

<script>
const COMPANY_ID = <?= $companyId ?>;
const FORM_ID = <?= $formId ?>;
const SHARE_URL = <?= json_encode($shareUrl) ?>;
const TYPE_LABELS = <?= json_encode($TYPE_LABELS) ?>;
const CHOICE_TYPES = ['single_choice', 'multiple_choice', 'dropdown'];
let questions = <?= json_encode($questions) ?>;
let formStatus = <?= json_encode($form['status']) ?>;

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
        body: JSON.stringify({ company_id: COMPANY_ID, form_id: FORM_ID, ...body }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) throw new Error(data.message || 'Something went wrong.');
    return data;
}

const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

function renderStatus() {
    const btn = document.getElementById('statusBtn');
    const state = document.getElementById('shareState');
    if (formStatus === 'open') {
        btn.textContent = 'Close form';
        btn.className = 'biz-btn biz-btn-danger biz-btn-sm';
        state.textContent = 'Open — accepting responses.';
        state.style.color = 'var(--bz-accent-d)';
    } else if (formStatus === 'closed') {
        btn.textContent = 'Reopen form';
        btn.className = 'biz-btn biz-btn-primary biz-btn-sm';
        state.textContent = 'Closed — the link shows a "closed" message.';
        state.style.color = '';
    } else {
        btn.textContent = 'Open form';
        btn.className = 'biz-btn biz-btn-primary biz-btn-sm';
        state.textContent = 'Draft — not live yet. Open it to start collecting.';
        state.style.color = '';
    }
}

document.getElementById('statusBtn').addEventListener('click', async () => {
    const next = formStatus === 'open' ? 'closed' : 'open';
    try {
        const { form } = await api('save.php', { id: FORM_ID, status: next });
        formStatus = form.status;
        renderStatus();
        showAlert(formStatus === 'open' ? 'Form is live.' : 'Form ' + formStatus + '.');
    } catch (e) { showAlert(e.message, 'error'); }
});

async function saveSettings() {
    try {
        await api('save.php', {
            id: FORM_ID,
            title: document.getElementById('fTitle').value,
            description: document.getElementById('fDescr').value,
            confirmation_message: document.getElementById('fConfirm').value,
        });
        showAlert('Settings saved.');
    } catch (e) { showAlert(e.message, 'error'); }
}

async function saveAccess() {
    try {
        await api('save.php', {
            id: FORM_ID,
            access: document.querySelector('input[name=access]:checked').value,
            one_response_per_person: document.getElementById('fOneResponse').checked ? 1 : 0,
        });
        showAlert('Saved.');
    } catch (e) { showAlert(e.message, 'error'); }
}

function copyShare() {
    navigator.clipboard.writeText(SHARE_URL).then(
        () => showAlert('Link copied.'),
        () => prompt('Copy this link', SHARE_URL)
    );
}

// ── Question list ────────────────────────────────────────────────────────
function renderQuestions() {
    const wrap = document.getElementById('questionList');
    if (!questions.length) {
        wrap.innerHTML = '<div class="biz-panel-empty">No questions yet. Pick a type and press Add.</div>';
        return;
    }
    wrap.innerHTML = questions.map((q, i) => card(q, i)).join('');
    if (window.lucide) lucide.createIcons();
}

function card(q, i) {
    const isSection = q.type === 'section';
    const opts = (q.options || []).map(esc).join(' · ');
    return `
    <div class="rounded" style="border:1px solid var(--bz-line)">
        <div class="flex items-center justify-between gap-2 px-2.5 py-1.5" style="background:var(--bz-head)">
            <span class="biz-kicker" style="margin:0">${esc(TYPE_LABELS[q.type] || q.type)}${q.required ? ' · required' : ''}</span>
            <span class="flex items-center gap-1">
                <button onclick="move(${i}, -1)" class="biz-btn biz-btn-ghost biz-btn-sm" ${i === 0 ? 'disabled' : ''}><i data-lucide="chevron-up" class="w-3 h-3"></i></button>
                <button onclick="move(${i}, 1)" class="biz-btn biz-btn-ghost biz-btn-sm" ${i === questions.length - 1 ? 'disabled' : ''}><i data-lucide="chevron-down" class="w-3 h-3"></i></button>
                <button onclick="editQ(${q.id})" class="biz-btn biz-btn-ghost biz-btn-sm">Edit</button>
                <button onclick="delQ(${q.id})" class="biz-btn biz-btn-danger biz-btn-sm"><i data-lucide="trash-2" class="w-3 h-3"></i></button>
            </span>
        </div>
        <div class="px-2.5 py-2">
            <div class="${isSection ? 'font-bold' : ''}" style="font-size:13px">${esc(q.label)}</div>
            ${q.help_text ? `<div class="biz-muted" style="font-size:11px">${esc(q.help_text)}</div>` : ''}
            ${opts ? `<div class="biz-muted mt-1" style="font-size:11px">${opts}</div>` : ''}
            ${q.type === 'rating' ? `<div class="biz-muted mt-1" style="font-size:11px">1 to ${(q.config && q.config.max) || 5}</div>` : ''}
        </div>
        <div id="editor-${q.id}" class="hidden px-2.5 pb-2.5"></div>
    </div>`;
}

function editorHtml(q) {
    const isNew = !q.id;
    const showOpts = CHOICE_TYPES.includes(q.type);
    return `
    <div class="space-y-2 pt-2" style="border-top:1px solid var(--bz-line-soft)">
        <label class="block"><span class="biz-label">Question / label</span>
            <input class="biz-input" data-f="label" value="${esc(q.label)}"></label>
        ${q.type === 'section' ? '' : `
        <label class="block"><span class="biz-label">Help text (optional)</span>
            <input class="biz-input" data-f="help_text" value="${esc(q.help_text || '')}"></label>
        <label class="flex items-center gap-2" style="font-size:12px">
            <input type="checkbox" data-f="required" ${q.required ? 'checked' : ''}> Required</label>`}
        ${showOpts ? `
        <label class="block"><span class="biz-label">Options (one per line)</span>
            <textarea class="biz-input" data-f="options" rows="4">${esc((q.options || []).join('\n'))}</textarea></label>` : ''}
        ${q.type === 'rating' ? `
        <label class="block"><span class="biz-label">Scale max (2–10)</span>
            <input class="biz-input biz-num" type="number" min="2" max="10" data-f="rating_max" value="${(q.config && q.config.max) || 5}"></label>` : ''}
        <div class="flex gap-2 pt-0.5">
            <button onclick="saveQ(this, ${q.id || 0}, '${q.type}')" class="biz-btn biz-btn-primary biz-btn-sm">Save</button>
            <button onclick="${isNew ? 'cancelNew(this)' : `closeEditor(${q.id})`}" class="biz-btn biz-btn-ghost biz-btn-sm">Cancel</button>
        </div>
    </div>`;
}

function editQ(id) {
    const box = document.getElementById('editor-' + id);
    if (!box.classList.contains('hidden')) { box.classList.add('hidden'); box.innerHTML = ''; return; }
    const q = questions.find(x => x.id === id);
    box.innerHTML = editorHtml(q);
    box.classList.remove('hidden');
}
function closeEditor(id) {
    const box = document.getElementById('editor-' + id);
    box.classList.add('hidden'); box.innerHTML = '';
}

function addQuestion() {
    const type = document.getElementById('newType').value;
    const wrap = document.getElementById('questionList');
    if (document.getElementById('newEditor')) return;
    const div = document.createElement('div');
    div.id = 'newEditor';
    div.className = 'rounded';
    div.style.border = '1px solid var(--bz-accent)';
    div.innerHTML = '<div class="px-2.5 py-1.5"><span class="biz-kicker">New ' + esc(TYPE_LABELS[type]) + '</span></div><div class="px-2.5 pb-2">'
        + editorHtml({ type, label: '', help_text: '', required: false, options: type === 'section' ? [] : ['Option 1', 'Option 2'], config: {} })
        + '</div>';
    wrap.appendChild(div);
    div.querySelector('input[data-f=label]').focus();
}
function cancelNew(btn) {
    const n = document.getElementById('newEditor');
    if (n) n.remove();
}

function collect(scope) {
    const g = f => scope.querySelector(`[data-f="${f}"]`);
    const q = { label: g('label') ? g('label').value : '' };
    if (g('help_text')) q.help_text = g('help_text').value;
    if (g('required')) q.required = g('required').checked ? 1 : 0;
    if (g('options')) q.options = g('options').value.split('\n').map(s => s.trim()).filter(Boolean);
    if (g('rating_max')) q.config = { max: parseInt(g('rating_max').value, 10) || 5 };
    return q;
}

async function saveQ(btn, id, type) {
    const scope = btn.closest('.space-y-2');
    const q = collect(scope);
    q.type = type;
    if (id) q.id = id;
    try {
        const data = await api('question_save.php', { question: q });
        questions = data.questions;
        const n = document.getElementById('newEditor');
        if (n) n.remove();
        renderQuestions();
        showAlert('Question saved.');
    } catch (e) { showAlert(e.message, 'error'); }
}

async function delQ(id) {
    if (!confirm('Delete this question?')) return;
    try {
        const data = await api('question_delete.php', { question_id: id });
        questions = data.questions;
        renderQuestions();
    } catch (e) { showAlert(e.message, 'error'); }
}

async function move(i, dir) {
    const j = i + dir;
    if (j < 0 || j >= questions.length) return;
    [questions[i], questions[j]] = [questions[j], questions[i]];
    renderQuestions();
    try {
        await api('reorder.php', { order: questions.map(q => q.id) });
    } catch (e) { showAlert(e.message, 'error'); }
}

renderStatus();
renderQuestions();
</script>
<?php include __DIR__ . '/partials/footer_app.php'; ?>
</body>
</html>
