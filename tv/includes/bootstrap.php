<?php

require_once __DIR__ . '/../../app/core/Auth.php';
require_once __DIR__ . '/../../app/core/DB.php';
require_once __DIR__ . '/../../app/core/Response.php';
require_once __DIR__ . '/../../app/core/Audit.php';
require_once __DIR__ . '/../../app/services/AuthService.php';

require_once __DIR__ . '/../services/StreamingService.php';
require_once __DIR__ . '/../services/TvMetricsService.php';
require_once __DIR__ . '/../services/TvManagementService.php';
require_once __DIR__ . '/functions.php';

date_default_timezone_set(tv_config('timezone'));
Auth::start();

