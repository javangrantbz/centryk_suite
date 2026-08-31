<?php
/**
 * Centryk Business — Accounting: manual journal entry and the journal list.
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
$companies = array_values(array_filter(
    $all,
    static fn ($c) => Entitlements::level((int)$c['id'], 'accounting') !== Entitlements::NONE
));

$activeCompany = null;
if ($companies) {
    $reqCid = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    foreach ($companies as $c) {
        if ((int)$c['id'] === $reqCid) { $activeCompany = $c; break; }
    }
    if (!$activeCompany) { $activeCompany = $companies[0]; }
}
$nav = 'journal';
?>
<!doctype html>
<html lang="en">
<head><?php $bizTitle = 'Journal'; include __DIR__ . '/partials/business_head.php'; ?></head>
<body class="min-h-screen bg-slate-50 antialiased">
<?php
ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
$pageTitle = 'Journal'; $headerMaxW = 'max-w-5xl'; $awCurrent = 'centryk';
include __DIR__ . '/partials/account_header.php';
$bizNav = 'accounting';
include __DIR__ . '/partials/business_sidebar.php';
?>

<div class="biz mx-auto max-w-5xl px-4 py-4">
    <?php if (!$companies): ?>
        <div class="biz-panel biz-panel-empty">No company you manage is on the Accounting package.</div>
    <?php else: ?>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div><p class="biz-kicker">Centryk Business · Accounting</p><h1 class="mt-0.5">Journal</h1></div>
        </div>

        <?php require __DIR__ . '/partials/accounting_nav.php'; ?>

        <div id="alert" class="biz-notice hidden mb-3"></div>
        <div id="notReady" class="biz-panel biz-panel-empty hidden">
            Accounting isn't set up for this company yet.
            <a class="biz-t-blue font-semibold" id="setupLink" href="#">Set up the books</a>.
        </div>

        <div id="wrap" class="hidden">
            <!-- ── New entry ────────────────────────────────────────── -->
            <div class="biz-panel mt-3">
                <div class="biz-panel-head"><span>New journal entry</span></div>
                <div class="biz-panel-body">
                    <div class="flex flex-wrap items-end gap-3 mb-2">
                        <label class="block"><span class="biz-label">Date</span>
                            <input type="date" id="j_date" class="biz-input" style="width:auto"></label>
                        <label class="block flex-1" style="min-width:200px"><span class="biz-label">Description</span>
                            <input id="j_memo" class="biz-input" placeholder="What this entry records"></label>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full" style="font-size:12px">
                            <thead><tr class="biz-muted" style="text-align:left">
                                <th style="padding:2px 6px">Account</th>
                                <th style="padding:2px 6px;width:110px;text-align:right">Debit</th>
                                <th style="padding:2px 6px;width:110px;text-align:right">Credit</th>
                                <th style="padding:2px 6px">Line note</th>
                                <th style="width:24px"></th>
                            </tr></thead>
                            <tbody id="jlines"></tbody>
                            <tfoot><tr style="font-weight:700">
                                <td style="padding:4px 6px;text-align:right">Totals</td>
                                <td id="tot_dr" class="biz-num" style="padding:4px 6px;text-align:right">0.00</td>
                                <td id="tot_cr" class="biz-num" style="padding:4px 6px;text-align:right">0.00</td>
                                <td colspan="2" style="padding:4px 6px"><span id="bal_flag"></span></td>
                            </tr></tfoot>
                        </table>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button class="biz-btn biz-btn-ghost biz-btn-sm" onclick="addLine()">Add line</button>
                        <span class="flex-1"></span>
                        <button class="biz-btn biz-btn-ghost" onclick="saveJournal('draft')" id="j_draft">Save draft</button>
                        <button class="biz-btn biz-btn-primary" onclick="saveJournal('posted')" id="j_post">Post</button>
                    </div>
                    <p class="biz-muted mt-1" style="font-size:11px">
                        Control accounts (Accounts Receivable, GST, …) aren't listed — they're posted by their subledger.
                    </p>
                </div>
            </div>

            <!-- ── Journal list ─────────────────────────────────────── -->
            <div class="biz-panel mt-3">
                <div class="biz-panel-head"><span>Journals</span></div>
                <div class="biz-panel-body">
                    <div class="flex flex-wrap items-end gap-2 mb-2">
                        <label class="block"><span class="biz-label">From</span><input type="date" id="f_from" class="biz-input" style="width:auto"></label>
                        <label class="block"><span class="biz-label">To</span><input type="date" id="f_to" class="biz-input" style="width:auto"></label>
                        <label class="block"><span class="biz-label">Source</span>
                            <select id="f_source" class="biz-select" style="width:auto">
                                <option value="">All</option>
                                <option value="manual">Manual</option>
                                <option value="opening">Opening</option>
                                <option value="ar_invoice">AR invoice</option>
                                <option value="ar_receipt">AR receipt</option>
                                <option value="ar_writeoff">AR write-off</option>
                                <option value="expense">Expense</option>
                                <option value="payroll">Payroll</option>
                                <option value="pos">POS</option>
                                <option value="closing">Closing</option>
                            </select></label>
                        <label class="block flex-1" style="min-width:140px"><span class="biz-label">Search</span>
                            <input id="f_q" class="biz-input" placeholder="memo or J number"></label>
                        <label class="flex items-center gap-1" style="font-size:12px"><input type="checkbox" id="f_drafts"> drafts</label>
                        <button class="biz-btn biz-btn-ghost biz-btn-sm" onclick="loadJournals()">Apply</button>
                    </div>
                    <div id="jlist"><div class="biz-panel-empty">Loading…</div></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
const CID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;
let POSTABLE = [];

function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function money(v){ return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function showAlert(msg, kind){
    const el = document.getElementById('alert');
    el.textContent = msg;
    el.className = 'biz-notice mb-3 ' + (kind === 'ok' ? 'biz-notice-green' : 'biz-notice-red');
    el.classList.remove('hidden');
    if (kind === 'ok') setTimeout(() => el.classList.add('hidden'), 4000);
}
async function api(path, body){
    const res = await fetch('api/accounting/' + path, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ company_id: CID }, body || {})),
    });
    const d = await res.json();
    if (!d.success) throw new Error(d.message || 'Request failed.');
    return d;
}

function accountOptions(sel){
    return '<option value="">— account —</option>' + POSTABLE
        .map(a => `<option value="${a.id}" ${a.id === sel ? 'selected' : ''}>${esc(a.code)} · ${esc(a.name)}</option>`).join('');
}
function addLine(accountId, debit, credit, memo){
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td style="padding:2px 6px"><select class="biz-select jl-acct">${accountOptions(accountId)}</select></td>
        <td style="padding:2px 6px"><input class="biz-input jl-dr biz-num" style="text-align:right" inputmode="decimal" value="${debit || ''}"></td>
        <td style="padding:2px 6px"><input class="biz-input jl-cr biz-num" style="text-align:right" inputmode="decimal" value="${credit || ''}"></td>
        <td style="padding:2px 6px"><input class="biz-input jl-memo" value="${esc(memo || '')}"></td>
        <td style="padding:2px 6px;text-align:center"><button class="biz-t-red" onclick="this.closest('tr').remove();recalc()">&times;</button></td>`;
    document.getElementById('jlines').appendChild(tr);
    tr.querySelectorAll('.jl-dr, .jl-cr').forEach(i => i.addEventListener('input', () => {
        // one side per line
        if (i.classList.contains('jl-dr') && i.value) tr.querySelector('.jl-cr').value = '';
        if (i.classList.contains('jl-cr') && i.value) tr.querySelector('.jl-dr').value = '';
        recalc();
    }));
}
function recalc(){
    let dr = 0, cr = 0;
    document.querySelectorAll('#jlines tr').forEach(tr => {
        dr += parseFloat(tr.querySelector('.jl-dr').value) || 0;
        cr += parseFloat(tr.querySelector('.jl-cr').value) || 0;
    });
    document.getElementById('tot_dr').textContent = money(dr);
    document.getElementById('tot_cr').textContent = money(cr);
    const diff = Math.round((dr - cr) * 100) / 100;
    const flag = document.getElementById('bal_flag');
    if (Math.abs(diff) < 0.005 && dr > 0){
        flag.innerHTML = '<span class="biz-chip biz-c-green">balanced</span>';
    } else {
        flag.innerHTML = `<span class="biz-chip biz-c-amber">out by ${money(Math.abs(diff))}</span>`;
    }
}

async function saveJournal(status){
    const lines = [];
    document.querySelectorAll('#jlines tr').forEach(tr => {
        const acct = parseInt(tr.querySelector('.jl-acct').value || '0', 10);
        const dr = parseFloat(tr.querySelector('.jl-dr').value) || 0;
        const cr = parseFloat(tr.querySelector('.jl-cr').value) || 0;
        if (acct && (dr > 0 || cr > 0)){
            lines.push({ account_id: acct, debit: dr, credit: cr, memo: tr.querySelector('.jl-memo').value.trim() });
        }
    });
    if (lines.length < 2){ showAlert('Add at least two lines with an account and an amount.'); return; }

    document.getElementById('j_post').disabled = true;
    document.getElementById('j_draft').disabled = true;
    try {
        await api('journal_save.php', {
            date: document.getElementById('j_date').value,
            memo: document.getElementById('j_memo').value.trim(),
            status, lines,
        });
        showAlert(status === 'draft' ? 'Saved as a draft.' : 'Journal posted.', 'ok');
        document.getElementById('j_memo').value = '';
        document.getElementById('jlines').innerHTML = '';
        addLine(); addLine(); recalc();
        loadJournals();
    } catch (e){ showAlert(e.message); }
    document.getElementById('j_post').disabled = false;
    document.getElementById('j_draft').disabled = false;
}

async function loadJournals(){
    const wrap = document.getElementById('jlist');
    try {
        const { journals } = await api('journals.php', {
            from: document.getElementById('f_from').value || null,
            to: document.getElementById('f_to').value || null,
            source: document.getElementById('f_source').value || null,
            q: document.getElementById('f_q').value.trim() || null,
            include_drafts: document.getElementById('f_drafts').checked,
            limit: 100,
        });
        if (!journals.length){ wrap.innerHTML = '<div class="biz-panel-empty">No journals match.</div>'; return; }
        wrap.innerHTML = journals.map(j => `
            <div class="biz-row" style="cursor:pointer" onclick="toggleJournal(${j.id}, this)">
                <span class="biz-num text-slate-500" style="width:70px">J${j.journal_no}</span>
                <span style="width:84px">${esc(j.entry_date)}</span>
                <span class="flex-1 min-w-0">${esc(j.memo || '—')}
                    ${j.source !== 'manual' ? `<span class="biz-chip biz-c-slate">${esc(j.source)}</span>` : ''}
                    ${j.status === 'draft' ? '<span class="biz-chip biz-c-amber">draft</span>' : ''}
                    ${j.is_reversal == 1 ? '<span class="biz-chip biz-c-blue">reversal</span>' : ''}
                    ${j.reversed_by_journal_id ? '<span class="biz-chip biz-c-slate">reversed</span>' : ''}
                </span>
                <span class="biz-num" style="width:100px;text-align:right">${money(j.total_debit)}</span>
            </div>
            <div class="biz-panel-body hidden" data-detail="${j.id}" style="background:var(--bz-head)"></div>`).join('');
    } catch (e){ wrap.innerHTML = `<div class="biz-panel-empty biz-t-red">${esc(e.message)}</div>`; }
}

async function toggleJournal(id, rowEl){
    const box = document.querySelector(`[data-detail="${id}"]`);
    if (!box.classList.contains('hidden')){ box.classList.add('hidden'); return; }
    box.classList.remove('hidden');
    box.innerHTML = 'Loading…';
    try {
        const { journal } = await api('journal_get.php', { journal_id: id });
        const lines = journal.lines.map(l => `
            <tr><td style="padding:1px 6px">${esc(l.account_code)} · ${esc(l.account_name)}</td>
                <td class="biz-num" style="padding:1px 6px;text-align:right">${Number(l.debit) ? money(l.debit) : ''}</td>
                <td class="biz-num" style="padding:1px 6px;text-align:right">${Number(l.credit) ? money(l.credit) : ''}</td>
                <td style="padding:1px 6px" class="biz-muted">${esc(l.memo || '')}</td></tr>`).join('');
        const canReverse = journal.status === 'posted' && !journal.reversed_by_journal_id;
        box.innerHTML = `
            <table class="w-full" style="font-size:12px"><tbody>${lines}</tbody></table>
            <div class="mt-2 flex items-center gap-2">
                ${canReverse ? `<button class="biz-btn biz-btn-danger biz-btn-sm" onclick="reverseJournal(${id})">Reverse this entry</button>` : ''}
                <span class="biz-muted" style="font-size:11px">
                    ${journal.source_ref ? 'ref ' + esc(journal.source_ref) + ' · ' : ''}
                    ${journal.posted_at ? 'posted ' + esc(String(journal.posted_at).slice(0,16).replace('T',' ')) : 'draft'}
                </span>
            </div>`;
    } catch (e){ box.innerHTML = `<span class="biz-t-red">${esc(e.message)}</span>`; }
}

async function reverseJournal(id){
    if (!window.confirm('Post a reversing entry for J' + id + '? The original stays on the books.')) return;
    try {
        await api('journal_reverse.php', { journal_id: id });
        showAlert('Reversing entry posted.', 'ok');
        loadJournals();
    } catch (e){ showAlert(e.message); }
}

async function init(){
    if (CID === null) return;
    try {
        const d = await api('accounts.php', { postable_only: true, active_only: true });
        if (!d.activated){
            document.getElementById('notReady').classList.remove('hidden');
            document.getElementById('setupLink').href = 'accounting.php?company_id=' + CID;
            return;
        }
        POSTABLE = d.accounts;
        document.getElementById('wrap').classList.remove('hidden');
        document.getElementById('j_date').value = new Date().toISOString().slice(0, 10);
        addLine(); addLine(); recalc();
        if (location.hash === '#drafts') document.getElementById('f_drafts').checked = true;
        loadJournals();
    } catch (e){ showAlert(e.message); }
}
init();
</script>
<?php include __DIR__ . '/partials/business_sidebar_end.php'; ?>
</body>
</html>
