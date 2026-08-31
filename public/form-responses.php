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

$view = ($_GET['view'] ?? 'summary') === 'responses' ? 'responses' : 'summary';

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
            </span>
            <span class="biz-muted" style="font-size:11px"><?= (int)$summary['total'] ?> response<?= (int)$summary['total'] === 1 ? '' : 's' ?></span>
        </div>

        <?php if ((int)$summary['total'] === 0): ?>
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

<script>if (window.lucide) lucide.createIcons();</script>
<?php include __DIR__ . '/partials/footer_app.php'; ?>
</body>
</html>
