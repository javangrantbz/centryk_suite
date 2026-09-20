<?php
/**
 * Centryk Forms — results for one form: a per-question summary and the
 * individual responses, plus a CSV export link.
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

$questions = array_values(array_filter(FormsService::questions($formId), static fn ($q) => $q['type'] !== 'section'));
$summary = FormsService::summary($formId);
$responses = FormsService::responses($formId, 200);

require_once __DIR__ . '/../app/services/FacebookPagePoster.php';
$reviewsOn = !empty($form['reviews_enabled']);
$view = $_GET['view'] ?? 'summary';
$view = in_array($view, ['responses', 'moderation'], true) ? $view : 'summary';
if ($view === 'moderation' && !$reviewsOn) {
    $view = 'summary';
}
$modCounts = FormsService::moderationCounts($formId);
$modStatus = in_array($_GET['status'] ?? '', ['approved', 'rejected'], true) ? $_GET['status'] : 'pending';
$modQueue = $view === 'moderation' ? FormsService::moderationQueue($formId, $modStatus) : [];
$fbConn = $view === 'moderation' ? FacebookPagePoster::connection($companyId) : null;

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();

function fr_bar(int $count, int $total): string
{
    $pct = $total > 0 ? round($count / $total * 100) : 0;
    return '<div style="height:8px;border-radius:2px;background:var(--bz-line-soft);overflow:hidden">'
        . '<div style="height:100%;width:' . $pct . '%;background:var(--bz-accent)"></div></div>';
}
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = htmlspecialchars($form['title']) . ' — responses'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php
$pageTitle = 'Centryk Forms';
$headerMaxW = 'max-w-5xl';
$awCurrent = 'forms';
include __DIR__ . '/partials/account_header.php';
?>

<div class="biz mx-auto max-w-5xl px-4 py-4">

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
            <p class="biz-kicker"><a href="forms.php?company_id=<?= $companyId ?>" class="biz-t-green">Forms</a> · results</p>
            <h1 class="mt-0.5"><?= htmlspecialchars($form['title']) ?></h1>
        </div>
        <div class="flex items-center gap-1.5">
            <a href="form-edit.php?id=<?= $formId ?>&company_id=<?= $companyId ?>" class="biz-btn biz-btn-ghost biz-btn-sm">Edit form</a>
            <a href="api/forms/export.php?company_id=<?= $companyId ?>&form_id=<?= $formId ?>" class="biz-btn biz-btn-primary biz-btn-sm">Export CSV</a>
        </div>
    </div>

    <div class="biz-panel">
        <div class="biz-panel-head" style="text-transform:none;letter-spacing:0">
            <span class="biz-seg">
                <a href="?id=<?= $formId ?>&company_id=<?= $companyId ?>&view=summary" class="<?= $view === 'summary' ? 'is-active' : '' ?>">Summary</a>
                <a href="?id=<?= $formId ?>&company_id=<?= $companyId ?>&view=responses" class="<?= $view === 'responses' ? 'is-active' : '' ?>">Responses</a>
                <?php if ($reviewsOn): ?>
                <a href="?id=<?= $formId ?>&company_id=<?= $companyId ?>&view=moderation" class="<?= $view === 'moderation' ? 'is-active' : '' ?>">Reviews<?= $modCounts['pending'] ? ' (' . $modCounts['pending'] . ')' : '' ?></a>
                <?php endif; ?>
            </span>
            <span class="biz-muted" style="font-size:11px"><?= (int)$summary['total'] ?> response<?= (int)$summary['total'] === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($view === 'moderation'): ?>
            <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line-soft)">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="biz-seg">
                        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $lbl): ?>
                        <a href="?id=<?= $formId ?>&company_id=<?= $companyId ?>&view=moderation&status=<?= $k ?>" class="<?= $modStatus === $k ? 'is-active' : '' ?>"><?= $lbl ?> (<?= (int)$modCounts[$k] ?>)</a>
                        <?php endforeach; ?>
                    </span>
                    <span class="biz-muted" style="font-size:11px">
                        <?= $fbConn ? 'Posting to ' . htmlspecialchars($fbConn['page_name'] ?: $fbConn['page_id']) : 'No Facebook Page connected: copy approved text to post by hand.' ?>
                    </span>
                </div>
            </div>
            <?php if (!$modQueue): ?>
                <div class="biz-panel-empty">
                    <?= $modStatus === 'pending' ? 'Nothing waiting. Reviews customers agree to share will appear here.' : 'No ' . $modStatus . ' reviews yet.' ?>
                </div>
            <?php else: ?>
            <div class="biz-list">
                <?php foreach ($modQueue as $r): ?>
                <div class="biz-row" style="align-items:flex-start" data-review-id="<?= (int)$r['id'] ?>">
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="flex flex-wrap items-center gap-1.5" style="font-size:11px">
                            <span class="biz-muted"><?= htmlspecialchars(date('j M Y H:i', strtotime($r['submitted_at']))) ?></span>
                            <?php if ($r['flagged']): ?><span class="biz-chip biz-c-red">Check wording</span><?php endif; ?>
                            <?php if ($r['fb_post_id']): ?><span class="biz-chip biz-c-green">On Facebook</span><?php endif; ?>
                            <?php if ($r['fb_error']): ?><span class="biz-chip biz-c-amber"><?= htmlspecialchars($r['fb_error']) ?></span><?php endif; ?>
                        </div>
                        <?php if ($modStatus === 'pending'): ?>
                        <textarea class="biz-input review-text" rows="3" style="font-size:13px"><?= htmlspecialchars((string)$r['post_text']) ?></textarea>
                        <div class="flex gap-1.5">
                            <button onclick="moderate(<?= (int)$r['id'] ?>, 'approve')" class="biz-btn biz-btn-primary biz-btn-sm">Approve<?= $fbConn ? ' &amp; post' : '' ?></button>
                            <button onclick="moderate(<?= (int)$r['id'] ?>, 'reject')" class="biz-btn biz-btn-danger biz-btn-sm">Reject</button>
                        </div>
                        <?php else: ?>
                        <div class="whitespace-pre-line rounded px-2 py-1.5" style="font-size:13px;background:var(--bz-head);border:1px solid var(--bz-line-soft)"><?= htmlspecialchars((string)$r['post_text']) ?></div>
                        <div class="flex gap-1.5">
                            <?php if ($modStatus === 'approved' && !$r['fb_post_id']): ?>
                            <?php if ($fbConn): ?><button onclick="moderate(<?= (int)$r['id'] ?>, 'post')" class="biz-btn biz-btn-primary biz-btn-sm">Post now</button><?php endif; ?>
                            <button onclick="copyReview(this)" class="biz-btn biz-btn-ghost biz-btn-sm">Copy text</button>
                            <?php endif; ?>
                            <?php if (!$r['fb_post_id']): ?>
                            <button onclick="moderate(<?= (int)$r['id'] ?>, '<?= $modStatus === 'approved' ? 'reject' : 'approve' ?>')" class="biz-btn biz-btn-ghost biz-btn-sm"><?= $modStatus === 'approved' ? 'Reject' : 'Approve' ?></button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        <?php elseif ((int)$summary['total'] === 0): ?>
            <div class="biz-panel-empty">No responses yet. Share the form link to start collecting.</div>

        <?php elseif ($view === 'summary'): ?>
            <div class="biz-panel-body space-y-4">
                <?php foreach ($summary['questions'] as $q): ?>
                <div>
                    <div class="font-bold" style="font-size:13px"><?= htmlspecialchars($q['label']) ?></div>
                    <div class="biz-muted" style="font-size:11px">
                        <?= (int)$q['answered'] ?> answered
                        <?php if (isset($q['average']) && $q['average'] !== null): ?> · avg <?= htmlspecialchars((string)$q['average']) ?><?php endif; ?>
                        <?php if (isset($q['min']) && $q['min'] !== null): ?> · range <?= htmlspecialchars((string)$q['min']) ?>–<?= htmlspecialchars((string)$q['max']) ?><?php endif; ?>
                    </div>

                    <?php if (!empty($q['breakdown'])): ?>
                        <div class="mt-1.5 space-y-1">
                        <?php foreach ($q['breakdown'] as $label => $count): ?>
                            <div class="grid grid-cols-[minmax(0,1fr)_2.5rem] items-center gap-2" style="font-size:12px">
                                <div>
                                    <div class="flex justify-between"><span class="truncate"><?= htmlspecialchars((string)$label) ?></span><span class="biz-num biz-muted"><?= (int)$count ?></span></div>
                                    <?= fr_bar((int)$count, (int)$q['answered']) ?>
                                </div>
                                <div class="biz-num biz-muted" style="font-size:11px;text-align:right"><?= $q['answered'] > 0 ? round($count / $q['answered'] * 100) : 0 ?>%</div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php elseif (!empty($q['samples'])): ?>
                        <ul class="mt-1.5 space-y-1">
                        <?php foreach ($q['samples'] as $s): ?>
                            <li class="rounded px-2 py-1" style="font-size:12px;background:var(--bz-head);border:1px solid var(--bz-line-soft)"><?= htmlspecialchars($s) ?></li>
                        <?php endforeach; ?>
                        </ul>
                        <?php if ($q['answered'] > count($q['samples'])): ?>
                        <div class="biz-muted mt-1" style="font-size:11px">+ <?= $q['answered'] - count($q['samples']) ?> more in the CSV export</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full" style="font-size:12px">
                    <thead>
                        <tr class="biz-muted" style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid var(--bz-line)">
                            <th class="px-3 py-2 font-bold">Submitted</th>
                            <th class="px-3 py-2 font-bold">Respondent</th>
                            <?php foreach ($questions as $q): ?>
                            <th class="px-3 py-2 font-bold" style="min-width:140px"><?= htmlspecialchars($q['label']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($responses as $r): ?>
                        <tr style="border-top:1px solid var(--bz-line-soft);vertical-align:top">
                            <td class="px-3 py-1.5 biz-num" style="white-space:nowrap"><?= htmlspecialchars(date('j M Y H:i', strtotime($r['submitted_at']))) ?></td>
                            <td class="px-3 py-1.5 biz-muted"><?= htmlspecialchars($r['respondent_name'] ?: 'Anonymous') ?></td>
                            <?php foreach ($questions as $q): ?>
                            <td class="px-3 py-1.5"><?= nl2br(htmlspecialchars((string)($r['answers'][(int)$q['id']] ?? ''))) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($responses) >= 200): ?>
            <div class="biz-panel-body biz-muted" style="font-size:11px">Showing the latest 200. Use Export CSV for the full set.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div id="modAlert" class="biz-notice hidden" style="position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:50;max-width:90vw"></div>
<script>
const COMPANY_ID = <?= $companyId ?>;

function modNotice(msg, kind) {
    const el = document.getElementById('modAlert');
    el.textContent = msg;
    el.className = 'biz-notice ' + (kind === 'error' ? 'biz-notice-red' : 'biz-notice-green');
    el.classList.remove('hidden');
    setTimeout(() => el.classList.add('hidden'), 4500);
}

async function moderate(id, action) {
    const row = document.querySelector('[data-review-id="' + id + '"]');
    const ta = row ? row.querySelector('.review-text') : null;
    const body = { company_id: COMPANY_ID, response_id: id, action };
    if (ta && (action === 'approve' || action === 'reject')) body.text = ta.value;
    row?.querySelectorAll('button').forEach(b => b.disabled = true);
    try {
        const res = await fetch('api/forms/moderate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) throw new Error(data.message || 'Something went wrong.');
        modNotice(data.facebook ? data.facebook.message : (action === 'reject' ? 'Rejected.' : 'Saved.'),
                  data.facebook && !data.facebook.posted ? 'error' : 'ok');
        setTimeout(() => location.reload(), 1100);
    } catch (e) {
        modNotice(e.message, 'error');
        row?.querySelectorAll('button').forEach(b => b.disabled = false);
    }
}

function copyReview(btn) {
    const text = btn.closest('.biz-row').querySelector('.whitespace-pre-line').textContent;
    const done = () => modNotice('Copied. Paste it into a Facebook post.');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, () => modNotice('Could not copy. Select the text and copy it by hand.', 'error'));
    } else {
        modNotice('Select the text and copy it by hand.', 'error');
    }
}
if (window.lucide) lucide.createIcons();
</script>
<?php include __DIR__ . '/partials/footer_app.php'; ?>
</body>
</html>
