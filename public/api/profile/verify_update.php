<?php
require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['requester', 'driver', 'admin']);
$in = aidfleet_get_json_body();
aidfleet_require_fields($in, ['otp', 'type']);

$otp = trim((string)$in['otp']);
$type = trim((string)$in['type']); // 'email' or 'phone'

aidfleet_session_start();
if (!isset($_SESSION['pending_profile_update'])) {
    aidfleet_send_json(['success' => false, 'message' => 'No pending profile update found.'], 400);
}

$pending = &$_SESSION['pending_profile_update'];
$email = $pending['email'];
$phone = $pending['phone'];


if ($type === 'email') {
    $result = aidfleet_verify_otp_secure($email, $otp, true);
    if (!$result['valid']) {
        aidfleet_send_json([
            'success' => false,
            'message' => $result['message'],
            'locked' => !empty($result['locked']),
            'locked_until' => $result['locked_until'] ?? null,
        ], !empty($result['locked']) ? 429 : 400);
    }

    $pending['emailVerified'] = true;
    aidfleet_mark_otp_used($email);

    $db = aidfleet_db();
    $id = (int)$u['id'];
    $table = ($u['role'] === 'requester') ? 'users' : (($u['role'] === 'driver') ? 'drivers' : 'administrators');
    $idCol = ($u['role'] === 'requester') ? 'user_id' : (($u['role'] === 'driver') ? 'driver_id' : 'admin_id');
    $stmt = $db->prepare("UPDATE {$table} SET email = ? WHERE {$idCol} = ?");
    $stmt->bind_param('si', $email, $id);
    $stmt->execute();
    $stmt->close();
    $_SESSION['aidfleet']['email'] = $email;

} elseif ($type === 'phone') {
    if ($u['role'] === 'admin') {
        aidfleet_send_json(['success' => false, 'message' => 'Phone verification is not available for admin profiles'], 400);
    }
    if (!preg_match('/^\d{6}$/', $otp)) {
        aidfleet_send_json(['success' => false, 'message' => 'Verification code must be 6 digits'], 400);
    }
    $result = aidfleet_verify_phone_otp($phone, $otp);
    if (!$result['valid']) {
        aidfleet_send_json([
            'success' => false,
            'message' => $result['reason'],
            'locked' => !empty($result['locked']),
            'locked_until' => $result['locked_until'] ?? null,
        ], !empty($result['locked']) ? 429 : 400);
    }
    $pending['phoneVerified'] = true;

    $db = aidfleet_db();
    $id = (int)$u['id'];
    $table = ($u['role'] === 'requester') ? 'users' : (($u['role'] === 'driver') ? 'drivers' : 'administrators');
    $idCol = ($u['role'] === 'requester') ? 'user_id' : (($u['role'] === 'driver') ? 'driver_id' : 'admin_id');
    $stmt = $db->prepare("UPDATE {$table} SET phone = ?, phone_verified = 1 WHERE {$idCol} = ?");
    $stmt->bind_param('si', $phone, $id);
    $stmt->execute();
    $stmt->close();
} else {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid verification type'], 400);
}

// Check if all necessary verifications are complete
if (!$pending['emailVerified'] || !$pending['phoneVerified']) {
    if ($type === 'email' && !$pending['phoneVerified']) {
        $otpResult = aidfleet_send_phone_otp($phone);
        if (!$otpResult['sent']) {
            aidfleet_send_json(['success' => false, 'message' => $otpResult['message']], 503);
        }
        aidfleet_send_json(['success' => true, 'message' => 'OTP_REQUIRED_PHONE', 'phone' => $phone]);
    }
    aidfleet_send_json(['success' => true, 'message' => 'Verification successful, waiting for next step.']);
}

// If all are verified, finalize
$db = aidfleet_db();
$id = (int)$u['id'];

if ($u['role'] === 'requester') {
    aidfleet_log('requester', $id, 'USER_PROFILE_UPDATE', 'users', $id, 'Requester finished profile update flow');
    unset($_SESSION['pending_profile_update']);
    $req = aidfleet_db_one($db, 'SELECT email, phone FROM users WHERE user_id = ?', 'i', [$id]) ?: [];
    aidfleet_send_json([
        'success' => true,
        'user' => ['role' => 'requester', 'id' => $id, 'name' => $u['name'], 'email' => $req['email'] ?? $email, 'phone' => $req['phone'] ?? $phone],
    ]);
} elseif ($u['role'] === 'driver') {
    $driver = aidfleet_db_one($db, 'SELECT email, phone, verification_status, availability_status FROM drivers WHERE driver_id = ?', 'i', [$id]) ?: [];
    aidfleet_log('driver', $id, 'DRIVER_PROFILE_UPDATE', 'drivers', $id, 'Driver finished profile update flow');
    unset($_SESSION['pending_profile_update']);
    aidfleet_send_json([
        'success' => true,
        'user' => [
            'role' => 'driver',
            'id' => $id,
            'name' => $u['name'],
            'email' => $driver['email'] ?? $email,
            'verification_status' => $driver['verification_status'] ?? null,
            'availability_status' => $driver['availability_status'] ?? null,
        ],
    ]);
} elseif ($u['role'] === 'admin') {
    aidfleet_log('admin', $id, 'ADMIN_PROFILE_UPDATE', 'administrators', $id, 'Admin finished profile update flow');
    unset($_SESSION['pending_profile_update']);
    $admin = aidfleet_db_one($db, 'SELECT email FROM administrators WHERE admin_id = ?', 'i', [$id]) ?: [];
    aidfleet_send_json([
        'success' => true,
        'user' => ['role' => 'admin', 'id' => $id, 'name' => $u['name'], 'email' => $admin['email'] ?? $email],
    ]);
}
