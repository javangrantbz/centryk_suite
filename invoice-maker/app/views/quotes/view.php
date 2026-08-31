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
    echo '<div class="biz"><div class="biz-notice biz-notice-red">Quote not found. <a href="'.BASE_URL.'/?page=quotes" class="underline">Return to list</a></div></div>';
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
        redirect_response(BASE_URL . '/?page=invoices-view&id=' . (int)$convertedInvoice['id']);
    }
    if (isset($_POST['accept'])) {
        $stmt = $pdo->prepare("UPDATE quotes SET status = 'accepted' WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, current_company_id()]);
        redirect_response(BASE_URL . '/?page=quotes-view&id=' . $id);
    }

    if (isset($_POST['reject'])) {
        $stmt = $pdo->prepare("UPDATE quotes SET status = 'rejected' WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, current_company_id()]);
        redirect_response(BASE_URL . '/?page=quotes-view&id=' . $id);
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

        redirect_response(BASE_URL . '/?page=invoices-view&id=' . $invoiceId);
    }

    if (isset($_POST['delete'])) {
        $stmt = $pdo->prepare("DELETE FROM quotes WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, current_company_id()]);
        redirect_response(BASE_URL . '/?page=quotes');
    }
}

// Public share link (tokenized).
if (empty($quote['share_token'])) {
    $tok = bin2hex(random_bytes(20));
    $pdo->prepare("UPDATE quotes SET share_token = ? WHERE id = ? AND company_id = ?")->execute([$tok, $id, current_company_id()]);
    $quote['share_token'] = $tok;
}
$invScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$shareUrl  = $invScheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . BASE_URL . '/share.php?t=' . $quote['share_token'];
$shareMsg  = 'Quote ' . $quote['quote_number'] . ' (' . money($quote['total']) . '): ' . $shareUrl;
?>

<div class="biz">
<div class="mb-3">
    <p class="biz-kicker"><a href="<?= BASE_URL ?>/?page=quotes" class="biz-t-green">Quotes</a> · <?= e($quote['quote_number']) ?></p>

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mt-0.5">
        <div class="flex items-center gap-2 flex-wrap">
            <h1 class="mt-0" style="font-family:ui-monospace,monospace"><?= e($quote['quote_number']) ?></h1>
            <span class="biz-chip <?= inv_status_chip($quote['status']) ?>"><?= e($quote['status']) ?></span>
            <?php if ($convertedInvoice): ?>
            <a href="<?= BASE_URL ?>/?page=invoices-view&id=<?= (int)$convertedInvoice['id'] ?>" class="biz-chip biz-c-green" style="text-decoration:none">→ <?= e($convertedInvoice['invoice_number']) ?></a>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-1.5">
            <a href="https://wa.me/?text=<?= rawurlencode($shareMsg) ?>" target="_blank" rel="noopener" class="biz-btn biz-btn-ghost biz-btn-sm" title="Share via WhatsApp"><i data-lucide="message-circle" class="w-3.5 h-3.5"></i></a>
            <a href="mailto:?subject=<?= rawurlencode('Quote ' . $quote['quote_number']) ?>&body=<?= rawurlencode($shareMsg) ?>" class="biz-btn biz-btn-ghost biz-btn-sm" title="Share via Email"><i data-lucide="mail" class="w-3.5 h-3.5"></i></a>
            <a href="<?= BASE_URL ?>/pdf-quote.php?id=<?= $quote['id'] ?>" target="_blank" class="biz-btn biz-btn-primary biz-btn-sm"><i data-lucide="download" class="w-3.5 h-3.5"></i> PDF</a>
            <form method="POST" onsubmit="return confirm('Delete this quote estimate?')">
                <button name="delete" value="1" class="biz-btn biz-btn-danger biz-btn-sm" title="Delete quote"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
            </form>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_300px] gap-3 items-start">
    <div class="min-w-0">
        <div class="biz-panel biz-panel-body">
            <div class="flex justify-between items-start gap-4 mb-4">
                <div>
                    <p class="biz-label">Prospect</p>
                    <div class="font-bold" style="font-size:14px"><?= e($quote['customer_name']) ?></div>
                    <div class="biz-muted"><?= e($quote['email']) ?></div>
                    <div class="biz-muted"><?= e($quote['phone']) ?></div>
                    <div class="biz-muted mt-2" style="max-width:20rem;white-space:pre-line"><?= e($quote['address']) ?></div>
                </div>
                <div class="text-right space-y-2 shrink-0">
                    <div><p class="biz-label">Quote date</p><div class="font-bold"><?= date('j M Y', strtotime($quote['issue_date'])) ?></div></div>
                    <div><p class="biz-label">Valid until</p><div class="font-bold"><?= date('j M Y', strtotime($quote['expiry_date'])) ?></div></div>
                </div>
            </div>

            <table class="w-full biz-num" style="font-size:12px">
                <thead>
                    <tr class="biz-muted" style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid var(--bz-line)">
                        <th class="py-2 font-bold">Description</th>
                        <th class="py-2 font-bold text-center">Qty</th>
                        <th class="py-2 font-bold text-right">Unit price</th>
                        <th class="py-2 font-bold text-right">Estimate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr style="border-top:1px solid var(--bz-line-soft)">
                            <td class="py-2 font-bold" style="font-family:inherit"><?= e($item['description']) ?></td>
                            <td class="py-2 text-center biz-muted"><?= e($item['quantity']) ?></td>
                            <td class="py-2 text-right biz-muted"><?= money($item['unit_price']) ?></td>
                            <td class="py-2 text-right font-bold"><?= money($item['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($quote['notes']): ?>
                <div class="mt-4 p-3 rounded" style="background:var(--bz-head);border:1px solid var(--bz-line-soft)">
                    <p class="biz-label">Notes &amp; terms</p>
                    <p class="biz-muted" style="white-space:pre-line"><?= e($quote['notes']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="space-y-3 lg:sticky lg:top-2">
        <div class="biz-panel biz-panel-body space-y-2">
            <div class="flex justify-between"><span class="biz-muted">Subtotal</span><span class="biz-num font-bold"><?= money($quote['subtotal']) ?></span></div>
            <div class="flex justify-between"><span class="biz-muted">Tax</span><span class="biz-num font-bold"><?= money($quote['tax']) ?></span></div>
            <div class="flex justify-between"><span class="biz-muted">Discount</span><span class="biz-num font-bold">-<?= money($quote['discount']) ?></span></div>
            <div class="flex justify-between items-end pt-2" style="border-top:1px solid var(--bz-line)">
                <span class="biz-label" style="margin:0">Total</span>
                <span class="biz-num" style="font-size:18px;font-weight:800"><?= money($quote['total']) ?></span>
            </div>
        </div>

        <div class="biz-panel biz-panel-body space-y-2">
            <div class="biz-label" style="margin:0">Actions</div>
            <form method="POST" class="space-y-2">
                <?php if ($convertedInvoice): ?>
                <a href="<?= BASE_URL ?>/?page=invoices-view&id=<?= (int)$convertedInvoice['id'] ?>" class="biz-btn biz-btn-primary" style="width:100%">View invoice <?= e($convertedInvoice['invoice_number']) ?></a>
                <?php else: ?>
                <button name="convert" value="1" class="biz-btn biz-btn-primary" style="width:100%">Convert to invoice</button>
                <?php endif; ?>
                <div class="grid grid-cols-2 gap-2">
                    <?php if ($quote['status'] !== 'accepted'): ?>
                        <button name="accept" value="1" class="biz-btn biz-btn-ghost">Accept</button>
                    <?php endif; ?>
                    <?php if ($quote['status'] !== 'rejected'): ?>
                        <button name="reject" value="1" class="biz-btn biz-btn-ghost">Reject</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
</div>
<!-- /.biz -->
