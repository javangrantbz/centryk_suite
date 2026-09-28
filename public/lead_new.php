<?php
/**
 * Sales Leads — mobile-first quick-add form. Built for sales agents in the
 * field on a phone, not a laptop: single column, large tap targets, no
 * page reload between entries. Free-core hub feature (no entitlement gate) —
 * any active company member can record a lead.
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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<title>Add a Sales Lead — Centryk</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
<script src="https://unpkg.com/lucide@latest"></script>
<style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; }
    /* 16px+ inputs so iOS Safari doesn't zoom in on focus; 48px tap targets. */
    .qf-input, .qf-textarea, .qf-select {
        width: 100%; font-size: 16px; border: 1px solid #cbd5e1; border-radius: 10px;
        padding: 12px 14px; background: #fff; color: #0f172a;
    }
    .qf-input, .qf-select { height: 48px; }
    .qf-textarea { min-height: 88px; resize: vertical; line-height: 1.4; }
    .qf-input:focus, .qf-textarea:focus, .qf-select:focus {
        outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,0.15);
    }
    .qf-label { display: block; font-size: 12.5px; font-weight: 700; color: #475569; margin-bottom: 5px; }
</style>
</head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Sales Leads'; $headerMaxW = 'max-w-xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-xl px-4 py-4 pb-24">

    <div class="mb-3 flex items-end justify-between gap-2">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Sales Leads</p>
            <h1 class="mt-0.5 text-lg font-black tracking-tight text-slate-900">Add a customer</h1>
        </div>
        <?php if ($activeCompany): ?>
        <a href="leads.php?company_id=<?= $companyId ?>" class="shrink-0 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 shadow-sm">
            <?= $isManager ? 'Roster' : 'My list' ?>
        </a>
        <?php endif; ?>
    </div>

    <div id="alert" class="mb-3 hidden rounded-xl px-3 py-2.5 text-sm font-semibold"></div>

    <?php if (!$companies): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
            You need to be a member of a company to add customers.
        </div>
    <?php else: ?>

        <?php if (count($companies) > 1): ?>
        <select class="qf-select mb-3" onchange="location.href='lead_new.php?company_id=' + this.value">
            <?php foreach ($companies as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <form id="leadForm" onsubmit="submitLead(event)" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="mb-3">
                <label class="qf-label">Business name *</label>
                <input id="fBusiness" class="qf-input" type="text" placeholder="e.g. Corozal Fresh Market" autocomplete="off" required>
            </div>
            <div class="mb-3">
                <label class="qf-label">Contact name</label>
                <input id="fContact" class="qf-input" type="text" placeholder="Who you spoke with" autocomplete="off">
            </div>
            <div class="mb-3 grid grid-cols-2 gap-2">
                <div>
                    <label class="qf-label">Phone</label>
                    <input id="fPhone" class="qf-input" type="tel" inputmode="tel" placeholder="6XX-XXXX" autocomplete="off">
                </div>
                <div>
                    <label class="qf-label">Email</label>
                    <input id="fEmail" class="qf-input" type="email" inputmode="email" placeholder="name@business.com" autocomplete="off">
                </div>
            </div>
            <div class="mb-3">
                <label class="qf-label">Address</label>
                <input id="fAddress" class="qf-input" type="text" placeholder="Street, town" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="qf-label">What did they order?</label>
                <textarea id="fOrder" class="qf-textarea" placeholder="e.g. 40 lbs ground beef, 20 lbs sausage links"></textarea>
            </div>
            <div class="mb-4">
                <label class="qf-label">Order value (BZ$)</label>
                <input id="fValue" class="qf-input" type="number" inputmode="decimal" step="0.01" min="0" placeholder="Optional">
            </div>
            <button type="submit" id="submitBtn" class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 py-3.5 text-base font-black text-white shadow-sm active:scale-[0.99]">
                <i data-lucide="check" class="h-4 w-4"></i> Save customer
            </button>
        </form>

        <div class="mt-5">
            <p class="mb-1.5 text-[10px] font-black uppercase tracking-[0.1em] text-slate-400">Recently added by you</p>
            <div id="recentList" class="space-y-1.5"></div>
        </div>

    <?php endif; ?>
</div>

<script>
const COMPANY_ID = <?= $companyId ?>;

function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function showAlert(msg, kind) {
    const el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'mb-3 rounded-xl px-3 py-2.5 text-sm font-semibold ' +
        (kind === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200');
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

async function submitLead(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    try {
        await api('create.php', {
            business_name: document.getElementById('fBusiness').value.trim(),
            contact_name: document.getElementById('fContact').value.trim(),
            phone: document.getElementById('fPhone').value.trim(),
            email: document.getElementById('fEmail').value.trim(),
            address: document.getElementById('fAddress').value.trim(),
            order_details: document.getElementById('fOrder').value.trim(),
            order_value: document.getElementById('fValue').value || null,
        });
        showAlert('Customer saved. Add another below.', 'success');
        document.getElementById('leadForm').reset();
        document.getElementById('fBusiness').focus();
        loadRecent();
    } catch (err) {
        showAlert(err.message, 'error');
    } finally {
        btn.disabled = false;
    }
}

async function loadRecent() {
    const list = document.getElementById('recentList');
    if (!list) return;
    try {
        const { leads } = await api('my_recent.php', {});
        if (!leads.length) {
            list.innerHTML = '<div class="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-center text-xs text-slate-400">Nothing added yet today.</div>';
            return;
        }
        list.innerHTML = leads.map(l => `
            <div class="rounded-xl border border-slate-200 bg-white px-3 py-2.5">
                <div class="text-sm font-bold text-slate-800">${esc(l.business_name)}</div>
                <div class="text-xs text-slate-400">${esc(l.contact_name || '')}${l.contact_name && l.phone ? ' · ' : ''}${esc(l.phone || '')}</div>
            </div>
        `).join('');
    } catch (err) { /* quiet — this list is a convenience, not critical */ }
}

if (COMPANY_ID) { loadRecent(); }
if (window.lucide) lucide.createIcons();
</script>
<?php include __DIR__ . '/partials/footer_app.php'; ?>
</body>
</html>
