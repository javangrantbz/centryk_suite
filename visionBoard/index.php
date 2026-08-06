<?php
/** Entry point: send visitors to the admin panel (Centryk handles auth). */
require_once __DIR__ . '/includes/functions.php';
redirect(app_base() . '/admin/index.php');
