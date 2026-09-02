<?php
$sku = trim((string)($item['sku'] ?? ''));
$stock = (float)($item['stock_qty'] ?? 0);
$tracksStock = (string)($item['track_inventory'] ?? '0') === '1';
$price = sell_price_label($item['price'] ?? 0);
$isPublished = (int)($item['listing_enabled'] ?? 0) === 1;
$audience = trim((string)($item['listing_audience'] ?? ''));
$storeName = trim((string)($item['store_name'] ?? ''));
$startsAt = trim((string)($item['listing_starts_at'] ?? ''));
$endsAt = trim((string)($item['listing_ends_at'] ?? ''));
$windowParts = [];
if ($startsAt !== '') {
    $windowParts[] = 'From ' . date('M j, Y', strtotime($startsAt));
}
if ($endsAt !== '') {
    $windowParts[] = 'Until ' . date('M j, Y', strtotime($endsAt));
}
$searchBlob = strtolower(trim(($item['name'] ?? '') . ' ' . $sku . ' ' . $storeName));
?>
<label class="sell-row block rounded-xl border border-slate-200 bg-slate-50 p-3 transition hover:border-violet-200 hover:bg-white"
       data-status="<?= $isPublished ? 'listed' : 'unlisted' ?>"
       data-audience="<?= sell_h($audience) ?>"
       data-starts="<?= sell_h($startsAt !== '' ? substr($startsAt, 0, 10) : '') ?>"
       data-ends="<?= sell_h($endsAt !== '' ? substr($endsAt, 0, 10) : '') ?>"
       data-store="<?= sell_h($storeName) ?>"
       data-search="<?= sell_h($searchBlob) ?>">
    <div class="flex items-start gap-2.5">
        <input type="checkbox" name="item_ids[]" value="<?= (int)$item['id'] ?>" class="mt-1">
        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate text-[13px] font-black text-slate-900"><?= sell_h($item['name']) ?></p>
                    <p class="mt-0.5 text-[11px] font-semibold text-slate-400">
                        <?= $sku !== '' ? 'SKU ' . sell_h($sku) : 'No SKU' ?> &middot; <?= sell_h($storeName) ?>
                    </p>
                </div>
                <span class="shrink-0 text-base font-black text-slate-900"><?= sell_h($price) ?></span>
            </div>
            <?php if (trim((string)($item['description'] ?? '')) !== ''): ?>
                <p class="mt-1.5 line-clamp-2 text-[11px] font-semibold leading-relaxed text-slate-500"><?= sell_h($item['description']) ?></p>
            <?php endif; ?>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-slate-600">
                    <?= $tracksStock ? sell_h(number_format($stock, 2)) . ' in stock' : 'Service / untracked' ?>
                </span>
                <?php if ($isPublished): ?>
                    <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-emerald-700">
                        <?= sell_h(sell_audience_label($audience)) ?>
                    </span>
                    <?php if ($windowParts): ?>
                        <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-slate-500">
                            <?= sell_h(implode(' / ', $windowParts)) ?>
                        </span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-slate-400">
                        Not listed
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</label>
