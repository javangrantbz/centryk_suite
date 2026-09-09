<?php
/**
 * Belize public-holidays strip — a small "what's coming up" band.
 *
 * Self-contained: reads the PublicHolidays service directly. Set
 * $holidayStripStyle before including to pick the shell:
 *   'band' (default) — full-bleed section, for the marketing landing page.
 *   'card'           — rounded card, for the logged-in dashboard's <main>.
 *
 * Renders nothing when there is no holiday today, none in the next ~10 days,
 * and no next holiday at all (or if the public_holidays table is missing).
 */
require_once __DIR__ . '/../../app/services/PublicHolidays.php';

$__hStyle          = (isset($holidayStripStyle) && $holidayStripStyle === 'card') ? 'card' : 'band';
$__hToday          = date('Y-m-d');
$__holidayToday    = null;
$__holidaysSoon    = [];
$__holidayNext     = null;
try {
    foreach (PublicHolidays::forRange($__hToday, date('Y-m-d', strtotime('+10 days'))) as $__h) {
        if ($__h['holiday_date'] === $__hToday) {
            $__holidayToday = $__h;
        } else {
            $__holidaysSoon[] = $__h;
        }
    }
    if (!$__holidayToday && !$__holidaysSoon) {
        $__n = PublicHolidays::upcoming(1);
        $__holidayNext = $__n[0] ?? null;
    }
} catch (Throwable $__e) {
    $__holidayToday = null;
    $__holidaysSoon = [];
    $__holidayNext  = null;
}

if (!($__holidayToday || $__holidaysSoon || $__holidayNext)) {
    return;
}

$__hDaysUntil = static function (string $d): int {
    return (int)(new DateTime('today'))->diff(new DateTime($d))->format('%r%a');
};
?>
<?php if ($__hStyle === 'card'): ?>
<div class="dash-fade mb-3 rounded-2xl border border-slate-200 bg-white px-5 py-3.5 shadow-sm">
<?php else: ?>
<section class="border-b border-slate-200 bg-slate-50">
    <div class="mx-auto max-w-6xl px-6 py-4">
<?php endif; ?>

        <?php if ($__holidayToday): ?>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-purple-600 px-3 py-1 text-[11px] font-black uppercase tracking-[0.14em] text-white">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M5.8 11.3 2 22l10.7-3.79"/><path d="M4 3h.01M22 8h.01M15 2h.01M22 20h.01"/><path d="m22 2-2.24.75a2.9 2.9 0 0 0-1.96 3.12c.1.86-.57 1.63-1.45 1.63h-.38c-.86 0-1.6.6-1.76 1.44L12 10"/><path d="m22 13-.82-.33c-.86-.34-1.82.2-1.98 1.11c-.11.7-.72 1.22-1.43 1.22H17"/><path d="m11 2 .33.82c.34.86-.2 1.82-1.11 1.98C9.52 4.9 9 5.52 9 6.23V7"/><path d="M11 13c1.93 1.93 2.83 4.17 2 5-.83.83-3.07-.07-5-2-1.93-1.93-2.83-4.17-2-5 .83-.83 3.07.07 5 2Z"/></svg>
                Public holiday today
            </span>
            <span class="text-sm font-black tracking-tight text-slate-900"><?= htmlspecialchars($__holidayToday['name']) ?></span>
            <span class="text-xs font-semibold text-slate-500">
                Belize &middot; <?= date('l, F j', strtotime($__holidayToday['holiday_date'])) ?><?php
                if ((float)$__holidayToday['pay_rate'] > 1):
                ?> &middot; <?= htmlspecialchars(PublicHolidays::rateLabel((float)$__holidayToday['pay_rate'])) ?> pay for hours worked<?php endif; ?>
            </span>
        </div>
        <?php endif; ?>

        <?php if ($__holidaysSoon): ?>
        <div class="<?= $__holidayToday ? 'mt-3 ' : '' ?>flex flex-wrap items-center gap-2">
            <span class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">
                <?= $__holidayToday ? 'Also coming up' : 'Upcoming holidays in Belize' ?>
            </span>
            <?php foreach ($__holidaysSoon as $__h): $__du = $__hDaysUntil($__h['holiday_date']); ?>
            <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 shadow-sm">
                <span class="font-black tracking-tight text-slate-900"><?= htmlspecialchars($__h['name']) ?></span>
                <span class="text-slate-300">&middot;</span>
                <span class="font-semibold text-slate-500"><?= date('D, M j', strtotime($__h['holiday_date'])) ?></span>
                <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-black uppercase tracking-wide text-slate-500">
                    <?= $__du <= 0 ? 'Today' : ($__du === 1 ? 'Tomorrow' : 'In ' . $__du . ' days') ?>
                </span>
            </span>
            <?php endforeach; ?>
        </div>
        <?php elseif ($__holidayNext): ?>
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs font-semibold text-slate-500">
            <span class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Next public holiday in Belize</span>
            <span class="font-black tracking-tight text-slate-900"><?= htmlspecialchars($__holidayNext['name']) ?></span>
            <span class="text-slate-300">&middot;</span>
            <span><?= date('l, F j', strtotime($__holidayNext['holiday_date'])) ?></span>
            <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-black uppercase tracking-wide text-slate-500">In <?= $__hDaysUntil($__holidayNext['holiday_date']) ?> days</span>
        </div>
        <?php endif; ?>

<?php if ($__hStyle === 'card'): ?>
</div>
<?php else: ?>
    </div>
</section>
<?php endif; ?>
