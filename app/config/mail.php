<?php

return [
    'from_name'      => $_ENV['MAIL_FROM_NAME']    ?? 'Centryk',
    'from_email'     => $_ENV['MAIL_FROM_EMAIL']   ?? 'no-reply@centryk.com',
    'default_driver' => $_ENV['MAIL_DRIVER']        ?? 'log',
    'admin_email'    => $_ENV['REQUEST_ADMIN_EMAIL'] ?? 'webdevelopment@bhilimited.com',
    'app_url'        => rtrim($_ENV['APP_URL'] ?? 'http://localhost/centryk/public', '/'),
    'smtp' => [
        'host'       => $_ENV['SMTP_HOST']       ?? '',
        'port'       => (int)($_ENV['SMTP_PORT'] ?? 587),
        'username'   => $_ENV['SMTP_USERNAME']   ?? '',
        'password'   => $_ENV['SMTP_PASSWORD']   ?? '',
        'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? 'tls',
    ],
];

/* production
return [
    'from_name'      => $_ENV['MAIL_FROM_NAME']    ?? 'Centryk',
    'from_email'     => $_ENV['MAIL_FROM_EMAIL']   ?? 'no-reply@centryk.com',
    'default_driver' => $_ENV['MAIL_DRIVER']        ?? 'log',
    'admin_email'    => $_ENV['REQUEST_ADMIN_EMAIL'] ?? 'webdevelopment@bhilimited.com',
    'app_url'        => rtrim($_ENV['APP_URL'] ?? 'https://centryk.net', '/'),
    'smtp' => [
        'host'       => $_ENV['SMTP_HOST']       ?? '',
        'port'       => (int)($_ENV['SMTP_PORT'] ?? 587),
        'username'   => $_ENV['SMTP_USERNAME']   ?? '',
        'password'   => $_ENV['SMTP_PASSWORD']   ?? '',
        'encryption' => $_ENV['SMTP_ENCRYPTION'] ?? 'tls',
    ],
];