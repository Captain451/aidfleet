<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['driver']);
$db = aidfleet_db();

$driver = aidfleet_db_one($db, '
    SELECT driver_id, verification_status, verification_note
    FROM drivers
    WHERE driver_id = ?
', 'i', [(int)$u['id']]);

if (!$driver) {
    aidfleet_send_json(['success' => false, 'message' => 'Driver not found'], 404);
}

if (($driver['verification_status'] ?? '') === 'approved') {
    aidfleet_send_json(['success' => false, 'message' => 'Documents already verified. Compliance uploads are disabled.'], 400);
}

// If account was temporarily disabled, do not allow re-uploads
if (($driver['verification_status'] ?? '') === 'rejected') {
    $note = (string)($driver['verification_note'] ?? '');
    if ($note && stripos($note, 'temporarily disabled') !== false) {
        aidfleet_send_json(['success' => false, 'message' => 'Account temporarily disabled. Compliance uploads are disabled.'], 400);
    }
}

$cfg = aidfleet_config();
$uploadsDir = $cfg['uploads']['driver_docs_dir'];

$license_path = $license_original = $license_mime = $license_uploaded_at = null;
$medical_path = $medical_original = $medical_mime = $medical_uploaded_at = null;

if (isset($_FILES['license_document']) && is_array($_FILES['license_document'])) {
    [$license_path, $license_original, $license_mime, $license_uploaded_at] =
        aidfleet_store_uploaded_file($_FILES['license_document'], [
            'prefix' => 'license_',
            'public_prefix' => $cfg['uploads']['driver_docs_public_prefix'],
            'upload_dir' => $uploadsDir,
            'max_bytes' => 20 * 1024 * 1024,
            'allowed' => [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            ],
            'error_message' => 'Document upload failed',
            'type_message' => 'Document must be PDF, JPG, or PNG',
            'size_message' => 'Document must be 20MB or less',
        ]);
}

if (isset($_FILES['medical_document']) && is_array($_FILES['medical_document'])) {
    [$medical_path, $medical_original, $medical_mime, $medical_uploaded_at] =
        aidfleet_store_uploaded_file($_FILES['medical_document'], [
            'prefix' => 'medical_',
            'public_prefix' => $cfg['uploads']['driver_docs_public_prefix'],
            'upload_dir' => $uploadsDir,
            'max_bytes' => 20 * 1024 * 1024,
            'allowed' => [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            ],
            'error_message' => 'Document upload failed',
            'type_message' => 'Document must be PDF, JPG, or PNG',
            'size_message' => 'Document must be 20MB or less',
        ]);
}

if (!$license_path && !$medical_path) {
    aidfleet_send_json(['success' => false, 'message' => 'Please select at least one document to upload'], 400);
}

$sql = 'UPDATE drivers SET verification_status = "pending", verification_note = NULL';
$params = [];
$types = '';

if ($license_path) {
    $sql .= ', documents_path = ?, documents_original_name = ?, documents_mime = ?, documents_uploaded_at = ?';
    $types .= 'ssss';
    array_push($params, $license_path, $license_original, $license_mime, $license_uploaded_at);
}

if ($medical_path) {
    $sql .= ', medical_doc_path = ?, medical_doc_original_name = ?, medical_doc_mime = ?, medical_doc_uploaded_at = ?';
    $types .= 'ssss';
    array_push($params, $medical_path, $medical_original, $medical_mime, $medical_uploaded_at);
}

$sql .= ' WHERE driver_id = ?';
$types .= 'i';
array_push($params, (int)$u['id']);

$stmt = $db->prepare($sql);
if (!$stmt) {
    aidfleet_send_json(['success' => false, 'message' => 'Server error'], 500);
}

$stmt->bind_param($types, ...$params);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    aidfleet_send_json(['success' => false, 'message' => 'Update failed'], 500);
}

aidfleet_log('driver', (int)$u['id'], 'DRIVER_REUPLOAD_DOCS', 'drivers', (int)$u['id'], 'Driver re-uploaded verification documents');

aidfleet_send_json(['success' => true, 'message' => 'Documents uploaded. Your account is pending verification again.']);

