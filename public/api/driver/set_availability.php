<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['driver']);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;
aidfleet_require_fields($in, ['availability_status']);
$status = trim((string)$in['availability_status']);

if (!in_array($status, ['offline', 'available'], true)) {
    aidfleet_send_json(['success' => false, 'message' => 'availability_status must be offline or available'], 400);
}

$db = aidfleet_db();
$d = aidfleet_db_one($db, 'SELECT verification_status, availability_status FROM drivers WHERE driver_id = ?', 'i', [(int)$u['id']]);
if (!$d) aidfleet_send_json(['success' => false, 'message' => 'Driver not found'], 404);
if (($d['verification_status'] ?? '') !== 'approved') {
    aidfleet_send_json(['success' => false, 'message' => 'Action Restricted: Your account is currently awaiting admin verification.'], 403);
}

if ($status === 'available') {
    if (($d['availability_status'] ?? '') === 'on_route') {
        aidfleet_send_json(['success' => false, 'message' => 'You cannot go online while navigating an active emergency.'], 409);
    }
    $activeTrip = aidfleet_db_one($db, '
        SELECT dr.dispatch_id FROM dispatch_records dr
        JOIN emergency_requests er ON er.request_id = dr.request_id
        WHERE dr.driver_id = ?
          AND dr.dispatch_status IN ("accepted","arrived","enroute_hospital")
          AND er.request_status NOT IN ("completed","cancelled","rejected")
        LIMIT 1
    ', 'i', [(int)$u['id']]);
    if ($activeTrip) {
        aidfleet_send_json(['success' => false, 'message' => 'You cannot go online while handling an active emergency.'], 409);
    }
}

$stmt = $db->prepare('UPDATE drivers SET availability_status = ? WHERE driver_id = ?');
$stmt->bind_param('si', $status, $u['id']);
$ok = $stmt->execute();
$stmt->close();
if (!$ok) aidfleet_send_json(['success' => false, 'message' => 'Update failed'], 500);

aidfleet_log('driver', (int)$u['id'], 'DRIVER_AVAILABILITY', 'drivers', (int)$u['id'], "Set availability: {$status}");
aidfleet_send_json(['success' => true, 'availability_status' => $status]);

