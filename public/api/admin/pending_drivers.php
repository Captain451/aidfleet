<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

$admin = aidfleet_require_auth(['admin']);
$db = aidfleet_db();

$rows = aidfleet_db_all($db, '
    SELECT driver_id, full_name, email, phone, license_number, ambulance_registration, ambulance_type,
           verification_status, availability_status, documents_path, documents_original_name, documents_uploaded_at, created_at
    FROM drivers
    WHERE verification_status = "pending"
    ORDER BY created_at DESC
');

aidfleet_send_json(['success' => true, 'user' => $admin, 'drivers' => $rows]);

