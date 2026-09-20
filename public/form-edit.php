<?php
/**
 * Centryk Forms — the builder for one form: settings, questions (add / edit /
 * reorder / delete), status, and the public share link.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/services/FormsService.php';
require_once __DIR__ . '/../app/services/FacebookPagePoster.php';
require_once __DIR__ . '/../app/services/StoreLink.php';
require_once __DIR__ . '/../app/services/FormResultsShare.php';

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
$fbConn = FacebookPagePoster::connection($companyId);
$isCompanyAdmin = ($activeCompany['role'] ?? '') === 'admin';
$modCounts = FormsService::moderationCounts($formId);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir    = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$publicBase = $scheme . '://' . $host . rtrim($dir, '/');
$longUrl = $publicBase . '/f.php?t=' . $form['share_token'];

// Short link: <site>/review/<company-slug>/<form-slug>. The long link keeps working.
$companySlug = StoreLink::ensure(DB::pdo(), $companyId, (string)$activeCompany['name']);
$formSlug = FormsService::ensureSlug($formId, $companyId);
$siteRoot = preg_replace('#/public$#', '', $publicBase);
// Company logo for the middle of the QR code: only a real uploaded file.
$qrLogo = '';
$lg = DB::pdo()->prepare('SELECT logo FROM companies WHERE id = :id');
$lg->execute(['id' => $companyId]);
$lgPath = trim((string)$lg->fetchColumn());
if (preg_match('#^uploads/companies/[A-Za-z0-9._-]+\.(png|jpe?g|webp|gif)$#i', $lgPath) && is_file(__DIR__ . '/' . $lgPath)) {
    $qrLogo = $lgPath;
}
$resShare = FormResultsShare::status($formId, $companyId);
$resultsUrl = $resShare['enabled'] && $resShare['token'] ? $siteRoot . '/results/' . $resShare['token'] : '';
$namedUrl = $siteRoot . '/review/' . $companySlug . '/' . $formSlug;   // descriptive link, still works
$shortCode = FormsService::ensureShortCode($formId, $companyId);
$shareUrl = $siteRoot . '/r/' . $shortCode;                            // shortest; used for the QR and Copy link

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
    'email'           => 'Email address',
    'phone'           => 'Phone number',
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
        <div class="flex items-center gap-2">
            <a href="forms.php?company_id=<?= $companyId ?>" class="biz-btn biz-btn-ghost biz-btn-sm">&larr; All forms</a>
            <p class="biz-kicker" style="margin:0">Editing</p>
        </div>
        <div class="flex items-center gap-1.5">
            <a href="f.php?t=<?= htmlspecialchars($form['share_token']) ?>&preview=1" target="_blank" rel="noopener" class="biz-btn biz-btn-ghost biz-btn-sm">Preview</a>
            <?php if ((int)$form['response_count'] > 0): ?>
            <a href="form-responses.php?id=<?= $formId ?>&company_id=<?= $companyId ?>" class="biz-btn biz-btn-ghost biz-btn-sm">Responses (<?= (int)$form['response_count'] ?>)</a>
            <?php endif; ?>
            <?php if (!empty($form['reviews_enabled'])): ?>
            <a href="form-responses.php?id=<?= $formId ?>&company_id=<?= $companyId ?>&view=moderation" class="biz-btn biz-btn-ghost biz-btn-sm">Review queue<?= $modCounts['pending'] ? ' (' . $modCounts['pending'] . ' pending)' : '' ?></a>
            <?php endif; ?>
            <button id="statusBtn" class="biz-btn biz-btn-primary biz-btn-sm"></button>
        </div>
    </div>

    <!-- Fixed so results are visible wherever the user has scrolled (e.g. the Facebook panel far down the sidebar). -->
    <div id="alert" class="biz-notice mb-3 hidden" style="position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:60;width:max-content;max-width:92vw;box-shadow:0 4px 16px rgba(0,0,0,.18)"></div>

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
                <p class="biz-muted" style="font-size:10px;margin:0 0 0 22px">Goes by device and network, so people on shared event Wi-Fi with similar phones can be wrongly blocked. Use with care at events.</p>
                <label class="flex items-start gap-2 pt-1" style="font-size:12px">
                    <input type="checkbox" id="fUniqueContacts" class="mt-0.5" <?= !empty($form['unique_contacts']) ? 'checked' : '' ?>>
                    <span>One entry per phone number or email <span class="biz-muted">(rejects a repeat of the same number or address; needs a Phone or Email question)</span></span>
                </label>
                <button onclick="saveAccess()" class="biz-btn biz-btn-ghost biz-btn-sm" style="width:100%">Save</button>
            </div>

            <div class="biz-panel biz-panel-body space-y-1.5">
                <p class="biz-label" style="margin:0">Share link</p>
                <div class="biz-muted" id="shareState" style="font-size:11px"></div>
                <input id="shareUrl" class="biz-input biz-num" style="font-size:11px" readonly value="<?= htmlspecialchars($shareUrl) ?>">
                <button onclick="copyShare()" class="biz-btn biz-btn-ghost biz-btn-sm" style="width:100%">Copy link</button>
                <label class="block pt-1"><span class="biz-label">Short code <span class="biz-muted">(the /r/ link)</span></span>
                    <div class="flex gap-1.5">
                        <input id="fCode" class="biz-input" maxlength="30" value="<?= htmlspecialchars($shortCode) ?>">
                        <button onclick="saveCode()" class="biz-btn biz-btn-ghost biz-btn-sm">Save</button>
                    </div></label>
                <p class="biz-muted" style="font-size:10px;margin:0">3 to 30 letters, numbers or hyphens, unique across Centryk. Changing it changes the QR code, so reprint any cards already made.</p>

                <details class="pt-1" style="font-size:11px">
                    <summary class="biz-muted" style="cursor:pointer">Other links that also open this form</summary>
                    <div class="space-y-1.5 pt-1.5">
                        <div class="biz-num" style="word-break:break-all"><?= htmlspecialchars($namedUrl) ?></div>
                        <label class="block"><span class="biz-label">Link name (the last part of the link above)</span>
                            <div class="flex gap-1.5">
                                <input id="fSlug" class="biz-input" maxlength="60" value="<?= htmlspecialchars($formSlug) ?>">
                                <button onclick="saveSlug()" class="biz-btn biz-btn-ghost biz-btn-sm">Save</button>
                            </div></label>
                        <div class="biz-num" style="word-break:break-all"><?= htmlspecialchars($longUrl) ?></div>
                    </div>
                </details>
            </div>

            <div class="biz-panel biz-panel-body space-y-2">
                <p class="biz-label" style="margin:0">QR code</p>
                <p class="biz-muted" style="font-size:11px;margin:0">Print it for tables, counters or receipts. Scanning opens this form.</p>
                <div id="qrBox" class="flex justify-center rounded bg-white p-2" style="border:1px solid var(--bz-line-soft)"></div>
                <?php if ($qrLogo !== ''): ?>
                <div style="font-size:12px">
                    <span class="biz-label">QR style</span>
                    <label class="flex items-center gap-2"><input type="radio" name="qrStyle" value="logo" checked onchange="qrOptionsChanged()"> With company logo</label>
                    <label class="flex items-center gap-2"><input type="radio" name="qrStyle" value="plain" onchange="qrOptionsChanged()"> Without logo (most reliable)</label>
                </div>
                <?php endif; ?>
                <label class="block"><span class="biz-label">Message on the card</span>
                    <textarea id="qrMessage" rows="3" maxlength="200" class="biz-input" placeholder="Scan to tell us what you think"><?= htmlspecialchars((string)$form['qr_message']) ?></textarea></label>
                <div class="flex items-center justify-between gap-2">
                    <span class="biz-muted" style="font-size:10px">Blank uses "Scan to tell us what you think". Keep it short for 8 per page.</span>
                    <button onclick="saveQrMessage()" class="biz-btn biz-btn-ghost biz-btn-sm">Save message</button>
                </div>
                <label class="block"><span class="biz-label">Print layout</span>
                    <select id="printLayout" class="biz-select" onchange="qrOptionsChanged()">
                        <option value="1">1 per page (large)</option>
                        <option value="4">4 per page (cut out)</option>
                        <option value="8">8 per page (cut out, small)</option>
                    </select></label>
                <p id="printHint" class="biz-muted hidden" style="font-size:10px;margin:0"></p>
                <p class="biz-muted" style="font-size:10px;margin:0">Always scan-test a printed card with a phone, in the lighting where it will be used.</p>
                <div class="flex gap-1.5">
                    <button onclick="downloadQr()" class="biz-btn biz-btn-ghost biz-btn-sm" style="flex:1">Download PNG</button>
                    <button onclick="printQrCard()" class="biz-btn biz-btn-primary biz-btn-sm" style="flex:1">Print</button>
                </div>
            </div>

            <div class="biz-panel biz-panel-body space-y-2">
                <p class="biz-label" style="margin:0">Share results</p>
                <p class="biz-muted" style="font-size:11px;margin:0">A public page with the totals and charts, protected by a 4 to 6 digit code. It never shows names, phone numbers, emails or written answers.</p>
                <?php if ($resultsUrl !== ''): ?>
                <div class="biz-muted" style="font-size:11px;color:var(--bz-accent-d)">Sharing is on.</div>
                <input id="resultsUrl" class="biz-input biz-num" style="font-size:11px" readonly value="<?= htmlspecialchars($resultsUrl) ?>">
                <button onclick="copyResultsLink()" class="biz-btn biz-btn-ghost biz-btn-sm" style="width:100%">Copy results link</button>
                <label class="block pt-1"><span class="biz-label">Change the code</span>
                    <div class="flex gap-1.5">
                        <input id="resultsPin" class="biz-input biz-num" inputmode="numeric" maxlength="6" placeholder="4 to 6 digits" autocomplete="off">
                        <button onclick="setResultsShare('enable')" class="biz-btn biz-btn-ghost biz-btn-sm">Save</button>
                    </div></label>
                <button onclick="setResultsShare('disable')" class="biz-btn biz-btn-danger biz-btn-sm" style="width:100%">Turn off sharing</button>
                <p class="biz-muted" style="font-size:10px;margin:0">The code can't be viewed again once saved, only replaced. Five wrong tries lock the page for 15 minutes.</p>
                <?php else: ?>
                <label class="block"><span class="biz-label">Choose a code</span>
                    <div class="flex gap-1.5">
                        <input id="resultsPin" class="biz-input biz-num" inputmode="numeric" maxlength="6" placeholder="4 to 6 digits" autocomplete="off">
                        <button onclick="setResultsShare('enable')" class="biz-btn biz-btn-primary biz-btn-sm">Turn on</button>
                    </div></label>
                <?php endif; ?>
            </div>

            <div class="biz-panel biz-panel-body space-y-2">
                <p class="biz-label" style="margin:0">Look &amp; reviews</p>
                <label class="block"><span class="biz-label">Theme</span>
                    <select id="fTheme" class="biz-select">
                        <?php foreach (FormsService::THEMES as $k => $t): ?>
                        <option value="<?= htmlspecialchars($k) ?>" <?= $form['theme'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($t['label']) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="flex items-start gap-2 pt-1" style="font-size:12px">
                    <input type="checkbox" id="fReviews" class="mt-0.5" <?= !empty($form['reviews_enabled']) ? 'checked' : '' ?>>
                    <span>Let customers agree to share their review. Reviews they agree to share wait in a queue for you to approve.</span>
                </label>
                <label class="block"><span class="biz-label">Facebook page / reviews link <span class="biz-muted">(optional)</span></span>
                    <input id="fRecommend" class="biz-input" placeholder="https://facebook.com/yourpage/reviews" value="<?= htmlspecialchars($form['fb_recommend_url']) ?>"></label>
                <label class="flex items-start gap-2" style="font-size:12px">
                    <input type="checkbox" id="fAutoRedirect" class="mt-0.5" <?= !empty($form['fb_auto_redirect']) ? 'checked' : '' ?>>
                    <span>Send diners to that link automatically, a few seconds after they submit, so they can like the page and leave a review. Otherwise they only see a button.</span>
                </label>
                <button onclick="saveReviewSettings()" class="biz-btn biz-btn-ghost biz-btn-sm" style="width:100%">Save</button>
            </div>

            <div class="biz-panel biz-panel-body space-y-2">
                <p class="biz-label" style="margin:0">Facebook Page</p>
                <div id="fbState" style="font-size:12px">
                <?php if ($fbConn): ?>
                    <span class="font-bold"><?= htmlspecialchars($fbConn['page_name'] ?: $fbConn['page_id']) ?></span>
                    <span class="biz-muted"> · connected. Approved reviews post here.</span>
                <?php else: ?>
                    <span class="biz-muted">Not connected. Approved reviews can be copied and posted by hand.</span>
                <?php endif; ?>
                </div>
                <?php if ($isCompanyAdmin): ?>
                <div id="fbConnectForm" class="space-y-1.5 <?= $fbConn ? 'hidden' : '' ?>">
                    <input id="fbPageId" class="biz-input biz-num" placeholder="Page ID" autocomplete="off">
                    <input id="fbToken" class="biz-input" type="password" placeholder="Page access token" autocomplete="off">
                    <button onclick="connectFacebook()" class="biz-btn biz-btn-primary biz-btn-sm" style="width:100%">Connect Page</button>
                </div>
                <?php if ($fbConn): ?>
                <button onclick="disconnectFacebook()" class="biz-btn biz-btn-danger biz-btn-sm" style="width:100%">Disconnect</button>
                <?php endif; ?>
                <?php else: ?>
                <p class="biz-muted" style="font-size:11px;margin:0">Only a company admin can connect the Page.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Filled in by printQrCard(): 1, 4 or 8 identical cards, laid out for one page. -->
<div id="printSheet"></div>
<style>
    #qrBox canvas, #qrBox img { width: 200px !important; height: 200px !important; }
    #printSheet { display: none; }
    @page { margin: 10mm; }
    @media print {
        body > *:not(#printSheet) { display: none !important; }
        #printSheet { display: grid !important; font-family: system-ui, sans-serif; color: #111; }
        #printSheet.sheet-1 { grid-template-columns: 1fr; }
        #printSheet.sheet-4, #printSheet.sheet-8 { grid-template-columns: repeat(2, 1fr); }
        .pc { box-sizing: border-box; text-align: center; display: flex; flex-direction: column; align-items: center;
              justify-content: center; break-inside: avoid; page-break-inside: avoid; overflow: hidden; }
        .pc-co { margin: 0; text-transform: uppercase; letter-spacing: .14em; font-weight: 800; color: #555; }
        .pc-title { margin: .25em 0 .1em; font-weight: 800; line-height: 1.15; }
        .pc-cta { margin: 0 0 .6em; }
        .pc-qr { display: block; }
        .pc-url { margin: .6em 0 0; color: #777; word-break: break-all; }

        /* 1 per page */
        .sheet-1 .pc { min-height: 250mm; padding: 10mm; }
        .sheet-1 .pc-co { font-size: 14pt; } .sheet-1 .pc-title { font-size: 32pt; } .sheet-1 .pc-cta { font-size: 20pt; }
        .sheet-1 .pc-qr { width: 100mm; height: 100mm; } .sheet-1 .pc-url { font-size: 10pt; }

        /* 4 per page (2 x 2), dashed cut lines */
        .sheet-4 .pc { height: 128mm; padding: 6mm; border: 1px dashed #aaa; }
        .sheet-4 .pc-co { font-size: 9pt; } .sheet-4 .pc-title { font-size: 17pt; } .sheet-4 .pc-cta { font-size: 12pt; }
        .sheet-4 .pc-qr { width: 66mm; height: 66mm; } .sheet-4 .pc-url { font-size: 7pt; }

        /* 8 per page (2 x 4), dashed cut lines; link text left off to fit */
        .sheet-8 .pc { height: 63mm; padding: 3mm; border: 1px dashed #aaa; }
        .sheet-8 .pc-co { font-size: 6.5pt; } .sheet-8 .pc-title { font-size: 10pt; } .sheet-8 .pc-cta { font-size: 8pt; margin-bottom: .3em; }
        .sheet-8 .pc-qr { width: 32mm; height: 32mm; } .sheet-8 .pc-url { display: none; }
    }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const COMPANY_ID = <?= $companyId ?>;
const FORM_ID = <?= $formId ?>;
const SHARE_URL = <?= json_encode($shareUrl) ?>;
const COMPANY_NAME = <?= json_encode((string)$activeCompany['name']) ?>;
const FORM_TITLE = <?= json_encode((string)$form['title']) ?>;
const TYPE_LABELS = <?= json_encode($TYPE_LABELS) ?>;
const CHOICE_TYPES = ['single_choice', 'multiple_choice', 'dropdown'];
let questions = <?= json_encode($questions) ?>;
let formStatus = <?= json_encode($form['status']) ?>;

let alertTimer = null;
function showAlert(msg, kind) {
    const el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'biz-notice mb-3' + (kind === 'error' ? ' biz-notice-red' : ' biz-notice-green');
    el.classList.remove('hidden');
    clearTimeout(alertTimer);
    // Errors stay longer: they usually explain what to fix.
    alertTimer = setTimeout(() => el.classList.add('hidden'), kind === 'error' ? 10000 : 4000);
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
            unique_contacts: document.getElementById('fUniqueContacts').checked ? 1 : 0,
        });
        showAlert('Saved.');
    } catch (e) { showAlert(e.message, 'error'); }
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

function copyShare() {
    const ok = () => showAlert('Link copied.');
    const manual = () => showAlert('Copy this link: ' + SHARE_URL, 'error');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(SHARE_URL).then(ok, () => legacyCopy(SHARE_URL) ? ok() : manual());
    } else {
        legacyCopy(SHARE_URL) ? ok() : manual();
    }
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

async function saveQrMessage() {
    try {
        await api('save.php', { id: FORM_ID, qr_message: document.getElementById('qrMessage').value });
        showAlert('Card message saved.');
    } catch (e) { showAlert(e.message, 'error'); }
}

async function setResultsShare(action) {
    try {
        await api('results_share.php', { action, pin: document.getElementById('resultsPin')?.value || '' });
        showAlert(action === 'enable' ? 'Saved. Reloading…' : 'Sharing turned off. Reloading…');
        setTimeout(() => location.reload(), 800);
    } catch (e) { showAlert(e.message, 'error'); }
}

function copyResultsLink() {
    const url = document.getElementById('resultsUrl').value;
    const ok = () => showAlert('Results link copied. Share the code with them separately.');
    const manual = () => showAlert('Copy this link: ' + url, 'error');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(ok, () => legacyCopy(url) ? ok() : manual());
    } else {
        legacyCopy(url) ? ok() : manual();
    }
}

async function saveCode() {
    try {
        await api('save.php', { id: FORM_ID, short_code: document.getElementById('fCode').value });
        showAlert('Short code updated. Reloading…');
        setTimeout(() => location.reload(), 800);
    } catch (e) { showAlert(e.message, 'error'); }
}

async function saveSlug() {
    try {
        await api('save.php', { id: FORM_ID, slug: document.getElementById('fSlug').value });
        showAlert('Short link updated. Reloading…');
        setTimeout(() => location.reload(), 800);
    } catch (e) { showAlert(e.message, 'error'); }
}

// ── Look, reviews & Facebook ─────────────────────────────────────────────
async function saveReviewSettings() {
    try {
        await api('save.php', {
            id: FORM_ID,
            theme: document.getElementById('fTheme').value,
            reviews_enabled: document.getElementById('fReviews').checked ? 1 : 0,
            fb_recommend_url: document.getElementById('fRecommend').value.trim(),
            fb_auto_redirect: document.getElementById('fAutoRedirect').checked ? 1 : 0,
        });
        showAlert('Saved.');
    } catch (e) { showAlert(e.message, 'error'); }
}

async function connectFacebook() {
    try {
        const { connection } = await api('facebook_connect.php', {
            action: 'connect',
            page_id: document.getElementById('fbPageId').value,
            access_token: document.getElementById('fbToken').value,
        });
        document.getElementById('fbToken').value = '';
        showAlert('Connected to ' + connection.page_name + '. Reloading…');
        setTimeout(() => location.reload(), 900);
    } catch (e) { showAlert(e.message, 'error'); }
}

async function disconnectFacebook() {
    try {
        await api('facebook_connect.php', { action: 'disconnect' });
        location.reload();
    } catch (e) { showAlert(e.message, 'error'); }
}

// ── QR code ──────────────────────────────────────────────────────────────
let qrReady = false;
let qrInstance = null;
const QR_LOGO = <?= json_encode($qrLogo) ?>;   // '' when the company has no usable logo
const QR_SIZE = 400;                            // drawn large, shown at 200px, so it prints sharply
function renderQr() {
    const box = document.getElementById('qrBox');
    qrReady = false;
    box.innerHTML = '';
    if (!window.QRCode) { box.textContent = 'QR code unavailable offline.'; return; }
    // Level H can lose ~30% of the code and still scan, which is what makes a logo in the middle safe.
    qrInstance = new QRCode(box, { text: SHARE_URL, width: QR_SIZE, height: QR_SIZE, correctLevel: QRCode.CorrectLevel.H });

    const style = document.querySelector('input[name="qrStyle"]:checked');
    const canvas = box.querySelector('canvas');
    if (!QR_LOGO || (style && style.value === 'plain') || !canvas) { qrReady = true; return; }

    const logo = new Image();
    logo.onload = () => {
        const ctx = canvas.getContext('2d');
        const size = canvas.width;
        const inner = Math.round(size * 0.20);          // logo area: 20% of the width (about 4% of the code)
        const pad = Math.round(inner * 0.14);           // white border so the logo doesn't blur into the modules
        const start = Math.round((size - inner) / 2);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(start - pad, start - pad, inner + pad * 2, inner + pad * 2);
        const ratio = Math.min(inner / logo.width, inner / logo.height);
        const w = logo.width * ratio, h = logo.height * ratio;
        ctx.drawImage(logo, (size - w) / 2, (size - h) / 2, w, h);
        const shown = box.querySelector('img');
        if (shown) shown.src = canvas.toDataURL('image/png');
        qrReady = true;
    };
    logo.onerror = () => { qrReady = true; };           // logo missing: plain QR code still works
    logo.src = QR_LOGO;
}
// The QR image as a PNG data URL. withBorder adds the white "quiet zone" (4 modules on every
// side) that scanners need; the download has it so it can be dropped into a flyer safely,
// while the printed cards already have white space around the code and use it without.
function qrDataUrl(withBorder) {
    const c = document.querySelector('#qrBox canvas');
    if (!c) {
        const img = document.querySelector('#qrBox img');
        return img ? img.src : '';
    }
    if (!withBorder) return c.toDataURL('image/png');
    let modules = 37;
    try { modules = qrInstance._oQRCode.getModuleCount() || 37; } catch (e) {}
    const border = Math.ceil(c.width / modules * 4);
    const out = document.createElement('canvas');
    out.width = out.height = c.width + border * 2;
    const ctx = out.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, out.width, out.height);
    ctx.drawImage(c, border, border);
    return out.toDataURL('image/png');
}
function downloadQr() {
    const url = qrDataUrl(true);
    if (!qrReady || !url) { showAlert('QR code is not ready yet.', 'error'); return; }
    const a = document.createElement('a');
    a.href = url;
    a.download = 'form-qr-' + FORM_ID + '.png';
    document.body.appendChild(a);
    a.click();
    a.remove();
}
// A QR style or print layout was changed: redraw the code and update the small-cards hint.
function qrOptionsChanged() {
    renderQr();
    const style = document.querySelector('input[name="qrStyle"]:checked');
    const layout = document.getElementById('printLayout').value;
    const hint = document.getElementById('printHint');
    if (style && style.value === 'logo' && layout === '8') {
        hint.textContent = 'Small cards scan less reliably with a logo in the middle. "Without logo" is safer for 8 per page.';
        hint.classList.remove('hidden');
    } else {
        hint.classList.add('hidden');
    }
}

// Print 1, 4 or 8 identical cards on one page (the layout is CSS in the print styles above).
function printQrCard() {
    const url = qrDataUrl();
    if (!qrReady || !url) { showAlert('QR code is not ready yet.', 'error'); return; }
    const n = parseInt(document.getElementById('printLayout').value, 10);
    const count = [1, 4, 8].includes(n) ? n : 1;
    const card =
        '<div class="pc">' +
        '<p class="pc-co">' + esc(COMPANY_NAME) + '</p>' +
        '<h1 class="pc-title">' + esc(FORM_TITLE) + '</h1>' +
        '<p class="pc-cta">' + esc(((document.getElementById('qrMessage').value || '').trim().replace(/\s+/g, ' ')) || 'Scan to tell us what you think') + '</p>' +
        '<img class="pc-qr" alt="QR code" src="' + url + '">' +
        '<p class="pc-url">' + esc(SHARE_URL) + '</p>' +
        '</div>';
    const sheet = document.getElementById('printSheet');
    sheet.className = 'sheet-' + count;
    sheet.innerHTML = card.repeat(count);
    window.print();
}

renderStatus();
renderQuestions();
renderQr();
</script>
<?php include __DIR__ . '/partials/footer_app.php'; ?>
</body>
</html>
