<?php
$id = $_GET['id'] ?? null;

$stmt = $pdo->prepare("
    SELECT quotes.*, customers.name AS customer_name, customers.email, customers.phone, customers.address
    FROM quotes
    JOIN customers ON customers.id = quotes.customer_id
    WHERE quotes.id = ? AND quotes.company_id = ?
");
$stmt->execute([$id, current_company_id()]);
$quote = $stmt->fetch();

if (!$quote) {
    echo '<div class="bg-red-50 text-red-600 p-8 rounded-3xl text-center">
            <i data-lucide="file-x" class="w-12 h-12 mx-auto mb-4"></i>
            <p class="font-bold text-xl">Quote not found.</p>
            <a href="'.BASE_URL.'/?page=quotes" class="text-sm underline mt-4 block">Return to list</a>
          </div>';
    return;
}

$itemStmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = ?");
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

// Paper trail: has this quote already been converted to an invoice?
$ci = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE quote_id = ? AND company_id = ? LIMIT 1");
$ci->execute([$id, current_company_id()]);
$convertedInvoice = $ci->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Never create a second invoice from the same quote.
    if (isset($_POST['convert']) && $convertedInvoice) {
        header('Location: ' . BASE_URL . '/?page=invoices-view&id=' . (int)$convertedInvoice['id']);
        exit;
    }
    if (isset($_POST['accept'])) {
        $stmt = $pdo->prepare("UPDATE quotes SET status = 'accepted' WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, current_company_id()]);
        header('Location: ' . BASE_URL . '/?page=quotes-view&id=' . $id);
        exit;
    }

    if (isset($_POST['reject'])) {
        $stmt = $pdo->prepare("UPDATE quotes SET status = 'rejected' WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, current_company_id()]);
        header('Location: ' . BASE_URL . '/?page=quotes-view&id=' . $id);
        exit;
    }

    if (isset($_POST['convert'])) {
        $invoiceNumber = 'INV-' . date('Ymd') . '-' . rand(100, 999);
        $invoiceStmt = $pdo->prepare("
            INSERT INTO invoices
            (company_id, customer_id, quote_id, invoice_number, status, issue_date, due_date, subtotal, tax, discount, total, notes)
            VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?)
        ");

        $issueDate = date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime('+30 days'));

        $invoiceStmt->execute([
            current_company_id(),
            $quote['customer_id'],
            $quote['id'],
            $invoiceNumber,
            $issueDate,
            $dueDate,
            $quote['subtotal'],
            $quote['tax'],
            $quote['discount'],
            $quote['total'],
            $quote['notes']
        ]);

        $invoiceId = $pdo->lastInsertId();
        $copyItemStmt = $pdo->prepare("
            INSERT INTO invoice_items
            (invoice_id, description, quantity, unit_price, total)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($items as $item) {
            $copyItemStmt->execute([
                $invoiceId,
                $item['description'],
                $item['quantity'],
                $item['unit_price'],
                $item['total']
            ]);
        }

        $updateQuote = $pdo->prepare("UPDATE quotes SET status = 'accepted' WHERE id = ? AND company_id = ?");
        $updateQuote->execute([$id, current_company_id()]);

        header('Location: ' . BASE_URL . '/?page=invoices-view&id=' . $invoiceId);
        exit;
    }

    if (isset($_POST['delete'])) {
        $stmt = $pdo->prepare("DELETE FROM quotes WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, current_company_id()]);
        header('Location: ' . BASE_URL . '/?page=quotes');
        exit;
    }
}

function getQuoteStatusBadge($status) {
    return match($status) {
        'accepted' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
        'sent' => 'bg-blue-50 text-blue-700 border-blue-100',
        'draft' => 'bg-gray-50 text-gray-600 border-gray-200',
        'rejected' => 'bg-red-50 text-red-700 border-red-100',
        'expired' => 'bg-slate-100 text-slate-500 border-slate-200',
        default => 'bg-gray-50 text-gray-600 border-gray-200'
    };
}
?>

<div class="mb-10">
    <div class="flex items-center space-x-2 text-sm text-gray-400 mb-4">
        <a href="<?= BASE_URL ?>/?page=quotes" class="hover:text-emerald-600 transition-colors">Quotes</a>
        <i data-lucide="chevron-right" class="w-4 h-4"></i>
        <span class="text-slate-900 font-medium"><?= e($quote['quote_number']) ?></span>
    </div>

    <?php if ($convertedInvoice): ?>
    <div class="mb-4 inline-flex items-center gap-2 rounded-xl bg-emerald-50 border border-emerald-100 px-3 py-1.5 text-xs font-bold text-emerald-700">
        <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>
        Converted to invoice
        <a href="<?= BASE_URL ?>/?page=invoices-view&id=<?= (int)$convertedInvoice['id'] ?>" class="font-mono underline hover:text-emerald-900"><?= e($convertedInvoice['invoice_number']) ?></a>
    </div>
    <?php endif; ?>

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div class="flex items-center">
            <h2 class="text-4xl font-extrabold text-slate-900 tracking-tight mr-4"><?= e($quote['quote_number']) ?></h2>
            <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider border <?= getQuoteStatusBadge($quote['status']) ?>">
                <?= e($quote['status']) ?>
            </span>
        </div>

        <div class="flex items-center gap-3">
            <a href="<?= BASE_URL ?>/pdf-quote.php?id=<?= $quote['id'] ?>" target="_blank" class="flex items-center bg-[#1a1a1a] hover:bg-emerald-600 text-white px-6 py-3 rounded-2xl font-bold transition-all shadow-lg shadow-gray-200">
                <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                Download PDF
            </a>
            
            <form method="POST" onsubmit="return confirm('Delete this quote estimate?')">
                <button name="delete" value="1" class="p-3 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-2xl transition-all" title="Delete Quote">
                    <i data-lucide="trash-2" class="w-5 h-5"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <div class="lg:col-span-2 space-y-8">
        <div class="bg-white p-10 rounded-3xl shadow-sm border border-gray-100">
            <div class="flex justify-between items-start mb-12">
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-4">Potential Client</h3>
                    <div class="text-xl font-black text-slate-900"><?= e($quote['customer_name']) ?></div>
                    <div class="text-sm text-gray-500 mt-1"><?= e($quote['email']) ?></div>
                    <div class="text-sm text-gray-500"><?= e($quote['phone']) ?></div>
                    <div class="text-sm text-gray-400 mt-4 leading-relaxed italic max-w-xs">
                        <?= nl2br(e($quote['address'])) ?>
                    </div>
                </div>

                <div class="text-right space-y-4">
                    <div>
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-widest">Quote Date</div>
                        <div class="text-sm font-bold text-slate-900"><?= date('M d, Y', strtotime($quote['issue_date'])) ?></div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-widest text-amber-500">Valid Until</div>
                        <div class="text-sm font-bold text-slate-900"><?= date('M d, Y', strtotime($quote['expiry_date'])) ?></div>
                    </div>
                </div>
            </div>

            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-gray-100">
                        <th class="py-4 text-xs font-bold text-gray-400 uppercase tracking-widest">Service Description</th>
                        <th class="py-4 text-xs font-bold text-gray-400 uppercase tracking-widest text-center">Qty</th>
                        <th class="py-4 text-xs font-bold text-gray-400 uppercase tracking-widest text-right">Unit Price</th>
                        <th class="py-4 text-xs font-bold text-gray-400 uppercase tracking-widest text-right">Estimate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="py-5">
                                <div class="text-sm font-bold text-slate-800"><?= e($item['description']) ?></div>
                            </td>
                            <td class="py-5 text-center text-sm text-gray-500"><?= e($item['quantity']) ?></td>
                            <td class="py-5 text-right text-sm text-gray-500"><?= money($item['unit_price']) ?></td>
                            <td class="py-5 text-right font-bold text-slate-900"><?= money($item['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($quote['notes']): ?>
                <div class="mt-12 p-6 bg-gray-50 rounded-2xl border border-gray-100">
                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Notes & Terms</h4>
                    <p class="text-sm text-gray-600 leading-relaxed"><?= nl2br(e($quote['notes'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100 space-y-6">
            <h3 class="text-lg font-bold text-slate-900 flex items-center">
                <i data-lucide="calculator" class="w-5 h-5 mr-3 text-emerald-600"></i>
                Total Estimate
            </h3>

            <div class="space-y-4">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500 font-medium">Subtotal</span>
                    <span class="font-bold text-slate-700"><?= money($quote['subtotal']) ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-blue-500 font-medium">Tax</span>
                    <span class="font-bold text-blue-600"><?= money($quote['tax']) ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-red-500 font-medium">Discount</span>
                    <span class="font-bold text-red-600">-<?= money($quote['discount']) ?></span>
                </div>
                
                <div class="pt-4 border-t border-gray-50 flex justify-between items-end">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Total</span>
                    <span class="text-3xl font-black text-slate-900"><?= money($quote['total']) ?></span>
                </div>
            </div>
        </div>

        <div class="bg-[#1a1a1a] p-8 rounded-3xl shadow-xl shadow-gray-200 space-y-4 text-white">
            <h3 class="text-lg font-bold flex items-center mb-6">
                <i data-lucide="zap" class="w-5 h-5 mr-3 text-emerald-400"></i>
                Actions
            </h3>

            <form method="POST" class="space-y-3">
                <?php if ($convertedInvoice): ?>
                <a href="<?= BASE_URL ?>/?page=invoices-view&id=<?= (int)$convertedInvoice['id'] ?>" class="w-full bg-emerald-500 hover:bg-emerald-600 text-[#1a1a1a] font-black py-4 rounded-2xl transition-all flex items-center justify-center">
                    <i data-lucide="external-link" class="w-5 h-5 mr-2"></i>
                    View Invoice <?= e($convertedInvoice['invoice_number']) ?>
                </a>
                <?php else: ?>
                <button name="convert" value="1" class="w-full bg-emerald-500 hover:bg-emerald-600 text-[#1a1a1a] font-black py-4 rounded-2xl transition-all flex items-center justify-center">
                    <i data-lucide="file-check-2" class="w-5 h-5 mr-2"></i>
                    Convert to Invoice
                </button>
                <?php endif; ?>

                <div class="grid grid-cols-2 gap-3">
                    <?php if ($quote['status'] !== 'accepted'): ?>
                        <button name="accept" value="1" class="bg-gray-800 hover:bg-gray-700 text-emerald-400 font-bold py-3 rounded-2xl transition-all text-sm">
                            Accept
                        </button>
                    <?php endif; ?>

                    <?php if ($quote['status'] !== 'rejected'): ?>
                        <button name="reject" value="1" class="bg-gray-800 hover:bg-gray-700 text-red-400 font-bold py-3 rounded-2xl transition-all text-sm">
                            Reject
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
