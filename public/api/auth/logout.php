<?php
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Auth.php';

Auth::logout();
Response::ok(['message' => 'Logged out.']);
