<?php
/**
 * Sales Leads — roster + follow-ups. Free-core hub feature (no entitlement
 * gate, no apps-registry/opt-in row) — same pattern as Case Management: any
 * active company member sees the customers they added; admin/manager see
 * the whole company roster and log follow-ups (calls, emails, reorders).
 *
 * The quick-add form lives on its own page (lead_new.php), built
 * mobile-first for sales agents entering customers on their phone in the
 * field — this page is the desktop-oriented roster/follow-up console.
 *
 * Called "Sales Leads" (not "Customers") to stay distinct from invoice-
 * maker/receivables' Customers (billing records) elsewhere in the hub.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/services/SalesLeadService.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated']) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
$user = $me['user'];

$companies = SalesLeadService::companiesFor((int)$user['id']);
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

$leads = $activeCompany ? SalesLeadService::list($companyId, (int)$user['id'], $activeCompany['role']) : [];
$stats = $activeCompany && $isManager ? SalesLeadService::stats($companyId) : ['total' => 0, 'new' => 0, 'due' => 0];

$statusChip = static function (string $s): string {
    return [
        'new'       => 'biz-c-blue',
        'contacted' => 'biz-c-amber',
        'reordered' => 'biz-c-green',
        'inactive'  => 'biz-c-slate',
    ][$s] ?? 'biz-c-slate';
};

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Sales Leads'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Sales Leads'; $headerMaxW = 'max-w-6xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="biz mx-auto max-w-6xl px-4 py-4" style="--bz-accent:#2563eb;--bz-accent-d:#1d4ed8">

    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="biz-kicker">Sales Leads</p>
            <h1 class="mt-0.5"><?= $isManager ? 'Roster and follow-ups' : 'Customers you\'ve added' ?></h1>
        </div>
        <div class="flex items-center gap-2">
            <?php if (count($companies) > 1): ?>
            <select class="biz-select" style="width:auto" onchange="location.href='leads.php?company_id=' + this.value">
                <?php foreach ($companies as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($activeCompany): ?>
            <a href="lead_new.php?company_id=<?= $companyId ?>" class="biz-btn biz-btn-primary biz-btn-sm">+ Add customer</a>
            <?php endif; ?>
        </div>
    </div>

    <div id="alert" class="biz-notice mb-3 hidden"></div>

    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">You need to be a member of a company to use Sales Leads.</div>
    <?php else: ?>

        <?php if ($isManager): ?>
        <div class="mb-3 grid grid-cols-3 gap-2">
            <div class="biz-panel" style="padding:10px 12px">
                <div class="biz-kicker">Total</div>
                <div style="font-size:20px;font-weight:800"><?= (int)$stats['total'] ?></div>
            </div>
            <div class="biz-panel" style="padding:10px 12px">
                <div class="biz-kicker">Not yet contacted</div>
                <div style="font-size:20px;font-weight:800"><?= (int)$stats['new'] ?></div>
            </div>
            <div class="biz-panel" style="padding:10px 12px">
                <div class="biz-kicker">Follow-up due</div>
                <div style="font-size:20px;font-weight:800;<?= $stats['due'] > 0 ? 'color:#b91c1c' : '' ?>"><?= (int)$stats['due'] ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <input id="fQ" class="biz-input" style="width:200px" type="text" placeholder="Search business or contact" oninput="debouncedReload()">
            <select id="fStatus" class="biz-select" style="width:auto" onchange="reloadLeads()">
                <option value="">Any status</option>
                <?php foreach (SalesLeadService::STATUSES as $s): ?>
                <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="flex items-center gap-1 text-xs font-semibold" style="color:var(--bz-muted)">
                <input type="checkbox" id="fDue" onchange="reloadLeads()"> Follow-up due only
            </label>
        </div>

        <div class="grid gap-3" style="grid-template-columns: 1fr; <?= $isManager ? 'grid-template-columns: 1.3fr 1fr;' : '' ?>">
            <div class="biz-panel">
                <div class="biz-panel-head"><span id="leadCount"><?= count($leads) ?> customer<?= count($leads) === 1 ? '' : 's' ?></span></div>
                <div id="leadList" class="biz-list">
                    <?php include __DIR__ . '/partials/lead_rows.php'; ?>
                </div>
            </div>

            <?php if ($isManager): ?>
            <div id="detailPanel" class="biz-panel hidden" style="align-self:start">
                <div class="biz-panel-head">
                    <span id="detailTitle">Customer</span>
                    <button class="biz-btn biz-btn-ghost biz-btn-sm" onclick="closeDetail()">Close</button>
                </div>
                <div class="biz-panel-body" id="detailBody"></div>
            </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<script>
const COMPANY_ID = <?= $companyId ?>;
const IS_MANAGER = <?= $isManager ? 'true' : 'false' ?>;
const STATUS_CHIP = { new: 'biz-c-blue', contacted: 'biz-c-amber', reordered: 'biz-c-green', inactive: 'biz-c-slate' };
const TODAY = <?= json_encode(date('Y-m-d')) ?>;

function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function showAlert(msg, kind) {
    const el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'biz-notice mb-3' + (kind === 'error' ? ' biz-notice-red' : ' biz-notice-green');
    el.classList.remove('hidden');
    setTimeout(() => el.classList.add('hidden'), 4000);
}

async function api(path, body) {
    const res = await fetch('api/sales-leads/' + path, {
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

let _debounce;
function debouncedReload() {
    clearTimeout(_debounce);
    _debounce = setTimeout(reloadLeads, 300);
}

async function reloadLeads() {
    try {
        const { leads } = await api('list.php', {
            q: document.getElementById('fQ').value.trim(),
            status: document.getElementById('fStatus').value,
            due: document.getElementById('fDue').checked,
        });
        renderLeads(leads);
    } catch (err) { showAlert(err.message, 'error'); }
}

function renderLeads(leads) {
    const list = document.getElementById('leadList');
    document.getElementById('leadCount').textContent = leads.length + ' customer' + (leads.length === 1 ? '' : 's');
    if (!leads.length) {
        list.innerHTML = '<div class="biz-panel-empty" style="padding:24px">No customers match this filter.</div>';
        return;
    }
    list.innerHTML = leads.map(l => {
        const due = l.next_follow_up_date && l.next_follow_up_date <= TODAY && l.status !== 'inactive';
        const agent = (l.agent_first || l.agent_last) ? `${l.agent_first || ''} ${l.agent_last || ''}`.trim() : '';
        return `
        <button class="biz-row" style="align-items:flex-start;width:100%;text-align:left" onclick="openDetail(${l.id})">
            <div class="min-w-0 flex-1">
                <span class="block font-bold" style="color:var(--bz-fg)">${esc(l.business_name)}</span>
                <div class="biz-muted mt-0.5" style="font-size:11px">
                    <span class="biz-chip ${STATUS_CHIP[l.status] || 'biz-c-slate'}">${esc(l.status)}</span>
                    ${due ? '<span class="biz-chip biz-c-red">follow up due</span>' : ''}
                    ${l.contact_name ? '· ' + esc(l.contact_name) : ''}
                    ${l.phone ? '· ' + esc(l.phone) : ''}
                    ${IS_MANAGER && agent ? '· added by ' + esc(agent) : ''}
                </div>
                ${l.order_details ? `<div class="biz-muted mt-0.5" style="font-size:11px">${esc(l.order_details)}${l.order_value ? ' · BZ$' + Number(l.order_value).toFixed(2) : ''}</div>` : ''}
            </div>
        </button>`;
    }).join('');
}

async function openDetail(id) {
    if (!IS_MANAGER) return;
    const panel = document.getElementById('detailPanel');
    const body = document.getElementById('detailBody');
    panel.classList.remove('hidden');
    body.innerHTML = '<div class="biz-muted" style="padding:8px 0">Loading…</div>';
    try {
        const { lead, follow_ups } = await api('detail.php', { id });
        document.getElementById('detailTitle').textContent = lead.business_name;
        renderDetail(lead, follow_ups);
    } catch (err) { showAlert(err.message, 'error'); }
}

function closeDetail() {
    document.getElementById('detailPanel').classList.add('hidden');
}

function renderDetail(l, followUps) {
    const body = document.getElementById('detailBody');
    const agent = `${l.agent_first || ''} ${l.agent_last || ''}`.trim();
    const timeline = followUps.map(f => `
        <div style="padding:6px 0;border-top:1px solid var(--bz-line-soft)">
            <div style="font-size:11px" class="biz-muted">
                ${esc(f.first_name)} ${esc(f.last_name)} · ${esc(f.created_at)}
                ${f.to_status ? ` · ${esc(f.from_status || '?')} → ${esc(f.to_status)}` : ''}
            </div>
            ${f.note ? `<div style="font-size:12.5px;margin-top:2px">${esc(f.note)}</div>` : ''}
        </div>
    `).join('') || '<div class="biz-muted" style="font-size:12px;padding:6px 0">No follow-ups logged yet.</div>';

    body.innerHTML = `
        <div style="font-size:12.5px;line-height:1.6">
            ${l.contact_name ? `<div><strong>Contact:</strong> ${esc(l.contact_name)}</div>` : ''}
            ${l.phone ? `<div><strong>Phone:</strong> ${esc(l.phone)}</div>` : ''}
            ${l.email ? `<div><strong>Email:</strong> ${esc(l.email)}</div>` : ''}
            ${l.address ? `<div><strong>Address:</strong> ${esc(l.address)}</div>` : ''}
            ${l.order_details ? `<div><strong>Order:</strong> ${esc(l.order_details)}${l.order_value ? ' (BZ$' + Number(l.order_value).toFixed(2) + ')' : ''}</div>` : ''}
            <div class="biz-muted" style="margin-top:4px">Added by ${esc(agent)} on ${esc(l.created_at)}</div>
        </div>

        <form onsubmit="submitFollowUp(event, ${l.id})" class="mt-3 grid gap-2" style="border-top:1px solid var(--bz-line);padding-top:10px">
            <div class="biz-label">Log a follow-up</div>
            <textarea id="fuNote" class="biz-input" placeholder="Called — reordering next week, etc."></textarea>
            <div class="grid gap-2" style="grid-template-columns:1fr 1fr">
                <select id="fuStatus" class="biz-select">
                    <option value="">Keep status: ${esc(l.status)}</option>
                    ${['new','contacted','reordered','inactive'].filter(s => s !== l.status).map(s => `<option value="${s}">Set: ${s}</option>`).join('')}
                </select>
                <input id="fuNext" class="biz-input" type="date" value="${l.next_follow_up_date || ''}" title="Next follow-up date">
            </div>
            <button type="submit" class="biz-btn biz-btn-primary biz-btn-sm">Save follow-up</button>
        </form>

        <div class="mt-3">
            <div class="biz-label">Timeline</div>
            ${timeline}
        </div>
    `;
}

async function submitFollowUp(e, id) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    try {
        await api('follow_up.php', {
            id,
            note: document.getElementById('fuNote').value.trim(),
            status: document.getElementById('fuStatus').value,
            next_follow_up_date: document.getElementById('fuNext').value || null,
        });
        showAlert('Follow-up saved.', 'success');
        openDetail(id);
        reloadLeads();
    } catch (err) {
        showAlert(err.message, 'error');
    } finally {
        btn.disabled = false;
    }
}

if (window.lucide) lucide.createIcons();
</script>
<?php include __DIR__ . '/partials/footer_app.php'; ?>
</body>
</html>
