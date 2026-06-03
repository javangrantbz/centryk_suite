<?php

use Dompdf\Dompdf;

if (!isset($invShare)) {
    // Authenticated mode: a logged-in user downloading their own PDF.
    require_once __DIR__ . '/../../invoice-maker/bootstrap.php';
    require_once __DIR__ . '/../../invoice-maker/vendor/autoload.php';
    require_auth();
    $id = $_GET['id'] ?? null;
    $companyId = current_company_id();
}
// Share mode: share.php has already set $invShare, $pdo, $id, $companyId + autoload.

// Business identity comes from the company (single source of truth);
// terms/logo-position/currency come from the app's invoice_settings.
$business = inv_business_profile($pdo, (int)$companyId);
$_SESSION['currency_symbol'] = $business['currency_symbol'] ?: '$';

$stmt = $pdo->prepare("
    SELECT invoices.*, customers.name AS customer_name, customers.email, customers.phone, customers.address
    FROM invoices
    JOIN customers ON customers.id = invoices.customer_id
    WHERE invoices.id = ? AND invoices.company_id = ?
");
$stmt->execute([$id, $companyId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die('Invoice not found.');
}

$itemStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$logoHtml = '';
$logoPath = inv_company_logo_path($business['business_logo']);
if ($logoPath) {
    $type = pathinfo($logoPath, PATHINFO_EXTENSION);
    $data = file_get_contents($logoPath);
    $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);

    $alignment = $business['logo_position'] ?: 'left';
    $margin = '0';
    if ($alignment === 'center') $margin = '0 auto';
    if ($alignment === 'right') $margin = '0 0 0 auto';

    $logoHtml = '<div style="margin-bottom: 20px; text-align: ' . $alignment . ';">
        <img src="' . $base64 . '" style="max-height: 80px; display: block; margin: ' . $margin . ';">
    </div>';
}

$phonesHtml = '';
foreach (inv_company_phones($business) as $ph) {
    $phonesHtml .= '<p>' . e($ph) . '</p>';
}

$html = '
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #111827; }
.header { display: flex; justify-content: space-between; margin-bottom: 40px; }
h1 { font-size: 28px; margin: 0; }
.table { width: 100%; border-collapse: collapse; margin-top: 30px; }
.table th { background: #f1f5f9; text-align: left; padding: 10px; }
.table td { border-bottom: 1px solid #e5e7eb; padding: 10px; }
.right { text-align: right; }
.totals { width: 300px; margin-left: auto; margin-top: 25px; }
.totals div { display: flex; justify-content: space-between; margin-bottom: 8px; }
.total { font-size: 18px; font-weight: bold; border-top: 1px solid #111827; padding-top: 10px; }
</style>

' . $logoHtml . '

<div class="header">
    <div>
		<h1>Invoice</h1>
		<p><strong>' . e($business['business_name'] ?: APP_NAME) . '</strong></p>
		<p>' . e($business['business_email']) . '</p>
		' . $phonesHtml . '
		<p>' . nl2br(e($business['business_address'])) . '</p>
		<p>' . e($business['business_tax_number']) . '</p>
	</div>
    <div class="right">
        <p><strong>Issue Date:</strong> ' . e($invoice['issue_date']) . '</p>
        <p><strong>Due Date:</strong> ' . e($invoice['due_date']) . '</p>
    </div>
</div>

<h3>Bill To</h3>
<p>
    ' . e($invoice['customer_name']) . '<br>
    ' . e($invoice['email']) . '<br>
    ' . e($invoice['phone']) . '<br>
    ' . nl2br(e($invoice['address'])) . '
</p>

<table class="table">
    <thead>
        <tr>
            <th>Description</th>
            <th>Qty</th>
            <th>Unit Price</th>
            <th class="right">Total</th>
        </tr>
    </thead>
    <tbody>';

foreach ($items as $item) {
    $html .= '
        <tr>
            <td>' . e($item['description']) . '</td>
            <td>' . e($item['quantity']) . '</td>
            <td>' . money($item['unit_price']) . '</td>
            <td class="right">' . money($item['total']) . '</td>
        </tr>';
}

$html .= '
    </tbody>
</table>

<div class="totals">
    <div><span>Subtotal</span><strong>' . money($invoice['subtotal']) . '</strong></div>
    <div><span>Tax</span><strong>' . money($invoice['tax']) . '</strong></div>
    <div><span>Discount</span><strong>' . money($invoice['discount']) . '</strong></div>
    <div class="total"><span>Total</span><strong>' . money($invoice['total']) . '</strong></div>
    <div><span>Paid</span><strong>' . money($invoice['amount_paid']) . '</strong></div>
    <div><span>Balance</span><strong>' . money($invoice['total'] - $invoice['amount_paid']) . '</strong></div>
</div>';

if ($invoice['notes']) {
    $html .= '
    <h3>Notes</h3>
    <p>' . nl2br(e($invoice['notes'])) . '</p>';
}

if ($business['invoice_terms']) {
    $html .= '
    <h3>Terms</h3>
    <p>' . nl2br(e($business['invoice_terms'])) . '</p>';
}

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();
$dompdf->stream('invoice-' . $invoice['invoice_number'] . '.pdf', ['Attachment' => false]);