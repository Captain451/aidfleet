<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

$u = aidfleet_require_auth(['driver']);
$db = aidfleet_db();

$row = aidfleet_db_one($db, '
    SELECT dr.dispatch_id, dr.dispatch_status, dr.dispatch_time, dr.driver_response_time,
           dr.hospital_name, dr.hospital_lat, dr.hospital_lng,
           er.request_id, er.emergency_type, er.location, er.lat, er.lng, er.description, er.request_status, er.request_time,
           us.full_name AS requester_name, us.phone AS requester_phone, us.avg_rating AS requester_rating, us.profile_image AS requester_profile_image
    FROM dispatch_records dr
    JOIN emergency_requests er ON er.request_id = dr.request_id
    JOIN users us ON us.user_id = er.user_id
    WHERE dr.driver_id = ?
      AND er.request_status NOT IN ("completed","cancelled","rejected")
      AND dr.dispatch_status IN ("selected","accepted","arrived","enroute_hospital")
    ORDER BY dr.dispatch_time DESC
    LIMIT 1
', 'i', [(int)$u['id']]);

aidfleet_send_json(['success' => true, 'dispatch' => $row]);

