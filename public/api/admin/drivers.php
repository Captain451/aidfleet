<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

aidfleet_require_auth(['admin']);
$db = aidfleet_db();

$rows = aidfleet_db_all($db, '
    SELECT driver_id, full_name, email, phone, license_number, ambulance_registration, ambulance_type,
           availability_status, verification_status, verification_note,
           documents_path, documents_original_name, documents_uploaded_at,
           medical_doc_path, medical_doc_original_name, medical_doc_uploaded_at,
           created_at
    FROM drivers
    ORDER BY created_at DESC
');

aidfleet_send_json(['success' => true, 'drivers' => $rows]);

