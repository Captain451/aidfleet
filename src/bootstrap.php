<?php
ob_start();

define('AIDFLEET_ROOT', dirname(__DIR__));

require_once __DIR__ . '/Core/env.php';
aidfleet_load_env();

require_once __DIR__ . '/Core/response.php';
require_once __DIR__ . '/Core/db.php';
require_once __DIR__ . '/Core/auth.php';
require_once __DIR__ . '/Core/logs.php';
require_once __DIR__ . '/Services/SmsService.php';
require_once __DIR__ . '/Services/mail_helpers.php';
require_once __DIR__ . '/Core/security.php';
