<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

aidfleet_send_json([
    'success' => true,
    'csrf_token' => aidfleet_csrf_token(),
]);
