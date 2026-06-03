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
    echo '<div class="mb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400">' . e($label) . '</div>';
    echo $val !== ''
        ? '<div class="text-sm font-semibold text-slate-800 whitespace-pre-line">' . nl2br(e($val)) . '</div>'
        : '<div class="text-sm font-medium italic text-slate-300">Not set</div>';
    echo '</div>';
};
?>

<?php if ($success): ?>
    <div class="bg-emerald-50 text-emerald-700 p-4 rounded-2xl mb-5 flex items-center border border-emerald-100">
        <i data-lucide="check-circle" class="w-5 h-5 mr-3"></i>
        <?= e($success) ?>
    </div>
<?php endif; ?>

<form method="POST" class="max-w-none space-y-5">
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
        <div class="space-y-5 xl:col-span-5">

            <!-- Business profile — managed in Centryk, read-only here -->
            <div class="bg-white p-5 rounded-3xl shadow-sm border border-gray-100 space-y-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">Business Profile</h3>
                        <p class="mt-0.5 text-xs font-semibold text-slate-400">
                            Managed in your Centryk company profile. Shown on every invoice &amp; quote.
                        </p>
                    </div>
                    <a href="<?= e($editUrl) ?>" target="_blank" rel="noopener"
                       class="inline-flex shrink-0 items-center gap-1.5 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-black text-slate-700 transition hover:bg-slate-100">
                        <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Edit
                    </a>
                </div>

                <div class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <?php if ($logoUrl): ?>
                    <img src="<?= e($logoUrl) ?>" alt="Logo" class="h-16 w-16 shrink-0 rounded-2xl border border-slate-200 bg-white object-contain p-1.5 shadow-sm">
                    <?php else: ?>
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 bg-white text-slate-300">
                        <i data-lucide="image" class="w-6 h-6"></i>
                    </div>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <div class="text-base font-black text-slate-900 truncate"><?= e($business['business_name'] ?: 'Company name not set') ?></div>
                        <div class="text-xs font-semibold text-slate-400 truncate"><?= e($business['business_email'] ?: 'No email on file') ?></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2">
                    <?php
                    $roField('Business Email', $business['business_email']);
                    $roField('TIN Number', $business['business_tax_number']);
                    $roField('Phone Numbers', $phones ? implode("\n", $phones) : '');
                    $roField('Opening Hours', $business['opening_hours']);
                    ?>
                    <div class="md:col-span-2">
                        <?php $roField('Business Address', $business['business_address']); ?>
                    </div>
                </div>
            </div>

            <!-- Default terms — editable, app-side -->
            <div class="bg-white p-5 rounded-3xl shadow-sm border border-gray-100">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <h3 class="text-lg font-bold text-slate-900 flex items-center">
                        <i data-lucide="file-check" class="w-5 h-5 mr-3 text-emerald-600"></i>
                        Default Terms
                    </h3>
                    <button class="bg-[#1a1a1a] hover:bg-emerald-600 text-white px-6 py-2.5 rounded-2xl font-black shadow-lg shadow-gray-200 transition-all hover:scale-105 active:scale-95 text-sm">
                        Save Changes
                    </button>
                </div>

                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Invoice Terms</label>
                        <textarea name="invoice_terms" rows="4" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100" placeholder="e.g. Net 30 days"><?= e($business['invoice_terms']) ?></textarea>
                    </div>

                    <div>
                        <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Quote Terms</label>
                        <textarea name="quote_terms" rows="4" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100" placeholder="e.g. Valid for 14 days"><?= e($business['quote_terms']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <aside class="space-y-5 xl:sticky xl:top-4 xl:col-span-3">
            <div class="bg-white p-5 rounded-3xl shadow-sm border border-gray-100">
                <h3 class="text-lg font-bold text-slate-900 mb-4 flex items-center">
                    <i data-lucide="palette" class="w-5 h-5 mr-3 text-emerald-600"></i>
                    Branding
                </h3>

                <div class="space-y-5">
                    <div>
                        <label class="block mb-3 text-xs font-bold text-gray-400 uppercase tracking-widest">Business Logo</label>
                        <div class="flex items-center gap-4">
                            <?php if ($logoUrl): ?>
                            <img src="<?= e($logoUrl) ?>" class="h-20 w-20 object-contain bg-slate-50 border border-slate-200 rounded-2xl p-2">
                            <?php else: ?>
                            <div class="h-20 w-20 border-2 border-dashed border-slate-200 rounded-2xl flex items-center justify-center text-gray-300 bg-slate-50">
                                <i data-lucide="image" class="w-7 h-7"></i>
                            </div>
                            <?php endif; ?>
                            <p class="flex-1 text-[11px] font-semibold text-slate-400">
                                Your logo comes from your Centryk company profile.
                                <a href="<?= e($editUrl) ?>" target="_blank" rel="noopener" class="font-bold text-emerald-600 hover:text-emerald-700">Change it there →</a>
                            </p>
                        </div>
                    </div>

                    <div>
                        <label class="block mb-3 text-xs font-bold text-gray-400 uppercase tracking-widest">Logo Position</label>
                        <div class="grid grid-cols-3 gap-2">
                            <?php foreach (['left', 'center', 'right'] as $pos): ?>
                                <label class="cursor-pointer">
                                    <input type="radio" name="logo_position" value="<?= $pos ?>" class="hidden peer" <?= $logoPosition === $pos ? 'checked' : '' ?>>
                                    <div class="text-center px-3 py-2.5 rounded-xl border border-slate-300 bg-white text-slate-500 shadow-sm peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:border-emerald-600 transition-all text-xs font-bold capitalize">
                                        <?= $pos ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white p-5 rounded-3xl shadow-sm border border-gray-100">
                <h3 class="text-base font-bold text-slate-900 mb-4">Summary</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-400">Company</span>
                        <span class="text-right font-semibold text-slate-800"><?= e($business['business_name'] ?: 'Not set') ?></span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-400">TIN</span>
                        <span class="text-right font-semibold text-slate-800"><?= e($business['business_tax_number'] ?: 'Not set') ?></span>
                    </div>
                    <div class="flex justify-between gap-3">
                        <span class="text-slate-400">Currency</span>
                        <span class="text-right font-semibold text-slate-800">$</span>
                    </div>
                </div>
            </div>
        </aside>

        <aside class="space-y-5 xl:sticky xl:top-4 xl:col-span-4">
            <div class="bg-white p-5 rounded-3xl shadow-sm border border-gray-100">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <h3 class="text-lg font-bold text-slate-900">Preview</h3>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-widest text-slate-500">Live Style</span>
                </div>

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
                </div>
            </div>
        </aside>
    </div>
</form>

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
