<?php if (!empty($user['is_admin'])): ?>
<div class="relative hidden sm:block" id="adminToolsWrapper">
    <button id="adminToolsBtn" type="button" class="flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
        <i data-lucide="shield-check" class="h-3.5 w-3.5"></i>
        Admin Tools
        <i data-lucide="chevron-down" class="h-3.5 w-3.5"></i>
    </button>
    <div id="adminToolsMenu" class="absolute right-0 top-full z-50 mt-2 hidden w-64 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl">
        <a href="requests.php" class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
            <i data-lucide="users" class="h-4 w-4 shrink-0"></i> New Users
        </a>
        <a href="registered-companies.php" class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
            <i data-lucide="building-2" class="h-4 w-4 shrink-0"></i> Companies
        </a>
        <a href="admin-business-roadmap.php" class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
            <i data-lucide="briefcase" class="h-4 w-4 shrink-0"></i> Centryk Business
        </a>
        <a href="onelink-api-accounts.php" class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
            <i data-lucide="credit-card" class="h-4 w-4 shrink-0"></i> OneLink API Accounts
        </a>
        <a href="admin-holidays.php" class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
            <i data-lucide="flag" class="h-4 w-4 shrink-0"></i> Public Holidays
        </a>
        <a href="admin-fiscal-invoicing.php" class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
            <i data-lucide="file-check-2" class="h-4 w-4 shrink-0"></i> BTS E-Invoicing
        </a>
        <a href="audit.php" class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
            <i data-lucide="history" class="h-4 w-4 shrink-0"></i> Audit Trail
        </a>
        <a href="visionboard-links.php" class="flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
            <i data-lucide="monitor-play" class="h-4 w-4 shrink-0"></i> Vision Board Links
        </a>
    </div>
</div>
<?php endif; ?>
