<?php
$sku = trim((string)($item['sku'] ?? ''));
$stock = (float)($item['stock_qty'] ?? 0);
$tracksStock = (string)($item['track_inventory'] ?? '0') === '1';
$price = sell_price_label($item['price'] ?? 0);
$isPublished = (int)($item['listing_enabled'] ?? 0) === 1;
$audience = trim((string)($item['listing_audience'] ?? ''));
$startsAt = trim((string)($item['listing_starts_at'] ?? ''));
$endsAt = trim((string)($item['listing_ends_at'] ?? ''));
$windowParts = [];
if ($startsAt !== '') {
    $windowParts[] = 'From ' . date('M j, Y', strtotime($startsAt));
}
if ($endsAt !== '') {
    $windowParts[] = 'Until ' . date('M j, Y', strtotime($endsAt));
}
?>
<label class="block rounded-2xl border border-slate-200 bg-slate-50 p-4 transition hover:border-violet-200 hover:bg-white">
    <div class="flex items-start gap-3">
        <input type="checkbox" name="item_ids[]" value="<?= (int)$item['id'] ?>" class="mt-1">
        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate text-sm font-black text-slate-900"><?= sell_h($item['name']) ?></p>
                    <p class="mt-0.5 text-xs font-semibold text-slate-400">
                        <?= $sku !== '' ? 'SKU ' . sell_h($sku) : 'No SKU' ?> &middot; <?= sell_h($item['store_name']) ?>
                    </p>
                </div>
                <span class="shrink-0 text-lg font-black text-slate-900"><?= sell_h($price) ?></span>
            </div>
            <?php if (trim((string)($item['description'] ?? '')) !== ''): ?>
                <p class="mt-2 line-clamp-2 text-xs font-semibold leading-relaxed text-slate-500"><?= sell_h($item['description']) ?></p>
            <?php endif; ?>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-600">
                    <?= $tracksStock ? sell_h(number_format($stock, 2)) . ' in stock' : 'Service / untracked' ?>
                </span>
                <?php if ($isPublished): ?>
                    <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-emerald-700">
                        <?= sell_h($audience !== '' ? $audience : 'published') ?>
                    </span>
                    <?php if ($windowParts): ?>
                        <span class="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-500">
                            <?= sell_h(implode(' / ', $windowParts)) ?>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</label>
