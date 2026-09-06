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
        <div id="contingencyBar" class="biz-notice mb-3" style="display:none;border-color:#fde68a;background:#fffbeb;color:#78350f">
            <div class="flex items-center justify-between gap-3" style="font-size:12px">
                <span><strong><span id="contingencyCount">0</span> contingency document(s)</strong> signed but not yet sent to BTS. Transmit them once BTS is reachable again.</span>
                <button type="button" class="biz-btn biz-btn-primary biz-btn-sm" onclick="transmitContingency()">Transmit backlog</button>
            </div>
        </div>
        <section class="biz-panel">
            <div class="biz-panel-head"><span>Fiscal documents</span></div>
            <div id="documentLog" class="biz-panel-body" style="padding:0"></div>
        </section>
    <?php endif; ?>
</div>

<!-- ── Credit note dialog ─────────────────────────────────────────────── -->
<div id="cnOverlay" class="hidden" style="position:fixed;inset:0;z-index:60;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:16px">
    <div class="biz" style="background:#fff;border-radius:12px;max-width:560px;width:100%;max-height:88vh;overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,.3)">
        <div class="biz-panel-head" style="border-radius:12px 12px 0 0">
            <span>Credit note</span>
            <button type="button" onclick="closeCreditNote()" class="biz-muted" style="border:0;background:none;font-size:18px;cursor:pointer;line-height:1">&times;</button>
        </div>
        <div class="biz-panel-body" style="display:grid;gap:12px">
            <p class="biz-muted" style="font-size:11px;margin:0">
                Crediting <strong id="cnOriginalNumber"></strong>. Set how much of each line to credit
                (defaults to the full quantity). The credit note is created as "Built" - review it in the
                log, then Submit to BTS.
            </p>
            <div id="cnLines" style="display:grid;gap:6px"></div>
            <label class="block"><span class="biz-label">Reason <span class="biz-muted" style="font-weight:400">(kept on the document, not sent to BTS)</span></span>
                <textarea id="cnReason" class="biz-input" rows="2" maxlength="500" placeholder="e.g. Goods returned - damaged in transit"></textarea></label>
            <div class="flex justify-between items-center">
                <span class="biz-num" style="font-weight:700">Credit total: <span id="cnTotal">BZD 0.00</span></span>
                <div class="flex gap-2">
                    <button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="closeCreditNote()">Cancel</button>
                    <button type="button" id="cnCreateBtn" class="biz-btn biz-btn-primary biz-btn-sm" onclick="createCreditNote()">Create credit note</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Debit note dialog ──────────────────────────────────────────────── -->
<div id="dnOverlay" class="hidden" style="position:fixed;inset:0;z-index:60;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;padding:16px">
    <div class="biz" style="background:#fff;border-radius:12px;max-width:620px;width:100%;max-height:88vh;overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,.3)">
        <div class="biz-panel-head" style="border-radius:12px 12px 0 0">
            <span>Debit note</span>
            <button type="button" onclick="closeDebitNote()" class="biz-muted" style="border:0;background:none;font-size:18px;cursor:pointer;line-height:1">&times;</button>
        </div>
        <div class="biz-panel-body" style="display:grid;gap:12px">
            <p class="biz-muted" style="font-size:11px;margin:0">
                Adding charges to <strong id="dnOriginalNumber"></strong> - a freight charge billed later, an
                undercharge correction, a late fee. These are new lines, not the original's. Created as "Built" -
                review it in the log, then Submit to BTS.
            </p>
            <div id="dnLines" style="display:grid;gap:6px"></div>
            <button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="dnAddLine()" style="justify-self:start">+ Add line</button>
            <label class="block"><span class="biz-label">Reason <span class="biz-muted" style="font-weight:400">(kept on the document, not sent to BTS)</span></span>
                <textarea id="dnReason" class="biz-input" rows="2" maxlength="500" placeholder="e.g. Freight not billed on the original invoice"></textarea></label>
            <div class="flex justify-between items-center">
                <span class="biz-num" style="font-weight:700">Debit total: <span id="dnTotal">BZD 0.00</span></span>
                <div class="flex gap-2">
                    <button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="closeDebitNote()">Cancel</button>
                    <button type="button" id="dnCreateBtn" class="biz-btn biz-btn-primary biz-btn-sm" onclick="createDebitNote()">Create debit note</button>
                </div>
            </div>
        </div>
    </div>
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
    const contingencyPending = docs.filter(d => Number(d.oper_mode) === 2 && d.status === 'signed').length;
    document.getElementById('contingencyBar').style.display = contingencyPending ? '' : 'none';
    document.getElementById('contingencyCount').textContent = contingencyPending;

    box.innerHTML = docs.map(doc => {
        const isContingency = Number(doc.oper_mode) === 2;
        const btsUnreachable = doc.status === 'error' && /connection failed|unreachable|timed out|could not resolve/i.test(doc.error_message || '');
        const canSubmit = ['built', 'rejected'].includes(doc.status) || (doc.status === 'error' && !btsUnreachable);
        const canContingency = ['built', 'error', 'rejected'].includes(doc.status) && doc.document_type !== 'cancellation' && !doc.superseded_by_document_id && !isContingency;
        const canCancel = doc.status === 'authorized' && doc.document_type !== 'cancellation';
        const canCredit = doc.status === 'authorized' && ['invoice', 'tax_receipt', 'debit_note'].includes(doc.document_type);
        const canDebit = doc.status === 'authorized' && ['invoice', 'tax_receipt'].includes(doc.document_type);
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
            ${isContingency ? `<div class="biz-muted mt-1" style="font-size:10px"><span class="biz-t-amber" style="font-weight:700">CONTINGENCY</span>${doc.contingency_reason ? ' · ' + esc(doc.contingency_reason) : ''}</div>` : ''}
            ${doc.superseded_by_document_id ? `<div class="biz-muted mt-1" style="font-size:10px">Superseded by contingency doc #${esc(doc.superseded_by_document_id)}</div>` : ''}
            ${doc.etdui ? `<div class="biz-muted mt-1" style="font-size:10px;font-family:monospace">ETDUI ${esc(doc.etdui)}</div>` : ''}
            ${doc.error_message ? `<div class="mt-1" style="font-size:11px;color:#b91c1c">${esc(doc.error_message)}</div>` : ''}
            ${(canSubmit || canContingency || canCancel || canCredit || canDebit) && CAN_EDIT ? `
            <div class="mt-1.5 flex gap-2 flex-wrap">
                ${canSubmit ? `<button type="button" class="biz-btn biz-btn-primary biz-btn-sm" onclick="submitDoc(${doc.id})">Submit to BTS</button>` : ''}
                ${canContingency ? `<button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="issueContingency(${doc.id})">Issue in contingency</button>` : ''}
                ${canCredit ? `<button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="openCreditNote(${doc.id})">Credit note</button>` : ''}
                ${canDebit ? `<button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="openDebitNote(${doc.id})">Debit note</button>` : ''}
                ${canCancel ? `<button type="button" class="biz-btn biz-btn-ghost biz-btn-sm" onclick="cancelDoc(${doc.id})">Cancel via BTS</button>` : ''}
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

// ── Contingency mode ─────────────────────────────────────────────────────
async function issueContingency(id){
    const reason = prompt('Why are you switching to contingency mode? (e.g. "No internet at the branch")');
    if (reason === null) return;
    const res = await fetch('api/fiscal/contingency_issue.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ company_id: CID, id, reason }),
    });
    const d = await res.json();
    if (!d.success) { showAlert(d.message || 'Could not issue the contingency document.'); return; }
    showAlert('Contingency document signed - the sale can proceed. Transmit it once BTS is back.', true);
    loadDocuments();
}

async function transmitContingency(){
    if (!confirm('Transmit all signed contingency documents to BTS now?')) return;
    const res = await fetch('api/fiscal/contingency_transmit.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ company_id: CID }),
    });
    const d = await res.json();
    if (!d.success) { showAlert(d.message || 'Could not transmit the backlog.'); return; }
    const s = d.summary || {};
    showAlert(`Backlog: ${s.authorized || 0} authorized, ${s.rejected || 0} rejected, ${s.still_failing || 0} still unreachable.`, (s.rejected || 0) === 0 && (s.still_failing || 0) === 0);
    loadDocuments();
}

// ── Credit note ──────────────────────────────────────────────────────────
let cnOriginalId = null;
let cnOriginalLines = [];

async function openCreditNote(id){
    const res = await fetch(`api/fiscal/document_show.php?company_id=${CID}&id=${id}`);
    const d = await res.json();
    if (!d.success) { showAlert(d.message || 'Could not load that document.'); return; }
    cnOriginalId = id;
    cnOriginalLines = d.document.lines || [];
    document.getElementById('cnOriginalNumber').textContent = d.document.our_number || ('Doc #' + id);
    document.getElementById('cnReason').value = '';
    document.getElementById('cnLines').innerHTML = cnOriginalLines.map((l, i) => `
        <div class="flex items-center gap-2" style="font-size:12px">
            <span class="min-w-0 flex-1 truncate">${esc(l.description)}
                <span class="biz-muted">· ${bzd(l.unit_price)} × ${Number(l.quantity)}${Number(l.tax_rate) > 0 ? ' · ' + Number(l.tax_rate) + '% GST' : ''}</span>
            </span>
            <input type="number" class="biz-input cnQty" data-line="${l.line_number}" data-i="${i}"
                   min="0" max="${Number(l.quantity)}" step="0.01" value="${Number(l.quantity)}"
                   style="width:90px;text-align:right" oninput="cnRecalc()">
        </div>`).join('');
    cnRecalc();
    document.getElementById('cnOverlay').classList.remove('hidden');
}

function closeCreditNote(){ document.getElementById('cnOverlay').classList.add('hidden'); cnOriginalId = null; }

function cnRecalc(){
    let total = 0;
    document.querySelectorAll('#cnLines .cnQty').forEach(inp => {
        const l = cnOriginalLines[Number(inp.dataset.i)];
        let q = Math.max(0, Math.min(Number(inp.value) || 0, Number(l.quantity)));
        const sub = q * Number(l.unit_price);
        total += sub + sub * (Number(l.tax_rate) || 0) / 100;
    });
    document.getElementById('cnTotal').textContent = bzd(total);
}

async function createCreditNote(){
    const lines = [];
    document.querySelectorAll('#cnLines .cnQty').forEach(inp => {
        const q = Number(inp.value) || 0;
        if (q > 0) lines.push({ line_number: Number(inp.dataset.line), quantity: q });
    });
    if (!lines.length) { showAlert('Set a quantity on at least one line.'); return; }
    const btn = document.getElementById('cnCreateBtn');
    btn.disabled = true;
    const res = await fetch('api/fiscal/credit_note.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ company_id: CID, id: cnOriginalId, reason: document.getElementById('cnReason').value, lines }),
    });
    const d = await res.json();
    btn.disabled = false;
    if (!d.success) { showAlert(d.message || 'Could not create the credit note.'); return; }
    closeCreditNote();
    showAlert('Credit note created (Built). Submit it to BTS from the log below.', true);
    loadDocuments();
}

// ── Debit note ───────────────────────────────────────────────────────────
let dnOriginalId = null;

function dnLineRow(){
    return `
    <div class="dnRow flex items-center gap-2" style="font-size:12px">
        <input type="text" class="biz-input dnDesc" placeholder="Charge description" maxlength="200" style="flex:1;min-width:0" oninput="dnRecalc()">
        <input type="number" class="biz-input dnQty" placeholder="Qty" min="0" step="0.01" value="1" style="width:70px;text-align:right" oninput="dnRecalc()">
        <input type="number" class="biz-input dnPrice" placeholder="Unit price" min="0" step="0.01" style="width:100px;text-align:right" oninput="dnRecalc()">
        <select class="biz-input dnTax" style="width:120px" onchange="dnRecalc()">
            <option value="standard">12.5% GST</option>
            <option value="zero_rated">Zero-rated</option>
            <option value="exempt">Exempt</option>
        </select>
        <button type="button" class="biz-muted" style="border:0;background:none;cursor:pointer;font-size:16px;line-height:1" onclick="this.closest('.dnRow').remove();dnRecalc()">&times;</button>
    </div>`;
}

function dnAddLine(){ document.getElementById('dnLines').insertAdjacentHTML('beforeend', dnLineRow()); }

async function openDebitNote(id){
    const res = await fetch(`api/fiscal/document_show.php?company_id=${CID}&id=${id}`);
    const d = await res.json();
    if (!d.success) { showAlert(d.message || 'Could not load that document.'); return; }
    dnOriginalId = id;
    document.getElementById('dnOriginalNumber').textContent = d.document.our_number || ('Doc #' + id);
    document.getElementById('dnReason').value = '';
    document.getElementById('dnLines').innerHTML = dnLineRow();
    dnRecalc();
    document.getElementById('dnOverlay').classList.remove('hidden');
}

function closeDebitNote(){ document.getElementById('dnOverlay').classList.add('hidden'); dnOriginalId = null; }

function dnCollect(){
    const lines = [];
    document.querySelectorAll('#dnLines .dnRow').forEach(row => {
        const description = row.querySelector('.dnDesc').value.trim();
        const quantity = Number(row.querySelector('.dnQty').value) || 0;
        const unit_price = Number(row.querySelector('.dnPrice').value) || 0;
        const tax_category = row.querySelector('.dnTax').value;
        if (description && quantity > 0 && unit_price > 0) {
            lines.push({ description, quantity, unit_price, tax_category, tax_rate: tax_category === 'standard' ? 12.5 : 0 });
        }
    });
    return lines;
}

function dnRecalc(){
    let total = 0;
    dnCollect().forEach(l => {
        const sub = l.quantity * l.unit_price;
        total += sub + sub * (l.tax_rate || 0) / 100;
    });
    document.getElementById('dnTotal').textContent = bzd(total);
}

async function createDebitNote(){
    const lines = dnCollect();
    if (!lines.length) { showAlert('Add at least one line with a description, quantity and unit price.'); return; }
    const btn = document.getElementById('dnCreateBtn');
    btn.disabled = true;
    const res = await fetch('api/fiscal/debit_note.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ company_id: CID, id: dnOriginalId, reason: document.getElementById('dnReason').value, lines }),
    });
    const d = await res.json();
    btn.disabled = false;
    if (!d.success) { showAlert(d.message || 'Could not create the debit note.'); return; }
    closeDebitNote();
    showAlert('Debit note created (Built). Submit it to BTS from the log below.', true);
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
