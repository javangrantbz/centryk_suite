<?php
// Quotes list = the unified Invoices & Quotes table, filtered to quotes.
$_GET['type'] = $_GET['type'] ?? 'quote';
require __DIR__ . '/../invoices/index.php';
