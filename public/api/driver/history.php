<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

$u = aidfleet_require_auth(['driver']);
$db = aidfleet_db();

$rows = aidfleet_db_all($db, '
    SELECT dr.dispatch_id, dr.dispatch_time, dr.dispatch_status, dr.completion_time,
           dr.rating_by_requester, dr.comment_by_requester, dr.rating_by_driver, dr.comment_by_driver,
           dr.hospital_name,
           er.request_id, er.emergency_type, er.location, er.request_status, er.request_time,
           u.full_name AS requester_name, u.phone AS requester_phone
    FROM dispatch_records dr
    JOIN emergency_requests er ON er.request_id = dr.request_id
    JOIN users u ON u.user_id = er.user_id
    WHERE dr.driver_id = ?
    ORDER BY dr.dispatch_time DESC
    LIMIT 200
', 'i', [(int)$u['id']]);

aidfleet_send_json(['success' => true, 'history' => $rows]);
