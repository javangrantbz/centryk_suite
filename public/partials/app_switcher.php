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
        return '<svg viewBox="0 0 32 32" class="h-12 w-12 shrink-0"><circle cx="16" cy="16" r="16" fill="#7c3aed"/><path d="M16 8l2.5 7.5H26l-6 4.5 2.5 7.5-6-4.5-6 4.5 2.5-7.5-6-4.5h7.5L16 8z" fill="white"/></svg>';
    }
    if ($key === 'mypay') {
        return '<img src="assets/myPay.png" alt="MyPay" class="h-12 w-12 rounded-2xl object-contain shadow-sm">';
    }
    if ($key === 'calendar') {
        $bg = htmlspecialchars($color ?: '#14b8a6');
        return '<span class="flex h-12 w-12 items-center justify-center rounded-2xl shadow-sm" style="background:' . $bg . '">'
             . '<svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">'
             . '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'
             . '</svg></span>';
    }
    if ($key === 'invoice') {
        $bg = htmlspecialchars($color ?: '#6366f1');
        return '<span class="flex h-12 w-12 items-center justify-center rounded-2xl shadow-sm" style="background:' . $bg . '">'
             . '<svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">'
             . '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/>'
             . '</svg></span>';
    }
    if ($key === 'store') {
        return '<span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-600 text-white shadow-sm">'
             . '<svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
             . '<path d="M3 9l1.5-5h15L21 9"/><path d="M5 9v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9"/><path d="M9 21v-7h6v7"/><path d="M3 9h18"/>'
             . '</svg></span>';
    }
    // Generic fallback — colored square with the first letter
    $bg     = htmlspecialchars($color ?: '#475569');
    $letter = htmlspecialchars(strtoupper(substr($label ?: $key, 0, 1)));
    return '<span class="flex h-12 w-12 items-center justify-center rounded-2xl text-white text-lg font-black shadow-sm" style="background:' . $bg . '">' . $letter . '</span>';
};
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

            <!-- Account: always opens the Centryk account hub (profile) -->
            <?php $awCentrykIcon = '<svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>';
            $awOnAccount = ($awCurrent === 'account'); ?>
            <a href="profile.php" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center transition <?= $awOnAccount ? 'bg-slate-100 ring-1 ring-slate-200' : 'hover:bg-slate-50' ?>">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white shadow-sm" style="color:#fff"><?= $awCentrykIcon ?></span>
                <span class="text-xs <?= $awOnAccount ? 'font-semibold' : 'font-medium' ?> text-slate-700">Account</span>
            </a>

            <?php $awOnStore = ($awCurrent === 'store'); ?>
            <?php if ($awOnStore): ?>
            <div class="flex flex-col items-center gap-2 rounded-xl p-3 text-center bg-slate-100 ring-1 ring-slate-200 cursor-default">
                <?= $awTileIcon('store', '#7c3aed', 'Store') ?>
                <span class="text-xs font-semibold text-slate-700">Store</span>
            </div>
            <?php else: ?>
            <a href="store.php" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center transition hover:bg-slate-50">
                <?= $awTileIcon('store', '#7c3aed', 'Store') ?>
                <span class="text-xs font-medium text-slate-700">Store</span>
            </a>
            <?php endif; ?>

            <?php if ($awMode === 'launch' && !empty($apps)): ?>
                <?php foreach ($apps as $app):
                    if (empty($app['enrolled'])) continue;          // only enrolled apps
                    if (($app['key'] ?? '') === 'centryk') continue; // centryk shown above
                    $k = (string)$app['key'];
                ?>
                <?php if ($k === $awCurrent): ?>
                <div class="flex flex-col items-center gap-2 rounded-xl p-3 text-center bg-slate-100 ring-1 ring-slate-200 cursor-default">
                    <?= $awTileIcon($k, (string)($app['color'] ?? ''), (string)($app['label'] ?? '')) ?>
                    <span class="text-xs font-semibold text-slate-700"><?= htmlspecialchars($app['label']) ?></span>
                </div>
                <?php else: ?>
                <button type="button" data-app="<?= htmlspecialchars($k) ?>" class="aw-app flex flex-col items-center gap-2 rounded-xl p-3 text-center transition hover:bg-slate-50">
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
                <a href="<?= htmlspecialchars($awApp['href']) ?>" class="flex flex-col items-center gap-2 rounded-xl p-3 text-center transition hover:bg-slate-50">
                    <?= $awTileIcon((string)$awApp['key'], (string)($awApp['color'] ?? ''), (string)$awApp['label']) ?>
                    <span class="text-xs font-medium text-slate-700"><?= htmlspecialchars($awApp['label']) ?></span>
                </a>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </div>
</div>
