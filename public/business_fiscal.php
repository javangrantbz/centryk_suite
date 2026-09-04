<?php
/**
 * Belize BTS Electronic Invoicing — admin page.
 * Gated: admin/manager of any active company (edit rights restricted to a
 * company admin - see $isCompanyAdmin). Deliberately NOT behind Centryk
 * Business/the 'receivables' package — invoice-maker and OnePay POS are
 * free, and BTS compliance is a legal requirement for any business that
 * issues invoices/receipts through them, not a premium AR feature.
 *
 * Registration-info form, certificate upload, a document log, and the
 * actual "Submit to BTS" / "Cancel via BTS" actions (FiscalInvoicingService::
 * submitToBts()/issueCancellation() - map -> sign -> transmit). UNTESTED
 * against BTS's live test environment: no company has uploaded a real
 * BTS-issued certificate yet, so the "Submit to BTS" button has never
 * actually reached BTS's server for any company. Treat the first real
 * submission as the live integration test.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated']) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
$user = $me['user'];
$pdo  = DB::pdo();

$coStmt = $pdo->prepare("
    SELECT c.id, c.name
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.user_id = :uid AND cm.status = 'active' AND cm.role IN ('admin','manager') AND c.status = 'active'
    ORDER BY c.name ASC
");
$coStmt->execute(['uid' => (int)$user['id']]);
$companies = $coStmt->fetchAll(PDO::FETCH_ASSOC);

$activeCompany = null;
if ($companies) {
    $reqCid = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    foreach ($companies as $c) {
        if ((int)$c['id'] === $reqCid) { $activeCompany = $c; break; }
    }
    if (!$activeCompany) { $activeCompany = $companies[0]; }
}
$isCompanyAdmin = false;
if ($activeCompany) {
    $roleStmt = $pdo->prepare("SELECT role FROM company_members WHERE user_id = :u AND company_id = :c AND status = 'active' LIMIT 1");
    $roleStmt->execute(['u' => (int)$user['id'], 'c' => (int)$activeCompany['id']]);
    $isCompanyAdmin = $roleStmt->fetchColumn() === 'admin';
}

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'E-Invoicing (BTS)'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'E-Invoicing (BTS)'; $headerMaxW = 'max-w-4xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="biz mx-auto max-w-4xl px-4 py-4">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Belize Tax Service · Compliance</p>
            <h1 class="mt-0.5">E-Invoicing (BTS)</h1>
        </div>
        <?php if (count($companies) > 1): ?>
        <div class="biz-seg">
            <?php foreach ($companies as $c): ?>
                <a href="business_fiscal.php?company_id=<?= (int)$c['id'] ?>"
                   class="<?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'is-active' : '' ?>"><?= htmlspecialchars($c['name']) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">You're not an admin or manager of any active company yet.</div>
    <?php else: ?>
        <div id="alert" class="biz-notice mb-3 hidden"></div>

        <div class="biz-panel mb-4" style="border-color:#fde68a;background:#fffbeb">
            <div class="biz-panel-body" style="font-size:12px;color:#78350f">
                <strong>New: this feature hasn't been tried with a real BTS certificate yet.</strong> Belize Tax
                Service now requires every invoice and receipt to be sent to them electronically and approved in
                real time before it's legally valid. To use it, get your own certificate from BTS's EFDR Portal,
                upload it below, and leave the environment set to "test" for your first few submissions. A rejected
                test submission has no effect on your business - it just means something needs adjusting before you
                switch to production.
            </div>
        </div>

        <!-- ── Fiscal profile / BTS registration ─────────────────────────── -->
        <section class="biz-panel mb-4">
            <div class="biz-panel-head">
                <span>Fiscal profile</span>
                <span id="profileStatusBadge" class="biz-muted" style="font-size:11px"></span>
            </div>
            <form id="profileForm" class="biz-panel-body" style="display:grid;gap:12px">
                <p class="biz-muted" style="font-size:11px;margin:-4px 0 4px">
                    This is the information BTS asks for to set up your test account. Name, TIN and address are
                    pre-filled from your invoice settings the first time you visit - change them here if this profile
                    needs to differ.
                </p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="biz-label">Company / organization name</span>
                        <input type="text" id="f_legal_name" class="biz-input" maxlength="180"></label>
                    <label class="block"><span class="biz-label">TIN</span>
                        <input type="text" id="f_tin" class="biz-input" maxlength="40"></label>
                </div>
                <label class="block"><span class="biz-label">Address</span>
                    <textarea id="f_address" class="biz-input" rows="2" maxlength="2000"></textarea></label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="biz-label">Economic activity code <span class="biz-muted" style="font-weight:400">(optional)</span></span>
                        <input type="text" id="f_economic_activity_code" class="biz-input" maxlength="40"></label>
                    <label class="block"><span class="biz-label">Establishment / branch code <span class="biz-muted" style="font-weight:400">(optional)</span></span>
                        <input type="text" id="f_establishment_code" class="biz-input" maxlength="40"></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block"><span class="biz-label">Contact person</span>
                        <input type="text" id="f_contact_name" class="biz-input" maxlength="150"></label>
                    <label class="block"><span class="biz-label">Contact position</span>
                        <input type="text" id="f_contact_position" class="biz-input" maxlength="100"></label>
                    <label class="block"><span class="biz-label">Contact email</span>
                        <input type="email" id="f_contact_email" class="biz-input" maxlength="150"></label>
                    <label class="block"><span class="biz-label">Contact phone</span>
                        <input type="text" id="f_contact_phone" class="biz-input" maxlength="50"></label>
                    <label class="block"><span class="biz-label">Technical / development contact name</span>
                        <input type="text" id="f_tech_contact_name" class="biz-input" maxlength="150"></label>
                    <label class="block"><span class="biz-label">Technical / development contact email</span>
                        <input type="email" id="f_tech_contact_email" class="biz-input" maxlength="150"></label>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block"><span class="biz-label">Environment</span>
                        <select id="f_environment" class="biz-select">
                            <option value="test">Test (sandbox)</option>
                            <option value="production">Production</option>
                        </select></label>
                    <label class="block"><span class="biz-label">Status</span>
                        <select id="f_status" class="biz-select">
                            <option value="not_started">Not started</option>
                            <option value="info_sent">Registration info sent to BTS</option>
                            <option value="sandbox_access">Sandbox access received</option>
                            <option value="live">Live (authorized issuer)</option>
                            <option value="suspended">Suspended</option>
                        </select></label>
                    <label class="mt-5 flex items-center gap-2 text-sm">
                        <input type="checkbox" id="f_enabled"> Enable for this company
                    </label>
                </div>
                <label class="block"><span class="biz-label">Notes <span class="biz-muted" style="font-weight:400">(optional)</span></span>
                    <textarea id="f_notes" class="biz-input" rows="2" maxlength="4000"></textarea></label>
                <div>
                    <button type="submit" class="biz-btn biz-btn-primary" <?= $isCompanyAdmin ? '' : 'disabled title="Only a company admin can edit this."' ?>>Save profile</button>
                </div>
            </form>
        </section>

        <!-- ── Certificate ────────────────────────────────────────────────── -->
        <section class="biz-panel mb-4">
            <div class="biz-panel-head"><span>Certificate</span></div>
            <div class="biz-panel-body" style="display:grid;gap:8px">
                <p class="biz-muted" style="font-size:11px">
                    Generate and download your certificate yourself from BTS's <strong>EFDR Portal</strong> (PFX/P12,
                    password = this company's TIN) - Centryk doesn't request or generate this on your behalf. Upload
                    the downloaded file here; it's used both to sign documents and to connect to BTS.
                </p>
                <div id="certStatus" class="text-sm font-semibold"></div>
                <?php if ($isCompanyAdmin): ?>
                <form id="certForm" class="flex flex-wrap items-center gap-2">
                    <input type="file" id="f_certificate" accept=".pfx,.p12" class="biz-input" style="width:auto">
                    <button type="submit" class="biz-btn biz-btn-primary">Upload certificate</button>
                </form>
                <?php endif; ?>
            </div>
        </section>

        <!-- ── Build a fiscal document from an existing invoice ──────────── -->
        <section class="biz-panel mb-4">
            <div class="biz-panel-head"><span>Build from an invoice</span></div>
            <div class="biz-panel-body flex flex-wrap items-end gap-3">
                <label class="block">
                    <span class="biz-label">Invoice</span>
                    <select id="invoicePicker" class="biz-select" style="min-width:260px"></select>
                </label>
                <button type="button" id="btnBuildFromInvoice" class="biz-btn biz-btn-primary">Build fiscal document</button>
                <span class="biz-muted" style="font-size:11px">Only issued (not draft/cancelled) invoices without one already.</span>
            </div>
        </section>

        <!-- ── Document log ───────────────────────────────────────────────── -->
        <section class="biz-panel">
            <div class="biz-panel-head"><span>Fiscal documents</span></div>
            <div id="documentLog" class="biz-panel-body" style="padding:0"></div>
        </section>
    <?php endif; ?>
</div>

<script>
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
const CAN_EDIT = <?= $isCompanyAdmin ? 'true' : 'false' ?>;

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function bzd(v){ return 'BZD ' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function showAlert(msg, ok){ const el = document.getElementById('alert'); el.textContent = msg; el.className = 'biz-notice mb-3 ' + (ok ? 'biz-notice-green' : 'biz-notice-red'); el.classList.remove('hidden'); setTimeout(() => el.classList.add('hidden'), 5000); }

const STATUS_TONE = {
    draft: '', built: 'biz-t-blue', signed: 'biz-t-blue', submitted: 'biz-t-amber',
    authorized: 'biz-t-green', rejected: 'biz-t-red', cancelled: '', error: 'biz-t-red',
};
const STATUS_LABEL = {
    draft: 'Draft', built: 'Built', signed: 'Signed', submitted: 'Submitted',
    authorized: 'Authorized', rejected: 'Rejected', cancelled: 'Cancelled', error: 'Error',
};

async function loadProfile(){
    if (CID === null) return;
    const res = await fetch(`api/fiscal/profile_get.php?company_id=${CID}`);
    const d = await res.json();
    if (!d.success) { showAlert(d.message || 'Could not load the fiscal profile.'); return; }
    const p = d.profile || {};
    document.getElementById('f_legal_name').value = p.legal_name || '';
    document.getElementById('f_tin').value = p.tin || '';
    document.getElementById('f_address').value = p.address || '';
    document.getElementById('f_economic_activity_code').value = p.economic_activity_code || '';
    document.getElementById('f_establishment_code').value = p.establishment_code || '';
    document.getElementById('f_contact_name').value = p.contact_name || '';
    document.getElementById('f_contact_position').value = p.contact_position || '';
    document.getElementById('f_contact_email').value = p.contact_email || '';
    document.getElementById('f_contact_phone').value = p.contact_phone || '';
    document.getElementById('f_tech_contact_name').value = p.tech_contact_name || '';
    document.getElementById('f_tech_contact_email').value = p.tech_contact_email || '';
    document.getElementById('f_environment').value = p.environment || 'test';
    document.getElementById('f_status').value = p.status || 'not_started';
    document.getElementById('f_enabled').checked = !!Number(p.enabled || 0);
    document.getElementById('f_notes').value = p.notes || '';
    document.getElementById('profileStatusBadge').textContent = p.status ? (STATUS_LABEL[p.status] || p.status) : 'Not started';

    const certBox = document.getElementById('certStatus');
    if (p.has_certificate) {
        const expiry = p.certificate_expires_on ? ` · expires ${esc(p.certificate_expires_on)}` : '';
        certBox.innerHTML = `<span class="biz-t-green">Certificate on file</span><span class="biz-muted" style="font-weight:400">${expiry}</span>`;
    } else {
        certBox.innerHTML = '<span class="biz-t-red">No certificate uploaded yet</span>';
    }
}

document.getElementById('certForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const file = document.getElementById('f_certificate').files[0];
    if (!file) { showAlert('Choose a .pfx/.p12 file first.'); return; }
    const form = new FormData();
    form.append('company_id', CID);
    form.append('certificate', file);
    const res = await fetch('api/fiscal/certificate_upload.php', { method: 'POST', body: form });
    const d = await res.json();
    if (!d.success) { showAlert(d.message || 'Could not upload the certificate.'); return; }
    showAlert('Certificate uploaded.', true);
    document.getElementById('f_certificate').value = '';
    loadProfile();
});

document.getElementById('profileForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!CAN_EDIT) { showAlert('Only a company admin can edit this.'); return; }
    const body = {
        company_id: CID,
        legal_name: document.getElementById('f_legal_name').value,
        tin: document.getElementById('f_tin').value,
        address: document.getElementById('f_address').value,
        economic_activity_code: document.getElementById('f_economic_activity_code').value,
        establishment_code: document.getElementById('f_establishment_code').value,
        contact_name: document.getElementById('f_contact_name').value,
        contact_position: document.getElementById('f_contact_position').value,
        contact_email: document.getElementById('f_contact_email').value,
        contact_phone: document.getElementById('f_contact_phone').value,
        tech_contact_name: document.getElementById('f_tech_contact_name').value,
        tech_contact_email: document.getElementById('f_tech_contact_email').value,
        environment: document.getElementById('f_environment').value,
        status: document.getElementById('f_status').value,
        enabled: document.getElementById('f_enabled').checked ? 1 : 0,
        notes: document.getElementById('f_notes').value,
    };
    const res = await fetch('api/fiscal/profile_save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const d = await res.json();
    if (!d.success) { showAlert(d.message || 'Could not save.'); return; }
    showAlert('Fiscal profile saved.', true);
    loadProfile();
});

async function loadInvoicePicker(){
    if (CID === null) return;
    const sel = document.getElementById('invoicePicker');
    const res = await fetch(`api/fiscal/eligible_invoices.php?company_id=${CID}`).catch(() => null);
    if (!res || !res.ok) { sel.innerHTML = '<option value="">(couldn\'t load invoices)</option>'; return; }
    const d = await res.json().catch(() => null);
    const invoices = (d && d.invoices) || [];
    sel.innerHTML = invoices.length
        ? invoices.map(i => `<option value="${i.id}">${esc(i.invoice_number || ('#' + i.id))} - ${bzd(i.total)}</option>`).join('')
        : '<option value="">No eligible invoices</option>';
}

document.getElementById('btnBuildFromInvoice')?.addEventListener('click', async () => {
    const invoiceId = document.getElementById('invoicePicker').value;
    if (!invoiceId) { showAlert('Pick an invoice first.'); return; }
    const res = await fetch('api/fiscal/issue_from_invoice.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ company_id: CID, invoice_id: Number(invoiceId) }),
    });
    const d = await res.json();
    if (!d.success) { showAlert(d.message || 'Could not build a fiscal document.'); return; }
    showAlert('Fiscal document built (status: Built - not submitted to BTS).', true);
    loadDocuments();
});

async function loadDocuments(){
    if (CID === null) return;
    const res = await fetch(`api/fiscal/documents_list.php?company_id=${CID}`);
    const d = await res.json();
    const box = document.getElementById('documentLog');
    if (!d.success) { box.innerHTML = `<div class="biz-panel-body">${esc(d.message || 'Could not load documents.')}</div>`; return; }
    const docs = d.documents || [];
    if (!docs.length) {
        box.innerHTML = '<div class="biz-panel-body biz-muted" style="font-size:12px">No fiscal documents yet.</div>';
        return;
    }
    box.innerHTML = docs.map(doc => {
        const canSubmit = ['built', 'error', 'rejected'].includes(doc.status);
        const canCancel = doc.status === 'authorized' && doc.document_type !== 'cancellation';
        return `
        <div style="padding:10px 16px;border-bottom:1px solid var(--bz-line)">
            <div class="biz-row" style="padding:0">
                <span class="min-w-0 flex-1">
                    <span style="font-weight:700">${esc(doc.our_number || ('Doc #' + doc.id))}</span>
                    <span class="biz-muted" style="font-size:11px"> · ${esc(doc.document_type)} · ${esc((doc.created_at || '').slice(0, 10))}</span>
                    ${doc.source_app ? `<span class="biz-muted" style="font-size:11px"> · from ${esc(doc.source_app)}</span>` : ''}
                </span>
                <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase ${STATUS_TONE[doc.status] || ''}" style="border:1px solid currentColor">${STATUS_LABEL[doc.status] || doc.status}</span>
                <span class="shrink-0 biz-num" style="min-width:90px;text-align:right">${bzd(doc.total)}</span>
            </div>
            ${doc.etdui ? `<div class="biz-muted mt-1" style="font-size:10px;font-family:monospace">ETDUI ${esc(doc.etdui)}</div>` : ''}
            ${doc.error_message ? `<div class="mt-1" style="font-size:11px;color:#b91c1c">${esc(doc.error_message)}</div>` : ''}
            ${canSubmit || canCancel ? `
            <div class="mt-1.5 flex gap-2">
                ${canSubmit && CAN_EDIT ? `<button type="button" class="biz-btn biz-btn-primary biz-btn-sm" onclick="submitDoc(${doc.id})">Submit to BTS</button>` : ''}
                ${canCancel && CAN_EDIT ? `<button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="cancelDoc(${doc.id})">Cancel via BTS</button>` : ''}
            </div>` : ''}
        </div>
    `; }).join('');
}

async function submitDoc(id){
    if (!confirm('Submit this document to BTS now? In the test environment this has no legal effect, but it does consume the next serial number if authorized.')) return;
    const res = await fetch('api/fiscal/submit.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ company_id: CID, id }),
    });
    const d = await res.json();
    if (!d.success) { showAlert(d.message || 'Could not submit that document.'); loadDocuments(); return; }
    const status = d.document?.status;
    showAlert(status === 'authorized' ? `Authorized - ETDUI ${d.document.etdui}` : `Result: ${STATUS_LABEL[status] || status}`, status === 'authorized');
    loadDocuments();
}

async function cancelDoc(id){
    const reason = prompt('Reason for cancelling this document with BTS (optional):') || '';
    const prep = await fetch('api/fiscal/cancel_via_bts.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ company_id: CID, id, reason }),
    });
    const prepData = await prep.json();
    if (!prepData.success) { showAlert(prepData.message || 'Could not prepare the cancellation.'); return; }
    showAlert('Cancellation prepared - submitting to BTS…', true);
    await submitDoc(prepData.document.id);
}

if (CID !== null) {
    loadProfile();
    loadInvoicePicker();
    loadDocuments();
}
</script>
</body>
</html>
