<?php
/**
 * Belize BTS Electronic Invoicing — platform-admin monitor.
 *
 * Cross-company visibility for whoever runs Centryk itself: every company
 * that has touched e-invoicing (profile status, certificate, counts) and
 * every fiscal document across all of them (build/sign/submit status,
 * BTS authorization code, errors). Company-scoped admins get their own view
 * at business_fiscal.php; this page is is_admin-only and has no per-company
 * write actions - it's for knowing what's built and what happened, not for
 * running a company's own e-invoicing day to day.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>BTS E-Invoicing (platform) - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased text-slate-900">
<?php include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-6xl px-4 pt-1 pb-10">
    <div class="mb-4">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-indigo-500">Platform admin · Belize</p>
        <h1 class="mt-1 text-2xl font-black tracking-tight">BTS Electronic Invoicing</h1>
        <p class="mt-1 max-w-3xl text-sm font-semibold text-slate-500">
            Every company on e-invoicing, and every fiscal document across all of them. This is a read-only monitor -
            a company's own admin manages their profile, certificate and submissions at
            <code class="rounded bg-slate-200 px-1">business_fiscal.php</code>.
        </p>
    </div>

    <div id="alert" class="mb-4 hidden rounded-xl border px-4 py-3 text-sm font-bold"></div>

    <div id="tiles" class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4"></div>

    <!-- ── Companies ──────────────────────────────────────────────────── -->
    <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-4 py-3">
            <h2 class="text-sm font-black uppercase tracking-wide text-slate-600">Companies</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[820px] w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">
                        <th class="px-4 py-2.5">Company</th>
                        <th class="px-4 py-2.5">TIN</th>
                        <th class="px-4 py-2.5">Status</th>
                        <th class="px-4 py-2.5">Env</th>
                        <th class="px-4 py-2.5">Enabled</th>
                        <th class="px-4 py-2.5">Certificate</th>
                        <th class="px-4 py-2.5 text-right">Documents</th>
                        <th class="px-4 py-2.5 text-right">Authorized</th>
                        <th class="px-4 py-2.5 text-right">Errors</th>
                    </tr>
                </thead>
                <tbody id="profileRows" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
        <div id="profilesEmpty" class="hidden px-4 py-8 text-center text-sm font-semibold text-slate-400">No company has a fiscal profile yet.</div>
    </section>

    <!-- ── Document log ───────────────────────────────────────────────── -->
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <h2 class="text-sm font-black uppercase tracking-wide text-slate-600">Fiscal documents</h2>
            <select id="statusFilter" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-600 outline-none focus:border-indigo-400">
                <option value="">All statuses</option>
                <option value="built">Built</option>
                <option value="signed">Signed</option>
                <option value="authorized">Authorized</option>
                <option value="rejected">Rejected</option>
                <option value="error">Error</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[900px] w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">
                        <th class="px-4 py-2.5">Company</th>
                        <th class="px-4 py-2.5">Doc #</th>
                        <th class="px-4 py-2.5">Type</th>
                        <th class="px-4 py-2.5">Status</th>
                        <th class="px-4 py-2.5">ETDUI</th>
                        <th class="px-4 py-2.5">Created</th>
                        <th class="px-4 py-2.5 text-right">Total</th>
                        <th class="px-4 py-2.5 text-right">&nbsp;</th>
                    </tr>
                </thead>
                <tbody id="documentRows" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
        <div id="documentsEmpty" class="hidden px-4 py-8 text-center text-sm font-semibold text-slate-400">No fiscal documents yet.</div>
    </section>
</main>

<!-- ── Document detail drawer ──────────────────────────────────────── -->
<div id="detailOverlay" class="fixed inset-0 z-40 hidden bg-slate-900/40" onclick="closeDetail()"></div>
<div id="detailDrawer" class="fixed right-0 top-0 z-50 h-full w-full max-w-lg translate-x-full overflow-y-auto bg-white shadow-2xl transition-transform">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
        <h3 class="text-sm font-black uppercase tracking-wide text-slate-600">Document detail</h3>
        <button type="button" onclick="closeDetail()" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100">✕</button>
    </div>
    <div id="detailBody" class="p-5 text-sm"></div>
</div>

<script>
function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function bzd(v){ return 'BZD ' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function showAlert(msg){ const el = document.getElementById('alert'); el.textContent = msg; el.className = 'mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700'; el.classList.remove('hidden'); }

const STATUS_TONE = {
    draft: 'bg-slate-100 text-slate-600', built: 'bg-blue-100 text-blue-700', signed: 'bg-blue-100 text-blue-700',
    submitted: 'bg-amber-100 text-amber-700', authorized: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-red-100 text-red-700', cancelled: 'bg-slate-100 text-slate-500', error: 'bg-red-100 text-red-700',
};
const PROFILE_STATUS_LABEL = {
    not_started: 'Not started', info_sent: 'Info sent to BTS', sandbox_access: 'Sandbox access',
    live: 'Live', suspended: 'Suspended',
};

function tile(label, value, tone){
    return `<div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
        <div class="text-[10px] font-black uppercase tracking-wide text-slate-400">${esc(label)}</div>
        <div class="mt-1 text-xl font-black ${tone || 'text-slate-900'}">${esc(value)}</div>
    </div>`;
}

async function load(){
    const status = document.getElementById('statusFilter').value;
    const res = await fetch(`api/admin/fiscal_overview.php?status=${encodeURIComponent(status)}`).catch(() => null);
    if (!res || !res.ok) { showAlert('Could not load the fiscal overview.'); return; }
    const d = await res.json().catch(() => null);
    if (!d || !d.success) { showAlert((d && d.message) || 'Could not load the fiscal overview.'); return; }

    const profiles = d.profiles || [];
    const documents = d.documents || [];

    const enabledCount = profiles.filter(p => Number(p.enabled) === 1).length;
    const certCount = profiles.filter(p => p.has_certificate).length;
    const authorizedCount = documents.filter(x => x.status === 'authorized').length;
    const errorCount = documents.filter(x => x.status === 'rejected' || x.status === 'error').length;
    document.getElementById('tiles').innerHTML = [
        tile('Companies enabled', `${enabledCount} / ${profiles.length}`),
        tile('Certificates on file', certCount),
        tile('Authorized documents', authorizedCount, 'text-emerald-700'),
        tile('Rejected / errors', errorCount, errorCount ? 'text-red-700' : 'text-slate-900'),
    ].join('');

    const profileRows = document.getElementById('profileRows');
    document.getElementById('profilesEmpty').classList.toggle('hidden', profiles.length > 0);
    profileRows.innerHTML = profiles.map(p => `
        <tr>
            <td class="px-4 py-2.5 font-bold">${esc(p.company_name)}</td>
            <td class="px-4 py-2.5 font-mono text-xs">${esc(p.tin || '-')}</td>
            <td class="px-4 py-2.5">${esc(PROFILE_STATUS_LABEL[p.status] || p.status)}</td>
            <td class="px-4 py-2.5 uppercase text-xs font-bold text-slate-500">${esc(p.environment)}</td>
            <td class="px-4 py-2.5">${Number(p.enabled) === 1 ? '<span class="text-emerald-600 font-bold">Yes</span>' : '<span class="text-slate-400">No</span>'}</td>
            <td class="px-4 py-2.5">${p.has_certificate ? `<span class="text-emerald-600 font-bold">On file</span>${p.certificate_expires_on ? `<div class="text-[10px] text-slate-400">exp ${esc(p.certificate_expires_on)}</div>` : ''}` : '<span class="text-red-500 font-semibold">None</span>'}</td>
            <td class="px-4 py-2.5 text-right font-mono">${p.document_count || 0}</td>
            <td class="px-4 py-2.5 text-right font-mono text-emerald-700">${p.authorized_count || 0}</td>
            <td class="px-4 py-2.5 text-right font-mono ${Number(p.errored_count) ? 'text-red-600' : ''}">${p.errored_count || 0}</td>
        </tr>
    `).join('');

    const docRows = document.getElementById('documentRows');
    document.getElementById('documentsEmpty').classList.toggle('hidden', documents.length > 0);
    docRows.innerHTML = documents.map(doc => `
        <tr>
            <td class="px-4 py-2.5 font-semibold">${esc(doc.company_name)}</td>
            <td class="px-4 py-2.5">${esc(doc.our_number || ('#' + doc.id))}</td>
            <td class="px-4 py-2.5 text-xs text-slate-500">${esc(doc.document_type)}</td>
            <td class="px-4 py-2.5"><span class="rounded px-2 py-0.5 text-[10px] font-black uppercase ${STATUS_TONE[doc.status] || ''}">${esc(doc.status)}</span></td>
            <td class="px-4 py-2.5 font-mono text-[10px] text-slate-500">${esc(doc.etdui || '-')}</td>
            <td class="px-4 py-2.5 text-xs text-slate-500">${esc((doc.created_at || '').slice(0, 16).replace('T', ' '))}</td>
            <td class="px-4 py-2.5 text-right font-mono">${bzd(doc.total)}</td>
            <td class="px-4 py-2.5 text-right"><button type="button" class="text-xs font-bold text-indigo-600 hover:underline" onclick="openDetail(${doc.id})">View</button></td>
        </tr>
    `).join('');
}

document.getElementById('statusFilter').addEventListener('change', load);

async function openDetail(id){
    const res = await fetch(`api/admin/fiscal_document.php?id=${id}`);
    const d = await res.json();
    const body = document.getElementById('detailBody');
    if (!d.success) { body.innerHTML = `<p class="text-red-600">${esc(d.message || 'Could not load this document.')}</p>`; }
    else {
        const doc = d.document;
        const lines = (doc.lines || []).map(l => `<div class="flex justify-between border-b border-slate-100 py-1 text-xs"><span>${esc(l.description)} × ${Number(l.quantity)}</span><span class="font-mono">${bzd(l.line_total)}</span></div>`).join('');
        const events = (doc.events || []).map(e => `<div class="text-xs text-slate-500"><span class="font-bold text-slate-700">${esc(e.event_type)}</span> - ${esc(e.detail || '')} <span class="text-slate-400">(${esc((e.created_at||'').slice(0,16).replace('T',' '))})</span></div>`).join('');
        body.innerHTML = `
            <p class="text-xs font-black uppercase tracking-wide text-indigo-500">${esc(doc.company_name)}</p>
            <h4 class="mt-0.5 text-lg font-black">${esc(doc.our_number || ('Doc #' + doc.id))}</h4>
            <p class="mt-1 text-xs text-slate-500">${esc(doc.document_type)} · <span class="rounded px-1.5 py-0.5 text-[10px] font-black uppercase ${STATUS_TONE[doc.status] || ''}">${esc(doc.status)}</span></p>
            ${doc.etdui ? `<p class="mt-2 break-all font-mono text-[11px] text-slate-600">ETDUI: ${esc(doc.etdui)}</p>` : ''}
            ${doc.authorization_code ? `<p class="mt-1 break-all font-mono text-[11px] text-emerald-700">Authorization: ${esc(doc.authorization_code)}</p>` : ''}
            ${doc.error_message ? `<p class="mt-2 text-xs font-semibold text-red-600">${esc(doc.error_message)}</p>` : ''}
            <div class="mt-3 rounded-xl border border-slate-100 bg-slate-50 p-3">
                <p class="mb-1 text-[10px] font-black uppercase tracking-wide text-slate-400">Lines</p>
                ${lines || '<p class="text-xs text-slate-400">No lines.</p>'}
                <div class="mt-2 flex justify-between border-t border-slate-200 pt-2 text-xs font-black"><span>Total</span><span class="font-mono">${bzd(doc.total)}</span></div>
            </div>
            <div class="mt-3 space-y-1">
                <p class="mb-1 text-[10px] font-black uppercase tracking-wide text-slate-400">Event history</p>
                ${events || '<p class="text-xs text-slate-400">No events logged.</p>'}
            </div>
            ${doc.bts_response_json ? `<details class="mt-3"><summary class="cursor-pointer text-xs font-bold text-slate-500">Raw BTS response</summary><pre class="mt-1 max-h-48 overflow-auto rounded-lg bg-slate-900 p-2 text-[10px] text-slate-100">${esc(JSON.stringify(JSON.parse(doc.bts_response_json), null, 2))}</pre></details>` : ''}
        `;
    }
    document.getElementById('detailOverlay').classList.remove('hidden');
    document.getElementById('detailDrawer').classList.remove('translate-x-full');
}

function closeDetail(){
    document.getElementById('detailOverlay').classList.add('hidden');
    document.getElementById('detailDrawer').classList.add('translate-x-full');
}

load();
</script>
</body>
</html>
