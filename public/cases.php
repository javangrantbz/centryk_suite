<?php
/**
 * Case Management — list/intake home. Free-core hub feature (no entitlement
 * gate, no apps-registry/opt-in row) — same pattern as Calendar: any active
 * company member can open and track cases; admin/manager see every case for
 * the company and manage its categories/services.
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

$companies = CaseManagementService::companiesFor((int)$user['id']);
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
$isManager = $activeCompany && in_array($activeCompany['role'], ['admin', 'manager'], true);
$companyId = $activeCompany ? (int)$activeCompany['id'] : 0;

$categories = $activeCompany ? CaseManagementService::categories($companyId, false) : [];
$services   = $activeCompany ? CaseManagementService::services($companyId, false) : [];
$cases      = $activeCompany ? CaseManagementService::listCases($companyId, (int)$user['id'], $activeCompany['role']) : [];
$stats      = $activeCompany ? CaseManagementService::stats($companyId, (int)$user['id'], $activeCompany['role']) : ['open' => 0, 'unassigned' => 0, 'overdue' => 0];
$members    = ($activeCompany && $isManager) ? CaseManagementService::companyMembers($companyId) : [];

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

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Case Management'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Case Management'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="biz mx-auto max-w-6xl px-4 py-4" style="--bz-accent:#2563eb;--bz-accent-d:#1d4ed8">

    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="biz-kicker">Case Management</p>
            <h1 class="mt-0.5">Track and resolve work across your team</h1>
        </div>
        <div class="flex items-center gap-2">
            <?php if (count($companies) > 1): ?>
            <select class="biz-select" style="width:auto" onchange="location.href='cases.php?company_id=' + this.value">
                <?php foreach ($companies as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($activeCompany): ?>
            <button onclick="openNewCase()" class="biz-btn biz-btn-primary biz-btn-sm">+ New case</button>
            <?php if ($isManager): ?>
            <button onclick="toggleSettings()" class="biz-btn biz-btn-ghost biz-btn-sm"><i data-lucide="settings" class="w-3 h-3"></i> Settings</button>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="alert" class="biz-notice mb-3 hidden"></div>

    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">You need to be a member of a company to use Case Management.</div>
    <?php else: ?>

        <div class="mb-3 grid grid-cols-3 gap-2">
            <div class="biz-panel" style="padding:10px 12px">
                <div class="biz-kicker">Open</div>
                <div style="font-size:20px;font-weight:800"><?= (int)$stats['open'] ?></div>
            </div>
            <div class="biz-panel" style="padding:10px 12px">
                <div class="biz-kicker">Unassigned</div>
                <div style="font-size:20px;font-weight:800"><?= (int)$stats['unassigned'] ?></div>
            </div>
            <div class="biz-panel" style="padding:10px 12px">
                <div class="biz-kicker">Overdue</div>
                <div style="font-size:20px;font-weight:800;<?= $stats['overdue'] > 0 ? 'color:#b91c1c' : '' ?>"><?= (int)$stats['overdue'] ?></div>
            </div>
        </div>

        <!-- New case box -->
        <div id="newCaseBox" class="biz-panel mb-3 hidden" style="padding:12px">
            <form onsubmit="submitNewCase(event)" class="grid gap-2 sm:grid-cols-2">
                <input id="ncSubject" class="biz-input sm:col-span-2" type="text" placeholder="Subject" maxlength="200" autocomplete="off" required>
                <textarea id="ncDescription" class="biz-input sm:col-span-2" placeholder="Description (optional)"></textarea>
                <select id="ncService" class="biz-select">
                    <option value="">No service / category</option>
                    <?php
                    $svcByCat = [];
                    foreach ($services as $s) {
                        if (!$s['is_active']) { continue; }
                        $svcByCat[$s['category_name'] ?: 'Other'][] = $s;
                    }
                    foreach ($svcByCat as $catName => $svcs): ?>
                    <optgroup label="<?= htmlspecialchars($catName) ?>">
                        <?php foreach ($svcs as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
                <select id="ncPriority" class="biz-select">
                    <option value="low">Low priority</option>
                    <option value="normal" selected>Normal priority</option>
                    <option value="high">High priority</option>
                    <option value="urgent">Urgent</option>
                </select>
                <input id="ncDueDate" class="biz-input" type="date">
                <div class="flex items-center gap-2">
                    <button type="submit" class="biz-btn biz-btn-primary biz-btn-sm">Create case</button>
                    <button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="closeNewCase()">Cancel</button>
                </div>
            </form>
        </div>

        <?php if ($isManager): ?>
        <!-- Settings: categories & services -->
        <div id="settingsBox" class="biz-panel mb-3 hidden" style="padding:12px">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <div class="biz-kicker mb-1.5">Categories</div>
                    <div id="categoryList" class="biz-list mb-2">
                        <?php foreach ($categories as $c): ?>
                        <div class="biz-row" style="padding:5px 0">
                            <span class="flex-1 <?= !$c['is_active'] ? 'biz-muted' : '' ?>"><?= htmlspecialchars($c['name']) ?></span>
                            <button class="biz-btn biz-btn-ghost biz-btn-sm" onclick="toggleCategory(<?= (int)$c['id'] ?>, <?= $c['is_active'] ? 'false' : 'true' ?>, '<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>')">
                                <?= $c['is_active'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <form onsubmit="submitNewCategory(event)" class="flex items-center gap-1.5">
                        <input id="newCategoryName" class="biz-input" style="flex:1" type="text" placeholder="New category" maxlength="150" autocomplete="off">
                        <button type="submit" class="biz-btn biz-btn-primary biz-btn-sm">Add</button>
                    </form>
                </div>
                <div>
                    <div class="biz-kicker mb-1.5">Services</div>
                    <div id="serviceList" class="biz-list mb-2">
                        <?php foreach ($services as $s): ?>
                        <div class="biz-row" style="padding:5px 0">
                            <span class="flex-1 <?= !$s['is_active'] ? 'biz-muted' : '' ?>">
                                <?= htmlspecialchars($s['name']) ?>
                                <?php if ($s['category_name']): ?><span class="biz-muted"> · <?= htmlspecialchars($s['category_name']) ?></span><?php endif; ?>
                            </span>
                            <button class="biz-btn biz-btn-ghost biz-btn-sm" onclick="toggleService(<?= (int)$s['id'] ?>, <?= $s['is_active'] ? 'false' : 'true' ?>, '<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>')">
                                <?= $s['is_active'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <form onsubmit="submitNewService(event)" class="grid gap-1.5">
                        <input id="newServiceName" class="biz-input" type="text" placeholder="New service" maxlength="200" autocomplete="off">
                        <select id="newServiceCategory" class="biz-select">
                            <option value="">No category</option>
                            <?php foreach ($categories as $c): if (!$c['is_active']) continue; ?>
                            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="biz-btn biz-btn-primary biz-btn-sm">Add service</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <select id="fStatus" class="biz-select" style="width:auto" onchange="reloadCases()">
                <option value="">Any open status</option>
                <?php foreach (CaseManagementService::STATUSES as $s): ?>
                <option value="<?= $s ?>"><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="fPriority" class="biz-select" style="width:auto" onchange="reloadCases()">
                <option value="">Any priority</option>
                <?php foreach (CaseManagementService::PRIORITIES as $p): ?>
                <option value="<?= $p ?>"><?= ucfirst($p) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($isManager): ?>
            <label class="flex items-center gap-1 text-xs font-semibold" style="color:var(--bz-muted)">
                <input type="checkbox" id="fMine" onchange="reloadCases()"> Only mine
            </label>
            <?php endif; ?>
            <label class="flex items-center gap-1 text-xs font-semibold" style="color:var(--bz-muted)">
                <input type="checkbox" id="fClosed" onchange="reloadCases()"> Include closed
            </label>
        </div>

        <div class="biz-panel">
            <div class="biz-panel-head"><span id="caseCount"><?= count($cases) ?> case<?= count($cases) === 1 ? '' : 's' ?></span></div>
            <div id="caseList" class="biz-list">
                <?php include __DIR__ . '/partials/cases_rows.php'; ?>
            </div>
        </div>

    <?php endif; ?>
</div>

<script>
const COMPANY_ID = <?= $companyId ?>;
const IS_MANAGER = <?= $isManager ? 'true' : 'false' ?>;

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
        body: JSON.stringify({ company_id: COMPANY_ID, ...body }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) {
        throw new Error(data.message || 'Something went wrong.');
    }
    return data;
}

function openNewCase() {
    document.getElementById('newCaseBox')?.classList.remove('hidden');
    document.getElementById('ncSubject')?.focus();
}
function closeNewCase() {
    document.getElementById('newCaseBox')?.classList.add('hidden');
}
function toggleSettings() {
    document.getElementById('settingsBox')?.classList.toggle('hidden');
}

async function submitNewCase(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
        const { id } = await api('create.php', {
            subject: document.getElementById('ncSubject').value.trim(),
            description: document.getElementById('ncDescription').value.trim(),
            service_id: document.getElementById('ncService').value || null,
            priority: document.getElementById('ncPriority').value,
            due_date: document.getElementById('ncDueDate').value || null,
        });
        location.href = 'case.php?id=' + id + '&company_id=' + COMPANY_ID;
    } catch (err) {
        showAlert(err.message, 'error');
        btn.disabled = false;
    }
}

async function reloadCases() {
    try {
        const { cases } = await api('list.php', {
            status: document.getElementById('fStatus').value,
            priority: document.getElementById('fPriority').value,
            mine: IS_MANAGER && document.getElementById('fMine')?.checked,
            include_closed: document.getElementById('fClosed').checked,
        });
        renderCases(cases);
    } catch (err) { showAlert(err.message, 'error'); }
}

function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

const STATUS_CHIP = { open: 'biz-c-blue', in_progress: 'biz-c-accent', waiting: 'biz-c-amber', resolved: 'biz-c-green', closed: 'biz-c-slate' };
const PRIORITY_CHIP = { urgent: 'biz-c-red', high: 'biz-c-amber', normal: 'biz-c-slate', low: 'biz-c-slate' };

function renderCases(cases) {
    const list = document.getElementById('caseList');
    document.getElementById('caseCount').textContent = cases.length + ' case' + (cases.length === 1 ? '' : 's');
    if (!cases.length) {
        list.innerHTML = '<div class="biz-panel-empty" style="padding:24px">No cases match this filter.</div>';
        return;
    }
    list.innerHTML = cases.map(c => `
        <div class="biz-row" style="align-items:flex-start">
            <div class="min-w-0 flex-1">
                <a href="case.php?id=${c.id}&company_id=${COMPANY_ID}" class="block font-bold" style="text-decoration:none;color:var(--bz-accent-d)">
                    ${esc(c.case_number)} — ${esc(c.subject)}
                </a>
                <div class="biz-muted mt-0.5" style="font-size:11px">
                    <span class="biz-chip ${STATUS_CHIP[c.status] || 'biz-c-slate'}">${esc(c.status.replace('_',' '))}</span>
                    <span class="biz-chip ${PRIORITY_CHIP[c.priority] || 'biz-c-slate'}">${esc(c.priority)}</span>
                    ${c.service_name ? '· ' + esc(c.service_name) : ''}
                    · requested by ${esc(c.req_first)} ${esc(c.req_last)}
                    ${c.asg_first ? '· assigned to ' + esc(c.asg_first) + ' ' + esc(c.asg_last) : '· unassigned'}
                    ${c.due_date ? '· due ' + esc(c.due_date) : ''}
                </div>
            </div>
        </div>
    `).join('');
}

async function submitNewCategory(e) {
    e.preventDefault();
    const input = document.getElementById('newCategoryName');
    const name = input.value.trim();
    if (!name) return;
    try {
        await api('category_save.php', { name });
        location.reload();
    } catch (err) { showAlert(err.message, 'error'); }
}

async function toggleCategory(id, active, name) {
    try {
        await api('category_save.php', { id, name, is_active: active });
        location.reload();
    } catch (err) { showAlert(err.message, 'error'); }
}

async function submitNewService(e) {
    e.preventDefault();
    const name = document.getElementById('newServiceName').value.trim();
    if (!name) return;
    try {
        await api('service_save.php', { name, category_id: document.getElementById('newServiceCategory').value || null });
        location.reload();
    } catch (err) { showAlert(err.message, 'error'); }
}

async function toggleService(id, active, name) {
    try {
        await api('service_save.php', { id, name, is_active: active });
        location.reload();
    } catch (err) { showAlert(err.message, 'error'); }
}

if (window.lucide) lucide.createIcons();
</script>
<?php include __DIR__ . '/partials/footer_app.php'; ?>
</body>
</html>
