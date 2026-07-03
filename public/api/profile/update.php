<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');

$u = aidfleet_require_auth(['requester', 'driver', 'admin']);
$in = aidfleet_get_json_body();

aidfleet_require_fields($in, ['email']);
$email = trim((string)$in['email']);
$phone = isset($in['phone']) ? trim((string)$in['phone']) : '';
$normalizedPhone = in_array($u['role'], ['requester', 'driver'], true) ? aidfleet_normalize_phone($phone) : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid email'], 400);
}

if (in_array($u['role'], ['requester', 'driver'], true)) {
    if ($phone === '') {
        aidfleet_send_json(['success' => false, 'message' => 'Phone is required'], 400);
    }
    // Allow +, digits, spaces, hyphen, parentheses.
    if (!preg_match('/^[0-9+\-\s()]{7,30}$/', $phone)) {
        aidfleet_send_json(['success' => false, 'message' => 'Invalid phone number'], 400);
    }
}

$db = aidfleet_db();
$id = (int)$u['id'];

$table = $u['role'] === 'requester' ? 'users' : ($u['role'] === 'driver' ? 'drivers' : 'administrators');
$idCol = $u['role'] === 'requester' ? 'user_id' : ($u['role'] === 'driver' ? 'driver_id' : 'admin_id');

$current = aidfleet_db_one($db, "SELECT email" . ($u['role'] !== 'admin' ? ", phone" : "") . " FROM {$table} WHERE {$idCol} = ?", 'i', [$id]);

$emailChanged = ($current['email'] !== $email);
$phoneChanged = ($u['role'] !== 'admin' && aidfleet_normalize_phone((string)$current['phone']) !== $normalizedPhone);

if ($emailChanged) {
    if (aidfleet_email_exists($email, $u['role'], $id)) {
        aidfleet_send_json(['success' => false, 'message' => 'User already exists'], 409);
    }
}

// Optional duplicate-phone enforcement for profile phone changes.
// Keep disabled unless you decide each account must have a globally unique phone.
// if ($phoneChanged && aidfleet_phone_exists($normalizedPhone, $u['role'], $id)) {
//     aidfleet_send_json(['success' => false, 'message' => 'Phone number already registered'], 409);
// }

aidfleet_session_start();


if (!$emailChanged && !$phoneChanged) {
    aidfleet_send_json(['success' => true, 'message' => 'No changes made.']);
}

// Reset OTP attempts on a fresh update or resend
$_SESSION['otp_attempts'] = 0;

// Store pending updates in session
$_SESSION['pending_profile_update'] = [
    'email' => $email,
    'phone' => $normalizedPhone,
    'emailChanged' => $emailChanged,
    'phoneChanged' => $phoneChanged,
    'emailVerified' => !$emailChanged,
    'phoneVerified' => !$phoneChanged
];

// Trigger the first necessary OTP
if ($emailChanged) {
    require_once __DIR__ . '/../auth/verify_email.php';
    $emailCheck = aidfleet_verify_email_real($email);
    if (!$emailCheck['valid']) {
        aidfleet_send_json(['success' => false, 'message' => 'Please enter a valid email address'], 400);
    }

    $_SESSION['email_otp_attempts'] = 0;
    $otp = aidfleet_generate_and_store_otp($email);
    aidfleet_send_otp_email($email, $u['name'], $otp, 'Email Update Verification');
    aidfleet_send_json(['success' => false, 'message' => 'OTP_REQUIRED_EMAIL', 'email' => $email]);
}

if ($phoneChanged) {
    $otpResult = aidfleet_send_phone_otp($normalizedPhone);
    if (!$otpResult['sent']) {
        aidfleet_send_json(['success' => false, 'message' => $otpResult['message']], 503);
    }
    aidfleet_send_json(['success' => false, 'message' => 'OTP_REQUIRED_PHONE', 'phone' => $normalizedPhone]);
}
