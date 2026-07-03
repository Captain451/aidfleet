<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

aidfleet_require_auth(['admin']);
$db = aidfleet_db();

$rows = aidfleet_db_all($db, '
    SELECT er.request_id, er.emergency_type, er.location, er.request_status, er.request_time,
           u.full_name AS requester_name, u.phone AS requester_phone,
           d.full_name AS driver_name
    FROM emergency_requests er
    JOIN users u ON u.user_id = er.user_id
    LEFT JOIN dispatch_records dr ON dr.dispatch_id = (
        SELECT dr2.dispatch_id
        FROM dispatch_records dr2
        WHERE dr2.request_id = er.request_id
        ORDER BY dr2.dispatch_time DESC
        LIMIT 1
    )
    LEFT JOIN drivers d ON d.driver_id = dr.driver_id
    ORDER BY er.request_time DESC
    LIMIT 500
');

aidfleet_send_json(['success' => true, 'requests' => $rows]);

