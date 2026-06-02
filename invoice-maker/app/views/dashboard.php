<?php
$companyId = current_company_id();

$totalCustomers = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE company_id = ?");
$totalCustomers->execute([$companyId]);

$totalInvoices = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE company_id = ?");
$totalInvoices->execute([$companyId]);

$totalQuotes = $pdo->prepare("SELECT COUNT(*) FROM quotes WHERE company_id = ?");
$totalQuotes->execute([$companyId]);

$outstanding = $pdo->prepare("
    SELECT COALESCE(SUM(total - amount_paid), 0)
    FROM invoices
    WHERE company_id = ? AND status != 'paid'
");
$outstanding->execute([$companyId]);
?>

<div class="mb-8">
    <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight italic">Overview</h2>
    <p class="text-xs font-bold text-slate-400 uppercase tracking-[0.2em] mt-1">Snapshot of your business performance</p>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
    <div class="bg-white p-6 rounded-[2.5rem] border border-gray-100 shadow-sm hover:shadow-xl hover:shadow-emerald-900/5 transition-all group">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 rounded-2xl bg-emerald-50 text-emerald-600 transition-colors group-hover:bg-emerald-600 group-hover:text-white">
                <i data-lucide="users" class="w-5 h-5"></i>
            </div>
        </div>
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Clients</p>
        <h3 class="text-3xl font-black text-slate-900 mt-1"><?= $totalCustomers->fetchColumn() ?></h3>
    </div>

    <div class="bg-white p-6 rounded-[2.5rem] border border-gray-100 shadow-sm hover:shadow-xl hover:shadow-blue-900/5 transition-all group">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 rounded-2xl bg-blue-50 text-blue-600 transition-colors group-hover:bg-blue-600 group-hover:text-white">
                <i data-lucide="file-text" class="w-5 h-5"></i>
            </div>
        </div>
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Invoices</p>
        <h3 class="text-3xl font-black text-slate-900 mt-1"><?= $totalInvoices->fetchColumn() ?></h3>
    </div>

    <div class="bg-white p-6 rounded-[2.5rem] border border-gray-100 shadow-sm hover:shadow-xl hover:shadow-amber-900/5 transition-all group">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 rounded-2xl bg-amber-50 text-amber-600 transition-colors group-hover:bg-amber-600 group-hover:text-white">
                <i data-lucide="clipboard-list" class="w-5 h-5"></i>
            </div>
        </div>
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Quotes</p>
        <h3 class="text-3xl font-black text-slate-900 mt-1"><?= $totalQuotes->fetchColumn() ?></h3>
    </div>

    <div class="bg-[#1a1a1a] p-6 rounded-[2.5rem] border border-gray-800 shadow-2xl shadow-gray-200 transition-all hover:scale-[1.02]">
        <div class="flex items-center justify-between mb-4">
            <div class="p-3 rounded-2xl bg-emerald-500/10 text-emerald-400">
                <i data-lucide="dollar-sign" class="w-5 h-5"></i>
            </div>
        </div>
        <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">Balance</p>
        <h3 class="text-2xl font-black text-white mt-1"><?= money($outstanding->fetchColumn()) ?></h3>
    </div>
</div>

<div class="mt-12 grid grid-cols-1 lg:grid-cols-2 gap-8">
    <div class="bg-white p-8 rounded-[3rem] border border-gray-100 shadow-sm">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-black text-slate-900">Quick Actions</h3>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <a href="<?= BASE_URL ?>/?page=invoices-create" class="flex items-center p-4 rounded-3xl bg-gray-50 hover:bg-emerald-50 text-slate-600 hover:text-emerald-700 transition-all group">
                <div class="p-2 rounded-xl bg-white shadow-sm mr-3 group-hover:bg-emerald-600 group-hover:text-white transition-all">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                </div>
                <span class="text-sm font-bold">New Invoice</span>
            </a>
            <a href="<?= BASE_URL ?>/?page=quotes-create" class="flex items-center p-4 rounded-3xl bg-gray-50 hover:bg-emerald-50 text-slate-600 hover:text-emerald-700 transition-all group">
                <div class="p-2 rounded-xl bg-white shadow-sm mr-3 group-hover:bg-emerald-600 group-hover:text-white transition-all">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                </div>
                <span class="text-sm font-bold">New Quote</span>
            </a>
        </div>
    </div>
    
    <div class="bg-emerald-600 p-8 rounded-[3rem] shadow-xl shadow-emerald-100 text-white flex flex-col justify-center">
        <h3 class="text-2xl font-black mb-2">Welcome back, <?= explode(' ', e(current_user()['name']))[0] ?>!</h3>
        <p class="text-emerald-100 text-sm leading-relaxed font-medium">Your business is growing. You have outstanding payments to collect today.</p>
        <div class="mt-6">
            <a href="<?= BASE_URL ?>/?page=invoices" class="inline-flex items-center bg-white text-emerald-600 px-6 py-3 rounded-2xl font-black text-xs uppercase tracking-widest hover:bg-emerald-50 transition-colors">
                View Invoices <i data-lucide="arrow-right" class="w-4 h-4 ml-2"></i>
            </a>
        </div>
    </div>
</div>
