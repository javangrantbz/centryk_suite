<?php

function money($amount)
{
    $symbol = $_SESSION['currency_symbol'] ?? '$';
    return $symbol . number_format((float)$amount, 2);
}

function e($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function active($page)
{
    return ($_GET['page'] ?? 'dashboard') === $page
        ? 'bg-slate-900 text-white'
        : 'text-slate-600 hover:bg-slate-100';
}