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
        INSERT INTO invoices
        (company_id, customer_id, invoice_number, status, issue_date, due_date, subtotal, tax, discount, total, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        current_company_id(),
        $_POST['customer_id'],
        $_POST['invoice_number'],
        $_POST['status'],
        $_POST['issue_date'],
        $_POST['due_date'],
        $subtotal,
        $tax,
        $discount,
        $total,
        $_POST['notes']
    ]);

    $invoiceId = $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO invoice_items
        (invoice_id, description, quantity, unit_price, total)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($descriptions as $index => $description) {
        if (trim($description) === '') continue;

        $qty = (float)$quantities[$index];
        $price = (float)$prices[$index];
        $lineTotal = $qty * $price;

        $itemStmt->execute([
            $invoiceId,
            $description,
            $qty,
            $price,
            $lineTotal
        ]);
    }

    redirect_response(BASE_URL . '/?page=invoices-view&id=' . $invoiceId);
}

$invoiceNumber = 'INV-' . date('Ymd') . '-' . rand(100, 999);
?>

<div class="mb-4">
    <div class="flex items-center space-x-2 text-xs text-gray-400 mb-3">
        <a href="<?= BASE_URL ?>/?page=invoices" class="hover:text-emerald-600 transition-colors">Invoices</a>
        <i data-lucide="chevron-right" class="w-4 h-4"></i>
        <span class="text-slate-900 font-medium">New Invoice</span>
    </div>
    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
            <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Create Invoice</h2>
            <p class="text-xs text-slate-500">Compact workspace for selecting a client, adding items, and issuing fast.</p>
        </div>
        <div class="hidden lg:flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-slate-400">
            <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-slate-200"><?= count($customers) ?> clients</span>
            <span class="rounded-full bg-white px-3 py-1.5 ring-1 ring-slate-200">Invoice builder</span>
        </div>
    </div>
</div>

<form method="POST" class="space-y-4">
    <div class="grid grid-cols-1 xl:grid-cols-[250px_minmax(0,1fr)_290px] gap-4 items-start">
        <aside class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden xl:sticky xl:top-4">
            <div class="border-b border-gray-100 px-4 py-3">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-black text-slate-900">Clients</h3>
                        <p class="text-[11px] text-slate-400">Pick one to prefill the invoice.</p>
                    </div>
                    <a href="<?= BASE_URL ?>/?page=customers&action=create" class="rounded-xl bg-emerald-50 px-2.5 py-1.5 text-[11px] font-bold text-emerald-700 hover:bg-emerald-100 transition">New</a>
                </div>
                <div class="relative mt-3">
                    <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-slate-300"></i>
                    <input type="text" id="invoice-customer-search" placeholder="Search clients" class="w-full rounded-2xl border border-slate-300 bg-slate-50 pl-9 pr-3 py-2.5 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                </div>
            </div>
            <div class="max-h-[420px] overflow-y-auto custom-scrollbar p-2 space-y-1">
                <?php foreach ($customers as $customer): ?>
                    <button
                        type="button"
                        data-customer-option
                        data-customer-id="<?= (int)$customer['id'] ?>"
                        data-customer-name="<?= e(strtolower($customer['name'])) ?>"
                        class="flex w-full items-center gap-3 rounded-2xl border border-transparent px-3 py-2.5 text-left transition hover:bg-slate-50 hover:border-slate-200"
                    >
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-sm font-black text-emerald-700">
                            <?= strtoupper(substr($customer['name'], 0, 1)) ?>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-bold text-slate-800"><?= e($customer['name']) ?></span>
                            <span class="block truncate text-[11px] text-slate-400"><?= e($customer['company'] ?: 'No company') ?></span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="space-y-4 min-w-0">
            <div class="bg-white p-4 rounded-3xl shadow-sm border border-gray-100">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block mb-2 text-[11px] font-bold text-gray-400 uppercase tracking-widest">Customer</label>
                        <div class="relative">
                            <i data-lucide="user" class="absolute left-3 top-3 w-4 h-4 text-gray-300"></i>
                            <select id="customer-select" name="customer_id" required class="w-full pl-9 border border-slate-300 bg-gray-50/50 rounded-2xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm appearance-none">
                                <option value="">Select customer</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= $customer['id'] ?>"><?= e($customer['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block mb-2 text-[11px] font-bold text-gray-400 uppercase tracking-widest">Invoice Number</label>
                        <input name="invoice_number" value="<?= $invoiceNumber ?>" required class="w-full border border-slate-300 bg-gray-50/50 rounded-2xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm font-mono">
                    </div>
                    <div>
                        <label class="block mb-2 text-[11px] font-bold text-gray-400 uppercase tracking-widest">Status</label>
                        <select name="status" class="w-full border border-slate-300 bg-gray-50/50 rounded-2xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm appearance-none">
                            <option value="draft">Draft</option>
                            <option value="sent">Sent</option>
                            <option value="paid">Paid</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="bg-white p-4 rounded-3xl shadow-sm border border-gray-100">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <h3 class="text-base font-bold text-slate-900 flex items-center">
                        <i data-lucide="list" class="w-4 h-4 mr-2 text-emerald-600"></i>
                        Line Items
                    </h3>
                    <button type="button" onclick="addItem()" class="flex items-center text-emerald-600 font-bold hover:text-emerald-700 transition-colors text-sm px-3 py-1.5 bg-emerald-50 rounded-xl">
                        <i data-lucide="plus-circle" class="w-4 h-4 mr-2"></i>
                        Add Line
                    </button>
                </div>

                <div class="hidden md:grid grid-cols-12 gap-3 px-1 pb-2 text-[11px] font-bold uppercase tracking-widest text-slate-400">
                    <div class="col-span-6">Description</div>
                    <div class="col-span-2 text-center">Qty</div>
                    <div class="col-span-2 text-right">Price</div>
                    <div class="col-span-2 text-right">Total</div>
                </div>

                <div id="items-container" class="space-y-3">
                    <div class="grid grid-cols-12 gap-3 item-row group">
                        <div class="col-span-12 md:col-span-6">
                            <input name="description[]" required placeholder="Item description" class="w-full border border-slate-300 bg-gray-50/50 rounded-xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm">
                        </div>
                        <div class="col-span-4 md:col-span-2">
                            <input name="quantity[]" type="number" step="0.01" value="1" class="w-full border border-slate-300 bg-gray-50/50 rounded-xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm quantity text-center" placeholder="Qty">
                        </div>
                        <div class="col-span-4 md:col-span-2">
                            <input name="unit_price[]" type="number" step="0.01" value="0.00" class="w-full border border-slate-300 bg-gray-50/50 rounded-xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm price text-right" placeholder="Price">
                        </div>
                        <div class="col-span-4 md:col-span-2">
                            <div class="relative">
                                <input readonly value="0.00" class="w-full border-transparent bg-emerald-50/30 text-emerald-700 font-bold rounded-xl px-3 py-2.5 text-sm line-total text-right outline-none">
                                <button type="button" onclick="this.closest('.item-row').remove(); calculateGrandTotal();" class="absolute -right-2 -top-2 bg-red-500 text-white p-1 rounded-full opacity-0 group-hover:opacity-100 transition-opacity">
                                    <i data-lucide="x" class="w-3 h-3"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white p-4 rounded-3xl shadow-sm border border-gray-100">
                <label class="block mb-2 text-[11px] font-bold text-gray-400 uppercase tracking-widest">Internal Notes / Terms</label>
                <textarea name="notes" rows="3" class="w-full border border-slate-300 bg-gray-50/50 rounded-2xl p-3 focus:ring-emerald-500 focus:bg-white transition-all text-sm" placeholder="Add any special instructions or terms..."></textarea>
            </div>
        </section>

        <aside class="space-y-4 xl:sticky xl:top-4">
            <div class="bg-white p-4 rounded-3xl shadow-sm border border-gray-100 space-y-4">
                <h3 class="text-sm font-black text-slate-900">Invoice Meta</h3>
                <div class="grid grid-cols-1 gap-4">
                    <div>
                        <label class="block mb-2 text-[11px] font-bold text-gray-400 uppercase tracking-widest">Issue Date</label>
                        <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required class="w-full border border-slate-300 bg-gray-50/50 rounded-2xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm">
                    </div>
                    <div>
                        <label class="block mb-2 text-[11px] font-bold text-gray-400 uppercase tracking-widest">Due Date</label>
                        <input type="date" name="due_date" class="w-full border border-slate-300 bg-gray-50/50 rounded-2xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm">
                    </div>
                </div>
            </div>

            <div class="bg-white p-4 rounded-3xl shadow-sm border border-gray-100 space-y-4">
                <div class="flex justify-between items-center pb-3 border-b border-gray-50">
                    <label class="text-[11px] font-bold text-gray-400 uppercase tracking-widest">Subtotal</label>
                    <span id="subtotal-display" class="font-mono font-bold text-slate-600">$0.00</span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block mb-2 text-[11px] font-bold text-blue-500 uppercase tracking-widest">Tax</label>
                        <input name="tax" id="tax-input" type="number" step="0.01" value="0.00" class="w-full border border-blue-200 bg-blue-50/20 rounded-xl px-3 py-2.5 focus:ring-blue-500 text-sm font-mono transition-all">
                    </div>
                    <div>
                        <label class="block mb-2 text-[11px] font-bold text-red-500 uppercase tracking-widest">Discount</label>
                        <input name="discount" id="discount-input" type="number" step="0.01" value="0.00" class="w-full border border-red-200 bg-red-50/20 rounded-xl px-3 py-2.5 focus:ring-red-500 text-sm font-mono transition-all">
                    </div>
                </div>

                <div class="bg-[#1a1a1a] px-4 py-4 rounded-2xl text-white">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-[11px] font-bold uppercase tracking-widest opacity-60">Grand Total</span>
                        <span id="total-display" class="text-xl font-black text-emerald-400">$0.00</span>
                    </div>
                </div>
            </div>

            <div class="bg-white p-4 rounded-3xl shadow-sm border border-gray-100">
                <div class="flex items-center justify-between gap-3">
                    <a href="<?= BASE_URL ?>/?page=invoices" class="text-xs font-bold text-gray-400 hover:text-slate-900 transition-colors">Discard</a>
                    <button class="bg-[#1a1a1a] hover:bg-emerald-600 text-white px-6 py-2.5 rounded-2xl font-black shadow-lg shadow-gray-200 transition-all hover:scale-105 active:scale-95 text-sm">
                        Save Invoice
                    </button>
                </div>
            </div>
        </aside>
    </div>
</form>

<script>
document.getElementById('invoice-customer-search')?.addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase().trim();
    document.querySelectorAll('[data-customer-option]').forEach((button) => {
        const name = button.dataset.customerName || '';
        button.style.display = name.includes(term) ? 'flex' : 'none';
    });
});

document.querySelectorAll('[data-customer-option]').forEach((button) => {
    button.addEventListener('click', () => {
        const select = document.getElementById('customer-select');
        if (!select) {
            return;
        }

        select.value = button.dataset.customerId || '';
        select.dispatchEvent(new Event('change', { bubbles: true }));

        document.querySelectorAll('[data-customer-option]').forEach((node) => {
            node.classList.remove('bg-emerald-50', 'border-emerald-200');
        });
        button.classList.add('bg-emerald-50', 'border-emerald-200');
    });
});

function addItem() {
    const row = `
        <div class="grid grid-cols-12 gap-3 item-row group animate-in slide-in-from-left duration-300">
            <div class="col-span-12 md:col-span-6">
                <input name="description[]" required placeholder="Item description" class="w-full border border-slate-300 bg-gray-50/50 rounded-xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm">
            </div>
            <div class="col-span-4 md:col-span-2">
                <input name="quantity[]" type="number" step="0.01" value="1" class="w-full border border-slate-300 bg-gray-50/50 rounded-xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm quantity text-center">
            </div>
            <div class="col-span-4 md:col-span-2">
                <input name="unit_price[]" type="number" step="0.01" value="0.00" class="w-full border border-slate-300 bg-gray-50/50 rounded-xl px-3 py-2.5 focus:ring-emerald-500 transition-all text-sm price text-right">
            </div>
            <div class="col-span-4 md:col-span-2 relative">
                <input readonly value="0.00" class="w-full border-transparent bg-emerald-50/30 text-emerald-700 font-bold rounded-xl px-3 py-2.5 text-sm line-total text-right outline-none">
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

// Initial calculation
calculateGrandTotal();
</script>
