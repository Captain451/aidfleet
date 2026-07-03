<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');

$u = aidfleet_require_auth(['requester', 'driver', 'admin']);
if ($u['role'] === 'admin') {
    aidfleet_send_json(['success' => false, 'message' => 'Administrator accounts cannot be deleted'], 403);
}

$db = aidfleet_db();
$id = (int)$u['id'];

if ($u['role'] === 'requester') {
    $has = aidfleet_db_one($db, 'SELECT request_id FROM emergency_requests WHERE user_id = ? LIMIT 1', 'i', [$id]);
    if ($has) {
        aidfleet_send_json([
            'success' => false,
            'message' => 'Cannot delete account because request history exists. Please contact the administrator.',
        ], 409);
    }

    aidfleet_log('requester', $id, 'USER_DELETE_ACCOUNT', 'users', $id, 'Requester deleted account');
    $stmt = $db->prepare('DELETE FROM users WHERE user_id = ?');
    if (!$stmt) aidfleet_send_json(['success' => false, 'message' => 'Server error'], 500);
    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) aidfleet_send_json(['success' => false, 'message' => 'Could not delete account'], 500);

    aidfleet_logout();
    aidfleet_send_json(['success' => true]);
}

// driver
$has = aidfleet_db_one($db, 'SELECT dispatch_id FROM dispatch_records WHERE driver_id = ? LIMIT 1', 'i', [$id]);
if ($has) {
    aidfleet_send_json([
        'success' => false,
        'message' => 'Cannot delete account because dispatch history exists. Please contact the administrator.',
    ], 409);
}

aidfleet_log('driver', $id, 'DRIVER_DELETE_ACCOUNT', 'drivers', $id, 'Driver deleted account');
$stmt = $db->prepare('DELETE FROM drivers WHERE driver_id = ?');
if (!$stmt) aidfleet_send_json(['success' => false, 'message' => 'Server error'], 500);
$stmt->bind_param('i', $id);
$ok = $stmt->execute();
$stmt->close();
if (!$ok) aidfleet_send_json(['success' => false, 'message' => 'Could not delete account'], 500);

aidfleet_logout();
aidfleet_send_json(['success' => true]);

