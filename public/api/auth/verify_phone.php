<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');

$in = aidfleet_get_json_body();
$phone = isset($in['phone']) ? trim((string)$in['phone']) : '';

if ($phone === '') {
    aidfleet_send_json(['success' => false, 'registered' => false, 'reason' => 'Phone is required'], 400);
}

$normalizedPhone = aidfleet_normalize_phone($phone);
$registered = false;
// Optional duplicate-phone detection. Keep disabled unless you want the
// frontend to block phone numbers already used by another account.
// $registered = aidfleet_phone_exists($normalizedPhone);

aidfleet_send_json([
    'success' => true,
    'registered' => $registered,
    'normalized' => $normalizedPhone,
    'reason' => $registered ? 'This phone number is already registered.' : '',
]);
