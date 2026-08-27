<?php
/**
 * Share-this-store widget. Expects in scope:
 *   $storeShortUrl  — absolute short link (centryk.bz/s/<slug>); required
 *   $name           — company name
 *   $shareVariant   — 'bar' (default, compact strip) | 'card' (bigger, for the
 *                     owner's empty state)
 */
if (empty($storeShortUrl)) {
    return;
}
$shareVariant = $shareVariant ?? 'bar';
$shareName    = trim((string)($name ?? 'this store')) ?: 'this store';
$shareMsg     = 'Check out ' . $shareName . ' on Centryk Store: ';

$u  = rawurlencode($storeShortUrl);
$t  = rawurlencode($shareMsg);
$tu = rawurlencode($shareMsg . $storeShortUrl);

$targets = [
    ['wa',       'WhatsApp', 'https://wa.me/?text=' . $tu,                                         '#25D366'],
    ['fb',       'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $u,                 '#1877F2'],
    ['x',        'X',        'https://twitter.com/intent/tweet?text=' . $t . '&url=' . $u,          '#000000'],
    ['tg',       'Telegram', 'https://t.me/share/url?url=' . $u . '&text=' . $t,                    '#229ED9'],
    ['email',    'Email',    'mailto:?subject=' . rawurlencode($shareName) . '&body=' . $tu,        '#64748b'],
];

$icons = [
    'wa'    => '<path d="M12 2a10 10 0 0 0-8.5 15.3L2 22l4.8-1.5A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .1-1.7-.1-.4-.1-.9-.3-1.6-.6-2.8-1.2-4.6-4-4.7-4.2-.2-.2-1.2-1.5-1.2-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.3.5-.4.4c-.1.2-.3.3-.1.6.1.3.7 1.1 1.4 1.8 1 .8 1.7 1.1 2 1.2.2.1.4.1.6-.1l.7-.9c.2-.3.4-.2.7-.1l2 .9c.3.2.5.2.5.4.1.1.1.7-.1 1.3Z"/>',
    'fb'    => '<path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.5 2.9h-2.3v7A10 10 0 0 0 22 12Z"/>',
    'x'     => '<path d="M18.9 2H22l-7.5 8.6L23 22h-6.8l-5.3-7-6.1 7H1.7l8-9.2L1 2h7l4.8 6.4L18.9 2Zm-1.2 18h1.9L7.1 4H5.1l12.6 16Z"/>',
    'tg'    => '<path d="M21.9 4.3 18.7 19c-.2 1-.9 1.3-1.8.8l-4.9-3.6-2.4 2.3c-.3.3-.5.5-1 .5l.3-4.9 8.9-8c.4-.3-.1-.5-.6-.2L6.9 13.2l-4.7-1.5c-1-.3-1-1 .2-1.5l18.4-7c.8-.3 1.5.2 1.1 1.1Z"/>',
    'email' => '<path d="M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm1.4 2L12 12.2 19.6 7H4.4ZM20 8.6l-8 5.5-8-5.5V17h16V8.6Z"/>',
];

$isCard = $shareVariant === 'card';
$isBanner = $shareVariant === 'banner';
$btnClass = $isCard
    ? 'flex h-11 w-11 items-center justify-center rounded-xl text-white shadow-sm transition hover:opacity-90'
    : ($isBanner
        ? 'flex h-9 w-9 items-center justify-center rounded-xl text-white shadow-sm ring-1 ring-black/5 transition hover:opacity-90'
        : 'flex h-9 w-9 items-center justify-center rounded-lg text-white transition hover:opacity-90');
$iconSize = $isCard ? 'h-5 w-5' : 'h-4 w-4';
?>
<div class="store-share <?= $isCard ? 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm' : ($isBanner ? 'rounded-2xl border border-white/60 bg-white/88 p-3 shadow-lg backdrop-blur-sm' : 'rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm') ?>">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <p class="text-[11px] font-black uppercase tracking-[0.16em] text-violet-600">
            <?= $isCard ? 'Share your store' : 'Share' ?>
        </p>
        <?php if ($isBanner): ?>
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="max-w-[min(48vw,280px)] truncate rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-600 shadow-sm">
                    <?= htmlspecialchars($storeShortUrl) ?>
                </span>
                <button id="storeShareCopy" type="button" data-label="Copy link" data-copy-url="<?= htmlspecialchars($storeShortUrl, ENT_QUOTES, 'UTF-8') ?>"
                        class="shrink-0 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-700 transition hover:border-violet-400 hover:text-violet-600">
                    Copy link
                </button>
                <?php foreach (array_slice($targets, 0, 3) as [$key, $label, $href, $color]): ?>
                <a href="<?= htmlspecialchars($href) ?>" target="_blank" rel="noopener noreferrer"
                   aria-label="Share on <?= htmlspecialchars($label) ?>" title="Share on <?= htmlspecialchars($label) ?>"
                   class="<?= $btnClass ?>" style="background:<?= $color ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="<?= $iconSize ?>"><?= $icons[$key] ?></svg>
                </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="flex min-w-0 flex-1 items-center gap-2">
                <input id="storeShareUrl" type="text" readonly value="<?= htmlspecialchars($storeShortUrl) ?>"
                       class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm font-semibold text-slate-700 outline-none">
                <button id="storeShareCopy" type="button" data-label="Copy" data-copy-url="<?= htmlspecialchars($storeShortUrl, ENT_QUOTES, 'UTF-8') ?>"
                        class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-bold text-slate-600 transition hover:border-violet-400 hover:text-violet-600">
                    Copy
                </button>
            </div>
            <div class="flex items-center gap-1.5">
                <?php foreach ($targets as [$key, $label, $href, $color]): ?>
                <a href="<?= htmlspecialchars($href) ?>" target="_blank" rel="noopener noreferrer"
                   aria-label="Share on <?= htmlspecialchars($label) ?>" title="Share on <?= htmlspecialchars($label) ?>"
                   class="<?= $btnClass ?>" style="background:<?= $color ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="<?= $iconSize ?>"><?= $icons[$key] ?></svg>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var copyBtn = document.getElementById('storeShareCopy');
    var urlField = document.getElementById('storeShareUrl');
    if (!copyBtn || copyBtn.dataset.wired) { return; }
    var copyUrl = copyBtn.getAttribute('data-copy-url') || (urlField ? (urlField.value || '') : '');
    if (copyUrl === '') { return; }
    copyBtn.dataset.wired = '1';
    copyBtn.addEventListener('click', function () {
        if (urlField) {
            // Select first so the value is grabbable even if programmatic copy is
            // blocked; then try execCommand (sync) and the async Clipboard API
            // without awaiting it (it can hang in some embedded contexts).
            urlField.focus();
            urlField.select();
            try { urlField.setSelectionRange(0, urlField.value.length); } catch (e) {}
            try { document.execCommand('copy'); } catch (e) {}
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            try { navigator.clipboard.writeText(copyUrl).catch(function () {}); } catch (e) {}
        }
        var original = copyBtn.getAttribute('data-label') || 'Copy';
        copyBtn.textContent = 'Copied!';
        copyBtn.classList.add('border-emerald-400', 'text-emerald-600');
        setTimeout(function () {
            copyBtn.textContent = original;
            copyBtn.classList.remove('border-emerald-400', 'text-emerald-600');
        }, 1600);
    });
})();
</script>
