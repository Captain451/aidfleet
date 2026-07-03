<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;

aidfleet_require_fields($in, ['role', 'full_name', 'email', 'phone', 'password']);

$role = trim((string)$in['role']);
$full_name = trim((string)$in['full_name']);
$email = trim((string)$in['email']);
$phone = trim((string)$in['phone']);
$password = (string)$in['password'];

if (!in_array($role, ['requester', 'driver'], true)) {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid role'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid email'], 400);
}
// Check that the email domain actually has DNS records
$domain = substr(strrchr($email, "@"), 1);
if (!checkdnsrr($domain, "MX") && !checkdnsrr($domain, "A")) {
    aidfleet_send_json(['success' => false, 'message' => 'Please enter a valid email address'], 400);
}

if (strlen($password) < 6) {
    aidfleet_send_json(['success' => false, 'message' => 'Password must be at least 6 characters'], 400);
}

// Validate phone number
$phoneDigits = $phone;
if (strpos($phoneDigits, '+254') === 0) $phoneDigits = substr($phoneDigits, 4);
$phoneDigits = ltrim($phoneDigits, '0');
$phoneDigits = preg_replace('/\D/', '', $phoneDigits);
if (strlen($phoneDigits) !== 9) {
    aidfleet_send_json(['success' => false, 'message' => 'Phone number must be exactly 9 digits (e.g. 722735468)'], 400);
}

$db = aidfleet_db();

// Enforce unique email across all 3 user tables
if (aidfleet_email_exists($email)) {
    aidfleet_send_json(['success' => false, 'message' => 'Email already registered'], 409);
}

$normalizedPhone = aidfleet_normalize_phone($phone);

$hash = password_hash($password, PASSWORD_DEFAULT);

if ($role === 'requester') {
    $stmt = $db->prepare('INSERT INTO users (full_name, email, phone, password_hash, phone_verified) VALUES (?,?,?,?,0)');
    if (!$stmt) aidfleet_send_json(['success' => false, 'message' => 'Server error'], 500);
    $stmt->bind_param('ssss', $full_name, $email, $normalizedPhone, $hash);
    $ok = $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    if (!$ok) aidfleet_send_json(['success' => false, 'message' => 'Registration failed'], 500);

    // Phone must be verified before auto-login is allowed
    aidfleet_log('requester', $id, 'USER_REGISTER', 'users', $id, 'New requester registered (pending phone verification)');

    // Send OTP
    $otpResult = aidfleet_send_phone_otp($normalizedPhone);

    if (!$otpResult['sent']) {
        // SMS failed, delete the account so the user can retry registration
        $delStmt = $db->prepare('DELETE FROM users WHERE user_id = ?');
        if ($delStmt) { $delStmt->bind_param('i', $id); $delStmt->execute(); $delStmt->close(); }
        aidfleet_send_json(['success' => false, 'message' => $otpResult['message']], 503);
    }

    aidfleet_send_json([
        'success'      => true,
        'requires_otp' => true,
        'phone'        => $normalizedPhone,
        'role'         => 'requester',
        'message'      => $otpResult['message'],
    ]);
}

// Driver registration - extra fields required
aidfleet_require_fields($in, ['license_number', 'ambulance_registration', 'ambulance_type']);
$license_number = trim((string)$in['license_number']);
$ambulance_registration = trim((string)$in['ambulance_registration']);
$ambulance_type = trim((string)$in['ambulance_type']);

// Document uploads: driving license and medical cert
$documents_path = null;
$documents_original = null;
$documents_mime = null;
$documents_uploaded_at = null;

$medical_doc_path = null;
$medical_doc_original = null;
$medical_doc_mime = null;
$medical_doc_uploaded_at = null;

$cfg = aidfleet_config();
$uploadsDir = $cfg['uploads']['driver_docs_dir'];

// Driving license upload
if (isset($_FILES['license_document']) && is_array($_FILES['license_document'])) {
    [$documents_path, $documents_original, $documents_mime, $documents_uploaded_at] =
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

// Medical certificate upload
if (isset($_FILES['medical_document']) && is_array($_FILES['medical_document'])) {
    [$medical_doc_path, $medical_doc_original, $medical_doc_mime, $medical_doc_uploaded_at] =
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

$stmt = $db->prepare('
    INSERT INTO drivers
      (full_name,email,phone,password_hash,phone_verified,license_number,ambulance_registration,ambulance_type,verification_status,availability_status,documents_path,documents_original_name,documents_mime,documents_uploaded_at,medical_doc_path,medical_doc_original_name,medical_doc_mime,medical_doc_uploaded_at)
    VALUES
      (?,?,?,?,0,?,?,?,"pending","offline",?,?,?,?,?,?,?,?)
');
if (!$stmt) aidfleet_send_json(['success' => false, 'message' => 'Server error'], 500);
$stmt->bind_param(
    'sssssssssssssss',
    $full_name,
    $email,
    $normalizedPhone,
    $hash,
    $license_number,
    $ambulance_registration,
    $ambulance_type,
    $documents_path,
    $documents_original,
    $documents_mime,
    $documents_uploaded_at,
    $medical_doc_path,
    $medical_doc_original,
    $medical_doc_mime,
    $medical_doc_uploaded_at
);
$ok = $stmt->execute();
$id = (int)$stmt->insert_id;
$stmt->close();
if (!$ok) aidfleet_send_json(['success' => false, 'message' => 'Registration failed'], 500);

// Phone must be verified before auto-login is allowed
aidfleet_log('driver', $id, 'DRIVER_REGISTER', 'drivers', $id, 'New driver registered (pending phone + document verification)');

// Send OTP
$otpResult = aidfleet_send_phone_otp($normalizedPhone);

if (!$otpResult['sent']) {
    $delStmt = $db->prepare('DELETE FROM drivers WHERE driver_id = ?');
    if ($delStmt) { $delStmt->bind_param('i', $id); $delStmt->execute(); $delStmt->close(); }
    aidfleet_send_json(['success' => false, 'message' => $otpResult['message']], 503);
}

aidfleet_send_json([
    'success'      => true,
    'requires_otp' => true,
    'phone'        => $normalizedPhone,
    'role'         => 'driver',
    'message'      => $otpResult['message'],
]);
