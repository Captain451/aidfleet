<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

$u = aidfleet_require_auth(['requester']);
$db = aidfleet_db();

$rows = aidfleet_db_all($db, '
    SELECT er.request_id, er.emergency_type, er.location, er.lat, er.lng, er.description, er.request_time, er.request_status,
           dr.dispatch_id, dr.dispatch_status, dr.dispatch_time, dr.reject_reason,
           dr.rating_by_requester, dr.comment_by_requester, dr.rating_by_driver, dr.comment_by_driver,
           dr.hospital_name,
           d.driver_id, d.full_name AS driver_name, d.phone AS driver_phone, d.ambulance_registration, d.ambulance_type
    FROM emergency_requests er
    LEFT JOIN dispatch_records dr ON dr.request_id = er.request_id
    LEFT JOIN drivers d ON d.driver_id = dr.driver_id
    WHERE er.user_id = ?
    ORDER BY er.request_time DESC
', 'i', [(int)$u['id']]);

aidfleet_send_json(['success' => true, 'requests' => $rows]);
