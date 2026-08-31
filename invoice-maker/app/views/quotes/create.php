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

    redirect_response(BASE_URL . '/?page=quotes-view&id=' . $quoteId);
}

$quoteNumber = 'QUO-' . date('Ymd') . '-' . rand(100, 999);
?>

<div class="biz">
<div class="mb-3">
    <p class="biz-kicker"><a href="<?= BASE_URL ?>/?page=quotes" class="biz-t-green">Quotes</a> · new</p>
    <h1 class="mt-0.5">Create quote</h1>
</div>

<form method="POST">
    <div class="grid grid-cols-1 xl:grid-cols-[220px_minmax(0,1fr)_270px] gap-3 items-start">
        <aside class="biz-panel overflow-hidden xl:sticky xl:top-2">
            <div class="biz-panel-head"><span>Clients</span>
                <a href="<?= BASE_URL ?>/?page=customers&action=create" class="biz-t-green" style="font-size:11px;font-weight:700">New</a>
            </div>
            <div class="biz-panel-body" style="border-bottom:1px solid var(--bz-line)">
                <input type="text" id="quote-customer-search" placeholder="Search clients…" class="biz-input">
            </div>
            <div id="quote-customer-list" class="max-h-[380px] overflow-y-auto custom-scrollbar biz-list">
                <?php foreach ($customers as $customer): ?>
                    <button type="button" data-customer-option
                        data-customer-id="<?= (int)$customer['id'] ?>"
                        data-customer-name="<?= e(strtolower($customer['name'])) ?>"
                        class="biz-row" style="align-items:flex-start">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded font-bold" style="font-size:11px;background:var(--bz-line-soft);color:var(--bz-muted)"><?= strtoupper(substr($customer['name'], 0, 1)) ?></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-bold"><?= e($customer['name']) ?></span>
                            <span class="block truncate biz-muted" style="font-size:11px"><?= e($customer['company'] ?: 'No company') ?></span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="space-y-3 min-w-0">
            <div class="biz-panel biz-panel-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label class="block md:col-span-2"><span class="biz-label">Client</span>
                        <select id="customer-select" name="customer_id" required class="biz-select">
                            <option value="">Select client</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?= $customer['id'] ?>"><?= e($customer['name']) ?></option>
                            <?php endforeach; ?>
                        </select></label>
                    <label class="block"><span class="biz-label">Quote number</span>
                        <input name="quote_number" value="<?= $quoteNumber ?>" required class="biz-input" style="font-family:ui-monospace,monospace"></label>
                    <label class="block"><span class="biz-label">Status</span>
                        <select name="status" class="biz-select">
                            <option value="draft">Draft</option><option value="sent">Sent</option><option value="accepted">Accepted</option>
                        </select></label>
                </div>
            </div>

            <div class="biz-panel">
                <div class="biz-panel-head"><span>Quote items</span>
                    <button type="button" onclick="addItem()" class="biz-btn biz-btn-ghost biz-btn-sm"><i data-lucide="plus" class="w-3 h-3"></i> Add line</button>
                </div>
                <div class="biz-panel-body">
                    <div class="hidden md:grid grid-cols-12 gap-2 px-1 pb-1 biz-label" style="margin-bottom:0">
                        <div class="col-span-6">Description</div>
                        <div class="col-span-2 text-center">Qty</div>
                        <div class="col-span-2 text-right">Price</div>
                        <div class="col-span-2 text-right">Total</div>
                    </div>
                    <div id="items-container" class="space-y-2">
                        <div class="grid grid-cols-12 gap-2 item-row group">
                            <div class="col-span-12 md:col-span-6"><input name="description[]" required placeholder="Service or product description" class="biz-input"></div>
                            <div class="col-span-4 md:col-span-2"><input name="quantity[]" type="number" step="0.01" value="1" class="biz-input biz-num quantity" style="text-align:center"></div>
                            <div class="col-span-4 md:col-span-2"><input name="unit_price[]" type="number" step="0.01" value="0.00" class="biz-input biz-num price" style="text-align:right"></div>
                            <div class="col-span-4 md:col-span-2 flex items-center gap-1">
                                <input readonly value="0.00" class="biz-input biz-num line-total" style="text-align:right;background:var(--bz-head)">
                                <button type="button" onclick="this.closest('.item-row').remove(); calculateGrandTotal();" class="biz-t-red shrink-0" style="font-size:12px">&times;</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="biz-panel biz-panel-body">
                <label class="block"><span class="biz-label">Notes</span>
                    <textarea name="notes" rows="3" class="biz-input" placeholder="Optional terms or notes for this quote…"></textarea></label>
            </div>
        </section>

        <aside class="space-y-3 xl:sticky xl:top-2">
            <div class="biz-panel biz-panel-body">
                <div class="grid grid-cols-2 gap-3">
                    <label class="block"><span class="biz-label">Issue date</span>
                        <input type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required class="biz-input"></label>
                    <label class="block"><span class="biz-label">Expiry date</span>
                        <input type="date" name="expiry_date" class="biz-input"></label>
                </div>
            </div>

            <div class="biz-panel biz-panel-body space-y-2">
                <div class="flex justify-between items-center">
                    <span class="biz-label" style="margin:0">Subtotal</span>
                    <span id="subtotal-display" class="biz-num font-bold">$0.00</span>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <label class="block"><span class="biz-label">Tax</span>
                        <input name="tax" id="tax-input" type="number" step="0.01" value="0.00" class="biz-input biz-num" style="text-align:right"></label>
                    <label class="block"><span class="biz-label">Discount</span>
                        <input name="discount" id="discount-input" type="number" step="0.01" value="0.00" class="biz-input biz-num" style="text-align:right"></label>
                </div>
                <div class="flex items-center justify-between rounded px-3 py-2" style="background:#0f172a;color:#fff">
                    <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:.7">Estimated total</span>
                    <span id="total-display" class="biz-num" style="font-size:16px;font-weight:800;color:#34d399">$0.00</span>
                </div>
            </div>

            <div class="biz-panel biz-panel-body flex items-center justify-between gap-2">
                <a href="<?= BASE_URL ?>/?page=quotes" class="biz-btn biz-btn-ghost">Discard</a>
                <button class="biz-btn biz-btn-primary">Save quote</button>
            </div>
        </aside>
    </div>
</form>
</div>
<!-- /.biz -->

<script>
document.getElementById('quote-customer-search')?.addEventListener('input', function(e) {
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
            node.classList.remove('is-active');
        });
        button.classList.add('is-active');
    });
});

function addItem() {
    const row = `
        <div class="grid grid-cols-12 gap-2 item-row group">
            <div class="col-span-12 md:col-span-6"><input name="description[]" required placeholder="Service or product description" class="biz-input"></div>
            <div class="col-span-4 md:col-span-2"><input name="quantity[]" type="number" step="0.01" value="1" class="biz-input biz-num quantity" style="text-align:center"></div>
            <div class="col-span-4 md:col-span-2"><input name="unit_price[]" type="number" step="0.01" value="0.00" class="biz-input biz-num price" style="text-align:right"></div>
            <div class="col-span-4 md:col-span-2 flex items-center gap-1">
                <input readonly value="0.00" class="biz-input biz-num line-total" style="text-align:right;background:var(--bz-head)">
                <button type="button" onclick="this.closest('.item-row').remove(); calculateGrandTotal();" class="biz-t-red shrink-0" style="font-size:12px">&times;</button>
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
