<?php
$companyId = current_company_id();
$success = '';

// Document-styling defaults live app-side; business identity is read-only
// and managed in Centryk (companies table) — see inv_business_profile().
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $companyId) {
    $stmt = $pdo->prepare("
        INSERT INTO invoice_settings (company_id, logo_position, currency_symbol, invoice_terms, quote_terms)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            logo_position   = VALUES(logo_position),
            currency_symbol = VALUES(currency_symbol),
            invoice_terms   = VALUES(invoice_terms),
            quote_terms     = VALUES(quote_terms)
    ");
    $stmt->execute([
        $companyId,
        $_POST['logo_position'] ?? 'left',
        '$',
        $_POST['invoice_terms'] ?? '',
        $_POST['quote_terms']   ?? '',
    ]);
    $success = 'Header settings updated.';
    $_SESSION['currency_symbol'] = '$';
}

$business = inv_business_profile($pdo, (int)$companyId);
$logoUrl  = inv_company_logo_url($business['business_logo']);
$phones   = inv_company_phones($business);
$editUrl  = rtrim(CENTRYK_BASE, '/') . '/profile.php'
          . ($business['company_uuid'] ? ('?company_uuid=' . urlencode($business['company_uuid'])) : '')
          . '#companies';

$logoPosition = $business['logo_position'] ?: 'left';
$previewHeaderClass = match ($logoPosition) {
    'center' => 'flex-col items-center text-center',
    'right'  => 'flex-row-reverse items-start text-right',
    default  => 'flex-row items-start text-left',
};
$previewMetaClass = match ($logoPosition) {
    'center' => 'items-center text-center',
    'right'  => 'items-end text-right',
    default  => 'items-start text-left',
};
$previewLogoWrapperClass = match ($logoPosition) {
    'center' => 'mx-auto',
    'right'  => 'mr-0',
    default  => 'ml-0',
};

// Read-only field renderer for the company-managed business profile.
$roField = function (string $label, $value) {
    $val = trim((string)$value);
    echo '<div>';
    echo '<div class="biz-label">' . e($label) . '</div>';
    echo $val !== ''
        ? '<div class="whitespace-pre-line" style="font-size:12px;font-weight:600">' . nl2br(e($val)) . '</div>'
        : '<div style="font-size:12px;color:var(--bz-faint)">Not set</div>';
    echo '</div>';
};
?>

<div class="biz">
<p class="biz-kicker">Invoice engine</p>
<h1 class="mt-0.5 mb-3">Header &amp; terms</h1>

<?php if ($success): ?>
    <div class="biz-notice biz-notice-green mb-3"><?= e($success) ?></div>
<?php endif; ?>

<form method="POST" class="max-w-none">
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-3 items-start">
        <div class="space-y-3 xl:col-span-5">

            <!-- Business profile — managed in Centryk, read-only here -->
            <div class="biz-panel">
                <div class="biz-panel-head"><span>Business profile</span>
                    <a href="<?= e($editUrl) ?>" target="_blank" rel="noopener" class="biz-t-green" style="font-size:11px;font-weight:700">Edit in Centryk →</a>
                </div>
                <div class="biz-panel-body space-y-3">
                    <div class="flex items-center gap-3">
                        <?php if ($logoUrl): ?>
                        <img src="<?= e($logoUrl) ?>" alt="Logo" class="h-12 w-12 shrink-0 rounded border object-contain p-1" style="border-color:var(--bz-line);background:#fff">
                        <?php else: ?>
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded border" style="border-style:dashed;border-color:var(--bz-line);color:var(--bz-faint)"><i data-lucide="image" class="w-5 h-5"></i></div>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <div class="font-bold truncate"><?= e($business['business_name'] ?: 'Company name not set') ?></div>
                            <div class="biz-muted truncate" style="font-size:11px"><?= e($business['business_email'] ?: 'No email on file') ?></div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php
                        $roField('Business email', $business['business_email']);
                        $roField('TIN number', $business['business_tax_number']);
                        $roField('Phone numbers', $phones ? implode("\n", $phones) : '');
                        $roField('Opening hours', $business['opening_hours']);
                        ?>
                        <div class="md:col-span-2"><?php $roField('Business address', $business['business_address']); ?></div>
                    </div>
                </div>
            </div>

            <!-- Default terms — editable, app-side -->
            <div class="biz-panel">
                <div class="biz-panel-head"><span>Default terms</span>
                    <button class="biz-btn biz-btn-primary biz-btn-sm">Save changes</button>
                </div>
                <div class="biz-panel-body space-y-2">
                    <label class="block"><span class="biz-label">Invoice terms</span>
                        <textarea name="invoice_terms" rows="4" class="biz-input" placeholder="e.g. Net 30 days"><?= e($business['invoice_terms']) ?></textarea></label>
                    <label class="block"><span class="biz-label">Quote terms</span>
                        <textarea name="quote_terms" rows="4" class="biz-input" placeholder="e.g. Valid for 14 days"><?= e($business['quote_terms']) ?></textarea></label>
                </div>
            </div>
        </div>

        <aside class="space-y-3 xl:sticky xl:top-2 xl:col-span-3">
            <div class="biz-panel">
                <div class="biz-panel-head"><span>Branding</span></div>
                <div class="biz-panel-body space-y-3">
                    <div>
                        <span class="biz-label">Business logo</span>
                        <div class="flex items-center gap-3 mt-1">
                            <?php if ($logoUrl): ?>
                            <img src="<?= e($logoUrl) ?>" class="h-14 w-14 object-contain border rounded p-1" style="border-color:var(--bz-line);background:var(--bz-head)">
                            <?php else: ?>
                            <div class="h-14 w-14 border rounded flex items-center justify-center" style="border-style:dashed;border-color:var(--bz-line);color:var(--bz-faint)"><i data-lucide="image" class="w-6 h-6"></i></div>
                            <?php endif; ?>
                            <p class="flex-1 biz-muted" style="font-size:11px">Comes from your Centryk company profile.
                                <a href="<?= e($editUrl) ?>" target="_blank" rel="noopener" class="biz-t-green font-bold">Change it there →</a></p>
                        </div>
                    </div>
                    <div>
                        <span class="biz-label">Logo position</span>
                        <div class="grid grid-cols-3 gap-2 mt-1">
                            <?php foreach (['left', 'center', 'right'] as $pos): ?>
                                <label class="cursor-pointer">
                                    <input type="radio" name="logo_position" value="<?= $pos ?>" class="hidden peer" <?= $logoPosition === $pos ? 'checked' : '' ?>>
                                    <div class="biz-btn biz-btn-ghost peer-checked:!bg-[var(--bz-accent)] peer-checked:!text-white peer-checked:!border-transparent" style="width:100%;text-transform:capitalize"><?= $pos ?></div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="biz-panel">
                <div class="biz-panel-head"><span>Summary</span></div>
                <div class="biz-panel-body" style="font-size:12px">
                    <div class="flex justify-between gap-3 py-0.5"><span class="biz-muted">Company</span><span class="font-semibold" style="text-align:right"><?= e($business['business_name'] ?: 'Not set') ?></span></div>
                    <div class="flex justify-between gap-3 py-0.5"><span class="biz-muted">TIN</span><span class="font-semibold" style="text-align:right"><?= e($business['business_tax_number'] ?: 'Not set') ?></span></div>
                    <div class="flex justify-between gap-3 py-0.5"><span class="biz-muted">Currency</span><span class="font-semibold" style="text-align:right">$</span></div>
                </div>
            </div>
        </aside>

        <aside class="space-y-3 xl:sticky xl:top-2 xl:col-span-4">
            <div class="biz-panel">
                <div class="biz-panel-head"><span>Preview</span><span class="biz-chip biz-c-slate">live style</span></div>
                <div class="biz-panel-body">

                <div class="space-y-4">
                    <div class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
                        <div id="previewHeader" class="flex gap-4 border-b border-slate-200 pb-4 <?= $previewHeaderClass ?>">
                            <div class="min-w-0 flex-1">
                                <div class="text-lg font-black text-slate-900"><?= e($business['business_name'] ?: 'Your Business Name') ?></div>
                                <div class="mt-1 text-xs text-slate-500"><?= e($business['business_email'] ?: 'business@email.com') ?></div>
                                <?php foreach ($phones ?: ['+501 000-0000'] as $ph): ?>
                                <div class="text-xs text-slate-500"><?= e($ph) ?></div>
                                <?php endforeach; ?>
                                <div class="mt-2 text-xs text-slate-400 whitespace-pre-line"><?= nl2br(e($business['business_address'] ?: "Business address\nCity, Country")) ?></div>
                            </div>
                            <div id="previewMeta" class="shrink-0 flex flex-col gap-2 <?= $previewMetaClass ?>">
                                <?php if ($logoUrl): ?>
                                <img src="<?= e($logoUrl) ?>" alt="Logo" class="h-12 w-12 rounded-2xl border border-slate-200 bg-white object-contain p-1 shadow-sm <?= $previewLogoWrapperClass ?>">
                                <?php else: ?>
                                <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-300 shadow-sm <?= $previewLogoWrapperClass ?>">
                                    <i data-lucide="image" class="w-5 h-5"></i>
                                </div>
                                <?php endif; ?>
                                <div class="text-[10px] font-bold uppercase tracking-widest text-slate-400">TIN</div>
                                <div class="text-xs font-semibold text-slate-700"><?= e($business['business_tax_number'] ?: 'Not set') ?></div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-3 py-3 border-b border-slate-200">
                            <div>
                                <div class="text-[11px] font-bold uppercase tracking-widest text-slate-400">Invoice Preview</div>
                                <div class="text-sm font-bold text-slate-800">INV-20260602-101</div>
                            </div>
                            <div class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-widest text-emerald-700">Draft</div>
                        </div>

                        <div class="space-y-2 py-3 border-b border-slate-200 text-sm">
                            <div class="flex justify-between gap-3">
                                <span class="text-slate-500">Consulting Services</span>
                                <span class="font-semibold text-slate-800">$450.00</span>
                            </div>
                            <div class="flex justify-between gap-3">
                                <span class="text-slate-500">Transport Coordination</span>
                                <span class="font-semibold text-slate-800">$125.00</span>
                            </div>
                        </div>

                        <div class="space-y-2 pt-3 text-sm">
                            <div class="flex justify-between gap-3">
                                <span class="text-slate-500">Subtotal</span>
                                <span class="font-semibold text-slate-800">$575.00</span>
                            </div>
                            <div class="flex justify-between gap-3">
                                <span class="text-slate-500">Tax</span>
                                <span class="font-semibold text-slate-800">$0.00</span>
                            </div>
                            <div class="flex justify-between gap-3 pt-2 border-t border-slate-200">
                                <span class="text-[11px] font-bold uppercase tracking-widest text-slate-400">Total</span>
                                <span class="text-base font-black text-slate-900">$575.00</span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-[#1a1a1a] p-4 text-white">
                        <div class="text-[11px] font-bold uppercase tracking-widest text-emerald-300/80">Quote Footer</div>
                        <div id="previewQuoteTerms" class="mt-2 text-sm font-semibold"><?= e($business['quote_terms'] ?: 'Your default quote terms will appear here.') ?></div>
                        <div class="mt-3 text-xs text-slate-400">Invoice terms preview follows the same style using your selected currency and business details.</div>
                    </div>
                </div><!-- /.space-y-4 -->
            </div><!-- /.biz-panel-body -->
            </div><!-- /.biz-panel -->
        </aside>
    </div>
</form>
</div><!-- /.biz -->

<script>
(function () {
    const headerMap = {
        center: 'flex-col items-center text-center',
        right:  'flex-row-reverse items-start text-right',
        left:   'flex-row items-start text-left',
    };
    const metaMap = {
        center: 'items-center text-center',
        right:  'items-end text-right',
        left:   'items-start text-left',
    };
    const logoMap = { center: 'mx-auto', right: 'mr-0', left: 'ml-0' };

    const headerBase = 'flex gap-4 border-b border-slate-200 pb-4';
    const metaBase   = 'shrink-0 flex flex-col gap-2';

    const $ = (id) => document.getElementById(id);

    function currentPosition() {
        const checked = document.querySelector('input[name="logo_position"]:checked');
        return checked ? checked.value : 'left';
    }

    function applyPosition() {
        const pos    = currentPosition();
        const header = $('previewHeader');
        const meta   = $('previewMeta');
        const logoEl = meta ? meta.querySelector('img, div') : null;
        const lw     = logoMap[pos] || logoMap.left;
        if (header) header.className = headerBase + ' ' + (headerMap[pos] || headerMap.left);
        if (meta)   meta.className   = metaBase + ' ' + (metaMap[pos] || metaMap.left);
        if (logoEl) {
            logoEl.classList.remove('mx-auto', 'mr-0', 'ml-0');
            logoEl.classList.add(lw);
        }
    }

    document.querySelectorAll('input[name="logo_position"]').forEach((r) => r.addEventListener('change', applyPosition));
    applyPosition();

    // Live quote-terms preview.
    const quoteTerms = document.querySelector('[name="quote_terms"]');
    const quotePrev  = $('previewQuoteTerms');
    if (quoteTerms && quotePrev) {
        quoteTerms.addEventListener('input', function () {
            quotePrev.textContent = this.value.trim() || 'Your default quote terms will appear here.';
        });
    }
})();
</script>
