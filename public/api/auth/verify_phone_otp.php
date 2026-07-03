<?php

require_once __DIR__ . '/../bootstrap.php';

/**
 * Phone OTP verification endpoint.
 *
 * Accepts: POST { phone, otp, role }
 * Validates the OTP, marks the account as phone-verified.
 */

aidfleet_require_method('POST');

$in = aidfleet_get_json_body();
aidfleet_require_fields($in, ['phone', 'otp', 'role']);

$phone = aidfleet_normalize_phone(trim((string)$in['phone']));
$otp   = trim((string)$in['otp']);
$role  = trim((string)$in['role']);
$accountPhone = isset($in['account_phone']) ? aidfleet_normalize_phone(trim((string)$in['account_phone'])) : $phone;

if (!in_array($role, ['requester', 'driver'], true)) {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid role'], 400);
}

$db = aidfleet_db();
$table = $role === 'requester' ? 'users' : 'drivers';
$idCol = $role === 'requester' ? 'user_id' : 'driver_id';

if ($accountPhone !== $phone) {
    $account = aidfleet_db_one($db, "SELECT $idCol AS id FROM $table WHERE phone = ? AND phone_verified = 0", 's', [$accountPhone]);
    if (!$account) {
        aidfleet_send_json(['success' => false, 'message' => 'No pending verification found for this account.'], 404);
    }

    // Optional duplicate-phone enforcement for changed-number verification.
    // if (aidfleet_phone_exists($phone)) {
    //     aidfleet_send_json(['success' => false, 'message' => 'This phone number is already registered.'], 409);
    // }
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

if ($role === 'requester') {
    $stmt = $accountPhone === $phone
        ? $db->prepare('UPDATE users SET phone_verified = 1 WHERE phone = ?')
        : $db->prepare('UPDATE users SET phone = ?, phone_verified = 1 WHERE phone = ? AND phone_verified = 0');
} else {
    $stmt = $accountPhone === $phone
        ? $db->prepare('UPDATE drivers SET phone_verified = 1 WHERE phone = ?')
        : $db->prepare('UPDATE drivers SET phone = ?, phone_verified = 1 WHERE phone = ? AND phone_verified = 0');
}

$affected = 0;
if ($stmt) {
    if ($accountPhone === $phone) {
        $stmt->bind_param('s', $phone);
    } else {
        $stmt->bind_param('ss', $phone, $accountPhone);
    }
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
}

if ($affected === 0) {
    aidfleet_send_json(['success' => false, 'message' => 'Account not found for this phone number'], 404);
}

$account = aidfleet_db_one($db, "SELECT $idCol AS id FROM $table WHERE phone = ?", 's', [$phone]);
$actorId = $account ? (int)$account['id'] : 0;

aidfleet_log($role, $actorId, 'PHONE_VERIFIED', $table, $actorId, 'Phone number verified via SMS OTP');

aidfleet_send_json([
    'success' => true,
    'message' => 'Phone number verified successfully.',
]);
