<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

aidfleet_require_auth(['requester','admin']);
$db = aidfleet_db();

$row = aidfleet_db_one($db, '
    SELECT COUNT(*) AS c
    FROM drivers
    WHERE verification_status = "approved"
      AND availability_status = "available"
');

aidfleet_send_json(['success' => true, 'count' => (int)($row['c'] ?? 0)]);

