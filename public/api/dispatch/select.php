<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['requester']);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;
aidfleet_require_fields($in, ['request_id', 'driver_id']);

$request_id = (int)$in['request_id'];
$driver_id = (int)$in['driver_id'];

$db = aidfleet_db();
$er = aidfleet_db_one($db, 'SELECT request_id, user_id, request_status FROM emergency_requests WHERE request_id = ?', 'i', [$request_id]);
if (!$er || (int)$er['user_id'] !== (int)$u['id']) {
    aidfleet_send_json(['success' => false, 'message' => 'Request not found'], 404);
}
if (($er['request_status'] ?? '') !== 'pending') {
    aidfleet_send_json(['success' => false, 'message' => 'Request is not pending'], 409);
}

$driver = aidfleet_db_one($db, '
    SELECT driver_id FROM drivers
    WHERE driver_id = ?
      AND verification_status = "approved"
      AND availability_status = "available"
', 'i', [$driver_id]);
if (!$driver) {
    aidfleet_send_json(['success' => false, 'message' => 'Driver not available'], 409);
}

// Create dispatch record.
$stmt = $db->prepare('INSERT INTO dispatch_records (request_id, driver_id, dispatch_status) VALUES (?,?, "selected")');
$stmt->bind_param('ii', $request_id, $driver_id);
$ok = $stmt->execute();
$dispatch_id = (int)$stmt->insert_id;
$stmt->close();
if (!$ok) {
    aidfleet_send_json(['success' => false, 'message' => 'Could not create dispatch'], 500);
}

// Update request status.
$stmt = $db->prepare('UPDATE emergency_requests SET request_status = "driver_selected" WHERE request_id = ?');
$stmt->bind_param('i', $request_id);
$stmt->execute();
$stmt->close();

aidfleet_log('requester', (int)$u['id'], 'DRIVER_SELECTED', 'dispatch_records', $dispatch_id, "Requester selected driver {$driver_id}");
aidfleet_send_json(['success' => true, 'dispatch_id' => $dispatch_id]);

