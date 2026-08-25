<?php
/**
 * Shared invoice-email pieces used by both send paths:
 *   - api/onepay/send_invoices.php  (server-to-server, bulk, OnePay-triggered)
 *   - api/send_invoice_email.php    (session-authed, single invoice, in-app button)
 *
 * Kept in one place so the two paths can't drift into sending differently
 * branded mail, and so every send is logged to invoice_reminders the same way.
 */

if (!function_exists('invoice_email_html')) {
/** Minimal branded HTML email body with a prominent "View invoice" button. */
function invoice_email_html(string $from, string $customer, string $number,
                            string $amount, ?string $due, string $link, string $type): string
{
    $from     = htmlspecialchars($from, ENT_QUOTES);
    $customer = htmlspecialchars($customer ?: 'there', ENT_QUOTES);
    $number   = htmlspecialchars($number, ENT_QUOTES);
    $amount   = htmlspecialchars($amount, ENT_QUOTES);
    $link     = htmlspecialchars($link, ENT_QUOTES);
    $dueLine  = $due ? '<p style="margin:0 0 4px;color:#475569;">Due date: <strong>' . htmlspecialchars($due, ENT_QUOTES) . '</strong></p>' : '';
    $intro    = $type === 'reminder'
        ? 'This is a friendly reminder that the following invoice is still outstanding.'
        : 'Please find your invoice below. You can view and download it using the button.';

    return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#0f172a;">'
         . '<h2 style="margin:0 0 4px;font-size:20px;">' . $from . '</h2>'
         . '<p style="margin:0 0 16px;color:#64748b;font-size:13px;">Invoice ' . $number . '</p>'
         . '<p style="margin:0 0 12px;">Hi ' . $customer . ',</p>'
         . '<p style="margin:0 0 16px;color:#334155;">' . $intro . '</p>'
         . '<div style="background:#f1f5f9;border-radius:12px;padding:16px 18px;margin:0 0 18px;">'
         . '<p style="margin:0 0 4px;color:#475569;">Amount due: <strong style="font-size:18px;color:#10b981;">' . $amount . '</strong></p>'
         . $dueLine
         . '</div>'
         . '<a href="' . $link . '" style="display:inline-block;background:#10b981;color:#fff;text-decoration:none;'
         . 'padding:12px 22px;border-radius:10px;font-weight:700;">View invoice</a>'
         . '<p style="margin:20px 0 0;color:#94a3b8;font-size:12px;">If the button doesn\'t work, copy this link:<br>' . $link . '</p>'
         . '</div>';
}
}

if (!function_exists('inv_email_sender_label')) {
/** How the business identifies itself in mail: invoice_settings name, else company name. */
function inv_email_sender_label(PDO $pdo, int $companyId): array
{
    $st = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
    $st->execute([$companyId]);
    $companyName = (string)($st->fetchColumn() ?: 'Your account');

    $businessName = '';
    $currency     = '$';
    $st2 = $pdo->prepare('SELECT business_name, currency_symbol FROM invoice_settings WHERE company_id = ?');
    $st2->execute([$companyId]);
    if ($row = $st2->fetch()) {
        $businessName = (string)($row['business_name'] ?? '');
        $currency     = (string)($row['currency_symbol'] ?? '$') ?: '$';
    }

    return [
        'from_label' => $businessName !== '' ? $businessName : $companyName,
        'currency'   => $currency,
    ];
}
}

if (!function_exists('inv_share_base')) {
/**
 * Public base for share links. Derived from Centryk's APP_URL rather than the
 * request host, since server-to-server sends have no meaningful HTTP_HOST.
 */
function inv_share_base(): string
{
    $appUrl = rtrim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost/centryk/public'), '/');
    return $appUrl . '/invoice-maker/share.php?t=';
}
}

if (!function_exists('inv_log_reminder')) {
/** Append one row to the invoice_reminders audit trail. */
function inv_log_reminder(PDO $pdo, int $companyId, int $invoiceId, string $runId,
                          string $toEmail, string $status, ?string $error): void
{
    $pdo->prepare('INSERT INTO invoice_reminders (company_id, invoice_id, run_id, channel, to_email, status, error, sent_at)
                   VALUES (:c, :i, :r, "email", :e, :s, :err, :sent)')
        ->execute([
            'c'    => $companyId,
            'i'    => $invoiceId,
            'r'    => $runId,
            'e'    => $toEmail,
            's'    => $status,
            'err'  => $error !== null ? substr($error, 0, 250) : null,
            'sent' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
        ]);
}
}
