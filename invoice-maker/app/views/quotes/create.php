<?php
$customersStmt = $pdo->prepare("SELECT * FROM customers WHERE company_id = ? ORDER BY name ASC");
$customersStmt->execute([current_company_id()]);
$customers = $customersStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $descriptions = $_POST['description'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];

    $subtotal = 0;

    foreach ($descriptions as $index => $description) {
        if (trim($description) === '') continue;

        $qty = (float)$quantities[$index];
        $price = (float)$prices[$index];
        $subtotal += $qty * $price;
    }

    $tax = (float)($_POST['tax'] ?? 0);
    $discount = (float)($_POST['discount'] ?? 0);
    $total = $subtotal + $tax - $discount;

    $stmt = $pdo->prepare("
        INSERT INTO quotes
        (company_id, customer_id, quote_number, status, issue_date, expiry_date, subtotal, tax, discount, total, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        current_company_id(),
        $_POST['customer_id'],
        $_POST['quote_number'],
        $_POST['status'],
        $_POST['issue_date'],
        $_POST['expiry_date'],
        $subtotal,
        $tax,
        $discount,
        $total,
        $_POST['notes']
    ]);

    $quoteId = $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO quote_items
        (quote_id, description, quantity, unit_price, total)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($descriptions as $index => $description) {
        if (trim($description) === '') continue;

        $qty = (float)$quantities[$index];
        $price = (float)$prices[$index];

        $itemStmt->execute([
            $quoteId,
            $description,
            $qty,
            $price,
            $qty * $price
        ]);
    }

    header('Location: ' . BASE_URL . '/?page=quotes-view&id=' . $quoteId);
    exit;
}

$quoteNumber = 'QUO-' . date('Ymd') . '-' . rand(100, 999);
?>

<div class="mb-10">
    <div class="flex items-center space-x-2 text-sm text-gray-400 mb-4">
        <a href="<?= BASE_URL ?>/?page=quotes" class="hover:text-emerald-600 transition-colors">Quotes</a>
        <i data-lucide="chevron-right" class="w-4 h-4"></i>
        <span class="text-slate-900 font-medium">New Quote</span>
    </div>
    <h2 class="text-4xl font-extrabold text-slate-900 tracking-tight">Create Quote</h2>
</div>

<form method="POST" class="space-y-8">
    <!-- Header Details -->
    <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div>
                <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Customer</label>
                <div class="relative">
                    <i data-lucide="user" class="absolute left-4 top-3.5 w-4 h-4 text-gray-300"></i>
                    <select name="customer_id" required class="w-full pl-10 border-gray-100 bg-gray-50/50 rounded-2xl p-3.5 focus:ring-emerald-500 transition-all text-sm appearance-none">
                        <option value="">Select customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= $customer['id'] ?>"><?= e($customer['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Quote Number</label>
                <input name="quote_number" value="<?= $quoteNumber ?>" required class="w-full border-gray-100 bg-gray-50/50 rounded-2xl p-3.5 focus:ring-emerald-500 transition-all text-sm font-mono">
            </div>

            <div>
                <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Initial Status</label>
                <select name="status" class="w-full border-gray-100 bg-gray-50/50 rounded-2xl p-3.5 focus:ring-emerald-500 transition-all text-sm appearance-none">
                    <option value="draft">Draft</option>
                    <option value="sent">Sent</option>
                    <option value="accepted">Accepted</option>
                </select>
            </div>

            <div>
                <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest">Issue Date</label>
                <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required class="w-full border-gray-100 bg-gray-50/50 rounded-2xl p-3.5 focus:ring-emerald-500 transition-all text-sm">
            </div>

            <div>
                <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest text-amber-500">Expiry Date</label>
                <input type="date" name="expiry_date" class="w-full border-amber-50 bg-amber-50/20 rounded-2xl p-3.5 focus:ring-amber-500 transition-all text-sm">
            </div>
        </div>
    </div>

    <!-- Items Section -->
    <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100">
        <h3 class="text-xl font-bold text-slate-900 mb-6 flex items-center">
            <i data-lucide="file-spreadsheet" class="w-5 h-5 mr-3 text-emerald-600"></i>
            Quote Items
        </h3>

        <div id="items-container" class="space-y-4">
            <div class="grid grid-cols-12 gap-4 item-row group">
                <div class="col-span-12 md:col-span-6">
                    <input name="description[]" required placeholder="Service or Product description" class="w-full border-gray-100 bg-gray-50/50 rounded-xl p-3.5 focus:ring-emerald-500 transition-all text-sm">
                </div>
                <div class="col-span-4 md:col-span-2">
                    <input name="quantity[]" type="number" step="0.01" value="1" class="w-full border-gray-100 bg-gray-50/50 rounded-xl p-3.5 focus:ring-emerald-500 transition-all text-sm quantity text-center" placeholder="Qty">
                </div>
                <div class="col-span-4 md:col-span-2">
                    <input name="unit_price[]" type="number" step="0.01" value="0.00" class="w-full border-gray-100 bg-gray-50/50 rounded-xl p-3.5 focus:ring-emerald-500 transition-all text-sm price text-right" placeholder="Price">
                </div>
                <div class="col-span-4 md:col-span-2">
                    <div class="relative">
                        <input readonly value="0.00" class="w-full border-transparent bg-emerald-50/30 text-emerald-700 font-bold rounded-xl p-3.5 text-sm line-total text-right outline-none">
                        <button type="button" onclick="this.closest('.item-row').remove(); calculateGrandTotal();" class="absolute -right-2 -top-2 bg-red-500 text-white p-1 rounded-full opacity-0 group-hover:opacity-100 transition-opacity">
                            <i data-lucide="x" class="w-3 h-3"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <button type="button" onclick="addItem()" class="mt-8 flex items-center text-emerald-600 font-bold hover:text-emerald-700 transition-colors text-sm px-4 py-2 bg-emerald-50 rounded-xl">
            <i data-lucide="plus-circle" class="w-4 h-4 mr-2"></i>
            Add Line Item
        </button>
    </div>

    <!-- Calculations and Notes -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100">
            <label class="block mb-4 text-xs font-bold text-gray-400 uppercase tracking-widest">Additional Notes</label>
            <textarea name="notes" rows="6" class="w-full border-gray-100 bg-gray-50/50 rounded-2xl p-4 focus:ring-emerald-500 focus:bg-white transition-all text-sm" placeholder="Any special terms for this estimate..."></textarea>
        </div>

        <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100 space-y-6">
            <div class="flex justify-between items-center pb-4 border-b border-gray-50">
                <label class="text-xs font-bold text-gray-400 uppercase tracking-widest">Estimated Subtotal</label>
                <span id="subtotal-display" class="font-mono font-bold text-slate-600">$0.00</span>
            </div>

            <div class="grid grid-cols-2 gap-8">
                <div>
                    <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest text-blue-500">Tax</label>
                    <input name="tax" id="tax-input" type="number" step="0.01" value="0.00" class="w-full border-blue-50 bg-blue-50/20 rounded-xl p-3.5 focus:ring-blue-500 text-sm font-mono transition-all">
                </div>
                <div>
                    <label class="block mb-2 text-xs font-bold text-gray-400 uppercase tracking-widest text-red-500">Discount</label>
                    <input name="discount" id="discount-input" type="number" step="0.01" value="0.00" class="w-full border-red-50 bg-red-50/20 rounded-xl p-3.5 focus:ring-red-500 text-sm font-mono transition-all">
                </div>
            </div>

            <div class="bg-[#1a1a1a] p-6 rounded-2xl flex justify-between items-center text-white">
                <span class="text-xs font-bold uppercase tracking-widest opacity-60">Estimated Total</span>
                <span id="total-display" class="text-2xl font-black text-emerald-400">$0.00</span>
            </div>
        </div>
    </div>

    <div class="flex items-center justify-between pt-10">
        <a href="<?= BASE_URL ?>/?page=quotes" class="text-gray-400 font-bold hover:text-slate-900 transition-colors">Discard Estimate</a>
        <button class="bg-[#1a1a1a] hover:bg-emerald-600 text-white px-12 py-5 rounded-3xl font-black shadow-2xl shadow-gray-200 transition-all hover:scale-105 active:scale-95 text-lg">
            Save Quote
        </button>
    </div>
</form>

<script>
function addItem() {
    const row = `
        <div class="grid grid-cols-12 gap-4 item-row group animate-in slide-in-from-left duration-300">
            <div class="col-span-12 md:col-span-6">
                <input name="description[]" required placeholder="Service or Product description" class="w-full border-gray-100 bg-gray-50/50 rounded-xl p-3.5 focus:ring-emerald-500 transition-all text-sm">
            </div>
            <div class="col-span-4 md:col-span-2">
                <input name="quantity[]" type="number" step="0.01" value="1" class="w-full border-gray-100 bg-gray-50/50 rounded-xl p-3.5 focus:ring-emerald-500 transition-all text-sm quantity text-center">
            </div>
            <div class="col-span-4 md:col-span-2">
                <input name="unit_price[]" type="number" step="0.01" value="0.00" class="w-full border-gray-100 bg-gray-50/50 rounded-xl p-3.5 focus:ring-emerald-500 transition-all text-sm price text-right">
            </div>
            <div class="col-span-4 md:col-span-2 relative">
                <input readonly value="0.00" class="w-full border-transparent bg-emerald-50/30 text-emerald-700 font-bold rounded-xl p-3.5 text-sm line-total text-right outline-none">
                <button type="button" onclick="this.closest('.item-row').remove(); calculateGrandTotal();" class="absolute -right-2 -top-2 bg-red-500 text-white p-1 rounded-full opacity-100 transition-opacity">
                    <i data-lucide="x" class="w-3 h-3"></i>
                </button>
            </div>
        </div>
    `;
    document.getElementById('items-container').insertAdjacentHTML('beforeend', row);
    lucide.createIcons();
}

function calculateGrandTotal() {
    let subtotal = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.quantity').value || 0);
        const price = parseFloat(row.querySelector('.price').value || 0);
        const lineTotal = qty * price;
        row.querySelector('.line-total').value = lineTotal.toFixed(2);
        subtotal += lineTotal;
    });

    const tax = parseFloat(document.getElementById('tax-input').value || 0);
    const discount = parseFloat(document.getElementById('discount-input').value || 0);
    const total = subtotal + tax - discount;

    document.getElementById('subtotal-display').innerText = '$' + subtotal.toFixed(2);
    document.getElementById('total-display').innerText = '$' + total.toFixed(2);
}

document.addEventListener('input', function(e) {
    if (e.target.closest('.item-row') || e.target.id === 'tax-input' || e.target.id === 'discount-input') {
        calculateGrandTotal();
    }
});

calculateGrandTotal();
</script>
