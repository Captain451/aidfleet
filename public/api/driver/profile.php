<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

$u = aidfleet_require_auth(['driver']);
$db = aidfleet_db();

$d = aidfleet_db_one($db, '
    SELECT driver_id, full_name, email, phone, license_number, ambulance_registration, ambulance_type,
           availability_status, verification_status, verification_note, last_lat, last_lng,
           documents_path, documents_original_name, documents_uploaded_at,
           medical_doc_path, medical_doc_original_name, medical_doc_uploaded_at,
           profile_image, avg_rating
    FROM drivers
    WHERE driver_id = ?
', 'i', [(int)$u['id']]);

aidfleet_send_json(['success' => true, 'driver' => $d]);

