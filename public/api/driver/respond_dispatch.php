<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['driver']);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;
aidfleet_require_fields($in, ['dispatch_id', 'decision']);

$dispatch_id = (int)$in['dispatch_id'];
$decision = trim((string)$in['decision']); // accepted | rejected
$reject_reason = isset($in['reject_reason']) ? trim((string)$in['reject_reason']) : null;

if (!in_array($decision, ['accepted', 'rejected'], true)) {
    aidfleet_send_json(['success' => false, 'message' => 'decision must be accepted or rejected'], 400);
}

$db = aidfleet_db();
$dr = aidfleet_db_one($db, '
    SELECT dispatch_id, request_id, driver_id, dispatch_status
    FROM dispatch_records
    WHERE dispatch_id = ?
', 'i', [$dispatch_id]);

if (!$dr || (int)$dr['driver_id'] !== (int)$u['id']) {
    aidfleet_send_json(['success' => false, 'message' => 'Dispatch not found'], 404);
}
if (($dr['dispatch_status'] ?? '') !== 'selected') {
    aidfleet_send_json(['success' => false, 'message' => 'Dispatch already responded to'], 409);
}

// Update the dispatch record with the driver's decision
$now = date('Y-m-d H:i:s');
if ($decision === 'accepted') {
    $stmt = $db->prepare('UPDATE dispatch_records SET dispatch_status = ?, driver_response_time = ?, reject_reason = NULL WHERE dispatch_id = ?');
    $stmt->bind_param('ssi', $decision, $now, $dispatch_id);
} else {
    $stmt = $db->prepare('UPDATE dispatch_records SET dispatch_status = ?, reject_reason = ?, driver_response_time = NULL WHERE dispatch_id = ?');
    $stmt->bind_param('ssi', $decision, $reject_reason, $dispatch_id);
}
$ok = $stmt->execute();
$stmt->close();
if (!$ok) aidfleet_send_json(['success' => false, 'message' => 'Update failed'], 500);

// Update request status and set driver availability accordingly
if ($decision === 'accepted') {
    $stmt = $db->prepare('UPDATE emergency_requests SET request_status = "accepted" WHERE request_id = ?');
    $stmt->bind_param('i', $dr['request_id']);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('UPDATE drivers SET availability_status = "on_route" WHERE driver_id = ?');
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $stmt->close();

    aidfleet_log('driver', (int)$u['id'], 'DISPATCH_ACCEPTED', 'dispatch_records', $dispatch_id, 'Driver accepted dispatch');
    aidfleet_send_json(['success' => true, 'dispatch_status' => 'accepted']);
}

// Rejection path - close original request and clone it so the requester can pick again
$origReq = aidfleet_db_one($db, '
    SELECT request_id, user_id, emergency_type, location, lat, lng, description
    FROM emergency_requests
    WHERE request_id = ?
', 'i', [$dr['request_id']]);

$stmt = $db->prepare('UPDATE drivers SET availability_status = "available" WHERE driver_id = ?');
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$stmt->close();

if ($origReq) {
    // Close the original request
    $stmt = $db->prepare('UPDATE emergency_requests SET request_status = "rejected" WHERE request_id = ?');
    $stmt->bind_param('i', $origReq['request_id']);
    $stmt->execute();
    $stmt->close();

    // Clone the request details so the requester can select a different driver
    $stmt = $db->prepare('INSERT INTO emergency_requests (user_id, emergency_type, location, lat, lng, description, request_status) VALUES (?,?,?,?,?,?, "pending")');
    $stmt->bind_param(
        'issdds',
        $origReq['user_id'],
        $origReq['emergency_type'],
        $origReq['location'],
        $origReq['lat'],
        $origReq['lng'],
        $origReq['description']
    );
    $okNew = $stmt->execute();
    $newRequestId = (int)$stmt->insert_id;
    $stmt->close();

    if ($okNew) {
        aidfleet_log('driver', (int)$u['id'], 'DISPATCH_REJECTED', 'dispatch_records', $dispatch_id, $reject_reason ?: 'Driver rejected dispatch');
        aidfleet_log('requester', (int)$origReq['user_id'], 'EMERGENCY_RECREATED_AFTER_REJECTION', 'emergency_requests', $newRequestId, 'New emergency request created after driver rejection');
        aidfleet_send_json(['success' => true, 'dispatch_status' => 'rejected']);
    }
}

// Fallback — original request not found or could not be cloned
aidfleet_log('driver', (int)$u['id'], 'DISPATCH_REJECTED', 'dispatch_records', $dispatch_id, $reject_reason ?: 'Driver rejected dispatch');
aidfleet_send_json(['success' => true, 'dispatch_status' => 'rejected']);

