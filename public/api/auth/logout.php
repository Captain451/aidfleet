<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
aidfleet_logout();
aidfleet_send_json(['success' => true]);

