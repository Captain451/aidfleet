<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;

aidfleet_require_fields($in, ['email', 'password']);
$email = trim((string) $in['email']);
$password = (string) $in['password'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid email'], 400);
}

$db = aidfleet_db();

// Check rate limits
$attempt_row = aidfleet_db_one($db, 'SELECT attempts, lockout_until FROM admin_login_attempts WHERE email = ?', 's', [$email]);
if ($attempt_row && $attempt_row['lockout_until']) {
    $lockout_time = strtotime((string) $attempt_row['lockout_until']);
    if (time() < $lockout_time) {
        $remaining = ceil(($lockout_time - time()) / 60);
        aidfleet_send_json([
            'success' => false,
            'message' => 'Too many failed attempts. You are locked out for 3 minutes.',
            'locked' => true,
            'locked_until' => $lockout_time
        ], 429);
    } else {
        $stmt = $db->prepare('UPDATE admin_login_attempts SET attempts = 0, lockout_until = NULL WHERE email = ?');
        if ($stmt) {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// Verify admin credentials
$admin = aidfleet_db_one($db, 'SELECT admin_id, full_name, email, password_hash FROM administrators WHERE email = ?', 's', [$email]);

if ($admin && password_verify($password, $admin['password_hash'])) {
    $stmt = $db->prepare('DELETE FROM admin_login_attempts WHERE email = ?');
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();
    }

    aidfleet_login('admin', (int) $admin['admin_id'], $admin['full_name'], $admin['email']);
    aidfleet_log('admin', (int) $admin['admin_id'], 'ADMIN_LOGIN', 'administrators', (int) $admin['admin_id'], 'Admin logged in via Admin Portal');
    aidfleet_send_json(['success' => true, 'user' => ['role' => 'admin', 'id' => (int) $admin['admin_id'], 'name' => $admin['full_name'], 'email' => $admin['email']]]);
}

// Login failed: add attempts
$stmt = $db->prepare('INSERT INTO admin_login_attempts (email, attempts) VALUES (?, 1) ON DUPLICATE KEY UPDATE attempts = attempts + 1');
if ($stmt) {
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->close();
}
$current_attempts = aidfleet_db_one($db, "SELECT attempts FROM admin_login_attempts WHERE email = ?", 's', [$email])['attempts'];

if ($current_attempts >= 5) {
    $lockoutTs = time() + 180;
    $lockout_until = date('Y-m-d H:i:s', $lockoutTs);
    $stmt = $db->prepare('UPDATE admin_login_attempts SET lockout_until = ? WHERE email = ?');
    if ($stmt) {
        $stmt->bind_param('ss', $lockout_until, $email);
        $stmt->execute();
        $stmt->close();
    }
    aidfleet_send_json([
        'success' => false,
        'message' => 'Too many failed attempts. You are locked out for 3 minutes.',
        'locked' => true,
        'locked_until' => $lockoutTs
    ], 429);
}

aidfleet_send_json(['success' => false, 'message' => 'Invalid credentials. Attempt ' . $current_attempts . ' of 5.'], 401);
