<?php
// "Sign out" from the Invoices app ends the shared Centryk session.
require_once __DIR__ . '/../../invoice-maker/app/config/app.php';
header('Location: ' . CENTRYK_BASE . '/logout.php');
exit;
