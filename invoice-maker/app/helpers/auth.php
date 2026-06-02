<?php

function require_auth()
{
    if (!isset($_SESSION['user'])) {
        header('Location: ' . BASE_URL . '/?page=login');
        exit;
    }
}

function current_user()
{
    return $_SESSION['user'] ?? null;
}