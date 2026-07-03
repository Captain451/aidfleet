<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$admin = aidfleet_require_auth(['admin']);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;

aidfleet_require_fields($in, ['driver_id', 'decision']);
$driver_id = (int)$in['driver_id'];
$decision = trim((string)$in['decision']);
$reason = isset($in['reason']) ? trim((string)$in['reason']) : '';
$context = isset($in['context']) ? trim((string)$in['context']) : '';

if (!in_array($decision, ['approved', 'rejected', 'pending'], true)) {
    aidfleet_send_json(['success' => false, 'message' => 'Decision must be approved, rejected, or pending'], 400);
}

$db = aidfleet_db();
$driver = aidfleet_db_one(
    $db,
    'SELECT driver_id, full_name, email, verification_status, availability_status FROM drivers WHERE driver_id = ?',
    'i',
    [$driver_id]
);
if (!$driver) {
    aidfleet_send_json(['success' => false, 'message' => 'Driver not found'], 404);
}

$note = null;
if ($decision === 'rejected') {
    if ($context === 'temporarily_disabled') {
        // Only allow revocation for already approved drivers who are not currently on a trip.
        if ($driver['verification_status'] !== 'approved') {
            aidfleet_send_json(['success' => false, 'message' => 'Only approved drivers can be temporarily disabled'], 409);
        }
        if (($driver['availability_status'] ?? '') === 'on_route') {
            aidfleet_send_json(['success' => false, 'message' => 'Cannot disable driver while handling an active trip'], 409);
        }
        $note = 'temporarily disabled: ' . $reason;
    } else {
        $note = 'rejected: ' . $reason;
    }
} else if ($decision === 'approved' && $context === 'reactivated') {
    $note = 'reactivated';
}

$stmt = $db->prepare('UPDATE drivers SET verification_status = ?, verification_note = ? WHERE driver_id = ?');
$stmt->bind_param('ssi', $decision, $note, $driver_id);
$ok = $stmt->execute();
$stmt->close();
if (!$ok) {
    aidfleet_send_json(['success' => false, 'message' => 'Update failed'], 500);
}

// When revoking, immediately force the driver offline.
if ($decision === 'rejected' && $context === 'temporarily_disabled') {
    $stmt = $db->prepare('UPDATE drivers SET availability_status = "offline" WHERE driver_id = ?');
    $stmt->bind_param('i', $driver_id);
    $stmt->execute();
    $stmt->close();
}

// Release session lock before slow email delivery so other admin requests are not blocked.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($decision !== 'pending') {
    aidfleet_send_status_email($driver['email'], $driver['full_name'], $decision, $reason, $context);
}

// Log message
if ($decision === 'rejected' && $reason !== '') {
    aidfleet_log('admin', (int)$admin['id'], 'DRIVER_VERIFICATION', 'drivers', $driver_id, "Driver rejected (with reason: {$reason})");
} else {
    aidfleet_log('admin', (int)$admin['id'], 'DRIVER_VERIFICATION', 'drivers', $driver_id, "Driver {$decision}" . ($reason ? " ({$reason})" : ''));
}

aidfleet_send_json(['success' => true, 'message' => "Driver {$decision}", 'note' => $note]);

