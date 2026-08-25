<?php
/**
 * OnePay → Invoices: email invoices to their customers and log every send.
 * Powers two OnePay actions:
 *   - "issue & email a batch"  (type=invoice: draft/sent invoices → emailed, draft flips to sent)
 *   - "remind everyone unpaid" (type=reminder + only_unpaid=true)
 *
 * Selection (any one of): invoice_ids[], batch_id, source_refs[].
 * Each recipient gets the public share link (share.php?t=<token>) — the same
 * link the in-app WhatsApp/email buttons use. Sends are recorded in
 * invoice_reminders (grouped by run_id) so the outcome is auditable and a bulk
 * run never silently drops recipients.
 *
 * Expected JSON body: provision_secret, company_uuid,
 *   type ('invoice'|'reminder'), only_unpaid (bool),
 *   invoice_ids[] | batch_id | source_refs[].
 * Returns: { run_id, summary:{sent,failed,skipped}, results:[...] }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../../app/core/Env.php';
require_once __DIR__ . '/../../../../app/services/MailerService.php';
require_once __DIR__ . '/../../../../invoice-maker/app/helpers/invoice_email.php';

$body      = onepay_request();
$pdo       = DB::pdo();
$companyId = onepay_company_id($pdo, $body);

$type      = ($body['type'] ?? 'invoice') === 'reminder' ? 'reminder' : 'invoice';
$onlyUnpaid = !empty($body['only_unpaid']);

// ── Resolve the target invoice set (company-scoped) ─────────────────────────
$where  = ['i.company_id = ?'];
$params = [$companyId];

$ids  = array_values(array_filter(array_map('intval', (array)($body['invoice_ids'] ?? []))));
$refs = array_values(array_filter(array_map(static fn($r) => trim((string)$r), (array)($body['source_refs'] ?? []))));

if ($ids) {
    $where[] = 'i.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $params  = array_merge($params, $ids);
} elseif (isset($body['batch_id']) && (int)$body['batch_id'] > 0) {
    $where[]  = 'i.batch_id = ?';
    $params[] = (int)$body['batch_id'];
} elseif ($refs) {
    $where[] = "i.source_app = 'onepay' AND i.source_ref IN (" . implode(',', array_fill(0, count($refs), '?')) . ')';
    $params  = array_merge($params, $refs);
} else {
    Response::error('Provide invoice_ids, batch_id, or source_refs.');
}

$sql = "SELECT i.id, i.invoice_number, i.status, i.total, i.amount_paid, i.due_date,
               i.share_token, c.name AS customer_name, c.email AS customer_email
        FROM invoices i
        JOIN customers c ON c.id = i.customer_id
        WHERE " . implode(' AND ', $where);
$st = $pdo->prepare($sql);
$st->execute($params);
$invoices = $st->fetchAll();

// ── Company presentation (business name + currency) ─────────────────────────
Env::load(__DIR__ . '/../../../../.env');
$sender    = inv_email_sender_label($pdo, $companyId);
$fromLabel = $sender['from_label'];
$cur       = $sender['currency'];
$shareBase = inv_share_base();

$mailer = new MailerService();
$runId  = bin2hex(random_bytes(20));
$today  = date('Y-m-d');

$results = [];
$summary = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

$markSent = $pdo->prepare("UPDATE invoices SET status = 'sent' WHERE id = ? AND status = 'draft'");

foreach ($invoices as $inv) {
    $outstanding = max(0.0, (float)$inv['total'] - (float)$inv['amount_paid']);
    $email       = strtolower(trim((string)$inv['customer_email']));

    // Skip rules — recorded so the caller sees why a recipient was left out.
    $skipReason = '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skipReason = 'no valid email';
    } elseif (in_array($inv['status'], ['cancelled'], true)) {
        $skipReason = 'cancelled';
    } elseif (($onlyUnpaid || $type === 'reminder') && ($inv['status'] === 'paid' || $outstanding <= 0.01)) {
        $skipReason = 'already paid';
    }

    if ($skipReason !== '') {
        $summary['skipped']++;
        $results[] = ['invoice_id' => (int)$inv['id'], 'invoice_number' => $inv['invoice_number'],
                      'customer' => $inv['customer_name'], 'email' => $inv['customer_email'],
                      'status' => 'skipped', 'reason' => $skipReason];
        continue;
    }

    $link    = $shareBase . rawurlencode($inv['share_token']);
    $amount  = $cur . number_format($outstanding, 2);
    $isOverdue = $inv['due_date'] && $inv['due_date'] < $today;
    $subject = $type === 'reminder'
        ? 'Payment reminder: Invoice ' . $inv['invoice_number'] . ($isOverdue ? ' (overdue)' : '')
        : 'Invoice ' . $inv['invoice_number'] . ' from ' . $fromLabel;

    $html = invoice_email_html($fromLabel, $inv['customer_name'], $inv['invoice_number'],
                               $amount, $inv['due_date'], $link, $type);

    try {
        $mailer->send($email, $subject, $html, '', 'invoice_' . $type);
        if ($type === 'invoice') {
            $markSent->execute([(int)$inv['id']]);
        }
        inv_log_reminder($pdo, $companyId, (int)$inv['id'], $runId, $email, 'sent', null);
        $summary['sent']++;
        $results[] = ['invoice_id' => (int)$inv['id'], 'invoice_number' => $inv['invoice_number'],
                      'customer' => $inv['customer_name'], 'email' => $email, 'status' => 'sent'];
    } catch (Throwable $e) {
        inv_log_reminder($pdo, $companyId, (int)$inv['id'], $runId, $email, 'failed', $e->getMessage());
        $summary['failed']++;
        $results[] = ['invoice_id' => (int)$inv['id'], 'invoice_number' => $inv['invoice_number'],
                      'customer' => $inv['customer_name'], 'email' => $email,
                      'status' => 'failed', 'reason' => $e->getMessage()];
    }
}

// ── If this was a batch run, mark it issued and refresh its rollups ─────────
if (isset($body['batch_id']) && (int)$body['batch_id'] > 0) {
    $bid = (int)$body['batch_id'];
    $pdo->prepare("UPDATE invoice_batches b
                   SET b.invoice_count = (SELECT COUNT(*) FROM invoices WHERE batch_id = b.id),
                       b.total_amount  = (SELECT COALESCE(SUM(total),0) FROM invoices WHERE batch_id = b.id),
                       b.status = 'issued'
                   WHERE b.id = ? AND b.company_id = ?")
        ->execute([$bid, $companyId]);
}

Response::ok(['run_id' => $runId, 'summary' => $summary, 'results' => $results]);

