<?php
/**
 * Public share link — streams an invoice/quote PDF by its share token.
 * No login: anyone with the token can view. Used by the WhatsApp/email buttons.
 */
require_once __DIR__ . '/../../app/core/DB.php';
require_once __DIR__ . '/../../invoice-maker/app/config/app.php';      // APP_NAME, BASE_URL
require_once __DIR__ . '/../../invoice-maker/app/helpers/functions.php'; // e(), money()

$token = trim($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
    http_response_code(404);
    exit('Document not found.');
}

$pdo = DB::pdo();
$invShare = true; // signals pdf-*.php to skip auth and use the vars set here

foreach ([['invoices', 'pdf-invoice.php'], ['quotes', 'pdf-quote.php']] as [$table, $renderer]) {
    $s = $pdo->prepare("SELECT id, company_id FROM {$table} WHERE share_token = ? LIMIT 1");
    $s->execute([$token]);
    $row = $s->fetch();
    if ($row) {
        $id        = (int) $row['id'];
        $companyId = (int) $row['company_id'];
        require_once __DIR__ . '/../../invoice-maker/vendor/autoload.php';
        require __DIR__ . '/' . $renderer;
        exit;
    }
}

http_response_code(404);
exit('Document not found.');
