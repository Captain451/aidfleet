<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$userData = aidfleet_require_auth();
$db = aidfleet_db();

if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    aidfleet_send_json(['success' => false, 'message' => 'File upload failed'], 400);
}

[$relativePath] = aidfleet_store_uploaded_file($_FILES['avatar'], [
    'prefix' => 'avatar_',
    'public_prefix' => 'uploads/avatars',
    'upload_dir' => aidfleet_public_upload_root('uploads/avatars'),
    'max_bytes' => 5 * 1024 * 1024,
    'allowed' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ],
    'type_message' => 'Invalid file type. Only JPG, PNG, and WEBP allowed.',
    'size_message' => 'File too large. Max 5MB.',
    'save_message' => 'Failed to save file',
]);

if ($userData['role'] === 'driver') {
    $table = 'drivers';
    $idField = 'driver_id';
} elseif ($userData['role'] === 'admin') {
    $table = 'administrators';
    $idField = 'admin_id';
    aidfleet_ensure_admin_profile_image_schema();
} else {
    $table = 'users';
    $idField = 'user_id';
}

// Fetch old avatar to delete it
$oldProfile = aidfleet_db_one($db, "SELECT profile_image FROM {$table} WHERE {$idField} = ?", "i", [$userData['id']]);
if ($oldProfile && $oldProfile['profile_image']) {
    aidfleet_delete_public_file($oldProfile['profile_image']);
}

$stmt = $db->prepare("UPDATE {$table} SET profile_image = ? WHERE {$idField} = ?");
$stmt->bind_param("si", $relativePath, $userData['id']);
$stmt->execute();
$stmt->close();

aidfleet_send_json(['success' => true, 'message' => 'Profile picture updated', 'profile_image' => $relativePath]);
