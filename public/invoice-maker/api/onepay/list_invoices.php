<?php
/**
 * OnePay → Invoices: list invoices for the company so OnePay can render its
 * receivables dashboard (who's paid / unpaid / overdue) without duplicating the
 * data locally. Defaults to OnePay-originated invoices; pass all_sources=true to
 * include manually-created ones too.
 *
 * Expected JSON body: provision_secret, company_uuid,
 *   batch_id (optional), all_sources (optional bool), limit (optional, <=500).
 * Returns: { invoices: [...], summary: { count, paid, unpaid, overdue,
 *            total_billed, total_paid, total_outstanding } }
 */
require_once __DIR__ . '/_bootstrap.php';

$body      = onepay_request();
$pdo       = DB::pdo();
$companyId = onepay_company_id($pdo, $body);

$where  = ['i.company_id = ?'];
$params = [$companyId];

if (empty($body['all_sources'])) {
    $where[] = "i.source_app = 'onepay'";
}
if (isset($body['batch_id']) && (int)$body['batch_id'] > 0) {
    $where[] = 'i.batch_id = ?';
    $params[] = (int)$body['batch_id'];
}

$limit = isset($body['limit']) ? max(1, min(500, (int)$body['limit'])) : 300;

$sql = "SELECT i.id, i.invoice_number, i.status, i.issue_date, i.due_date,
               i.subtotal, i.tax, i.discount, i.total, i.amount_paid,
               i.share_token, i.source_ref, i.batch_id, i.created_at,
               c.name AS customer_name, c.email AS customer_email,
               b.title AS batch_title
        FROM invoices i
        JOIN customers c ON c.id = i.customer_id
        LEFT JOIN invoice_batches b ON b.id = i.batch_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.created_at DESC, i.id DESC
        LIMIT {$limit}";
$st = $pdo->prepare($sql);
$st->execute($params);

$today = date('Y-m-d');
$rows  = [];
$summary = [
    'count' => 0, 'paid' => 0, 'unpaid' => 0, 'overdue' => 0,
    'total_billed' => 0.0, 'total_paid' => 0.0, 'total_outstanding' => 0.0,
];

foreach ($st->fetchAll() as $r) {
    $total      = (float)$r['total'];
    $paid       = (float)$r['amount_paid'];
    $status     = $r['status'];
    $outstanding = max(0.0, $total - $paid);

    // Display status: an unpaid invoice past its due date reads as overdue even
    // if a nightly job hasn't flipped the stored status yet.
    $display = $status;
    $isOverdue = false;
    if (!in_array($status, ['paid', 'cancelled'], true)
        && $r['due_date'] && $r['due_date'] < $today && $outstanding > 0.01) {
        $display   = 'overdue';
        $isOverdue = true;
    }

    $summary['count']++;
    $summary['total_billed']      += $total;
    $summary['total_paid']        += $paid;
    $summary['total_outstanding'] += $outstanding;
    if ($status === 'paid')       $summary['paid']++;
    elseif ($isOverdue)           $summary['overdue']++;
    else                          $summary['unpaid']++;

    $rows[] = [
        'id'             => (int)$r['id'],
        'invoice_number' => $r['invoice_number'],
        'status'         => $status,
        'display_status' => $display,
        'issue_date'     => $r['issue_date'],
        'due_date'       => $r['due_date'],
        'total'          => $total,
        'amount_paid'    => $paid,
        'outstanding'    => $outstanding,
        'share_token'    => $r['share_token'],
        'source_ref'     => $r['source_ref'],
        'batch_id'       => $r['batch_id'] !== null ? (int)$r['batch_id'] : null,
        'batch_title'    => $r['batch_title'],
        'customer_name'  => $r['customer_name'],
        'customer_email' => $r['customer_email'],
        'created_at'     => $r['created_at'],
    ];
}

Response::ok(['invoices' => $rows, 'summary' => $summary]);
