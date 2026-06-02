<?php
$id = $_GET['id'] ?? null;

$stmt = $pdo->prepare("
    SELECT invoices.*, customers.name AS customer_name, customers.email, customers.phone, customers.address
    FROM invoices
    JOIN customers ON customers.id = invoices.customer_id
    WHERE invoices.id = ? AND invoices.company_id = ?
");
$stmt->execute([$id, current_company_id()]);
$invoice = $stmt->fetch();

if (!$invoice) {
    echo '<div class="bg-red-50 text-red-600 p-8 rounded-3xl text-center">
            <i data-lucide="file-x" class="w-12 h-12 mx-auto mb-4"></i>
            <p class="font-bold text-xl">Invoice not found.</p>
            <a href="'.BASE_URL.'/?page=invoices" class="text-sm underline mt-4 block">Return to list</a>
          </div>';
    return;
}

$itemStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_paid'])) {
        $stmt = $pdo->prepare("
            UPDATE invoices
            SET status = 'paid', amount_paid = total
            WHERE id = ? AND company_id = ?
        ");
        $stmt->execute([$id, current_company_id()]);

        header('Location: ' . BASE_URL . '/?page=invoices-view&id=' . $id);
        exit;
    }

    if (isset($_POST['delete'])) {
        $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, current_company_id()]);

        header('Location: ' . BASE_URL . '/?page=invoices');
        exit;
    }
}

function getStatusBadge($status) {
    return match($status) {
        'paid' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
        'sent' => 'bg-blue-50 text-blue-700 border-blue-100',
        'draft' => 'bg-gray-50 text-gray-600 border-gray-200',
        'overdue' => 'bg-red-50 text-red-700 border-red-100',
        'cancelled' => 'bg-slate-100 text-slate-500 border-slate-200',
        default => 'bg-gray-50 text-gray-600 border-gray-200'
    };
}
?>

<div class="mb-10">
    <div class="flex items-center space-x-2 text-sm text-gray-400 mb-4">
        <a href="<?= BASE_URL ?>/?page=invoices" class="hover:text-emerald-600 transition-colors">Invoices</a>
        <i data-lucide="chevron-right" class="w-4 h-4"></i>
        <span class="text-slate-900 font-medium"><?= e($invoice['invoice_number']) ?></span>
    </div>
    
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div class="flex items-center">
            <h2 class="text-4xl font-extrabold text-slate-900 tracking-tight mr-4"><?= e($invoice['invoice_number']) ?></h2>
            <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider border <?= getStatusBadge($invoice['status']) ?>">
                <?= e($invoice['status']) ?>
            </span>
        </div>

        <div class="flex items-center gap-3">
            <a href="<?= BASE_URL ?>/pdf-invoice.php?id=<?= $invoice['id'] ?>" target="_blank" class="flex items-center bg-[#1a1a1a] hover:bg-emerald-600 text-white px-6 py-3 rounded-2xl font-bold transition-all shadow-lg shadow-gray-200">
                <i data-lucide="download" class="w-4 h-4 mr-2"></i>
                Download PDF
            </a>
            
            <form method="POST" onsubmit="return confirm('Delete this invoice permanently?')">
                <button name="delete" value="1" class="p-3 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-2xl transition-all" title="Delete Invoice">
                    <i data-lucide="trash-2" class="w-5 h-5"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    <!-- Invoice Content -->
    <div class="lg:col-span-2 space-y-8">
        <div class="bg-white p-10 rounded-3xl shadow-sm border border-gray-100">
            <div class="flex justify-between items-start mb-12">
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-4">Client Information</h3>
                    <div class="text-xl font-black text-slate-900"><?= e($invoice['customer_name']) ?></div>
                    <div class="text-sm text-gray-500 mt-1"><?= e($invoice['email']) ?></div>
                    <div class="text-sm text-gray-500"><?= e($invoice['phone']) ?></div>
                    <div class="text-sm text-gray-400 mt-4 leading-relaxed italic max-w-xs">
                        <?= nl2br(e($invoice['address'])) ?>
                    </div>
                </div>

                <div class="text-right space-y-4">
                    <div>
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-widest">Issue Date</div>
                        <div class="text-sm font-bold text-slate-900"><?= date('M d, Y', strtotime($invoice['issue_date'])) ?></div>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-gray-400 uppercase tracking-widest text-red-400">Due Date</div>
                        <div class="text-sm font-bold text-slate-900"><?= date('M d, Y', strtotime($invoice['due_date'])) ?></div>
                    </div>
                </div>
            </div>

            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-gray-100">
                        <th class="py-4 text-xs font-bold text-gray-400 uppercase tracking-widest">Description</th>
                        <th class="py-4 text-xs font-bold text-gray-400 uppercase tracking-widest text-center">Qty</th>
                        <th class="py-4 text-xs font-bold text-gray-400 uppercase tracking-widest text-right">Unit Price</th>
                        <th class="py-4 text-xs font-bold text-gray-400 uppercase tracking-widest text-right">Amount</th>
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

            <?php if ($invoice['notes']): ?>
                <div class="mt-12 p-6 bg-gray-50 rounded-2xl border border-gray-100">
                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Notes & Terms</h4>
                    <p class="text-sm text-gray-600 leading-relaxed"><?= nl2br(e($invoice['notes'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary Sidebar -->
    <div class="space-y-6">
        <div class="bg-white p-8 rounded-3xl shadow-sm border border-gray-100 space-y-6">
            <h3 class="text-lg font-bold text-slate-900 flex items-center">
                <i data-lucide="pie-chart" class="w-5 h-5 mr-3 text-emerald-600"></i>
                Summary
            </h3>

            <div class="space-y-4">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500 font-medium">Subtotal</span>
                    <span class="font-bold text-slate-700"><?= money($invoice['subtotal']) ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-blue-500 font-medium">Tax</span>
                    <span class="font-bold text-blue-600"><?= money($invoice['tax']) ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-red-500 font-medium">Discount</span>
                    <span class="font-bold text-red-600">-<?= money($invoice['discount']) ?></span>
                </div>
                
                <div class="pt-4 border-t border-gray-50 flex justify-between items-end">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Total Amount</span>
                    <span class="text-3xl font-black text-slate-900"><?= money($invoice['total']) ?></span>
                </div>
            </div>
        </div>

        <div class="bg-[#1a1a1a] p-8 rounded-3xl shadow-xl shadow-gray-200 space-y-6 text-white">
            <h3 class="text-lg font-bold flex items-center">
                <i data-lucide="wallet" class="w-5 h-5 mr-3 text-emerald-400"></i>
                Payment
            </h3>

            <div class="space-y-4">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-400">Already Paid</span>
                    <span class="font-bold text-emerald-400"><?= money($invoice['amount_paid']) ?></span>
                </div>
                <div class="flex justify-between text-lg pt-2">
                    <span class="text-gray-400">Balance Due</span>
                    <span class="font-black text-white"><?= money($invoice['total'] - $invoice['amount_paid']) ?></span>
                </div>
            </div>

            <?php if ($invoice['status'] !== 'paid'): ?>
                <form method="POST" class="pt-4">
                    <button name="mark_paid" value="1" class="w-full bg-emerald-500 hover:bg-emerald-600 text-[#1a1a1a] font-black py-4 rounded-2xl transition-all shadow-lg shadow-emerald-900/20 flex items-center justify-center">
                        <i data-lucide="check-circle-2" class="w-5 h-5 mr-2"></i>
                        Mark as Paid
                    </button>
                </form>
            <?php else: ?>
                <div class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 p-4 rounded-2xl text-center text-sm font-bold flex items-center justify-center">
                    <i data-lucide="shield-check" class="w-4 h-4 mr-2"></i>
                    Fully Paid
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
