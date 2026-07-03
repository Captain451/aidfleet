<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['driver']);

$in = aidfleet_get_json_body();
aidfleet_require_fields($in, ['dispatch_id']);
$dispatch_id = (int)$in['dispatch_id'];

$db = aidfleet_db();
$dr = aidfleet_db_one($db, '
    SELECT dr.dispatch_id, dr.request_id, dr.driver_id, dr.dispatch_status
    FROM dispatch_records dr
    WHERE dr.dispatch_id = ?
', 'i', [$dispatch_id]);

if (!$dr || (int)$dr['driver_id'] !== (int)$u['id']) {
    aidfleet_send_json(['success' => false, 'message' => 'Dispatch not found'], 404);
}

if (!in_array($dr['dispatch_status'], ['accepted', 'arrived', 'enroute_hospital'], true)) {
    aidfleet_send_json(['success' => false, 'message' => 'Only active responses can be cancelled'], 409);
}

$now = date('Y-m-d H:i:s');
$reason = trim((string)($in['cancel_reason'] ?? ''));
if ($reason === '') {
    $reason = 'Unspecified';
}

$stmt = $db->prepare('UPDATE dispatch_records SET dispatch_status = "rejected", driver_response_time = ?, reject_reason = ? WHERE dispatch_id = ?');
$stmt->bind_param('ssi', $now, $reason, $dispatch_id);
$stmt->execute();
$stmt->close();

$stmt = $db->prepare('UPDATE emergency_requests SET request_status = "cancelled" WHERE request_id = ?');
$stmt->bind_param('i', $dr['request_id']);
$stmt->execute();
$stmt->close();

$stmt = $db->prepare('UPDATE drivers SET availability_status = "offline" WHERE driver_id = ?');
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$stmt->close();

$req = aidfleet_db_one($db, 'SELECT user_id FROM emergency_requests WHERE request_id = ?', 'i', [(int)$dr['request_id']]);
$requesterId = $req ? (int)$req['user_id'] : 0;

aidfleet_log('driver', (int)$u['id'], 'DISPATCH_CANCELLED_BY_DRIVER', 'dispatch_records', $dispatch_id, $reason);
if ($requesterId > 0) {
    aidfleet_log('requester', $requesterId, 'EMERGENCY_CANCELLED_BY_DRIVER', 'emergency_requests', (int)$dr['request_id'], $reason);
}

aidfleet_send_json(['success' => true, 'message' => 'Emergency response cancelled']);
