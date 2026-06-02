<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
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
<body class="bg-[#f8fafc] text-slate-900 overflow-hidden">

<div class="flex h-screen overflow-hidden">
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
            <div class="relative dropdown group">
                <button class="flex items-center space-x-4 hover:bg-gray-50 p-2.5 rounded-2xl transition-all border border-transparent hover:border-gray-100">
                    <div class="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center font-black text-sm shadow-lg shadow-emerald-100">
                        <?= strtoupper(substr(current_user()['name'], 0, 1)) ?>
                    </div>
                    <div class="text-left hidden sm:block">
                        <p class="text-sm font-black text-slate-900 leading-none mb-1"><?= e(current_user()['name']) ?></p>
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest"><?= e(current_user()['email']) ?></p>
                    </div>
                    <i data-lucide="chevron-down" class="w-4 h-4 text-gray-300"></i>
                </button>
                
                <div class="dropdown-menu hidden absolute left-0 mt-3 w-64 bg-white rounded-[2rem] shadow-2xl border border-gray-100 p-3 z-50 animate-in fade-in slide-in-from-top-2 duration-200">
                    <div class="px-4 py-4 border-b border-gray-50 mb-3">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-1">Account Active</p>
                        <p class="text-xs font-bold text-slate-600 truncate"><?= e(current_user()['email']) ?></p>
                    </div>
                    
                    <a href="<?= BASE_URL ?>/logout.php" class="flex items-center justify-center space-x-3 w-full bg-red-50 text-red-600 hover:bg-red-600 hover:text-white p-4 rounded-2xl transition-all font-black text-sm group/logout">
                        <i data-lucide="log-out" class="w-5 h-5 group-hover/logout:translate-x-1 transition-transform"></i>
                        <span>LOGOUT SESSION</span>
                    </a>
                </div>
            </div>

            <a href="<?= BASE_URL ?>/?page=dashboard" class="flex items-center text-[#1a1a1a] hover:text-emerald-600 transition-all group">
                <span class="text-2xl font-black tracking-tighter italic group-hover:scale-105 transition-transform"><?= APP_NAME ?></span>
            </a>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto custom-scrollbar p-6 lg:p-10">
            <div class="max-w-5xl mx-auto h-full">
