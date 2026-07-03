<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

$u = aidfleet_require_auth(['requester']);
$db = aidfleet_db();

$userId = (int)$u['id'];

$row = aidfleet_db_one($db, '
    SELECT er.request_id, er.emergency_type, er.location, er.lat, er.lng, er.description, er.request_time, er.request_status
    FROM emergency_requests er
    WHERE er.user_id = ?
      AND NOT (
          er.request_status = "pending"
          AND NOT EXISTS (SELECT 1 FROM dispatch_records dr WHERE dr.request_id = er.request_id)
      )
    ORDER BY er.request_time DESC
    LIMIT 1
', 'i', [$userId]);

if (!$row || in_array($row['request_status'], ['completed', 'cancelled'])) {
    aidfleet_send_json(['success' => true, 'request' => null]);
}

$dispatch = aidfleet_db_one($db, '
    SELECT dr.dispatch_id, dr.dispatch_status, dr.dispatch_time, dr.driver_response_time, dr.reject_reason,
           dr.hospital_name, dr.hospital_lat, dr.hospital_lng,
           d.driver_id, d.full_name AS driver_name, d.phone AS driver_phone, d.ambulance_registration, d.ambulance_type,
           d.last_lat, d.last_lng
    FROM dispatch_records dr
    JOIN drivers d ON d.driver_id = dr.driver_id
    WHERE dr.request_id = ?
    ORDER BY dr.dispatch_time DESC
    LIMIT 1
', 'i', [(int)$row['request_id']]);

if ($dispatch && !empty($dispatch['driver_id'])) {
    $phoneRow = aidfleet_db_one($db, 'SELECT phone FROM drivers WHERE driver_id = ?', 'i', [(int)$dispatch['driver_id']]);
    if ($phoneRow && !empty($phoneRow['phone'])) {
        $dispatch['driver_phone'] = $phoneRow['phone'];
    }
}

$row['dispatch'] = $dispatch;
aidfleet_send_json(['success' => true, 'request' => $row]);
