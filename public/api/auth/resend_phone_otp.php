<?php

require_once __DIR__ . '/../bootstrap.php';

/**
 * Resend phone OTP endpoint.
 *
 * Accepts: POST { phone }
 * Rate-limited to prevent SMS bombing.
 */


aidfleet_require_method('POST');

$in = aidfleet_get_json_body();
aidfleet_require_fields($in, ['phone']);

$phone = aidfleet_normalize_phone(trim((string)$in['phone']));
$accountPhone = isset($in['account_phone']) ? aidfleet_normalize_phone(trim((string)$in['account_phone'])) : '';
$role = isset($in['role']) ? trim((string)$in['role']) : '';

$db = aidfleet_db();

if ($accountPhone !== '') {
    if (!in_array($role, ['requester', 'driver'], true)) {
        aidfleet_send_json(['success' => false, 'message' => 'Invalid role'], 400);
    }
    if ($phone === $accountPhone) {
        aidfleet_send_json(['success' => false, 'message' => 'Enter a different phone number from the current one.'], 400);
    }

    $table = $role === 'requester' ? 'users' : 'drivers';
    $idCol = $role === 'requester' ? 'user_id' : 'driver_id';
    $account = aidfleet_db_one(
        $db,
        "SELECT $idCol AS id FROM $table WHERE phone = ? AND phone_verified = 0",
        's',
        [$accountPhone]
    );
    if (!$account) {
        aidfleet_send_json([
            'success' => false,
            'message' => 'No pending verification found for this account.',
        ], 404);
    }

    // Optional duplicate-phone enforcement for changed-number verification.
    // if (aidfleet_phone_exists($phone)) {
    //     aidfleet_send_json(['success' => false, 'message' => 'This phone number is already registered.'], 409);
    // }
} else {
    // Ensure this phone actually belongs to a registered but unverified account.
    $userExists = aidfleet_db_one($db,
        "SELECT user_id AS id FROM users WHERE phone = ? AND phone_verified = 0",
        's', [$phone]
    );
    $driverExists = aidfleet_db_one($db,
        "SELECT driver_id AS id FROM drivers WHERE phone = ? AND phone_verified = 0",
        's', [$phone]
    );

    if (!$userExists && !$driverExists) {
        aidfleet_send_json([
            'success' => false,
            'message' => 'No pending verification found for this phone number.',
        ], 404);
    }
}

// Send the OTP -rate limiting is handled internally
$result = aidfleet_send_phone_otp($phone);

if (!$result['sent']) {
    $payload = [
        'success' => false,
        'message' => $result['message'],
    ];
    $statusCode = 503;
    if (!empty($result['rate_limited'])) {
        $payload['rate_limited'] = true;
        $statusCode = 429;
        if (isset($result['retry_after_seconds'])) {
            $payload['retry_after_seconds'] = (int) $result['retry_after_seconds'];
        }
    }
    aidfleet_send_json($payload, $statusCode);
}

aidfleet_send_json([
    'success' => true,
    'message' => $result['message'],
]);
