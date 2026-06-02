<?php
/**
 * Catalog of user-facing notification preferences (Profile → Notifications).
 * Single source of truth shared by the profile page and the save endpoint.
 * `default` is used when the user has no stored preference yet.
 */
return [
    'security_alerts' => [
        'label'   => 'Security alerts',
        'desc'    => 'New sign-ins from unrecognized devices and password changes.',
        'default' => 1,
    ],
    'account_updates' => [
        'label'   => 'Account & company updates',
        'desc'    => 'When you are added to a company or your role changes.',
        'default' => 1,
    ],
    'app_reminders' => [
        'label'   => 'App reminders',
        'desc'    => 'Payroll runs and compliance deadlines from your connected apps.',
        'default' => 1,
    ],
    'product_news' => [
        'label'   => 'Product news & tips',
        'desc'    => 'Occasional updates about new Centryk features.',
        'default' => 0,
    ],
];
