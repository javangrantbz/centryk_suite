<?php
require_once __DIR__ . '/../../invoice-maker/bootstrap.php';

// Buffer output so views can still issue header() redirects after a form POST
// (the layout header is rendered before the view).
ob_start();

$page = $_GET['page'] ?? 'dashboard';
$APP  = __DIR__ . '/../../invoice-maker/app';

require $APP . '/views/layouts/header.php';
require $APP . '/views/layouts/sidebar.php';

$routes = [
    'dashboard'        => '/views/dashboard.php',
    'customers'        => '/views/customers/index.php',
    'customers-create' => '/views/customers/create.php',
    'customers-edit'   => '/views/customers/edit.php',
    'invoices'         => '/views/invoices/index.php',
    'invoices-create'  => '/views/invoices/create.php',
    'invoices-view'    => '/views/invoices/view.php',
    'receipts'         => '/views/receipts/index.php',
    'quotes'           => '/views/quotes/index.php',
    'quotes-create'    => '/views/quotes/create.php',
    'quotes-view'      => '/views/quotes/view.php',
    'documents'        => '/views/documents/index.php',
    'settings'         => '/views/settings/index.php',
];

require $APP . ($routes[$page] ?? '/views/dashboard.php');
require $APP . '/views/layouts/footer.php';
