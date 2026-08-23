<?php
require_once __DIR__ . '/../../app/core/Auth.php';

Auth::start();
if (!Auth::user()) {
    header('Location: login.php');
    exit;
}

header('Location: app.php');
exit;
