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

// Paper trail: the quote this invoice was created from.
$sourceQuote = null;
if (!empty($invoice['quote_id'])) {
    $sq = $pdo->prepare("SELECT id, quote_number FROM quotes WHERE id = ? AND company_id = ?");
    $sq->execute([$invoice['quote_id'], current_company_id()]);
    $sourceQuote = $sq->fetch();
}

// Public share link (tokenized).
if (empty($invoice['share_token'])) {
    $tok = bin2hex(random_bytes(20));
    $pdo->prepare("UPDATE invoices SET share_token = ? WHERE id = ? AND company_id = ?")->execute([$tok, $id, current_company_id()]);
    $invoice['share_token'] = $tok;
}
$invScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$shareUrl  = $invScheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . BASE_URL . '/share.php?t=' . $invoice['share_token'];
$shareMsg  = 'Invoice ' . $invoice['invoice_number'] . ' (' . money($invoice['total']) . '): ' . $shareUrl;

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

<div class="mb-3">
    <div class="flex items-center space-x-2 text-sm text-gray-400 mb-3">
        <a href="<?= BASE_URL ?>/?page=invoices" class="hover:text-emerald-600 transition-colors">Invoices</a>
        <i data-lucide="chevron-right" class="w-4 h-4"></i>
        <span class="text-slate-900 font-medium"><?= e($invoice['invoice_number']) ?></span>
    </div>

    <?php if ($sourceQuote): ?>
    <div class="mb-4 inline-flex items-center gap-2 rounded-xl bg-amber-50 border border-amber-100 px-3 py-1.5 text-xs font-bold text-amber-700">
        <i data-lucide="git-merge" class="w-3.5 h-3.5"></i>
        Created from quote
        <a href="<?= BASE_URL ?>/?page=quotes-view&id=<?= (int)$sourceQuote['id'] ?>" class="font-mono underline hover:text-amber-900"><?= e($sourceQuote['quote_number']) ?></a>
    </div>
    <?php endif; ?>

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center">
            <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight mr-3"><?= e($invoice['invoice_number']) ?></h2>
            <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider border <?= getStatusBadge($invoice['status']) ?>">
                <?= e($invoice['status']) ?>
            </span>
        </div>

        <div class="flex items-center gap-2.5">
            <a href="https://wa.me/?text=<?= rawurlencode($shareMsg) ?>" target="_blank" rel="noopener" title="Share via WhatsApp" class="flex items-center justify-center w-12 h-12 rounded-2xl bg-[#25D366] text-white hover:opacity-90 transition shadow-lg shadow-green-100">
                <i data-lucide="message-circle" class="w-5 h-5"></i>
            </a>
            <button type="button" id="btnEmailInvoice"
                    data-invoice-id="<?= (int)$invoice['id'] ?>"
                    data-customer-email="<?= e($invoice['email'] ?? '') ?>"
                    title="<?= !empty($invoice['email']) ? 'Email this invoice to ' . e($invoice['email']) : 'This customer has no email address on file' ?>"
                    <?= empty($invoice['email']) ? 'disabled' : '' ?>
                    class="flex items-center justify-center w-12 h-12 rounded-2xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition disabled:opacity-40 disabled:cursor-not-allowed">
                <i data-lucide="mail" class="w-5 h-5"></i>
            </button>
            <a href="<?= BASE_URL ?>/pdf-invoice.php?id=<?= $invoice['id'] ?>" target="_blank" class="flex items-center bg-[#1a1a1a] hover:bg-emerald-600 text-white px-5 py-2.5 rounded-2xl font-bold transition-all shadow-lg shadow-gray-200">
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

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    <!-- Invoice Content -->
    <div class="lg:col-span-2 space-y-5">
        <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
            <div class="flex justify-between items-start mb-8">
                <div>
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-4">Client Information</h3>
                    <div class="text-xl font-black text-slate-900"><?= e($invoice['customer_name']) ?></div>
                    <div class="text-sm text-gray-500 mt-1"><?= e($invoice['email']) ?></div>
                    <div class="text-sm text-gray-500"><?= e($invoice['phone']) ?></div>
                    <div class="text-sm text-gray-400 mt-4 leading-relaxed italic max-w-xs">
                        <?= nl2br(e($invoice['address'])) ?>
                    </div>
                </div>

                <div class="text-right space-y-3">
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
                        <th class="py-3 text-xs font-bold text-gray-400 uppercase tracking-widest">Description</th>
                        <th class="py-3 text-xs font-bold text-gray-400 uppercase tracking-widest text-center">Qty</th>
                        <th class="py-3 text-xs font-bold text-gray-400 uppercase tracking-widest text-right">Unit Price</th>
                        <th class="py-3 text-xs font-bold text-gray-400 uppercase tracking-widest text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="py-3.5">
                                <div class="text-sm font-bold text-slate-800"><?= e($item['description']) ?></div>
                            </td>
                            <td class="py-3.5 text-center text-sm text-gray-500"><?= e($item['quantity']) ?></td>
                            <td class="py-3.5 text-right text-sm text-gray-500"><?= money($item['unit_price']) ?></td>
                            <td class="py-3.5 text-right font-bold text-slate-900"><?= money($item['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($invoice['notes']): ?>
                <div class="mt-8 p-4 bg-gray-50 rounded-2xl border border-gray-100">
                    <h4 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Notes & Terms</h4>
                    <p class="text-sm text-gray-600 leading-relaxed"><?= nl2br(e($invoice['notes'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary Sidebar -->
    <div class="space-y-5">
        <div class="bg-white p-5 rounded-3xl shadow-sm border border-gray-100 space-y-4">
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
                
                <div class="pt-3 border-t border-gray-50 flex justify-between items-end">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Total Amount</span>
                    <span class="text-2xl font-black text-slate-900"><?= money($invoice['total']) ?></span>
                </div>
            </div>
        </div>

        <div class="bg-[#1a1a1a] p-5 rounded-3xl shadow-xl shadow-gray-200 space-y-4 text-white">
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
                <form method="POST" class="pt-2">
                    <button name="mark_paid" value="1" class="w-full bg-emerald-500 hover:bg-emerald-600 text-[#1a1a1a] font-black py-3 rounded-2xl transition-all shadow-lg shadow-emerald-900/20 flex items-center justify-center">
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

<script>
document.getElementById('btnEmailInvoice')?.addEventListener('click', async function () {
    const btn   = this;
    const email = btn.dataset.customerEmail;
    if (!email) return;
    if (!confirm('Email this invoice to ' + email + '?')) return;

    const icon = btn.querySelector('i');
    btn.disabled = true;
    if (icon) icon.setAttribute('data-lucide', 'loader');

    try {
        const res = await fetch('<?= BASE_URL ?>/api/send_invoice_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ invoice_id: Number(btn.dataset.invoiceId) })
        });
        const data = await res.json();

        if (!res.ok || data.error) {
            alert(data.error || ('Could not send the invoice (HTTP ' + res.status + ').'));
            btn.disabled = false;
            if (icon) { icon.setAttribute('data-lucide', 'mail'); window.lucide?.createIcons(); }
            return;
        }

        // MAIL_DRIVER not set to smtp means the send was only logged, never
        // actually delivered - say so rather than claiming it was sent.
        alert(data.mail_status === 'logged'
            ? 'Email logged but not delivered: ' + (data.note || 'mail sending is not configured.')
            : 'Invoice emailed to ' + data.email + '.');
        window.location.reload();
    } catch (err) {
        alert('Network error while sending: ' + err.message);
        btn.disabled = false;
        if (icon) { icon.setAttribute('data-lucide', 'mail'); window.lucide?.createIcons(); }
    }
});
</script>
