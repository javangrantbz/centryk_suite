<?php
// Waffle app switcher. Set before include (both optional):
//   $awAlign = 'left' | 'right'   — dropdown alignment (default 'left')
//   $awMode  = 'links' | 'launch' — 'launch' fires the in-app launcher (logged-in dashboard);
//                                    'links' points apps at the about page (default)
$awAlign = $awAlign ?? 'left';
$awMode  = $awMode  ?? 'links';
$awPos   = $awAlign === 'right' ? 'right-0' : 'left-0';
?>
<div class="relative shrink-0" id="appSwitcherWrapper">
    <button id="appSwitcherBtn" aria-label="App switcher" class="flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
            <rect x="3"  y="3"  width="4" height="4" rx="1"/><rect x="10" y="3"  width="4" height="4" rx="1"/><rect x="17" y="3"  width="4" height="4" rx="1"/>
            <rect x="3"  y="10" width="4" height="4" rx="1"/><rect x="10" y="10" width="4" height="4" rx="1"/><rect x="17" y="10" width="4" height="4" rx="1"/>
            <rect x="3"  y="17" width="4" height="4" rx="1"/><rect x="10" y="17" width="4" height="4" rx="1"/><rect x="17" y="17" width="4" height="4" rx="1"/>
        </svg>
    </button>
    <div id="appSwitcherDropdown" class="hidden absolute <?= $awPos ?> top-full mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl z-50">
        <p class="mb-3 text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Switch App</p>
        <div class="grid grid-cols-3 gap-2">

            <!-- Centryk (current) -->
            <div class="flex flex-col items-center gap-2 rounded-xl p-3 text-center bg-slate-100 ring-1 ring-slate-200 cursor-default">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white shadow-sm">
                    <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                </span>
                <span class="text-xs font-semibold text-slate-700">Centryk</span>
            </div>

            <?php if ($awMode === 'launch'): ?>
            <!-- OnePay -->
            <button type="button" data-app="onepay" class="aw-app flex flex-col items-center gap-2 rounded-xl p-3 text-center transition hover:bg-slate-50">
                <svg viewBox="0 0 32 32" class="h-12 w-12 shrink-0"><circle cx="16" cy="16" r="16" fill="#7c3aed"/><path d="M16 8l2.5 7.5H26l-6 4.5 2.5 7.5-6-4.5-6 4.5 2.5-7.5-6-4.5h7.5L16 8z" fill="white"/></svg>
                <span class="text-xs font-medium text-slate-700">OnePay</span>
            </button>
            <!-- MyPay -->
            <button type="button" data-app="mypay" class="aw-app flex flex-col items-center gap-2 rounded-xl p-3 text-center transition hover:bg-slate-50">
                <img src="assets/myPay.png" alt="MyPay" class="h-12 w-12 rounded-2xl object-contain shadow-sm">
                <span class="text-xs font-medium text-slate-700">MyPay</span>
            </button>
            <?php else: ?>
            <!-- OnePay -->
            <a href="about.php#onepay" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center transition hover:bg-slate-50">
                <svg viewBox="0 0 32 32" class="h-12 w-12 shrink-0"><circle cx="16" cy="16" r="16" fill="#7c3aed"/><path d="M16 8l2.5 7.5H26l-6 4.5 2.5 7.5-6-4.5-6 4.5 2.5-7.5-6-4.5h7.5L16 8z" fill="white"/></svg>
                <span class="text-xs font-medium text-slate-700">OnePay</span>
            </a>
            <!-- MyPay -->
            <a href="about.php#mypay" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center transition hover:bg-slate-50">
                <img src="assets/myPay.png" alt="MyPay" class="h-12 w-12 rounded-2xl object-contain shadow-sm">
                <span class="text-xs font-medium text-slate-700">MyPay</span>
            </a>
            <?php endif; ?>

        </div>
    </div>
</div>
