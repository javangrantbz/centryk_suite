<?php
/**
 * Centryk Business — customer-facing "Explore more services".
 *
 * Company admins see the paid capability packages, what their company already
 * has, and can ask a Centryk advisor to set one up. Requesting never activates
 * anything — it creates a lead (api/apps/request_package.php).
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

// Companies this user administers — buying a package is an admin decision.
$coStmt = $pdo->prepare("
    SELECT c.id, c.name
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.user_id = :uid AND cm.status = 'active' AND cm.role = 'admin' AND c.status = 'active'
    ORDER BY c.name ASC
");
$coStmt->execute(['uid' => (int)$user['id']]);
$companies = $coStmt->fetchAll(PDO::FETCH_ASSOC);

$activeCompany = null;
if ($companies) {
    $requestedCid = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    foreach ($companies as $c) {
        if ((int)$c['id'] === $requestedCid) { $activeCompany = $c; break; }
    }
    if (!$activeCompany) { $activeCompany = $companies[0]; }
}

$catalog = [];
$entStates = [];   // package_key => 'active' | 'suspended'
$requested = [];   // package_key ('' for general) => status
if ($activeCompany) {
    $cid = (int)$activeCompany['id'];

    $catalog = $pdo->query("
        SELECT `key`, label, description, monthly_price, currency, is_app
        FROM business_packages
        WHERE status = 'active'
        ORDER BY sort_order ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $es = $pdo->prepare("
        SELECT package_key, state FROM company_entitlements
        WHERE company_id = :cid AND state <> 'revoked'
    ");
    $es->execute(['cid' => $cid]);
    foreach ($es->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $entStates[$row['package_key']] = $row['state'];
    }

    $rq = $pdo->prepare("
        SELECT package_key, status FROM business_package_requests
        WHERE company_id = :cid AND status IN ('pending', 'contacted')
    ");
    $rq->execute(['cid' => $cid]);
    foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $requested[$row['package_key'] ?? ''] = $row['status'];
    }
}

$fmtPrice = static function (array $p): string {
    $amount = (float)($p['monthly_price'] ?? 0);
    if ($amount <= 0) {
        return 'Custom pricing';
    }
    return 'from ' . htmlspecialchars($p['currency'] ?: 'BZD') . ' '
        . number_format($amount, 2) . '/mo';
};

$icons = [
    'receivables'    => 'wallet',
    'reconciliation' => 'scale',
    'routes'         => 'truck',
    'enterprise'     => 'building-2',
];

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Centryk Business</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Centryk Business'; $headerMaxW = 'max-w-5xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-5xl px-4 pt-4 pb-12">

    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-violet-600">Centryk Business</p>
            <h1 class="mt-0.5 text-2xl font-black tracking-tight text-slate-950">More tools for growing operations</h1>
            <p class="mt-1 max-w-2xl text-sm font-semibold text-slate-500">
                Your Centryk hub stays free. These are optional add-ons for companies that need
                receivables, reconciliation, field routes, or multi-entity structure. A Centryk
                advisor sets them up with you — nothing switches on automatically.
            </p>
        </div>
        <?php if ($activeCompany && (in_array('active', $entStates, true) || in_array('suspended', $entStates, true))): ?>
        <a href="business_insights.php?company_id=<?= (int)$activeCompany['id'] ?>"
           class="shrink-0 rounded-xl border border-violet-200 bg-violet-50 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-violet-700 hover:bg-violet-100">
            View insights
        </a>
        <?php endif; ?>
    </div>

    <?php if (!$companies): ?>
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center">
            <p class="text-sm font-bold text-slate-500">You need to be an admin of a company to explore Centryk Business.</p>
        </div>
    <?php else: ?>

        <?php if (count($companies) > 1): ?>
            <div class="mb-5 flex flex-wrap items-center gap-2">
                <span class="text-[11px] font-black uppercase tracking-[0.12em] text-slate-400">Company</span>
                <?php foreach ($companies as $c): ?>
                    <a href="business.php?company_id=<?= (int)$c['id'] ?>"
                       class="rounded-lg border px-3 py-1.5 text-xs font-bold <?= (int)$c['id'] === (int)$activeCompany['id'] ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-slate-200 bg-white text-slate-500 hover:border-violet-200' ?>">
                        <?= htmlspecialchars($c['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div id="alert" class="mb-4 hidden rounded-xl border p-3 text-sm font-semibold"></div>

        <div class="grid gap-4 sm:grid-cols-2">
            <?php foreach ($catalog as $p):
                $key   = $p['key'];
                $state = $entStates[$key] ?? null;
                $req   = $requested[$key] ?? null;
                $icon  = $icons[$key] ?? 'sparkles';
            ?>
            <div class="flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
                        <i data-lucide="<?= htmlspecialchars($icon) ?>" class="h-5 w-5"></i>
                    </div>
                    <?php if ($state === 'active'): ?>
                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-emerald-700">Active</span>
                    <?php elseif ($state === 'suspended'): ?>
                        <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-amber-700">Paused</span>
                    <?php elseif ($req): ?>
                        <span class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-500">Requested</span>
                    <?php endif; ?>
                </div>

                <h2 class="mt-3 text-base font-black"><?= htmlspecialchars($p['label']) ?></h2>
                <p class="mt-1 flex-1 text-sm font-semibold text-slate-500"><?= htmlspecialchars($p['description']) ?></p>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <span class="text-xs font-black uppercase tracking-[0.1em] text-slate-400"><?= $fmtPrice($p) ?></span>
                    <?php
                    $moduleLinks = ['receivables' => ['receivables.php', 'Open ledger'], 'reconciliation' => ['reconciliation.php', 'Open workbench'], 'routes' => ['routes.php', 'Open routes'], 'enterprise' => ['groups.php', 'Open groups']];
                    ?>
                    <?php if ($state === 'active' && isset($moduleLinks[$key])): ?>
                        <a href="<?= $moduleLinks[$key][0] ?>?company_id=<?= (int)$activeCompany['id'] ?>" class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-emerald-700"><?= $moduleLinks[$key][1] ?></a>
                    <?php elseif ($state === 'active'): ?>
                        <span class="text-xs font-bold text-slate-400">On your plan</span>
                    <?php elseif ($state === 'suspended'): ?>
                        <button data-package="<?= htmlspecialchars($key) ?>" class="reqBtn rounded-xl bg-amber-500 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-amber-600">Reactivate</button>
                    <?php elseif ($req): ?>
                        <span class="text-xs font-bold text-slate-400">We'll be in touch</span>
                    <?php else: ?>
                        <button data-package="<?= htmlspecialchars($key) ?>" class="reqBtn rounded-xl bg-violet-600 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-white hover:bg-violet-700">Request access</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (in_array('active', $entStates, true) || in_array('suspended', $entStates, true)): ?>
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <p class="text-sm font-black">Recent activity</p>
                <button id="actRefresh" class="text-xs font-bold text-violet-600 hover:text-violet-800">Refresh</button>
            </div>
            <div id="actRows" class="mt-3 divide-y divide-slate-100 text-sm">
                <p class="py-2 text-xs font-semibold text-slate-400">Loading…</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5">
            <div>
                <p class="text-sm font-black">Not sure what fits?</p>
                <p class="text-xs font-semibold text-slate-500">Tell us about your operation and we'll recommend a starting point.</p>
            </div>
            <?php if (isset($requested[''])): ?>
                <span class="text-xs font-bold text-slate-400">Enquiry received — we'll be in touch</span>
            <?php else: ?>
                <button data-package="" class="reqBtn rounded-xl border border-violet-200 bg-violet-50 px-4 py-2 text-xs font-black uppercase tracking-[0.1em] text-violet-700 hover:bg-violet-100">Talk to us</button>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<script>
if (window.lucide) lucide.createIcons();

const COMPANY_ID = <?= $activeCompany ? (int)$activeCompany['id'] : 'null' ?>;

function showAlert(msg, type) {
    const el = document.getElementById('alert');
    if (!el) return;
    el.textContent = msg;
    el.className = 'mb-4 rounded-xl border p-3 text-sm font-semibold ' + (type === 'error'
        ? 'border-red-200 bg-red-50 text-red-700'
        : 'border-emerald-200 bg-emerald-50 text-emerald-700');
    el.classList.remove('hidden');
}

// ── Recent activity ──────────────────────────────────────────────────────
const MODULE_LABEL = { receivables: 'Receivables', reconciliation: 'Reconciliation', routes: 'Routes', billing: 'Billing', entitlement: 'Access' };
function timeAgo(s){
    const d = new Date((s || '').replace(' ', 'T'));
    if (isNaN(d)) return '';
    const mins = Math.round((Date.now() - d.getTime()) / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return mins + 'm ago';
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return hrs + 'h ago';
    return Math.round(hrs / 24) + 'd ago';
}
async function loadActivity(){
    const box = document.getElementById('actRows');
    if (!box || COMPANY_ID === null) return;
    try {
        const res = await fetch('api/business/activity.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ company_id: COMPANY_ID }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'Could not load activity.');
        const rows = data.activity || [];
        if (!rows.length){ box.innerHTML = '<p class="py-2 text-xs font-semibold text-slate-400">Nothing yet.</p>'; return; }
        box.innerHTML = rows.map(a => `
            <div class="flex items-start gap-3 py-2">
                <span class="mt-0.5 shrink-0 rounded-md bg-violet-50 px-1.5 py-0.5 text-[10px] font-black uppercase tracking-wide text-violet-600">${MODULE_LABEL[a.module] || a.module}</span>
                <span class="min-w-0 flex-1">
                    <span class="block text-xs font-semibold text-slate-700">${(a.summary || '').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}</span>
                    <span class="block text-[11px] font-medium text-slate-400">${a.actor} · ${timeAgo(a.at)}</span>
                </span>
            </div>`).join('');
    } catch (e){ box.innerHTML = '<p class="py-2 text-xs font-semibold text-red-500">' + e.message + '</p>'; }
}
document.getElementById('actRefresh')?.addEventListener('click', loadActivity);
loadActivity();

document.querySelectorAll('.reqBtn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const pkg = btn.dataset.package;
        let message = '';
        if (pkg === '') {
            message = prompt('A line or two about what your company needs (optional):') || '';
            if (message === null) return;
        }
        btn.disabled = true;
        btn.textContent = 'Sending…';
        try {
            const res = await fetch('api/apps/request_package.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ company_id: COMPANY_ID, package_key: pkg, message }),
            });
            const data = await res.json();
            if (!res.ok || data.success !== true) throw new Error(data.message || 'Request failed');
            showAlert(data.message, 'ok');
            const card = btn.closest('.flex-col, .flex-wrap');
            btn.replaceWith(Object.assign(document.createElement('span'), {
                className: 'text-xs font-bold text-slate-400',
                textContent: "We'll be in touch",
            }));
        } catch (e) {
            showAlert(e.message, 'error');
            btn.disabled = false;
            btn.textContent = pkg === '' ? 'Talk to us' : 'Request access';
        }
    });
});
</script>
</body>
</html>
