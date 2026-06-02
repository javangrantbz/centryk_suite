<?php

require_once __DIR__ . '/../../invoice-maker/bootstrap.php';

require_once __DIR__ . '/../../invoice-maker/vendor/autoload.php';

use Dompdf\Dompdf;

require_auth();

$id = $_GET['id'] ?? null;
$companyId = current_company_id();

$userStmt = $pdo->prepare("SELECT * FROM invoice_settings WHERE company_id = ?");
$userStmt->execute([$companyId]);
$business = $userStmt->fetch() ?: [];
$business += ['business_logo'=>null,'logo_position'=>'left','business_name'=>'','business_address'=>'','business_email'=>'','business_phone'=>'','business_tax_number'=>'','currency_symbol'=>'$','invoice_terms'=>null,'quote_terms'=>null];

$_SESSION['currency_symbol'] = $business['currency_symbol'] ?: '$';

$stmt = $pdo->prepare("
    SELECT quotes.*, customers.name AS customer_name, customers.email, customers.phone, customers.address
    FROM quotes
    JOIN customers ON customers.id = quotes.customer_id
    WHERE quotes.id = ? AND quotes.company_id = ?
");
$stmt->execute([$id, $companyId]);
$quote = $stmt->fetch();

if (!$quote) {
    die('Quote not found.');
}

$itemStmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = ?");
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$logoHtml = '';
if ($business['business_logo']) {
    $logoPath = __DIR__ . '/' . $business['business_logo'];
    if (file_exists($logoPath)) {
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
		<h1>Quote</h1>
		<p><strong>' . e($business['business_name'] ?: APP_NAME) . '</strong></p>
		<p>' . e($business['business_email']) . '</p>
		<p>' . e($business['business_phone']) . '</p>
		<p>' . nl2br(e($business['business_address'])) . '</p>
		<p>' . e($business['business_tax_number']) . '</p>
	</div>
    <div class="right">
        <p><strong>Issue Date:</strong> ' . e($quote['issue_date']) . '</p>
        <p><strong>Expiry Date:</strong> ' . e($quote['expiry_date']) . '</p>
    </div>
</div>

<h3>Customer</h3>
<p>
    ' . e($quote['customer_name']) . '<br>
    ' . e($quote['email']) . '<br>
    ' . e($quote['phone']) . '<br>
    ' . nl2br(e($quote['address'])) . '
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
    <div><span>Subtotal</span><strong>' . money($quote['subtotal']) . '</strong></div>
    <div><span>Tax</span><strong>' . money($quote['tax']) . '</strong></div>
    <div><span>Discount</span><strong>' . money($quote['discount']) . '</strong></div>
    <div class="total"><span>Total</span><strong>' . money($quote['total']) . '</strong></div>
</div>';

if ($quote['notes']) {
    $html .= '
    <h3>Notes</h3>
    <p>' . nl2br(e($quote['notes'])) . '</p>';
}

if ($business['quote_terms']) {
    $html .= '
    <h3>Terms</h3>
    <p>' . nl2br(e($business['quote_terms'])) . '</p>';
}

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();
$dompdf->stream('quote-' . $quote['quote_number'] . '.pdf', ['Attachment' => false]);