<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');
$u = aidfleet_require_auth(['requester']);

$driverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
if ($driverId <= 0) {
    aidfleet_send_json(['success' => false, 'message' => 'driver_id is required'], 400);
}

$db = aidfleet_db();
$row = aidfleet_db_one($db, '
    SELECT d.phone
    FROM dispatch_records dr
    INNER JOIN emergency_requests er ON er.request_id = dr.request_id
    INNER JOIN drivers d ON d.driver_id = dr.driver_id
    WHERE er.user_id = ?
      AND dr.driver_id = ?
      AND er.request_status NOT IN ("completed", "cancelled")
      AND dr.dispatch_status IN ("selected", "accepted", "arrived", "enroute_hospital", "rejected")
    ORDER BY dr.dispatch_time DESC
    LIMIT 1
', 'ii', [(int)$u['id'], $driverId]);

if (!$row) {
    aidfleet_send_json(['success' => false, 'message' => 'Driver contact not available'], 404);
}

aidfleet_send_json(['success' => true, 'phone' => $row['phone'] ?? '']);
