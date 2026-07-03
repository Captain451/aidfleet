<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['driver']);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;
aidfleet_require_fields($in, ['dispatch_id']);
$dispatch_id = (int)$in['dispatch_id'];

$db = aidfleet_db();
$dr = aidfleet_db_one($db, 'SELECT dispatch_id, request_id, driver_id, dispatch_status FROM dispatch_records WHERE dispatch_id = ?', 'i', [$dispatch_id]);
if (!$dr || (int)$dr['driver_id'] !== (int)$u['id']) {
    aidfleet_send_json(['success' => false, 'message' => 'Dispatch not found'], 404);
}
if (!in_array($dr['dispatch_status'], ['accepted', 'arrived', 'enroute_hospital', 'completed'], true)) {
    aidfleet_send_json(['success' => false, 'message' => 'Dispatch not accepted'], 409);
}

$now = date('Y-m-d H:i:s');
$stmt = $db->prepare('UPDATE dispatch_records SET dispatch_status = "completed", completion_time = ? WHERE dispatch_id = ?');
$stmt->bind_param('si', $now, $dispatch_id);
$stmt->execute();
$stmt->close();

$stmt = $db->prepare('UPDATE emergency_requests SET request_status = "completed" WHERE request_id = ?');
$stmt->bind_param('i', $dr['request_id']);
$stmt->execute();
$stmt->close();

$stmt = $db->prepare('UPDATE drivers SET availability_status = "offline" WHERE driver_id = ?');
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$stmt->close();

aidfleet_log('driver', (int)$u['id'], 'DISPATCH_COMPLETED', 'dispatch_records', $dispatch_id, 'Driver completed dispatch');
aidfleet_send_json(['success' => true]);

