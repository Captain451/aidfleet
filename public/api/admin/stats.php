<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

aidfleet_require_auth(['admin']);
$db = aidfleet_db();

$counts = [
    'pending_drivers' => (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM drivers WHERE verification_status = "pending"') ['c'] ?? 0),
    'approved_drivers' => (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM drivers WHERE verification_status = "approved"') ['c'] ?? 0),
    'active_requests' => (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM emergency_requests WHERE request_status NOT IN ("completed","cancelled","rejected" )') ['c'] ?? 0),
    'total_requests' => (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM emergency_requests') ['c'] ?? 0),
    'total_users' => (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM users') ['c'] ?? 0),
];

aidfleet_send_json(['success' => true, 'stats' => $counts]);

