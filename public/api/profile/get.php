<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

$u = aidfleet_require_auth(['requester', 'driver', 'admin']);
$db = aidfleet_db();

if ($u['role'] === 'requester') {
    $row = aidfleet_db_one(
        $db,
        'SELECT user_id, full_name, email, phone, created_at, updated_at, profile_image, avg_rating FROM users WHERE user_id = ?',
        'i',
        [(int)$u['id']]
    );
    if (!$row) aidfleet_send_json(['success' => false, 'message' => 'Profile not found'], 404);
    aidfleet_send_json(['success' => true, 'profile' => $row]);
}

if ($u['role'] === 'driver') {
    $row = aidfleet_db_one(
        $db,
        'SELECT driver_id, full_name, email, phone, license_number, ambulance_registration, ambulance_type,
                availability_status, verification_status, verification_note, created_at, updated_at, profile_image, avg_rating
         FROM drivers
         WHERE driver_id = ?',
        'i',
        [(int)$u['id']]
    );
    if (!$row) aidfleet_send_json(['success' => false, 'message' => 'Profile not found'], 404);
    aidfleet_send_json(['success' => true, 'profile' => $row]);
}

// admin
aidfleet_ensure_admin_profile_image_schema();
$row = aidfleet_db_one(
    $db,
    'SELECT admin_id, full_name, email, profile_image, created_at FROM administrators WHERE admin_id = ?',
    'i',
    [(int)$u['id']]
);
if (!$row) aidfleet_send_json(['success' => false, 'message' => 'Profile not found'], 404);
aidfleet_send_json(['success' => true, 'profile' => $row]);

