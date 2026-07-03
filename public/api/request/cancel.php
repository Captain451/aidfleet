<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['requester']);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;
aidfleet_require_fields($in, ['request_id']);
$request_id = (int)$in['request_id'];

$db = aidfleet_db();
$er = aidfleet_db_one($db, 'SELECT request_id, user_id, request_status FROM emergency_requests WHERE request_id = ?', 'i', [$request_id]);
if (!$er || (int)$er['user_id'] !== (int)$u['id']) {
    aidfleet_send_json(['success' => false, 'message' => 'Request not found'], 404);
}
if (in_array($er['request_status'], ['completed', 'cancelled'], true)) {
    aidfleet_send_json(['success' => false, 'message' => 'Request already closed'], 409);
}

$activeDriverTrip = aidfleet_db_one($db, '
    SELECT dr.dispatch_id
    FROM dispatch_records dr
    WHERE dr.request_id = ?
      AND dr.dispatch_status IN ("accepted", "arrived", "enroute_hospital")
    LIMIT 1
', 'i', [$request_id]);

$cancelReason = 'Cancelled by requester';
$userId = (int)$u['id'];

if ($activeDriverTrip) {
    $requestIds = [$request_id];
} else {
    // No active driver on this trip - fully close the entire open workflow
    $openRows = aidfleet_db_all($db, '
        SELECT request_id
        FROM emergency_requests
        WHERE user_id = ?
          AND request_status NOT IN ("completed", "cancelled")
    ', 'i', [$userId]);
    $requestIds = array_map(static fn($r) => (int)$r['request_id'], $openRows);
    if (empty($requestIds)) {
        $requestIds = [$request_id];
    }
}

foreach ($requestIds as $rid) {
    $stmt = $db->prepare('UPDATE emergency_requests SET request_status = "cancelled" WHERE request_id = ?');
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('
        UPDATE dispatch_records
        SET dispatch_status = "rejected", reject_reason = ?
        WHERE request_id = ?
          AND dispatch_status IN ("selected", "accepted", "arrived", "enroute_hospital")
    ');
    if ($stmt) {
        $stmt->bind_param('si', $cancelReason, $rid);
        $stmt->execute();
        $stmt->close();
    }

    $driverRow = aidfleet_db_one($db, '
        SELECT driver_id FROM dispatch_records
        WHERE request_id = ?
        ORDER BY dispatch_time DESC
        LIMIT 1
    ', 'i', [$rid]);
    if ($driverRow && !empty($driverRow['driver_id'])) {
        $driverId = (int)$driverRow['driver_id'];
        $stillActive = aidfleet_db_one($db, '
            SELECT dr.dispatch_id
            FROM dispatch_records dr
            INNER JOIN emergency_requests er ON er.request_id = dr.request_id
            WHERE dr.driver_id = ?
              AND er.request_status NOT IN ("completed", "cancelled")
              AND dr.dispatch_status IN ("accepted", "arrived", "enroute_hospital")
            LIMIT 1
        ', 'i', [$driverId]);
        if (!$stillActive) {
            $stmt = $db->prepare('UPDATE drivers SET availability_status = "available" WHERE driver_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $driverId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

aidfleet_log('requester', $userId, 'EMERGENCY_CANCELLED', 'emergency_requests', $request_id, 'Requester cancelled emergency request');
aidfleet_send_json(['success' => true, 'cancelled_count' => count($requestIds)]);
