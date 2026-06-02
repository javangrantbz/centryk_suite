<?php
// Suite header data: the user's enrolled apps (for the waffle) + active company uuid.
$invHdrApps = [];
$invHdrUuid = '';
$__hdrUid = (int)($_SESSION['user']['id'] ?? 0);
if ($__hdrUid) {
    $__hs = $pdo->prepare("SELECT a.`key`, a.label, a.color, a.icon
                           FROM apps a JOIN user_app_access ua ON ua.app_id = a.id
                           WHERE ua.user_id = :u AND a.status = 'active' ORDER BY a.sort_order");
    $__hs->execute(['u' => $__hdrUid]);
    $invHdrApps = $__hs->fetchAll(PDO::FETCH_ASSOC);
}
if (function_exists('current_company_id') && current_company_id()) {
    $__cs = $pdo->prepare("SELECT uuid FROM companies WHERE id = ?");
    $__cs->execute([current_company_id()]);
    $invHdrUuid = (string)($__cs->fetchColumn() ?: '');
}
$invSwitchQs = $invHdrUuid !== '' ? '&company_uuid=' . urlencode($invHdrUuid) : '';
$invCalQs    = $invHdrUuid !== '' ? '?company_uuid=' . urlencode($invHdrUuid) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <link rel="icon" type="image/svg+xml" href="<?= CENTRYK_BASE ?>/favicon.svg">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .dropdown:hover .dropdown-menu { display: block; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
    </style>
</head>
<body class="bg-[#f8fafc] text-slate-900 overflow-hidden font-sans">

<!-- Centryk accent bar -->
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>

<div class="flex overflow-hidden" style="height: calc(100vh - 3px)">
    <!-- Slim Sidebar Navigation -->
    <aside class="w-20 lg:w-24 bg-[#1a1a1a] flex flex-col items-center py-8 flex-shrink-0 z-50">
        <div class="mb-10 text-emerald-500">
            <i data-lucide="layout-grid" class="w-8 h-8"></i>
        </div>
        
        <nav class="flex-1 flex flex-col space-y-6">
            <a href="<?= BASE_URL ?>/?page=customers" class="p-4 rounded-2xl transition-all group <?= active('customers') ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-900/50' : 'text-gray-500 hover:text-white hover:bg-white/5' ?>" title="Customers">
                <i data-lucide="users" class="w-6 h-6"></i>
            </a>
            <a href="<?= BASE_URL ?>/?page=quotes" class="p-4 rounded-2xl transition-all group <?= active('quotes') ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-900/50' : 'text-gray-500 hover:text-white hover:bg-white/5' ?>" title="Quotes">
                <i data-lucide="clipboard-list" class="w-6 h-6"></i>
            </a>
            <a href="<?= BASE_URL ?>/?page=invoices" class="p-4 rounded-2xl transition-all group <?= active('invoices') ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-900/50' : 'text-gray-500 hover:text-white hover:bg-white/5' ?>" title="Invoices">
                <i data-lucide="receipt" class="w-6 h-6"></i>
            </a>
            <a href="<?= BASE_URL ?>/?page=documents" class="p-4 rounded-2xl transition-all group <?= active('documents') ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-900/50' : 'text-gray-500 hover:text-white hover:bg-white/5' ?>" title="Documents">
                <i data-lucide="folder" class="w-6 h-6"></i>
            </a>
            <a href="<?= BASE_URL ?>/?page=settings" class="p-4 rounded-2xl transition-all group <?= active('settings') ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-900/50' : 'text-gray-500 hover:text-white hover:bg-white/5' ?>" title="Settings">
                <i data-lucide="settings" class="w-6 h-6"></i>
            </a>
        </nav>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 bg-[#f8fafc]">
        <!-- Top Bar (Made Taller - "The Car") -->
        <header class="h-20 bg-white border-b border-gray-200 flex items-center justify-between px-8 flex-shrink-0 shadow-sm z-40">
            <!-- Brand -->
            <a href="<?= BASE_URL ?>/?page=dashboard" class="flex items-center text-[#1a1a1a] hover:text-emerald-600 transition-all group">
                <span class="text-2xl font-black tracking-tighter italic group-hover:scale-105 transition-transform"><?= APP_NAME ?></span>
            </a>

            <!-- Suite cluster: calendar · waffle · account -->
            <div class="flex items-center gap-1.5">

                <!-- Calendar -->
                <a href="<?= CENTRYK_BASE ?>/calendar.php<?= $invCalQs ?>" title="Calendar"
                   class="w-10 h-10 flex items-center justify-center rounded-xl text-slate-500 hover:bg-teal-50 hover:text-teal-600 transition">
                    <i data-lucide="calendar" class="w-5 h-5"></i>
                </a>

                <!-- Waffle app switcher -->
                <div class="relative dropdown group">
                    <button class="w-10 h-10 flex items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 transition" title="Switch app">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <rect x="3" y="3" width="4" height="4" rx="1"/><rect x="10" y="3" width="4" height="4" rx="1"/><rect x="17" y="3" width="4" height="4" rx="1"/>
                            <rect x="3" y="10" width="4" height="4" rx="1"/><rect x="10" y="10" width="4" height="4" rx="1"/><rect x="17" y="10" width="4" height="4" rx="1"/>
                            <rect x="3" y="17" width="4" height="4" rx="1"/><rect x="10" y="17" width="4" height="4" rx="1"/><rect x="17" y="17" width="4" height="4" rx="1"/>
                        </svg>
                    </button>
                    <div class="dropdown-menu hidden absolute right-0 mt-3 w-72 bg-white rounded-2xl shadow-2xl border border-gray-100 p-4 z-50">
                        <p class="mb-3 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Switch App</p>
                        <div class="grid grid-cols-3 gap-2">
                            <!-- Account -->
                            <a href="<?= CENTRYK_BASE ?>/profile.php" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white shadow-sm">
                                    <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                                </span>
                                <span class="text-xs font-medium text-slate-700">Account</span>
                            </a>
                            <?php foreach ($invHdrApps as $a): $k = (string)$a['key']; if ($k === 'centryk') continue; ?>
                                <?php if ($k === 'invoice'): ?>
                                <div class="flex flex-col items-center gap-2 rounded-xl p-3 text-center bg-slate-100 ring-1 ring-slate-200 cursor-default">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl text-2xl shadow-sm" style="background:<?= e($a['color']) ?>18"><?= e($a['icon']) ?></span>
                                    <span class="text-xs font-semibold text-slate-700"><?= e($a['label']) ?></span>
                                </div>
                                <?php else: ?>
                                <a href="<?= CENTRYK_BASE ?>/switch.php?app=<?= urlencode($k) . $invSwitchQs ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center hover:bg-slate-50 transition">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl text-2xl shadow-sm" style="background:<?= e($a['color']) ?>18"><?= e($a['icon']) ?></span>
                                    <span class="text-xs font-medium text-slate-700"><?= e($a['label']) ?></span>
                                </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Account -->
                <div class="relative dropdown group">
                    <button class="flex items-center gap-2 hover:bg-gray-50 p-1.5 rounded-xl transition border border-transparent hover:border-gray-100">
                        <div class="w-9 h-9 rounded-lg bg-emerald-600 text-white flex items-center justify-center font-black text-sm shadow shadow-emerald-100"><?= strtoupper(substr(current_user()['name'], 0, 1)) ?></div>
                        <i data-lucide="chevron-down" class="w-4 h-4 text-gray-300"></i>
                    </button>
                    <div class="dropdown-menu hidden absolute right-0 mt-3 w-64 bg-white rounded-2xl shadow-2xl border border-gray-100 p-3 z-50">
                        <div class="px-3 py-3 border-b border-gray-50 mb-2">
                            <p class="text-sm font-bold text-slate-800 truncate"><?= e(current_user()['name']) ?></p>
                            <p class="text-xs text-gray-400 truncate"><?= e(current_user()['email']) ?></p>
                        </div>
                        <a href="<?= CENTRYK_BASE ?>/profile.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 transition">
                            <i data-lucide="user-cog" class="w-4 h-4"></i> Manage your Centryk Account
                        </a>
                        <a href="<?= BASE_URL ?>/logout.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-semibold text-red-600 hover:bg-red-50 transition">
                            <i data-lucide="log-out" class="w-4 h-4"></i> Sign out
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto custom-scrollbar p-6 lg:p-10">
            <div class="max-w-5xl mx-auto h-full">
