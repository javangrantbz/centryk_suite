<?php
// Waffle app switcher. Set before include (both optional):
//   $awAlign = 'left' | 'right'   — dropdown alignment (default 'left')
//   $awMode  = 'links' | 'launch' — 'launch' fires the in-app launcher (logged-in dashboard);
//                                    'links' points apps at the about page (default)
//
// In 'launch' mode the dropdown lists the user's *enrolled* apps from
// AuthService::allAppsWithEnrollment(). If the caller already populated
// $apps in scope (the dashboard does), that's used directly; otherwise
// the partial queries it itself.
$awAlign   = $awAlign   ?? 'left';
$awMode    = $awMode    ?? 'links';
$awCurrent = $awCurrent ?? 'centryk'; // which app key is the current page
$awPos     = $awAlign === 'right' ? 'right-0' : 'left-0';
$awComingSoonAppKeys = ['store' => true, 'visionboard' => true];

if ($awMode === 'launch' && !isset($apps)) {
    if (class_exists('Auth') && class_exists('AuthService')) {
        $_swUser = Auth::user();
        if ($_swUser) {
            $apps = AuthService::allAppsWithEnrollment((int)$_swUser['id']);
        }
    }
}

// Canonical icon for a known app key, falling back to a colored letter tile.
$awTileIcon = function (string $key, string $color = '', string $label = '') {
    if ($key === 'onepay') {
        return '<span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-purple-50 text-purple-700 ring-1 ring-purple-100">'
             . '<svg viewBox="0 0 32 32" class="h-7 w-7"><path d="M16 8l2.5 7.5H26l-6 4.5 2.5 7.5-6-4.5-6 4.5 2.5-7.5-6-4.5h7.5L16 8z" fill="currentColor"/></svg>'
             . '</span>';
    }
    if ($key === 'mypay') {
        return '<span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-orange-50 ring-1 ring-orange-100">'
             . '<img src="assets/myPay.png" alt="MyPay" class="h-8 w-8 object-contain">'
             . '</span>';
    }
    if ($key === 'calendar') {
        $bg = htmlspecialchars($color ?: '#14b8a6');
        return '<span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-50 ring-1 ring-teal-100" style="color:' . $bg . '">'
             . '<svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">'
             . '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'
             . '</svg></span>';
    }
    if ($key === 'invoice') {
        $bg = htmlspecialchars($color ?: '#6366f1');
        return '<span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 ring-1 ring-emerald-100" style="color:' . $bg . '">'
             . '<svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">'
             . '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>'
             . '</svg></span>';
    }
    if ($key === 'store') {
        return '<span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-700 ring-1 ring-violet-100">'
             . '<svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
             . '<path d="M3 9l1.5-5h15L21 9"/><path d="M5 9v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9"/><path d="M9 21v-7h6v7"/><path d="M3 9h18"/>'
             . '</svg></span>';
    }
    // Generic fallback — colored square with the first letter
    $bg     = htmlspecialchars($color ?: '#475569');
    $letter = htmlspecialchars(strtoupper(substr($label ?: $key, 0, 1)));
    return '<span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-50 text-lg font-black ring-1 ring-slate-100" style="color:' . $bg . '">' . $letter . '</span>';
};
?>
<div class="relative shrink-0" id="appSwitcherWrapper">
    <button id="appSwitcherBtn" aria-label="App switcher" class="flex h-9 items-center gap-2 rounded-xl px-2.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
            <rect x="3"  y="3"  width="4" height="4" rx="1"/><rect x="10" y="3"  width="4" height="4" rx="1"/><rect x="17" y="3"  width="4" height="4" rx="1"/>
            <rect x="3"  y="10" width="4" height="4" rx="1"/><rect x="10" y="10" width="4" height="4" rx="1"/><rect x="17" y="10" width="4" height="4" rx="1"/>
            <rect x="3"  y="17" width="4" height="4" rx="1"/><rect x="10" y="17" width="4" height="4" rx="1"/><rect x="17" y="17" width="4" height="4" rx="1"/>
        </svg>
        <span class="hidden text-xs font-black uppercase tracking-[0.12em] md:inline">Apps</span>
    </button>
    <div id="appSwitcherDropdown" class="hidden absolute <?= $awPos ?> top-full mt-2 w-80 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl shadow-slate-900/10 z-50">
        <div class="mb-4">
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Centryk Apps</p>
            <p class="mt-1 text-xs font-semibold text-slate-500">One login for every tool.</p>
        </div>
        <div class="grid grid-cols-3 gap-2">

            <!-- Account: always opens the Centryk account hub (profile) -->
            <?php $awCentrykIcon = '<svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>';
            $awOnAccount = ($awCurrent === 'account'); ?>
            <a href="profile.php" class="flex flex-col items-center gap-2 rounded-xl border p-3 text-center transition <?= $awOnAccount ? 'border-slate-300 bg-slate-100 ring-1 ring-slate-200' : 'border-slate-200 bg-slate-50/70 hover:border-slate-300 hover:bg-slate-100' ?>">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white shadow-sm" style="color:#fff"><?= $awCentrykIcon ?></span>
                <span class="text-xs <?= $awOnAccount ? 'font-semibold' : 'font-medium' ?> text-slate-700">Account</span>
            </a>
        </div>

        <div class="mt-3 border-t border-slate-100 pt-3">
            <div class="grid grid-cols-3 gap-2">

            <?php if ($awMode === 'launch' && !empty($apps)): ?>
                <?php foreach ($apps as $app):
                    if (empty($app['enrolled'])) continue;          // only enrolled apps
                    if (($app['key'] ?? '') === 'centryk') continue; // centryk shown above
                    $k = (string)$app['key'];
                    if (isset($awComingSoonAppKeys[$k])) continue;
                ?>
                <?php if ($k === $awCurrent): ?>
                <div class="flex flex-col items-center gap-2 rounded-xl border border-slate-300 bg-slate-100 p-3 text-center ring-1 ring-slate-200 cursor-default">
                    <?= $awTileIcon($k, (string)($app['color'] ?? ''), (string)($app['label'] ?? '')) ?>
                    <span class="text-xs font-semibold text-slate-700"><?= htmlspecialchars($app['label']) ?></span>
                </div>
                <?php else: ?>
                <button type="button" data-app="<?= htmlspecialchars($k) ?>" class="aw-app flex flex-col items-center gap-2 rounded-xl border border-slate-200 bg-white p-3 text-center transition hover:border-slate-300 hover:bg-slate-50">
                    <?= $awTileIcon($k, (string)($app['color'] ?? ''), (string)($app['label'] ?? '')) ?>
                    <span class="text-xs font-medium text-slate-700"><?= htmlspecialchars($app['label']) ?></span>
                </button>
                <?php endif; ?>
                <?php endforeach; ?>
            <?php elseif ($awMode === 'links'): ?>
                <?php
                $awMarketingApps = [
                    ['key' => 'onepay',   'label' => 'OnePay',   'href' => 'about.php#onepay'],
                    ['key' => 'mypay',    'label' => 'MyPay',    'href' => 'about.php#mypay'],
                    ['key' => 'calendar', 'label' => 'Calendar', 'href' => 'login.php#request', 'color' => '#14b8a6'],
                    ['key' => 'invoice',  'label' => 'Invoices', 'href' => 'login.php#request', 'color' => '#10b981'],
                ];
                foreach ($awMarketingApps as $awApp):
                ?>
                <a href="<?= htmlspecialchars($awApp['href']) ?>" class="flex flex-col items-center gap-2 rounded-xl border border-slate-200 bg-white p-3 text-center transition hover:border-slate-300 hover:bg-slate-50">
                    <?= $awTileIcon((string)$awApp['key'], (string)($awApp['color'] ?? ''), (string)$awApp['label']) ?>
                    <span class="text-xs font-medium text-slate-700"><?= htmlspecialchars($awApp['label']) ?></span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
        </div>
    </div>
</div>
