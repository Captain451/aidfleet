<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['requester']);

$in = aidfleet_get_json_body();
$request_id = isset($in['request_id']) ? (int)$in['request_id'] : 0;

$db = aidfleet_db();

if ($request_id > 0) {
    $er = aidfleet_db_one($db, '
        SELECT request_id, user_id, emergency_type, location, lat, lng, description, request_status
        FROM emergency_requests
        WHERE request_id = ?
    ', 'i', [$request_id]);
} else {
    $er = aidfleet_db_one($db, '
        SELECT request_id, user_id, emergency_type, location, lat, lng, description, request_status
        FROM emergency_requests
        WHERE user_id = ?
          AND request_status IN ("cancelled", "rejected")
        ORDER BY request_time DESC
        LIMIT 1
    ', 'i', [(int)$u['id']]);
}

if (!$er || (int)$er['user_id'] !== (int)$u['id']) {
    aidfleet_send_json(['success' => false, 'message' => 'Request not found'], 404);
}

if (($er['request_status'] ?? '') === 'pending') {
    aidfleet_send_json(['success' => true, 'request_id' => (int)$er['request_id']]);
}

if (!in_array($er['request_status'] ?? '', ['cancelled', 'rejected'], true)) {
    aidfleet_send_json(['success' => false, 'message' => 'This request cannot be reopened for driver selection'], 409);
}

$existingPending = aidfleet_db_one($db, '
    SELECT request_id FROM emergency_requests
    WHERE user_id = ? AND request_status = "pending"
      AND NOT EXISTS (
        SELECT 1 FROM dispatch_records dr
        WHERE dr.request_id = emergency_requests.request_id
          AND dr.dispatch_status NOT IN ("rejected")
      )
    ORDER BY request_time DESC
    LIMIT 1
', 'i', [(int)$u['id']]);

if ($existingPending) {
    aidfleet_send_json(['success' => true, 'request_id' => (int)$existingPending['request_id']]);
}

$stmt = $db->prepare('INSERT INTO emergency_requests (user_id, emergency_type, location, lat, lng, description, request_status) VALUES (?,?,?,?,?,?, "pending")');
$stmt->bind_param(
    'issdds',
    $er['user_id'],
    $er['emergency_type'],
    $er['location'],
    $er['lat'],
    $er['lng'],
    $er['description']
);
$ok = $stmt->execute();
$newRequestId = (int)$stmt->insert_id;
$stmt->close();

if (!$ok || $newRequestId <= 0) {
    aidfleet_send_json(['success' => false, 'message' => 'Could not prepare request for redispatch'], 500);
}

aidfleet_log('requester', (int)$u['id'], 'EMERGENCY_REOPENED', 'emergency_requests', $newRequestId, 'Reopened after cancellation/rejection');
aidfleet_send_json(['success' => true, 'request_id' => $newRequestId]);
