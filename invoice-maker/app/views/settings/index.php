<?php
$companyId = current_company_id();
$success = '';

$INV_SETTING_DEFAULTS = [
    'business_name' => '', 'business_email' => '', 'business_phone' => '',
    'business_address' => '', 'business_tax_number' => '', 'business_logo' => '',
    'logo_position' => 'left', 'currency_symbol' => '$', 'invoice_terms' => '', 'quote_terms' => '',
];

$stmt = $pdo->prepare("SELECT * FROM invoice_settings WHERE company_id = ?");
$stmt->execute([$companyId]);
$user = ($stmt->fetch() ?: []) + $INV_SETTING_DEFAULTS;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $companyId) {
    $businessLogo = $user['business_logo'];

    if (isset($_FILES['business_logo']) && $_FILES['business_logo']['error'] === UPLOAD_ERR_OK) {
        $allowedExtensions = ['png', 'jpg', 'jpeg'];
        $extension = strtolower(pathinfo($_FILES['business_logo']['name'], PATHINFO_EXTENSION));
        if (in_array($extension, $allowedExtensions)) {
            $safeName   = uniqid('logo_', true) . '.' . $extension;
            $uploadsDir = __DIR__ . '/../../../../public/invoice-maker/uploads';
            if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0775, true); }
            if (move_uploaded_file($_FILES['business_logo']['tmp_name'], $uploadsDir . '/' . $safeName)) {
                $businessLogo = 'uploads/' . $safeName;
            }
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO invoice_settings
            (company_id, business_name, business_email, business_phone, business_address,
             business_tax_number, business_logo, logo_position, currency_symbol, invoice_terms, quote_terms)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            business_name = VALUES(business_name), business_email = VALUES(business_email),
            business_phone = VALUES(business_phone), business_address = VALUES(business_address),
            business_tax_number = VALUES(business_tax_number), business_logo = VALUES(business_logo),
            logo_position = VALUES(logo_position), currency_symbol = VALUES(currency_symbol),
            invoice_terms = VALUES(invoice_terms), quote_terms = VALUES(quote_terms)
    ");
    $stmt->execute([
        $companyId,
        $_POST['business_name']       ?? '',
        $_POST['business_email']      ?? '',
        $_POST['business_phone']      ?? '',
        $_POST['business_address']    ?? '',
        $_POST['business_tax_number'] ?? '',
        $businessLogo,
        $_POST['logo_position']       ?? 'left',
        $_POST['currency_symbol']     ?? '$',
        $_POST['invoice_terms']       ?? '',
        $_POST['quote_terms']         ?? '',
    ]);

    $success = 'Settings updated successfully.';
    $_SESSION['currency_symbol'] = ($_POST['currency_symbol'] ?? '$') ?: '$';

    $stmt = $pdo->prepare("SELECT * FROM invoice_settings WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $user = ($stmt->fetch() ?: []) + $INV_SETTING_DEFAULTS;
}
?>

<div class="mb-10">
    <h2 class="text-4xl font-extrabold text-slate-900 tracking-tight">Settings</h2>
    <p class="text-slate-500 mt-2">Configure your business profile and document defaults.</p>
</div>

<?php if ($success): ?>
    <div class="bg-emerald-50 text-emerald-700 p-4 rounded-2xl mb-8 flex items-center border border-emerald-100 animate-in fade-in slide-in-from-top-4 duration-500">
        <i data-lucide="check-circle" class="w-5 h-5 mr-3"></i>
        <?= e($success) ?>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="max-w-5xl">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
        <!-- Sidebar Info -->
        <div class="lg:col-span-1">
            <h3 class="text-lg font-bold text-slate-900 mb-2">Business Profile</h3>
            <p class="text-sm text-gray-500 leading-relaxed">
                This information will be displayed on your generated invoices and quotes. Ensure your tax details are correct for legal compliance.
            </p>
        </div>

        <!-- Form Section -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Business Name</label>
                        <input name="business_name" value="<?= e($user['business_name']) ?>" class="w-full border-gray-200 rounded-xl p-3 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Business Email</label>
                        <input name="business_email" type="email" value="<?= e($user['business_email']) ?>" class="w-full border-gray-200 rounded-xl p-3 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Business Phone</label>
                        <input name="business_phone" value="<?= e($user['business_phone']) ?>" class="w-full border-gray-200 rounded-xl p-3 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Tax/VAT Number</label>
                        <input name="business_tax_number" value="<?= e($user['business_tax_number']) ?>" class="w-full border-gray-200 rounded-xl p-3 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Currency Symbol</label>
                        <input name="currency_symbol" value="<?= e($user['currency_symbol'] ?: '$') ?>" class="w-full border-gray-200 rounded-xl p-3 focus:ring-emerald-500">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Business Address</label>
                        <textarea name="business_address" rows="3" class="w-full border-gray-200 rounded-xl p-3 focus:ring-emerald-500"><?= e($user['business_address']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Branding Section -->
            <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100">
                <h3 class="text-lg font-bold text-slate-900 mb-6 flex items-center">
                    <i data-lucide="palette" class="w-5 h-5 mr-3 text-emerald-600"></i>
                    Branding
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <label class="block mb-4 text-xs font-bold text-gray-400 uppercase tracking-widest">Business Logo</label>
                        <div class="flex items-center space-x-6">
                            <?php if ($user['business_logo']): ?>
                                <div class="relative group">
                                    <img src="<?= e($user['business_logo']) ?>" class="h-24 w-24 object-contain bg-gray-50 border border-gray-100 rounded-2xl p-2 transition-transform group-hover:scale-105">
                                    <div class="absolute inset-0 bg-black/40 rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                        <i data-lucide="refresh-cw" class="text-white w-6 h-6"></i>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="h-24 w-24 border-2 border-dashed border-gray-100 rounded-2xl flex items-center justify-center text-gray-300">
                                    <i data-lucide="image" class="w-8 h-8"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="flex-1">
                                <input type="file" name="business_logo" id="logo-input" class="hidden">
                                <label for="logo-input" class="cursor-pointer bg-gray-50 hover:bg-gray-100 text-gray-600 px-4 py-2 rounded-xl text-sm font-bold border border-gray-200 transition-colors inline-block">
                                    Choose File
                                </label>
                                <p class="text-[10px] text-gray-400 mt-2 uppercase tracking-tighter">PNG, JPG or JPEG. Max 2MB.</p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block mb-4 text-xs font-bold text-gray-400 uppercase tracking-widest">Logo Position</label>
                        <div class="grid grid-cols-3 gap-2">
                            <?php foreach (['left', 'center', 'right'] as $pos): ?>
                                <label class="cursor-pointer">
                                    <input type="radio" name="logo_position" value="<?= $pos ?>" class="hidden peer" <?= $user['logo_position'] === $pos ? 'checked' : '' ?>>
                                    <div class="text-center p-3 rounded-xl border border-gray-100 bg-gray-50/50 text-gray-400 peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:border-emerald-600 transition-all text-xs font-bold capitalize">
                                        <?= $pos ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Terms Section -->
            <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100">
                <h3 class="text-lg font-bold text-slate-900 mb-6 flex items-center">
                    <i data-lucide="file-check" class="w-5 h-5 mr-3 text-emerald-600"></i>
                    Default Terms
                </h3>
                
                <div class="space-y-6">
                    <div>
                        <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Invoice Terms</label>
                        <textarea name="invoice_terms" rows="3" class="w-full border-gray-200 rounded-xl p-3 focus:ring-emerald-500 text-sm" placeholder="e.g. Net 30 days"><?= e($user['invoice_terms']) ?></textarea>
                    </div>

                    <div>
                        <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Quote Terms</label>
                        <textarea name="quote_terms" rows="3" class="w-full border-gray-200 rounded-xl p-3 focus:ring-emerald-500 text-sm" placeholder="e.g. Valid for 14 days"><?= e($user['quote_terms']) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Action -->
            <div class="flex justify-end pt-4">
                <button class="bg-[#1a1a1a] hover:bg-emerald-600 text-white px-10 py-4 rounded-2xl font-black text-lg shadow-xl shadow-gray-200 transition-all hover:scale-105 active:scale-95">
                    Save Changes
                </button>
            </div>
        </div>
    </div>
</form>
