<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

aidfleet_require_auth(['admin']);
$db = aidfleet_db();

$rows = aidfleet_db_all($db, '
    SELECT dr.dispatch_id, dr.dispatch_time, dr.dispatch_status, dr.driver_response_time, dr.completion_time,
           er.request_id, er.emergency_type, er.location, er.request_status, er.request_time,
           u.full_name AS requester_name, u.phone AS requester_phone,
           d.full_name AS driver_name, d.phone AS driver_phone, d.ambulance_registration, d.ambulance_type
    FROM dispatch_records dr
    JOIN emergency_requests er ON er.request_id = dr.request_id
    JOIN users u ON u.user_id = er.user_id
    JOIN drivers d ON d.driver_id = dr.driver_id
    ORDER BY dr.dispatch_time DESC
    LIMIT 200
');

aidfleet_send_json(['success' => true, 'dispatches' => $rows]);

