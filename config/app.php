<?php

 //AidFleet backend configuration.
 //Sensitive values are loaded from the project-root .env file.
 

require_once __DIR__ . '/../src/Core/env.php';
aidfleet_load_env();

return [
    'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', 3306),
        'name' => env('DB_NAME', 'aidfleet_db'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
    ],

    'uploads' => [
        'driver_docs_dir'           => __DIR__ . '/../public/' . env('UPLOAD_DRIVER_DOCS_DIR', 'uploads/driver_docs'),
        'driver_docs_public_prefix' => env('UPLOAD_DRIVER_DOCS_DIR', 'uploads/driver_docs'),
    ],

    'smtp' => [
        'enabled'    => (bool) env('SMTP_ENABLED', false),
        'host'       => env('SMTP_HOST', 'smtp.gmail.com'),
        'port'       => (int) env('SMTP_PORT', 587),
        'secure'     => env('SMTP_SECURE', 'tls'),
        'auth'       => (bool) env('SMTP_AUTH', true),
        'username'   => env('SMTP_USERNAME', ''),
        'password'   => env('SMTP_PASSWORD', ''),
        'from_email' => env('MAIL_FROM_ADDRESS', env('SMTP_USERNAME', '')),
        'from_name'  => env('MAIL_FROM_NAME', 'AidFleet Emergency Dispatch'),
    ],

    'sms' => [
        'enabled'    => (bool) env('SMS_ENABLED', false),
        // username and api keys 
        'username'   => env('SMS_USERNAME', 'sandbox'),
        'api_key'    => env('SMS_API_KEY', ''),
      
        // Leave empty when you do not have one
        'sender_id'  => env('SMS_SENDER_ID', ''),
        'verify_ssl' => (bool) env('SMS_SSL_VERIFY', true),
        'enqueue'    => (bool) env('SMS_ENQUEUE', false),
    ],
];
