<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}
if (strcasecmp((string)($user['email'] ?? ''), 'webdevelopment@bhilimited.com') !== 0) {
    header('Location: index.php');
    exit;
}

$pdo = DB::pdo();
$stmt = $pdo->prepare("
    SELECT c.id, c.uuid, c.name, c.logo, cm.role
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.user_id = :uid AND cm.status = 'active' AND c.status = 'active'
    ORDER BY c.name ASC
");
$stmt->execute(['uid' => (int)$user['id']]);
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$companies) {
    http_response_code(403);
    echo 'No company access found.';
    exit;
}

$requestedUuid = trim((string)($_GET['company_uuid'] ?? ''));
$activeCompany = $companies[0];
if ($requestedUuid !== '') {
    foreach ($companies as $company) {
        if ((string)$company['uuid'] === $requestedUuid) {
            $activeCompany = $company;
            break;
        }
    }
}

$pageTitle = 'Advertise';
$headerMaxW = 'max-w-7xl';
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
    <title>Advertise - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>[data-lucide] { display: inline-block; }</style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased text-slate-900">
<?php include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-7xl px-6 py-8">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Advertisement Machine</p>
            <h1 class="mt-1 text-2xl font-black tracking-tight text-slate-900">Advertise Inventory</h1>
            <p class="mt-1 text-sm font-semibold text-slate-500">Publish selected inventory items from <?= htmlspecialchars($activeCompany['name']) ?> to employees or the global company market.</p>
        </div>
        <form method="get" class="flex items-center gap-2">
            <select name="company_uuid" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 shadow-sm outline-none focus:border-violet-500">
                <?php foreach ($companies as $company): ?>
                <option value="<?= htmlspecialchars($company['uuid']) ?>" <?= $company['uuid'] === $activeCompany['uuid'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($company['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button class="rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">Switch</button>
        </form>
    </div>

    <section class="grid gap-5 lg:grid-cols-[0.9fr_1.5fr]">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-100 text-violet-700"><i data-lucide="megaphone" class="h-5 w-5"></i></span>
                <div>
                    <h2 class="text-lg font-black tracking-tight">Campaign Setup</h2>
                    <p class="text-xs font-semibold text-slate-400">Template controls for item publishing.</p>
                </div>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Audience</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-700">
                            <input type="radio" name="audience" checked class="mr-2"> Employees
                        </label>
                        <label class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-700">
                            <input type="radio" name="audience" class="mr-2"> Centryk Market
                        </label>
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Visibility Window</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-violet-500">
                        <input type="date" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-violet-500">
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Message</label>
                    <textarea rows="4" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700 outline-none focus:border-violet-500" placeholder="Short promotional note shown on the Store feed."></textarea>
                </div>
                <a href="store.php" class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-violet-700 transition hover:bg-violet-100">
                    <i data-lucide="store" class="h-4 w-4"></i> Preview Store Feed
                </a>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-black tracking-tight">Inventory Candidates</h2>
                    <p class="text-xs font-semibold text-slate-400">Template list. Later this will pull from OnePay inventory.</p>
                </div>
                <button class="rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white">Publish Selected</button>
            </div>
            <div class="grid gap-3 md:grid-cols-2">
                <?php
                $items = [
                    ['Wireless Barcode Scanner', 'SKU POS-2041', '$185.00', 'Centryk Market', 'bg-cyan-50 text-cyan-700 border-cyan-200'],
                    ['Receipt Printer Roll Pack', 'SKU SUP-1088', '$22.50', 'Employees only', 'bg-violet-50 text-violet-700 border-violet-200'],
                    ['Countertop Card Reader', 'SKU PAY-3300', '$310.00', 'Centryk Market', 'bg-cyan-50 text-cyan-700 border-cyan-200'],
                    ['Thermal Printer', 'SKU POS-7780', '$420.00', 'Employees only', 'bg-violet-50 text-violet-700 border-violet-200'],
                ];
                foreach ($items as $item):
                ?>
                <label class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex items-start gap-3">
                        <input type="checkbox" class="mt-1">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-black text-slate-900"><?= htmlspecialchars($item[0]) ?></p>
                            <p class="mt-0.5 text-xs font-semibold text-slate-400"><?= htmlspecialchars($item[1]) ?></p>
                            <div class="mt-3 flex items-center justify-between gap-2">
                                <span class="text-lg font-black text-slate-900"><?= htmlspecialchars($item[2]) ?></span>
                                <span class="rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] <?= $item[4] ?>"><?= htmlspecialchars($item[3]) ?></span>
                            </div>
                        </div>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>if (window.lucide) { lucide.createIcons(); }</script>
</body>
</html>
