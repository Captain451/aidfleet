<?php

require_once __DIR__ . '/../bootstrap.php';

/**
 * All password changes require OTP verification (send_otp + verify_and_change).
 */


aidfleet_require_method('POST');

$u  = aidfleet_require_auth(['requester', 'driver', 'admin']);
$in = aidfleet_get_json_body();

$action = isset($in['action']) ? (string)$in['action'] : '';

$db    = aidfleet_db();
$id    = (int)$u['id'];
$table = $u['role'] === 'requester' ? 'users' : ($u['role'] === 'driver' ? 'drivers' : 'administrators');
$idCol = $u['role'] === 'requester' ? 'user_id' : ($u['role'] === 'driver' ? 'driver_id' : 'admin_id');

// Change password: send OTP after current password check
if ($action === 'send_otp') {
    $current = isset($in['current_password']) ? (string)$in['current_password'] : '';
    $newPw   = isset($in['new_password'])     ? (string)$in['new_password'] : '';

    if ($current === '') {
        aidfleet_send_json(['success' => false, 'message' => 'Current password is required'], 400);
    }
    if (strlen($newPw) < 6) {
        aidfleet_send_json(['success' => false, 'message' => 'New password must be at least 6 characters'], 400);
    }

    // Verify current password
    $row = aidfleet_db_one($db, "SELECT password_hash, full_name, email FROM {$table} WHERE {$idCol} = ?", 'i', [$id]);
    if (!$row) {
        aidfleet_send_json(['success' => false, 'message' => 'Profile not found'], 404);
    }
    if (!password_verify($current, (string)$row['password_hash'])) {
        aidfleet_send_json(['success' => false, 'message' => 'Current password is incorrect'], 400);
    }

    unset($_SESSION['password_otp_attempts']);

    // Generate OTP and send to email
    $email = (string)$row['email'];
    $name  = (string)$row['full_name'];
    $otp   = aidfleet_generate_and_store_otp($email);
    $sent  = aidfleet_send_otp_email($email, $name, $otp, 'Password Change Verification');

    if (!$sent) {
        aidfleet_send_json(['success' => false, 'message' => 'Could not send verification email. Please try again later.'], 500);
    }

    // Log OTP sent
    aidfleet_log($u['role'], $id, 'PASSWORD_CHANGE_OTP_SENT', $table, $id, 'OTP sent to ' . $email);

    // Mask email for display
    $parts = explode('@', $email);
    $masked = substr($parts[0], 0, 2) . str_repeat('*', max(1, strlen($parts[0]) - 2)) . '@' . $parts[1];

    aidfleet_send_json([
        'success' => true,
        'message' => 'Verification code sent to your email',
        'masked_email' => $masked,
    ]);

// Change password: verify OTP and update
} elseif ($action === 'verify_and_change') {
    $current = isset($in['current_password']) ? (string)$in['current_password'] : '';
    $newPw   = isset($in['new_password'])     ? (string)$in['new_password'] : '';
    $otp     = isset($in['otp'])              ? trim((string)$in['otp']) : '';

    if ($current === '' || $newPw === '' || $otp === '') {
        aidfleet_send_json(['success' => false, 'message' => 'All fields are required'], 400);
    }
    if (strlen($newPw) < 6) {
        aidfleet_send_json(['success' => false, 'message' => 'New password must be at least 6 characters'], 400);
    }

    // Verify current password
    $row = aidfleet_db_one($db, "SELECT password_hash, email FROM {$table} WHERE {$idCol} = ?", 'i', [$id]);
    if (!$row) {
        aidfleet_send_json(['success' => false, 'message' => 'Profile not found'], 404);
    }
    if (!password_verify($current, (string)$row['password_hash'])) {
        aidfleet_send_json(['success' => false, 'message' => 'Current password is incorrect'], 400);
    }

    $email = (string)$row['email'];
    $otpResult = aidfleet_verify_otp_secure($email, $otp, false);
    if (!$otpResult['valid']) {
        aidfleet_send_json([
            'success' => false,
            'message' => $otpResult['message'],
            'locked' => !empty($otpResult['locked']),
            'locked_until' => $otpResult['locked_until'] ?? null,
        ], !empty($otpResult['locked']) ? 429 : 400);
    }

    // Update password
    $hash = password_hash($newPw, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE {$table} SET password_hash = ? WHERE {$idCol} = ?");
    if (!$stmt) aidfleet_send_json(['success' => false, 'message' => 'Server error'], 500);
    $stmt->bind_param('si', $hash, $id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) aidfleet_send_json(['success' => false, 'message' => 'Could not update password'], 500);

    // Mark OTP as used
    aidfleet_mark_otp_used($email);

    // Log
    $logAction = $u['role'] === 'requester' ? 'USER_PASSWORD_CHANGE' : ($u['role'] === 'driver' ? 'DRIVER_PASSWORD_CHANGE' : 'ADMIN_PASSWORD_CHANGE');
    aidfleet_log($u['role'], $id, $logAction, $table, $id, 'Password changed via OTP-verified flow');

    aidfleet_send_json(['success' => true, 'message' => 'Password changed successfully']);

} else {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid action. Use send_otp or verify_and_change.'], 400);
}
