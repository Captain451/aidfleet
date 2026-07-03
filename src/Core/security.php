<?php

/**
 * Shared security helpers for account lookups and upload validation.
 */

function aidfleet_account_meta(string $role): ?array
{
    $map = [
        'requester' => ['table' => 'users', 'id_col' => 'user_id'],
        'driver' => ['table' => 'drivers', 'id_col' => 'driver_id'],
        'admin' => ['table' => 'administrators', 'id_col' => 'admin_id'],
    ];
    return $map[$role] ?? null;
}

function aidfleet_email_exists(string $email, ?string $excludeRole = null, int $excludeId = 0): bool
{
    $db = aidfleet_db();
    foreach (['requester', 'driver', 'admin'] as $role) {
        $meta = aidfleet_account_meta($role);
        if (!$meta) {
            continue;
        }
        $sql = "SELECT {$meta['id_col']} AS id FROM {$meta['table']} WHERE email = ?";
        $types = 's';
        $params = [$email];
        if ($excludeRole === $role && $excludeId > 0) {
            $sql .= " AND {$meta['id_col']} <> ?";
            $types .= 'i';
            $params[] = $excludeId;
        }
        if (aidfleet_db_one($db, $sql, $types, $params)) {
            return true;
        }
    }
    return false;
}

function aidfleet_phone_exists(string $phone, ?string $excludeRole = null, int $excludeId = 0): bool
{
    $db = aidfleet_db();
    $normalized = aidfleet_normalize_phone($phone);
    foreach (['requester', 'driver'] as $role) {
        $meta = aidfleet_account_meta($role);
        if (!$meta) {
            continue;
        }
        $sql = "SELECT {$meta['id_col']} AS id FROM {$meta['table']} WHERE phone = ?";
        $types = 's';
        $params = [$normalized];
        if ($excludeRole === $role && $excludeId > 0) {
            $sql .= " AND {$meta['id_col']} <> ?";
            $types .= 'i';
            $params[] = $excludeId;
        }
        if (aidfleet_db_one($db, $sql, $types, $params)) {
            return true;
        }
    }

    $adminPhoneCol = $db->query("SHOW COLUMNS FROM administrators LIKE 'phone'");
    if ($adminPhoneCol && $adminPhoneCol->num_rows > 0) {
        $sql = 'SELECT admin_id AS id FROM administrators WHERE phone = ?';
        $types = 's';
        $params = [$normalized];
        if ($excludeRole === 'admin' && $excludeId > 0) {
            $sql .= ' AND admin_id <> ?';
            $types .= 'i';
            $params[] = $excludeId;
        }
        return (bool) aidfleet_db_one($db, $sql, $types, $params);
    }

    return false;
}

function aidfleet_ensure_admin_profile_image_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $db = aidfleet_db();
    $colCheck = $db->query("SHOW COLUMNS FROM administrators LIKE 'profile_image'");
    if ($colCheck && $colCheck->num_rows === 0) {
        $db->query("ALTER TABLE administrators ADD COLUMN profile_image VARCHAR(255) NULL AFTER email");
    }
    $done = true;
}

function aidfleet_ensure_user_status_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $db = aidfleet_db();
    $statusCheck = $db->query("SHOW COLUMNS FROM users LIKE 'account_status'");
    if ($statusCheck && $statusCheck->num_rows === 0) {
        $db->query("ALTER TABLE users ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER total_ratings");
    }
    $noteCheck = $db->query("SHOW COLUMNS FROM users LIKE 'account_note'");
    if ($noteCheck && $noteCheck->num_rows === 0) {
        $db->query("ALTER TABLE users ADD COLUMN account_note TEXT NULL AFTER account_status");
    }
    $done = true;
}

function aidfleet_public_upload_root(string $relativePath = ''): string
{
    $base = realpath(AIDFLEET_ROOT . '/public');
    if (!$base) {
        aidfleet_send_json(['success' => false, 'message' => 'Upload root unavailable'], 500);
    }
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath !== '' && str_contains($relativePath, '..')) {
        aidfleet_send_json(['success' => false, 'message' => 'Invalid upload path'], 500);
    }
    return $relativePath === '' ? $base : $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function aidfleet_detect_uploaded_mime(string $tmpPath): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }
    return '';
}

/**
 * Store an uploaded file after server-side MIME validation.
 *
 * @return array{0:?string,1:?string,2:?string,3:?string}
 */
function aidfleet_store_uploaded_file(array $file, array $options): array
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return [null, null, null, null];
    }
    if ($err !== UPLOAD_ERR_OK) {
        aidfleet_send_json(['success' => false, 'message' => $options['error_message'] ?? 'File upload failed'], 400);
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        aidfleet_send_json(['success' => false, 'message' => 'Invalid upload'], 400);
    }

    $size = (int)($file['size'] ?? 0);
    $maxBytes = (int)($options['max_bytes'] ?? (5 * 1024 * 1024));
    if ($size <= 0 || $size > $maxBytes) {
        aidfleet_send_json(['success' => false, 'message' => $options['size_message'] ?? 'File size is not allowed'], 400);
    }

    $allowed = $options['allowed'] ?? [];
    $mime = aidfleet_detect_uploaded_mime($tmp);
    if ($mime === '' || !array_key_exists($mime, $allowed)) {
        aidfleet_send_json(['success' => false, 'message' => $options['type_message'] ?? 'File type is not allowed'], 400);
    }

    $publicPrefix = trim((string)($options['public_prefix'] ?? ''), '/');
    $uploadDir = (string)($options['upload_dir'] ?? aidfleet_public_upload_root($publicPrefix));
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        aidfleet_send_json(['success' => false, 'message' => 'Upload directory unavailable'], 500);
    }

    $realUploadDir = realpath($uploadDir);
    $publicRoot = aidfleet_public_upload_root();
    $publicRootPrefix = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $realUploadDirPrefix = rtrim($realUploadDir ?: '', DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!$realUploadDir || !str_starts_with($realUploadDirPrefix, $publicRootPrefix)) {
        aidfleet_send_json(['success' => false, 'message' => 'Invalid upload directory'], 500);
    }

    $ext = is_array($allowed[$mime]) ? (string)$allowed[$mime][0] : (string)$allowed[$mime];
    $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
    if ($ext === '') {
        aidfleet_send_json(['success' => false, 'message' => 'Invalid upload extension'], 500);
    }

    $prefix = preg_replace('/[^a-z0-9_]/i', '', (string)($options['prefix'] ?? 'upload_'));
    $filename = $prefix . date('YmdHis') . '_' . bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    $dest = $realUploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmp, $dest)) {
        aidfleet_send_json(['success' => false, 'message' => $options['save_message'] ?? 'Could not save uploaded file'], 500);
    }
    @chmod($dest, 0644);

    $originalName = basename((string)($file['name'] ?? 'upload'));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/', '', $originalName);
    $path = ($publicPrefix !== '' ? $publicPrefix . '/' : '') . $filename;

    return [$path, $originalName, $mime, date('Y-m-d H:i:s')];
}

function aidfleet_delete_public_file(?string $relativePath): void
{
    $relativePath = trim(str_replace('\\', '/', (string)$relativePath), '/');
    if ($relativePath === '' || str_contains($relativePath, '..')) {
        return;
    }

    $publicRoot = aidfleet_public_upload_root();
    $path = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $real = realpath($path);
    $publicRootPrefix = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($real && str_starts_with($real, $publicRootPrefix) && is_file($real)) {
        @unlink($real);
    }
}
