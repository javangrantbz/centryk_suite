<?php
/**
 * Belize GST (General Sales Tax) — monthly output-tax summary to help prepare
 * the GST return. Not the return, not tax advice. Built from sales invoices.
 * Gated: admin/manager of a company holding the 'receivables' package.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/core/Entitlements.php';
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
$all = $coStmt->fetchAll(PDO::FETCH_ASSOC);
$companies = array_values(array_filter($all, static fn ($c) => Entitlements::level((int)$c['id'], 'receivables') !== Entitlements::NONE));

$activeCompany = null;
if ($companies) {
    $reqCid = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    foreach ($companies as $c) {
        if ((int)$c['id'] === $reqCid) { $activeCompany = $c; break; }
    }
    if (!$activeCompany) { $activeCompany = $companies[0]; }
}

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'GST Summary'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'GST Summary'; $headerMaxW = 'max-w-3xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; $bizNav = 'tax'; include __DIR__ . '/partials/business_sidebar.php'; ?>

<div class="biz mx-auto max-w-3xl px-4 py-4">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Centryk Business · Belize</p>
            <h1 class="mt-0.5">GST summary</h1>
        </div>
    </div>

    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">The GST summary reads sales from Receivables. No company you manage has that package.</div>
    <?php else: ?>
        <div id="alert" class="biz-notice mb-3 hidden"></div>

        <div class="biz-panel">
            <div class="biz-panel-body flex flex-wrap items-end gap-3">
                <label class="block">
                    <span class="biz-label">Tax period</span>
                    <input type="month" id="period" class="biz-input" style="width:auto">
                </label>
                <label class="block">
                    <span class="biz-label">Invoices with no GST split</span>
                    <select id="treat" class="biz-select" style="width:auto">
                        <option value="inclusive">Treat price as GST-inclusive (12.5%)</option>
                        <option value="zerorated">Treat as zero-rated / exempt</option>
                    </select>
                </label>
                <button onclick="run()" class="biz-btn biz-btn-primary">Show</button>
                <button onclick="window.print()" class="biz-btn biz-btn-ghost">Print</button>
            </div>
        </div>

        <div id="report" class="mt-3"></div>

        <p class="biz-muted mt-3" style="font-size:11px">
            A working summary to help prepare your GST return — it is not the return and not tax advice.
            Figures are on an invoice-date basis and cover sent, overdue, paid and written-off invoices
            issued in the period. Confirm the treatment of zero-rated and exempt supplies with your accountant.
        </p>
    <?php endif; ?>
</div>

<script>
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
document.getElementById('period') && (document.getElementById('period').value = new Date(Date.now() - 15*864e5).toISOString().slice(0,7));

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function bzd(v){ return 'BZD ' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function showAlert(msg){ const el = document.getElementById('alert'); el.textContent = msg; el.className = 'biz-notice biz-notice-red mb-3'; el.classList.remove('hidden'); }

function line(label, value, opts){
    opts = opts || {};
    return `<div class="biz-row" style="font-size:12px${opts.strong ? ';font-weight:700' : ''}">
        <span class="min-w-0 flex-1">${esc(label)}${opts.sub ? `<span class="block biz-muted" style="font-size:11px;font-weight:400">${esc(opts.sub)}</span>` : ''}</span>
        <span class="shrink-0 biz-num ${opts.tone || ''}" style="${opts.strong ? 'font-weight:700' : ''}">${value}</span>
    </div>`;
}

async function run(){
    if (CID === null) return;
    try {
        const res = await fetch('api/business/gst_report.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ company_id: CID, period: document.getElementById('period').value, treat_untaxed_as: document.getElementById('treat').value }),
        });
        const d = await res.json();
        if (!d.success) throw new Error(d.message || 'Could not build the summary.');
        const s = d.sales, t = s.with_recorded_tax, u = s.without_recorded_tax, bd = d.bad_debt_relief;

        document.getElementById('report').innerHTML = `
            <div class="biz-panel">
                <div class="biz-panel-head">
                    <span>${esc(d.company)} — ${esc(d.period_label)}</span>
                    ${d.tin ? `<span class="biz-muted" style="font-size:11px">TIN ${esc(d.tin)}</span>` : ''}
                </div>
                <div class="biz-panel-body">
                    ${line('Total sales invoiced', bzd(s.gross_total), { sub: `${s.invoice_count} invoice${s.invoice_count === 1 ? '' : 's'} · ${d.basis}` })}
                    ${t.count ? line(`Invoices with GST recorded (${t.count})`, bzd(t.gross), { sub: `net ${bzd(t.net)} · GST ${bzd(t.tax)}` }) : ''}
                    ${u.count ? line(`Invoices with no GST split (${u.count})`, bzd(u.gross),
                        { sub: d.treat_untaxed_as === 'inclusive' ? `treated GST-inclusive · net ${bzd(u.net)} · GST ${bzd(u.imputed_gst)}` : 'treated as zero-rated / exempt' }) : ''}
                </div>
                <div class="biz-panel-body" style="border-top:1px solid var(--bz-line);background:var(--bz-head)">
                    ${line('Output tax on sales', bzd(d.output_tax), { strong: true })}
                    ${bd.n ? line(`Less bad-debt relief (${bd.n})`, '− ' + bzd(bd.gst_relief), { sub: `GST portion of ${bzd(bd.writeoff_total)} written off this period`, tone: 'biz-t-green' }) : ''}
                    ${line('Net GST for the return', bzd(d.net_output_tax), { strong: true, tone: 'biz-t-blue' })}
                </div>
            </div>`;
    } catch (e){ showAlert(e.message); }
}
run();
</script>
<?php include __DIR__ . '/partials/business_sidebar_end.php'; ?>
</body>
</html>
