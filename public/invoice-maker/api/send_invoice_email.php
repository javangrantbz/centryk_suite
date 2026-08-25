<?php
/**
 * In-app "Email invoice" — sends a single invoice to its customer from the
 * invoice UI, using the same MailerService path, branded template, and
 * invoice_reminders audit trail as the bulk OnePay-triggered sender.
 *
 * Replaces the old mailto: link, which only opened the user's local mail
 * client (no server send, no logging, no draft->sent transition, and a dead
 * end on any device without a mail client configured).
 *
 * POST { invoice_id, type?: 'invoice'|'reminder' } — session-authed and
 * scoped to the user's active company.
 */

require_once __DIR__ . '/../../../invoice-maker/bootstrap.php';
require_once __DIR__ . '/../../../app/core/Env.php';
require_once __DIR__ . '/../../../app/services/MailerService.php';
require_once __DIR__ . '/../../../invoice-maker/app/helpers/invoice_email.php';

require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$companyId = current_company_id();
if ($companyId <= 0) {
    json_response(['error' => 'No active company.'], 403);
}

$body      = json_decode(file_get_contents('php://input'), true) ?: [];
$invoiceId = (int)($body['invoice_id'] ?? 0);
$type      = ($body['type'] ?? 'invoice') === 'reminder' ? 'reminder' : 'invoice';

if ($invoiceId <= 0) {
    json_response(['error' => 'Invoice ID is required.'], 422);
}

$st = $pdo->prepare(
    "SELECT i.id, i.invoice_number, i.status, i.total, i.amount_paid, i.due_date, i.share_token,
            c.name AS customer_name, c.email AS customer_email
     FROM invoices i
     JOIN customers c ON c.id = i.customer_id
     WHERE i.id = ? AND i.company_id = ?
     LIMIT 1"
);
$st->execute([$invoiceId, $companyId]);
$invoice = $st->fetch();

if (!$invoice) {
    json_response(['error' => 'Invoice not found.'], 404);
}

$email = strtolower(trim((string)$invoice['customer_email']));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'This customer has no valid email address on file.'], 422);
}
if ($invoice['status'] === 'cancelled') {
    json_response(['error' => 'This invoice is cancelled.'], 422);
}

// Share links are minted lazily on first view; an invoice emailed before it
// was ever opened in the UI would otherwise carry an empty token.
if (empty($invoice['share_token'])) {
    $token = bin2hex(random_bytes(20));
    $pdo->prepare('UPDATE invoices SET share_token = ? WHERE id = ? AND company_id = ?')
        ->execute([$token, $invoiceId, $companyId]);
    $invoice['share_token'] = $token;
}

Env::load(__DIR__ . '/../../../.env');
$sender      = inv_email_sender_label($pdo, $companyId);
$outstanding = max(0.0, (float)$invoice['total'] - (float)$invoice['amount_paid']);
$amount      = $sender['currency'] . number_format($outstanding, 2);
$link        = inv_share_base() . rawurlencode($invoice['share_token']);
$isOverdue   = $invoice['due_date'] && $invoice['due_date'] < date('Y-m-d');

$subject = $type === 'reminder'
    ? 'Payment reminder: Invoice ' . $invoice['invoice_number'] . ($isOverdue ? ' (overdue)' : '')
    : 'Invoice ' . $invoice['invoice_number'] . ' from ' . $sender['from_label'];

$html = invoice_email_html($sender['from_label'], $invoice['customer_name'], $invoice['invoice_number'],
                           $amount, $invoice['due_date'], $link, $type);

$runId = bin2hex(random_bytes(20));

try {
    $result = (new MailerService())->send($email, $subject, $html, '', 'invoice_' . $type);

    if ($type === 'invoice') {
        $pdo->prepare("UPDATE invoices SET status = 'sent' WHERE id = ? AND company_id = ? AND status = 'draft'")
            ->execute([$invoiceId, $companyId]);
    }
    inv_log_reminder($pdo, $companyId, $invoiceId, $runId, $email, 'sent', null);

    json_response([
        'success'     => true,
        'email'       => $email,
        'mail_status' => $result['status'] ?? 'sent',
        'note'        => $result['note'] ?? null,
    ]);
} catch (Throwable $e) {
    inv_log_reminder($pdo, $companyId, $invoiceId, $runId, $email, 'failed', $e->getMessage());
    json_response(['error' => 'Could not send: ' . $e->getMessage()], 502);
}
