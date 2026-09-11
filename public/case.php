<?php
/**
 * Case Management — case detail: timeline (status changes + comments),
 * documents, and controls (status / assignment) gated by
 * CaseManagementService's visibility + role rules.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/services/CaseManagementService.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated']) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
$user = $me['user'];

$caseId    = (int)($_GET['id'] ?? 0);
$companyId = (int)($_GET['company_id'] ?? 0);

$companies = CaseManagementService::companiesFor((int)$user['id']);
$activeCompany = null;
foreach ($companies as $c) {
    if ((int)$c['id'] === $companyId) { $activeCompany = $c; break; }
}
if (!$activeCompany) {
    http_response_code(404);
    echo 'Company not found, or you are not a member of it.';
    exit;
}
$role = $activeCompany['role'];
$isManager = in_array($role, ['admin', 'manager'], true);

$case = CaseManagementService::getCase($caseId, $companyId, (int)$user['id'], $role);
if (!$case) {
    http_response_code(404);
    echo 'Case not found, or you do not have access to it.';
    exit;
}
$canAct = $isManager
    || (int)$case['requester_user_id'] === (int)$user['id']
    || (int)($case['assignee_user_id'] ?? 0) === (int)$user['id'];

$events    = CaseManagementService::events($caseId, $isManager);
$documents = CaseManagementService::documents($caseId);
$members   = $isManager ? CaseManagementService::companyMembers($companyId) : [];

$statusChip = static function (string $s): string {
    return [
        'open'        => 'biz-c-blue',
        'in_progress' => 'biz-c-accent',
        'waiting'     => 'biz-c-amber',
        'resolved'    => 'biz-c-green',
        'closed'      => 'biz-c-slate',
    ][$s] ?? 'biz-c-slate';
};
$priorityChip = static function (string $p): string {
    return [
        'urgent' => 'biz-c-red',
        'high'   => 'biz-c-amber',
        'normal' => 'biz-c-slate',
        'low'    => 'biz-c-slate',
    ][$p] ?? 'biz-c-slate';
};
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = $case['case_number']; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Case Management'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="biz mx-auto max-w-6xl px-4 py-4" style="--bz-accent:#2563eb;--bz-accent-d:#1d4ed8">

    <a href="cases.php?company_id=<?= $companyId ?>" class="biz-muted" style="font-size:11px;text-decoration:none">&larr; All cases</a>

    <div class="mt-1 mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="biz-kicker"><?= htmlspecialchars($case['case_number']) ?></p>
            <h1 class="mt-0.5"><?= htmlspecialchars($case['subject']) ?></h1>
            <div class="mt-1">
                <span class="biz-chip <?= $statusChip($case['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $case['status'])) ?></span>
                <span class="biz-chip <?= $priorityChip($case['priority']) ?>"><?= htmlspecialchars($case['priority']) ?></span>
                <?php if ($case['service_name']): ?><span class="biz-muted" style="font-size:11px"> · <?= htmlspecialchars($case['service_name']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <div id="alert" class="biz-notice mb-3 hidden"></div>

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_20rem]">

        <div class="min-w-0">
            <div class="biz-panel mb-3" style="padding:12px">
                <div class="biz-kicker mb-1">Description</div>
                <p style="font-size:13px;white-space:pre-wrap"><?= htmlspecialchars($case['description'] ?: 'No description given.') ?></p>
            </div>

            <div class="biz-panel mb-3">
                <div class="biz-panel-head"><span>Activity</span></div>
                <div id="timeline" style="padding:10px 12px">
                    <?php foreach ($events as $ev): ?>
                    <?php
                        $who = trim(($ev['first_name'] ?? '') . ' ' . ($ev['last_name'] ?? '')) ?: 'System';
                        $when = date('j M Y, g:ia', strtotime($ev['created_at']));
                    ?>
                    <div style="padding:6px 0;border-bottom:1px solid var(--bz-line)">
                        <?php if ($ev['event_type'] === 'comment'): ?>
                            <div style="font-size:12px"><strong><?= htmlspecialchars($who) ?></strong>
                                <?php if ($ev['is_internal']): ?><span class="biz-chip biz-c-amber" style="margin-left:4px">internal</span><?php endif; ?>
                                <span class="biz-muted"> · <?= $when ?></span>
                            </div>
                            <p style="font-size:13px;white-space:pre-wrap;margin-top:2px"><?= htmlspecialchars($ev['note']) ?></p>
                        <?php elseif ($ev['event_type'] === 'status_change'): ?>
                            <div style="font-size:12px" class="biz-muted">
                                <strong><?= htmlspecialchars($who) ?></strong> changed status
                                <?= htmlspecialchars(str_replace('_', ' ', (string)$ev['from_value'])) ?> &rarr; <strong><?= htmlspecialchars(str_replace('_', ' ', (string)$ev['to_value'])) ?></strong>
                                <span class="biz-muted"> · <?= $when ?></span>
                                <?php if ($ev['note']): ?><br><?= htmlspecialchars($ev['note']) ?><?php endif; ?>
                            </div>
                        <?php elseif ($ev['event_type'] === 'assigned'): ?>
                            <div style="font-size:12px" class="biz-muted">
                                <strong><?= htmlspecialchars($who) ?></strong> updated the assignee
                                <span class="biz-muted"> · <?= $when ?></span>
                            </div>
                        <?php else: ?>
                            <div style="font-size:12px" class="biz-muted">
                                <strong><?= htmlspecialchars($who) ?></strong> opened this case
                                <span class="biz-muted"> · <?= $when ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($canAct): ?>
                <div style="padding:10px 12px;border-top:1px solid var(--bz-line)">
                    <form onsubmit="submitComment(event)" class="grid gap-1.5">
                        <textarea id="commentBody" class="biz-input" placeholder="Add a comment..." required></textarea>
                        <div class="flex items-center justify-between">
                            <?php if ($isManager): ?>
                            <label class="flex items-center gap-1 text-xs font-semibold biz-muted">
                                <input type="checkbox" id="commentInternal"> Internal note (staff only)
                            </label>
                            <?php else: ?><span></span><?php endif; ?>
                            <button type="submit" class="biz-btn biz-btn-primary biz-btn-sm">Post</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <div class="biz-panel">
                <div class="biz-panel-head"><span>Documents</span></div>
                <div id="docList" class="biz-list">
                    <?php if (!$documents): ?>
                    <div class="biz-panel-empty" style="padding:16px">No documents attached.</div>
                    <?php else: foreach ($documents as $d): ?>
                    <div class="biz-row">
                        <a href="api/cases/download_document.php?company_id=<?= $companyId ?>&document_id=<?= (int)$d['id'] ?>" class="flex-1" style="text-decoration:none;color:var(--bz-accent-d);font-size:13px;font-weight:600">
                            <i data-lucide="paperclip" class="w-3 h-3" style="display:inline"></i> <?= htmlspecialchars($d['original_filename']) ?>
                        </a>
                        <span class="biz-muted" style="font-size:11px"><?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?> · <?= date('j M Y', strtotime($d['created_at'])) ?></span>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                <?php if ($canAct): ?>
                <div style="padding:10px 12px;border-top:1px solid var(--bz-line)">
                    <form id="uploadForm" class="flex items-center gap-2">
                        <input type="file" id="docFile" class="biz-input" style="flex:1">
                        <button type="button" onclick="submitUpload()" class="biz-btn biz-btn-ghost biz-btn-sm">Attach</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="biz-panel mb-3" style="padding:12px">
                <div class="biz-kicker mb-1.5">Details</div>
                <dl style="font-size:12px;margin:0">
                    <dt class="biz-muted">Requester</dt>
                    <dd style="margin:0 0 6px 0"><?= htmlspecialchars($case['req_first'] . ' ' . $case['req_last']) ?></dd>

                    <dt class="biz-muted">Assignee</dt>
                    <dd style="margin:0 0 6px 0">
                        <?php if ($isManager): ?>
                        <select id="assigneeSelect" class="biz-select" onchange="submitAssign(this.value)">
                            <option value="">Unassigned</option>
                            <?php foreach ($members as $m): ?>
                            <option value="<?= (int)$m['id'] ?>" <?= (int)($case['assignee_user_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <?= $case['asg_first'] ? htmlspecialchars($case['asg_first'] . ' ' . $case['asg_last']) : 'Unassigned' ?>
                        <?php endif; ?>
                    </dd>

                    <dt class="biz-muted">Status</dt>
                    <dd style="margin:0 0 6px 0">
                        <?php if ($canAct): ?>
                        <select id="statusSelect" class="biz-select" onchange="submitStatus(this.value)">
                            <?php foreach (CaseManagementService::STATUSES as $s): ?>
                            <option value="<?= $s ?>" <?= $case['status'] === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <?= htmlspecialchars(str_replace('_', ' ', $case['status'])) ?>
                        <?php endif; ?>
                    </dd>

                    <dt class="biz-muted">Category</dt>
                    <dd style="margin:0 0 6px 0"><?= htmlspecialchars($case['category_name'] ?: '—') ?></dd>

                    <dt class="biz-muted">Due date</dt>
                    <dd style="margin:0 0 6px 0"><?= $case['due_date'] ? htmlspecialchars(date('j M Y', strtotime($case['due_date']))) : '—' ?></dd>

                    <dt class="biz-muted">Opened</dt>
                    <dd style="margin:0 0 6px 0"><?= htmlspecialchars(date('j M Y, g:ia', strtotime($case['created_at']))) ?></dd>

                    <?php if ($case['resolved_at']): ?>
                    <dt class="biz-muted">Resolved</dt>
                    <dd style="margin:0 0 6px 0"><?= htmlspecialchars(date('j M Y, g:ia', strtotime($case['resolved_at']))) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>
</div>

<script>
const COMPANY_ID = <?= $companyId ?>;
const CASE_ID = <?= $caseId ?>;

function showAlert(msg, kind) {
    const el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'biz-notice mb-3' + (kind === 'error' ? ' biz-notice-red' : ' biz-notice-green');
    el.classList.remove('hidden');
    setTimeout(() => el.classList.add('hidden'), 4000);
}

async function api(path, body) {
    const res = await fetch('api/cases/' + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ company_id: COMPANY_ID, id: CASE_ID, ...body }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) {
        throw new Error(data.message || 'Something went wrong.');
    }
    return data;
}

async function submitComment(e) {
    e.preventDefault();
    const body = document.getElementById('commentBody').value.trim();
    if (!body) return;
    const internal = document.getElementById('commentInternal')?.checked || false;
    try {
        await api('comment.php', { body, internal });
        location.reload();
    } catch (err) { showAlert(err.message, 'error'); }
}

async function submitStatus(status) {
    try {
        await api('status.php', { status });
        location.reload();
    } catch (err) { showAlert(err.message, 'error'); }
}

async function submitAssign(assigneeUserId) {
    try {
        await api('assign.php', { assignee_user_id: assigneeUserId || null });
        location.reload();
    } catch (err) { showAlert(err.message, 'error'); }
}

async function submitUpload() {
    const input = document.getElementById('docFile');
    if (!input.files.length) { showAlert('Choose a file first.', 'error'); return; }
    const fd = new FormData();
    fd.append('company_id', COMPANY_ID);
    fd.append('id', CASE_ID);
    fd.append('file', input.files[0]);
    try {
        const res = await fetch('api/cases/upload_document.php', { method: 'POST', body: fd });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) throw new Error(data.message || 'Upload failed.');
        location.reload();
    } catch (err) { showAlert(err.message, 'error'); }
}

if (window.lucide) lucide.createIcons();
</script>
<?php include __DIR__ . '/partials/footer_app.php'; ?>
</body>
</html>
