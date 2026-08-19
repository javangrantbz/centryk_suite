<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/services/OnePayCompanyProfile.php';

Auth::start();
$me = AuthService::me();

if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    header('Location: login.php');
    exit;
}

$user = $me['user'];
$pdo  = DB::pdo();

$uuid = trim((string)($_GET['uuid'] ?? ''));
if ($uuid === '') {
    header('Location: registered-companies.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id, uuid, name, logo, status, created_at FROM companies WHERE uuid = :uuid LIMIT 1');
$stmt->execute(['uuid' => $uuid]);
$centrykCompany = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$centrykCompany) {
    header('Location: registered-companies.php');
    exit;
}

$adminStmt = $pdo->prepare("
    SELECT u.first_name, u.last_name, u.email, u.last_login_at
    FROM company_members cm
    JOIN users u ON u.id = cm.user_id
    WHERE cm.company_id = :cid AND cm.role = 'admin' AND cm.status = 'active'
    ORDER BY u.last_login_at DESC
    LIMIT 1
");
$adminStmt->execute(['cid' => $centrykCompany['id']]);
$centrykAdmin = $adminStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$empCountStmt = $pdo->prepare("SELECT COUNT(*) FROM company_members WHERE company_id = :cid AND status = 'active'");
$empCountStmt->execute(['cid' => $centrykCompany['id']]);
$employeeCount = (int)$empCountStmt->fetchColumn();

$profile = OnePayCompanyProfile::fetch($uuid);
$onePayError = OnePayCompanyProfile::lastError();
$hasOnePay = $profile['company'] !== null;

function cp_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function cp_money($v): string { return '$' . number_format((float)$v, 2); }
function cp_date($v): string {
    $v = (string)($v ?? '');
    if ($v === '') return '—';
    $ts = strtotime($v);
    return $ts ? date('M j, Y', $ts) : '—';
}

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();

$pageTitle  = $centrykCompany['name'];
$headerMaxW = 'max-w-6xl';
$awCurrent  = 'centryk';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title><?= cp_h($centrykCompany['name']) ?> - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>[data-lucide] { display: inline-block; }</style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-6xl px-4 pt-1 pb-8">

    <a href="registered-companies.php" class="mb-4 inline-flex items-center gap-1.5 text-xs font-black uppercase tracking-[0.12em] text-slate-500 hover:text-slate-800">
        <i data-lucide="arrow-left" class="h-3.5 w-3.5"></i> Company Registry
    </a>

    <!-- Company header -->
    <div class="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center gap-4 bg-slate-950 px-5 py-5 text-white">
            <?php if (!empty($centrykCompany['logo'])): ?>
            <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-white">
                <img src="<?= cp_h($centrykCompany['logo']) ?>" alt="" class="h-full w-full object-contain p-1.5">
            </div>
            <?php else: ?>
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-sky-500 text-xl font-black text-slate-900">
                <?= cp_h(strtoupper(substr($centrykCompany['name'], 0, 1))) ?>
            </div>
            <?php endif; ?>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-black tracking-tight"><?= cp_h($centrykCompany['name']) ?></h1>
                    <span class="rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] <?= $centrykCompany['status'] === 'active' ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300' : 'border-white/15 bg-white/5 text-white/50' ?>"><?= cp_h($centrykCompany['status']) ?></span>
                    <?php if ($hasOnePay): ?>
                    <span class="rounded-full border border-cyan-400/30 bg-cyan-400/10 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-cyan-300">OnePay Connected</span>
                    <?php endif; ?>
                </div>
                <p class="mt-1 text-xs font-semibold text-white/55">Registered <?= cp_date($centrykCompany['created_at']) ?> · Admin: <?= cp_h(trim(($centrykAdmin['first_name'] ?? '') . ' ' . ($centrykAdmin['last_name'] ?? '')) ?: 'Unnamed') ?> (<?= cp_h($centrykAdmin['email'] ?? '—') ?>)</p>
            </div>
            <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-center">
                <p class="text-lg font-black"><?= $employeeCount ?></p>
                <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-white/40">Centryk Members</p>
            </div>
        </div>
    </div>

    <?php if (!$hasOnePay): ?>
    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-8 text-center">
        <p class="text-sm font-bold text-amber-800">No OnePay data found for this company.</p>
        <p class="mt-1 text-xs font-semibold text-amber-600"><?= cp_h($onePayError ?: 'This company may not be using OnePay yet.') ?></p>
    </div>
    <?php else: ?>

    <!-- Summary tiles -->
    <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">All-Time Sales</p>
            <p class="mt-2 text-xl font-black"><?= cp_money($profile['sales_summary']['all_time']['total']) ?></p>
            <p class="mt-1 text-[11px] font-bold text-slate-400"><?= (int)$profile['sales_summary']['all_time']['count'] ?> transaction(s)</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Last 30 Days</p>
            <p class="mt-2 text-xl font-black text-emerald-600"><?= cp_money($profile['sales_summary']['last_30_days']['total']) ?></p>
            <p class="mt-1 text-[11px] font-bold text-slate-400"><?= (int)$profile['sales_summary']['last_30_days']['count'] ?> transaction(s)</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Inventory Items</p>
            <p class="mt-2 text-xl font-black"><?= count($profile['inventory']['items']) ?></p>
            <p class="mt-1 text-[11px] font-bold text-slate-400"><?= count($profile['inventory']['categories']) ?> categor<?= count($profile['inventory']['categories']) === 1 ? 'y' : 'ies' ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Customers</p>
            <p class="mt-2 text-xl font-black"><?= (int)$profile['customers_count'] ?></p>
            <p class="mt-1 text-[11px] font-bold text-slate-400"><?= count($profile['stores']) ?> store(s)</p>
        </div>
    </div>

    <!-- Staff -->
    <section class="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <h2 class="text-sm font-black tracking-tight">Cashiers &amp; Staff</h2>
            <span class="text-xs font-bold text-slate-400"><?= count($profile['staff']) ?> member(s)<?= $profile['pending_invites'] ? ' · ' . count($profile['pending_invites']) . ' pending invite(s)' : '' ?></span>
        </div>
        <div class="overflow-x-auto">
        <div class="min-w-[600px]">
            <div class="grid grid-cols-[1.3fr_1.5fr_1fr_1fr_0.9fr] gap-3 bg-slate-50 px-5 py-2.5 text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">
                <span>Name</span><span>Email</span><span>Role</span><span>Store</span><span>Status</span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php if (!$profile['staff']): ?>
                <div class="px-5 py-6 text-center text-sm font-semibold text-slate-400">No staff added yet.</div>
                <?php endif; ?>
                <?php foreach ($profile['staff'] as $s): ?>
                <div class="grid grid-cols-[1.3fr_1.5fr_1fr_1fr_0.9fr] gap-3 px-5 py-3 text-sm">
                    <span class="truncate font-bold text-slate-800"><?= cp_h(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))) ?></span>
                    <span class="truncate text-slate-500"><?= cp_h($s['email'] ?? '') ?></span>
                    <span class="truncate text-slate-600"><?= cp_h($s['role_labels'] ?: '—') ?></span>
                    <span class="truncate text-slate-500"><?= cp_h($s['store_name'] ?? '') ?></span>
                    <span class="text-[10px] font-black uppercase tracking-[0.1em] <?= $s['status'] === 'active' ? 'text-emerald-600' : 'text-slate-400' ?>"><?= cp_h($s['status']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php foreach ($profile['pending_invites'] as $inv): ?>
                <div class="grid grid-cols-[1.3fr_1.5fr_1fr_1fr_0.9fr] gap-3 bg-amber-50/40 px-5 py-3 text-sm">
                    <span class="truncate font-bold text-slate-500 italic">Pending invite</span>
                    <span class="truncate text-slate-500"><?= cp_h($inv['email'] ?? '') ?></span>
                    <span class="truncate text-slate-400">—</span>
                    <span class="truncate text-slate-500"><?= cp_h($inv['store_name'] ?? '') ?></span>
                    <span class="text-[10px] font-black uppercase tracking-[0.1em] text-amber-600">Invited</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        </div>
    </section>

    <!-- Inventory -->
    <section class="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <h2 class="text-sm font-black tracking-tight">Inventory</h2>
            <span class="text-xs font-bold text-slate-400"><?= count($profile['inventory']['items']) ?> item(s)</span>
        </div>
        <div class="overflow-x-auto">
        <div class="min-w-[650px]">
            <div class="grid grid-cols-[1.6fr_0.9fr_0.8fr_0.7fr_0.8fr_0.8fr] gap-3 bg-slate-50 px-5 py-2.5 text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">
                <span>Item</span><span>SKU</span><span>Category</span><span>Type</span><span>Price</span><span>Stock</span>
            </div>
            <div class="divide-y divide-slate-100 max-h-[420px] overflow-y-auto">
                <?php if (!$profile['inventory']['items']): ?>
                <div class="px-5 py-6 text-center text-sm font-semibold text-slate-400">No inventory items yet.</div>
                <?php endif; ?>
                <?php foreach ($profile['inventory']['items'] as $it): ?>
                <div class="grid grid-cols-[1.6fr_0.9fr_0.8fr_0.7fr_0.8fr_0.8fr] gap-3 px-5 py-3 text-sm <?= empty($it['active']) ? 'opacity-50' : '' ?>">
                    <span class="truncate font-bold text-slate-800"><?= cp_h($it['name'] ?? '') ?></span>
                    <span class="truncate text-slate-500"><?= cp_h($it['sku'] ?: '—') ?></span>
                    <span class="truncate text-slate-500"><?= cp_h($it['category_name'] ?: '—') ?></span>
                    <span class="truncate text-slate-500 capitalize"><?= cp_h($it['item_type'] ?? '') ?></span>
                    <span class="font-bold text-slate-700"><?= cp_money($it['price'] ?? 0) ?></span>
                    <span class="text-slate-500"><?= !empty($it['track_inventory']) ? cp_h($it['stock_qty']) : '—' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        </div>
    </section>

    <!-- Promotions -->
    <section class="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <h2 class="text-sm font-black tracking-tight">Promotions</h2>
            <span class="text-xs font-bold text-slate-400"><?= count($profile['promotions']) ?> rule(s)</span>
        </div>
        <div class="overflow-x-auto">
        <div class="min-w-[650px]">
            <div class="grid grid-cols-[1.4fr_0.9fr_1fr_0.9fr_0.9fr_0.8fr] gap-3 bg-slate-50 px-5 py-2.5 text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">
                <span>Name</span><span>Code</span><span>Type</span><span>Value</span><span>Window</span><span>Status</span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php if (!$profile['promotions']): ?>
                <div class="px-5 py-6 text-center text-sm font-semibold text-slate-400">No promotions set up.</div>
                <?php endif; ?>
                <?php foreach ($profile['promotions'] as $p): ?>
                <div class="grid grid-cols-[1.4fr_0.9fr_1fr_0.9fr_0.9fr_0.8fr] gap-3 px-5 py-3 text-sm">
                    <span class="truncate font-bold text-slate-800"><?= cp_h($p['name'] ?? '') ?></span>
                    <span class="truncate text-slate-500 font-mono text-xs"><?= cp_h($p['promo_code'] ?: '—') ?></span>
                    <span class="truncate text-slate-500"><?= cp_h(str_replace('_', ' ', $p['promo_type'] ?? '')) ?></span>
                    <span class="text-slate-600"><?= cp_h($p['discount_value'] ?? '0') ?></span>
                    <span class="truncate text-[11px] text-slate-400"><?= cp_date($p['starts_at'] ?? null) ?> – <?= cp_date($p['ends_at'] ?? null) ?></span>
                    <span class="text-[10px] font-black uppercase tracking-[0.1em] <?= !empty($p['active']) ? 'text-emerald-600' : 'text-slate-400' ?>"><?= !empty($p['active']) ? 'Active' : 'Inactive' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        </div>
    </section>

    <!-- Registers & Tables -->
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h2 class="text-sm font-black tracking-tight">Registers</h2>
                <span class="text-xs font-bold text-slate-400"><?= count($profile['registers']) ?></span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php if (!$profile['registers']): ?>
                <div class="px-5 py-6 text-center text-sm font-semibold text-slate-400">No registers configured.</div>
                <?php endif; ?>
                <?php foreach ($profile['registers'] as $r): ?>
                <div class="flex items-center justify-between px-5 py-3 text-sm">
                    <div class="min-w-0">
                        <p class="truncate font-bold text-slate-800"><?= cp_h($r['name'] ?? '') ?><?= !empty($r['is_main']) ? ' <span class="text-[10px] font-black uppercase text-violet-600">Main</span>' : '' ?></p>
                        <p class="truncate text-xs text-slate-400"><?= cp_h($r['store_name'] ?? '') ?> · <?= cp_h($r['register_code'] ?: '—') ?></p>
                    </div>
                    <span class="shrink-0 text-[10px] font-black uppercase tracking-[0.1em] <?= !empty($r['active']) ? 'text-emerald-600' : 'text-slate-400' ?>"><?= !empty($r['active']) ? 'Active' : 'Inactive' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h2 class="text-sm font-black tracking-tight">Restaurant Tables</h2>
                <span class="text-xs font-bold text-slate-400"><?= count($profile['restaurant_tables']) ?></span>
            </div>
            <div class="divide-y divide-slate-100">
                <?php if (!$profile['restaurant_tables']): ?>
                <div class="px-5 py-6 text-center text-sm font-semibold text-slate-400">Not a restaurant setup, or no tables configured.</div>
                <?php endif; ?>
                <?php foreach ($profile['restaurant_tables'] as $t): ?>
                <div class="flex items-center justify-between px-5 py-3 text-sm">
                    <div class="min-w-0">
                        <p class="truncate font-bold text-slate-800">Table <?= cp_h($t['label'] ?? '') ?></p>
                        <p class="truncate text-xs text-slate-400"><?= cp_h($t['store_name'] ?? '') ?><?= !empty($t['section']) ? ' · ' . cp_h($t['section']) : '' ?><?= !empty($t['seats']) ? ' · ' . (int)$t['seats'] . ' seats' : '' ?></p>
                    </div>
                    <span class="shrink-0 text-[10px] font-black uppercase tracking-[0.1em] <?= $t['status'] === 'available' ? 'text-emerald-600' : 'text-amber-600' ?>"><?= cp_h(str_replace('_', ' ', $t['status'] ?? '')) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <?php endif; ?>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>if (window.lucide) { lucide.createIcons(); }</script>
</body>
</html>
