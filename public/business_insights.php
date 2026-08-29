<?php
/**
 * Centryk Business — one-company KPI snapshot (the finance-lead view).
 * Read-only; pulls from Receivables, Routes and Reconciliation for whatever
 * the company is entitled to. Gated: admin/manager of a company with >=1 package.
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
    SELECT DISTINCT c.id, c.name
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    JOIN company_entitlements e ON e.company_id = c.id AND e.state <> 'revoked'
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

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Business Insights'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php $pageTitle = 'Business Insights'; $headerMaxW = 'max-w-5xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="biz mx-auto max-w-5xl px-4 py-4">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="biz-kicker">Centryk Business</p>
            <h1 class="mt-0.5">Insights</h1>
        </div>
        <?php if (count($companies) > 1): ?>
        <div class="biz-seg">
            <?php foreach ($companies as $c): ?>
                <a href="business_insights.php?company_id=<?= (int)$c['id'] ?>"
                   class="<?= $activeCompany && (int)$c['id'] === (int)$activeCompany['id'] ? 'is-active' : '' ?>">
                    <?= htmlspecialchars($c['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">
            No company on a Centryk Business plan that you manage. Insights covers Receivables, Routes and Reconciliation.
        </div>
    <?php else: ?>
        <div id="alert" class="biz-notice mb-3 hidden"></div>
        <p class="biz-muted mb-3" style="font-size:11px">
            <?= htmlspecialchars($activeCompany['name']) ?> · as of <span id="asOf">…</span>
        </p>
        <div id="sections" class="space-y-3">
            <div class="biz-panel biz-panel-empty">Loading…</div>
        </div>
    <?php endif; ?>
</div>

<script>
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function bzd(v){ return v == null ? '—' : 'BZD ' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function pct(v){ return v == null ? '—' : Number(v).toFixed(1) + '%'; }
function days(v){ return v == null ? '—' : Number(v).toFixed(0) + ' days'; }
function showAlert(msg){
    const el = document.getElementById('alert');
    el.textContent = msg; el.className = 'biz-notice biz-notice-red mb-3'; el.classList.remove('hidden');
}

function tile(label, value, tone, sub){
    return `<div class="biz-tile">
        <div class="biz-tile-l">${esc(label)}</div>
        <div class="biz-tile-v ${tone || ''}">${value}</div>
        ${sub ? `<div class="biz-num biz-muted" style="font-size:11px;font-weight:600">${sub}</div>` : ''}
    </div>`;
}
function panel(title, tiles, note){
    return `<div class="biz-panel">
        <div class="biz-panel-head">${esc(title)}</div>
        <div class="biz-panel-body">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">${tiles.join('')}</div>
            ${note ? `<p class="biz-muted mt-2" style="font-size:11px">${note}</p>` : ''}
        </div>
    </div>`;
}

async function load(){
    if (CID === null) return;
    try {
        const res = await fetch('api/business/insights.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ company_id: CID }),
        });
        const d = await res.json();
        if (!d.success) throw new Error(d.message || 'Could not load insights.');
        document.getElementById('asOf').textContent = d.as_of || '';
        const out = [];

        if (d.receivables){
            const r = d.receivables;
            out.push(panel('Receivables', [
                tile('Outstanding', bzd(r.outstanding)),
                tile('Overdue', bzd(r.overdue), r.overdue > 0 ? 'biz-t-red' : '', pct(r.overdue_pct) + ' of book'),
                tile('DSO', days(r.dso), r.dso > 45 ? 'biz-t-amber' : '', 'days sales outstanding'),
                tile('Avg days to pay', days(r.avg_days_to_pay), '', 'settled invoices, 180d'),
                tile('Billed this month', bzd(r.billed_this_month)),
                tile('Collected this month', bzd(r.collected_this_month), '', r.collection_ratio != null ? pct(r.collection_ratio) + ' of billed' : ''),
                tile('Over 90 days', bzd(r.over_90), r.over_90 > 0 ? 'biz-t-red' : ''),
                tile('Credit flags', (r.on_hold + r.over_limit) || '0', (r.on_hold + r.over_limit) ? 'biz-t-amber' : '', `${r.on_hold} on hold · ${r.over_limit} over limit`),
            ]));
        }
        if (d.bad_debt){
            const b = d.bad_debt;
            const kinds = Object.entries(b.by_kind || {}).map(([k, v]) => `${k.replace(/_/g,' ')} ${bzd(v.total)}`).join(' · ');
            out.push(panel('Bad debt & adjustments', [
                tile('Written off YTD', bzd(b.written_off_ytd), b.written_off_ytd > 0 ? 'biz-t-red' : '', `${b.count_ytd} item${b.count_ytd === 1 ? '' : 's'}`),
                tile('Write-off rate', b.writeoff_rate != null ? Number(b.writeoff_rate).toFixed(2) + '%' : '—', '', 'of sales YTD'),
                tile('Awaiting approval', bzd(b.pending), b.pending > 0 ? 'biz-t-amber' : '', `${b.pending_count} pending`),
            ], kinds || null));
        }
        if (d.routes){
            const t = d.routes;
            out.push(panel('Field Sales & Routes', [
                tile('Cash in transit', bzd(t.cash_in_transit), t.cash_in_transit > 0 ? 'biz-t-amber' : ''),
                tile('On the road', t.on_the_road || '0'),
                tile('Awaiting approval', t.awaiting_approval || '0', t.awaiting_approval ? 'biz-t-amber' : ''),
                tile('Variance flags', t.variance_flags_30d || '0', t.variance_flags_30d ? 'biz-t-red' : '', 'last 30 days'),
            ]));
        }
        if (d.reconciliation){
            const c = d.reconciliation;
            out.push(panel('Reconciliation', [
                tile('Unmatched deposits', c.unmatched_credits || '0', c.unmatched_credits ? 'biz-t-amber' : ''),
                tile('Unmatched value', bzd(c.unmatched_value)),
                tile('Match rate', pct(c.match_rate), '', 'of bank credits'),
            ]));
        }

        document.getElementById('sections').innerHTML = out.join('') ||
            '<div class="biz-panel biz-panel-empty">No modules active for this company.</div>';
    } catch (e){ showAlert(e.message); }
}
load();
</script>
</body>
</html>
