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
    echo '<div class="biz"><div class="biz-notice biz-notice-red">Invoice not found. <a href="'.BASE_URL.'/?page=invoices" class="underline">Return to list</a></div></div>';
    return;
}

$itemStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

// BTS e-invoicing: the fiscal document built from this invoice, if any, plus
// whether this company has fiscal invoicing switched on at all.
require_once dirname(__DIR__, 4) . '/app/services/FiscalInvoicingService.php';
$fiscalProfile = FiscalInvoicingService::getProfile(current_company_id());
$fiscalEnabled = !empty($fiscalProfile['enabled']);
$fiscalDocStmt = $pdo->prepare(
    "SELECT id, status, etdui, error_message FROM fiscal_documents
     WHERE company_id = ? AND source_app = 'invoice-maker' AND source_ref = ?
     ORDER BY id DESC LIMIT 1"
);
$fiscalDocStmt->execute([current_company_id(), (string)$id]);
$fiscalDoc = $fiscalDocStmt->fetch() ?: null;

$payStmt = $pdo->prepare("SELECT amount, payment_date, method, notes FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC, id DESC");
$payStmt->execute([$id]);
$payments = $payStmt->fetchAll();

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

/**
 * Every payment is a row in `payments`; invoices.amount_paid is only ever a
 * cached SUM of them. Recomputing from the ledger (instead of incrementing
 * the column) means a deleted or corrected payment can never leave the
 * invoice's balance out of step with its own history.
 */
$inv_apply_payment = function (PDO $pdo, int $invoiceId, int $companyId, float $amount,
                               string $method, string $notes): void {
    $pdo->beginTransaction();
    try {
        // Lock the invoice so two concurrent payments can't both read the
        // same balance and jointly overpay it.
        $lock = $pdo->prepare("SELECT total, amount_paid FROM invoices WHERE id = ? AND company_id = ? FOR UPDATE");
        $lock->execute([$invoiceId, $companyId]);
        $inv = $lock->fetch();
        if (!$inv) {
            throw new RuntimeException('Invoice not found.');
        }

        $balance = round((float)$inv['total'] - (float)$inv['amount_paid'], 2);
        if ($amount > $balance + 0.005) {
            throw new RuntimeException('Payment exceeds the ' . money($balance) . ' balance due.');
        }

        $pdo->prepare("INSERT INTO payments (invoice_id, amount, payment_date, method, notes)
                       VALUES (?, ?, CURDATE(), ?, ?)")
            ->execute([$invoiceId, number_format($amount, 2, '.', ''), $method ?: null, $notes ?: null]);

        $paid  = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = " . (int)$invoiceId)->fetchColumn();
        $total = (float)$inv['total'];

        // No 'partial' state exists in the status enum, so a part-paid
        // invoice keeps its current status and shows its balance instead.
        $pdo->prepare("UPDATE invoices
                       SET amount_paid = ?,
                           status = CASE WHEN ? THEN 'paid' ELSE status END
                       WHERE id = ? AND company_id = ?")
            ->execute([
                number_format($paid, 2, '.', ''),
                $paid >= $total - 0.005 ? 1 : 0,
                $invoiceId,
                $companyId,
            ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['record_payment']) || isset($_POST['mark_paid'])) {
        $balanceDue = round((float)$invoice['total'] - (float)$invoice['amount_paid'], 2);

        // "Mark as Fully Paid" settles the remaining balance, but still goes
        // through the ledger so the history is never missing a payment.
        if (isset($_POST['mark_paid'])) {
            $amount = $balanceDue;
            $method = 'Marked paid';
            $notes  = '';
        } else {
            $raw    = str_replace(',', '', trim((string)($_POST['payment_amount'] ?? '')));
            $amount = round((float)$raw, 2);
            $method = substr(trim((string)($_POST['payment_method'] ?? '')), 0, 100);
            $notes  = substr(trim((string)($_POST['payment_notes'] ?? '')), 0, 500);

            if (!is_numeric($raw) || $amount <= 0) {
                header('Location: ' . BASE_URL . '/?page=invoices-view&id=' . $id . '&pay_err=' . rawurlencode('Enter a payment amount greater than zero.'));
                exit;
            }
        }

        if ($balanceDue <= 0.005) {
            header('Location: ' . BASE_URL . '/?page=invoices-view&id=' . $id . '&pay_err=' . rawurlencode('This invoice is already fully paid.'));
            exit;
        }

        try {
            $inv_apply_payment($pdo, (int)$id, current_company_id(), $amount, $method, $notes);
            header('Location: ' . BASE_URL . '/?page=invoices-view&id=' . $id . '&pay_ok=1');
        } catch (Throwable $e) {
            header('Location: ' . BASE_URL . '/?page=invoices-view&id=' . $id . '&pay_err=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    if (isset($_POST['send_to_bts'])) {
        try {
            $uid = (int)($_SESSION['user']['id'] ?? 0);
            $fd = $fiscalDoc;
            if (!$fd) {
                $fd = FiscalInvoicingService::fromInvoice(current_company_id(), (int)$id, $uid);
            }
            $result = FiscalInvoicingService::submitToBts(current_company_id(), (int)$fd['id'], $uid);
            $q = $result['status'] === 'authorized' ? '&bts_ok=1' : '&bts_err=' . rawurlencode($result['error_message'] ?: ('Result: ' . $result['status']));
        } catch (Throwable $e) {
            $q = '&bts_err=' . rawurlencode($e->getMessage());
        }
        header('Location: ' . BASE_URL . '/?page=invoices-view&id=' . $id . $q);
        exit;
    }

    if (isset($_POST['delete'])) {
        // Enforced server-side, not just by hiding the button — the POST is
        // reachable regardless of what the page rendered.
        if (inv_is_pos_receipt($invoice)) {
            header('Location: ' . BASE_URL . '/?page=invoices-view&id=' . $id . '&pay_err=' . rawurlencode(inv_pos_receipt_delete_message()));
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, current_company_id()]);

        header('Location: ' . BASE_URL . '/?page=invoices');
        exit;
    }
}

?>

<div class="biz">
<div class="mb-3">
    <p class="biz-kicker"><a href="<?= BASE_URL ?>/?page=invoices" class="biz-t-green">Invoices</a> · <?= e($invoice['invoice_number']) ?></p>

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mt-0.5">
        <div class="flex items-center gap-2 flex-wrap">
            <h1 class="mt-0" style="font-family:ui-monospace,monospace"><?= e($invoice['invoice_number']) ?></h1>
            <span class="biz-chip <?= inv_status_chip($invoice['status']) ?>"><?= e($invoice['status']) ?></span>
            <?php if ($sourceQuote): ?>
            <a href="<?= BASE_URL ?>/?page=quotes-view&id=<?= (int)$sourceQuote['id'] ?>" class="biz-chip biz-c-amber" style="text-decoration:none">from <?= e($sourceQuote['quote_number']) ?></a>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-1.5">
            <a href="https://wa.me/?text=<?= rawurlencode($shareMsg) ?>" target="_blank" rel="noopener" class="biz-btn biz-btn-ghost biz-btn-sm" title="Share via WhatsApp"><i data-lucide="message-circle" class="w-3.5 h-3.5"></i></a>
            <button type="button" id="btnEmailInvoice"
                    data-invoice-id="<?= (int)$invoice['id'] ?>"
                    data-customer-email="<?= e($invoice['email'] ?? '') ?>"
                    title="<?= !empty($invoice['email']) ? 'Email this invoice to ' . e($invoice['email']) : 'This customer has no email address on file' ?>"
                    <?= empty($invoice['email']) ? 'disabled' : '' ?>
                    class="biz-btn biz-btn-ghost biz-btn-sm"><i data-lucide="mail" class="w-3.5 h-3.5"></i></button>
            <a href="<?= BASE_URL ?>/pdf-invoice.php?id=<?= $invoice['id'] ?>" target="_blank" class="biz-btn biz-btn-primary biz-btn-sm"><i data-lucide="download" class="w-3.5 h-3.5"></i> PDF</a>
            <?php if (inv_is_pos_receipt($invoice)): ?>
                <span class="biz-btn biz-btn-ghost biz-btn-sm" style="opacity:.4;cursor:not-allowed" title="<?= e(inv_pos_receipt_delete_message()) ?>"><i data-lucide="lock" class="w-3.5 h-3.5"></i></span>
            <?php else: ?>
                <form method="POST" onsubmit="return confirm('Delete this invoice permanently?')">
                    <button name="delete" value="1" class="biz-btn biz-btn-danger biz-btn-sm" title="Delete invoice"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-3 items-start">
    <div class="lg:col-span-2">
        <div class="biz-panel biz-panel-body">
            <div class="flex justify-between items-start gap-4 mb-4">
                <div>
                    <p class="biz-label">Bill to</p>
                    <div class="font-bold" style="font-size:14px"><?= e($invoice['customer_name']) ?></div>
                    <div class="biz-muted"><?= e($invoice['email']) ?></div>
                    <div class="biz-muted"><?= e($invoice['phone']) ?></div>
                    <div class="biz-muted mt-2" style="max-width:20rem;white-space:pre-line"><?= e($invoice['address']) ?></div>
                </div>
                <div class="text-right space-y-2 shrink-0">
                    <div><p class="biz-label">Issue date</p><div class="font-bold"><?= date('j M Y', strtotime($invoice['issue_date'])) ?></div></div>
                    <div><p class="biz-label">Due date</p><div class="font-bold"><?= date('j M Y', strtotime($invoice['due_date'])) ?></div></div>
                </div>
            </div>

            <table class="w-full biz-num" style="font-size:12px">
                <thead>
                    <tr class="biz-muted" style="text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:0.06em;border-bottom:1px solid var(--bz-line)">
                        <th class="py-2 font-bold">Description</th>
                        <th class="py-2 font-bold text-center">Qty</th>
                        <th class="py-2 font-bold text-right">Unit price</th>
                        <th class="py-2 font-bold text-right">Amount</th>
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

            <?php if ($invoice['notes']): ?>
                <div class="mt-4 p-3 rounded" style="background:var(--bz-head);border:1px solid var(--bz-line-soft)">
                    <p class="biz-label">Notes &amp; terms</p>
                    <p class="biz-muted" style="white-space:pre-line"><?= e($invoice['notes']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="space-y-3">
        <div class="biz-panel biz-panel-body space-y-2">
            <div class="flex justify-between"><span class="biz-muted">Subtotal</span><span class="biz-num font-bold"><?= money($invoice['subtotal']) ?></span></div>
            <div class="flex justify-between"><span class="biz-muted">Tax</span><span class="biz-num font-bold"><?= money($invoice['tax']) ?></span></div>
            <div class="flex justify-between"><span class="biz-muted">Discount</span><span class="biz-num font-bold">-<?= money($invoice['discount']) ?></span></div>
            <div class="flex justify-between items-end pt-2" style="border-top:1px solid var(--bz-line)">
                <span class="biz-label" style="margin:0">Total</span>
                <span class="biz-num" style="font-size:18px;font-weight:800"><?= money($invoice['total']) ?></span>
            </div>
        </div>

        <?php if ($fiscalEnabled): ?>
        <?php
            $fdStatus = $fiscalDoc['status'] ?? null;
            $fdLabels = ['built' => 'Built, not sent', 'signed' => 'Signed', 'submitted' => 'Submitted',
                        'authorized' => 'Authorized by BTS', 'rejected' => 'Rejected by BTS', 'error' => 'BTS unreachable', 'cancelled' => 'Cancelled'];
            $fdOk = $fdStatus === 'authorized';
        ?>
        <div class="biz-panel biz-panel-body space-y-2">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em" class="biz-muted">BTS E-Invoicing</div>
            <?php if (!empty($_GET['bts_ok'])): ?>
                <div class="biz-notice biz-notice-green" style="font-size:11px">Authorized by BTS.</div>
            <?php elseif (!empty($_GET['bts_err'])): ?>
                <div class="biz-notice biz-notice-red" style="font-size:11px"><?= e($_GET['bts_err']) ?></div>
            <?php endif; ?>
            <?php if ($fiscalDoc): ?>
                <div class="flex justify-between items-center">
                    <span class="biz-muted" style="font-size:12px">Status</span>
                    <span class="biz-chip <?= $fdOk ? 'biz-c-green' : ($fdStatus === 'rejected' || $fdStatus === 'error' ? 'biz-c-red' : 'biz-c-amber') ?>"><?= e($fdLabels[$fdStatus] ?? $fdStatus) ?></span>
                </div>
                <?php if (!empty($fiscalDoc['etdui'])): ?>
                    <div class="biz-muted" style="font-size:10px;font-family:ui-monospace,monospace;word-break:break-all">ETDUI <?= e($fiscalDoc['etdui']) ?></div>
                <?php endif; ?>
                <?php if (!empty($fiscalDoc['error_message'])): ?>
                    <div style="font-size:11px;color:#b91c1c"><?= e($fiscalDoc['error_message']) ?></div>
                <?php endif; ?>
            <?php else: ?>
                <div class="biz-muted" style="font-size:12px">Not submitted to BTS yet.</div>
            <?php endif; ?>
            <?php if (!$fdOk && $invoice['status'] !== 'draft'): ?>
            <form method="POST">
                <button name="send_to_bts" value="1" class="biz-btn biz-btn-primary biz-btn-sm" style="width:100%">
                    <?= $fiscalDoc ? 'Retry - Send to BTS' : 'Send to BTS' ?>
                </button>
            </form>
            <?php endif; ?>
            <a href="<?= CENTRYK_BASE ?>/business_fiscal.php" class="biz-t-green" style="font-size:11px">Manage e-invoicing &rarr;</a>
        </div>
        <?php endif; ?>

        <div class="biz-panel biz-panel-body space-y-3" style="background:#0f172a;color:#fff;border-color:#1e293b">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:.6">Payment</div>

            <?php if (!empty($_GET['pay_err'])): ?>
                <div class="rounded px-2 py-1.5" style="font-size:11px;font-weight:700;background:rgba(244,63,94,.12);color:#fda4af"><?= e($_GET['pay_err']) ?></div>
            <?php elseif (!empty($_GET['pay_ok'])): ?>
                <div class="rounded px-2 py-1.5" style="font-size:11px;font-weight:700;background:rgba(16,185,129,.12);color:#6ee7b7">Payment recorded.</div>
            <?php endif; ?>

            <?php $balanceDue = round((float)$invoice['total'] - (float)$invoice['amount_paid'], 2); ?>

            <div class="space-y-1.5 biz-num">
                <div class="flex justify-between" style="font-size:12px"><span style="opacity:.6">Already paid</span><span style="color:#34d399;font-weight:700"><?= money($invoice['amount_paid']) ?></span></div>
                <div class="flex justify-between"><span style="opacity:.6">Balance due</span><span style="font-weight:800"><?= money($balanceDue) ?></span></div>
            </div>

            <?php if ($payments): ?>
            <div style="border-top:1px solid rgba(255,255,255,.1);padding-top:8px">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:.4;margin-bottom:4px">History</div>
                <div class="space-y-1 biz-num" style="max-height:11rem;overflow-y:auto">
                    <?php foreach ($payments as $p): ?>
                    <div class="flex items-start justify-between gap-2" style="font-size:11px">
                        <div class="min-w-0">
                            <div style="opacity:.8;font-weight:600"><?= date('j M Y', strtotime($p['payment_date'])) ?></div>
                            <?php if (!empty($p['method']) || !empty($p['notes'])): ?>
                            <div class="truncate" style="font-size:10px;opacity:.5;max-width:150px"><?= e(trim(($p['method'] ?? '') . (!empty($p['notes']) ? ' · ' . $p['notes'] : ''), ' ·')) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="shrink-0" style="color:#34d399;font-weight:700"><?= money($p['amount']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($balanceDue > 0.005): ?>
                <form method="POST" class="space-y-1.5" style="border-top:1px solid rgba(255,255,255,.1);padding-top:8px">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;opacity:.4">Record a payment</div>
                    <div class="flex gap-1.5">
                        <input type="number" step="0.01" min="0.01" max="<?= $balanceDue ?>" name="payment_amount"
                               placeholder="<?= number_format($balanceDue, 2, '.', '') ?>" required class="biz-input biz-num"
                               style="background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);color:#fff">
                        <input type="text" name="payment_method" placeholder="Cash, cheque…" maxlength="100" class="biz-input"
                               style="background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);color:#fff">
                    </div>
                    <input type="text" name="payment_notes" placeholder="Reference / note (optional)" maxlength="500" class="biz-input"
                           style="background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);color:#fff">
                    <button name="record_payment" value="1" class="biz-btn biz-btn-sm" style="width:100%;background:rgba(255,255,255,.12);color:#fff;border-color:transparent">Record payment</button>
                </form>

                <form method="POST" onsubmit="return confirm('Settle the full <?= money($balanceDue) ?> balance now?')">
                    <button name="mark_paid" value="1" class="biz-btn biz-btn-sm" style="width:100%;background:#10b981;color:#04231a;border-color:transparent;font-weight:800">Mark as fully paid</button>
                </form>
            <?php else: ?>
                <div class="rounded px-3 py-2 text-center" style="font-size:12px;font-weight:700;background:rgba(16,185,129,.1);color:#34d399">Fully paid</div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
<!-- /.biz -->

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
