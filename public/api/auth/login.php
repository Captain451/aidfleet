<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');

$db = aidfleet_db();
$db->query("
    CREATE TABLE IF NOT EXISTS login_attempts (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        email         VARCHAR(190) NOT NULL,
        ip_address    VARCHAR(45) NOT NULL,
        attempts      INT NOT NULL DEFAULT 0,
        lockout_until DATETIME NULL,
        last_attempt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_email_ip (email, ip_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;

aidfleet_require_fields($in, ['email', 'password']);
$email = trim((string)$in['email']);
$password = (string)$in['password'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid email'], 400);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$attemptRow = aidfleet_db_one(
    $db,
    'SELECT attempts, lockout_until FROM login_attempts WHERE email = ? AND ip_address = ?',
    'ss',
    [$email, $ip]
);
if ($attemptRow && !empty($attemptRow['lockout_until'])) {
    $lockoutTime = strtotime((string)$attemptRow['lockout_until']);
    if ($lockoutTime > time()) {
        $remainingSec = max(0, $lockoutTime - time());
        aidfleet_send_json([
            'success' => false,
            'message' => 'Too many failed login attempts. Please try again after ' . $remainingSec . ' second(s).',
            'locked' => true,
            'locked_until' => $lockoutTime,
            'attempts_remaining' => 0,
        ], 429);
    }
    $stmt = $db->prepare('UPDATE login_attempts SET attempts = 0, lockout_until = NULL WHERE email = ? AND ip_address = ?');
    if ($stmt) {
        $stmt->bind_param('ss', $email, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

// Admin accounts can only log in through the admin portal
$adminEmail = aidfleet_db_one($db, 'SELECT admin_id FROM administrators WHERE email = ?', 's', [$email]);
if ($adminEmail) {
    $failInfo = aidfleet_record_login_failure($db, $email, $ip);
    if (!empty($failInfo['locked'])) {
        aidfleet_send_json([
            'success' => false,
            'message' => 'Too many failed login attempts. Please try again after ' . (int)$failInfo['lockout_seconds'] . ' second(s).',
            'locked' => true,
            'locked_until' => (int)$failInfo['locked_until'],
            'attempts_remaining' => 0,
        ], 429);
    }
    aidfleet_send_json([
        'success' => false,
        'message' => 'Invalid credentials',
        'attempts_remaining' => (int)$failInfo['attempts_remaining'],
    ], 401);
}

$driver = aidfleet_db_one($db, 'SELECT driver_id, full_name, email, phone, password_hash, phone_verified, verification_status, availability_status FROM drivers WHERE email = ?', 's', [$email]);
if ($driver && password_verify($password, $driver['password_hash'])) {
    aidfleet_clear_login_attempts($db, $email, $ip);
    if (empty($driver['phone_verified'])) {
        $phone = aidfleet_normalize_phone($driver['phone']);
        aidfleet_send_json([
            'success'          => false,
            'phone_unverified' => true,
            'phone'            => $phone,
            'role'             => 'driver',
            'message'          => 'Your phone number is not verified. Please register again to receive a verification code.',
        ], 403);
    }
    aidfleet_login('driver', (int)$driver['driver_id'], $driver['full_name'], $driver['email']);
    aidfleet_log('driver', (int)$driver['driver_id'], 'DRIVER_LOGIN', 'drivers', (int)$driver['driver_id'], 'Driver logged in');
    aidfleet_send_json([
        'success' => true,
        'user' => [
            'role' => 'driver',
            'id' => (int)$driver['driver_id'],
            'name' => $driver['full_name'],
            'email' => $driver['email'],
            'verification_status' => $driver['verification_status'],
            'availability_status' => $driver['availability_status'],
        ],
    ]);
}

$user = aidfleet_db_one($db, 'SELECT user_id, full_name, email, phone, password_hash, phone_verified, account_status FROM users WHERE email = ?', 's', [$email]);
if ($user && password_verify($password, $user['password_hash'])) {
    aidfleet_clear_login_attempts($db, $email, $ip);
    if (($user['account_status'] ?? '') === 'disabled') {
        aidfleet_send_json(['success' => false, 'message' => 'Your account has been temporarily disabled. Please contact support.'], 403);
    }
    if (empty($user['phone_verified'])) {
        $phone = aidfleet_normalize_phone($user['phone']);
        aidfleet_send_json([
            'success'          => false,
            'phone_unverified' => true,
            'phone'            => $phone,
            'role'             => 'requester',
            'message'          => 'Your phone number is not verified. Please register again to receive a verification code.',
        ], 403);
    }
    aidfleet_login('requester', (int)$user['user_id'], $user['full_name'], $user['email']);
    aidfleet_log('requester', (int)$user['user_id'], 'USER_LOGIN', 'users', (int)$user['user_id'], 'Requester logged in');
    aidfleet_send_json(['success' => true, 'user' => ['role' => 'requester', 'id' => (int)$user['user_id'], 'name' => $user['full_name'], 'email' => $user['email'], 'phone' => $user['phone']]]);
}

$failInfo = aidfleet_record_login_failure($db, $email, $ip);
if (!empty($failInfo['locked'])) {
    aidfleet_send_json([
        'success' => false,
        'message' => 'Too many failed login attempts. Please wait ' . (int)$failInfo['lockout_seconds'] . ' second(s).',
        'locked' => true,
        'locked_until' => (int)$failInfo['locked_until'],
        'attempts_remaining' => 0,
    ], 429);
}
aidfleet_send_json([
    'success' => false,
    'message' => 'Invalid credentials',
    'attempts_remaining' => (int)$failInfo['attempts_remaining'],
], 401);

function aidfleet_record_login_failure(mysqli $db, string $email, string $ip): array
{
    $stmt = $db->prepare('INSERT INTO login_attempts (email, ip_address, attempts) VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE attempts = attempts + 1');
    if ($stmt) {
        $stmt->bind_param('ss', $email, $ip);
        $stmt->execute();
        $stmt->close();
    }
    $row = aidfleet_db_one($db, 'SELECT attempts FROM login_attempts WHERE email = ? AND ip_address = ?', 'ss', [$email, $ip]);
    $attempts = (int)($row['attempts'] ?? 0);
    if ($attempts >= 5) {
        $lockoutUntilTs = time() + 60;
        $lockoutUntil = date('Y-m-d H:i:s', $lockoutUntilTs);
        $stmt = $db->prepare('UPDATE login_attempts SET lockout_until = ? WHERE email = ? AND ip_address = ?');
        if ($stmt) {
            $stmt->bind_param('sss', $lockoutUntil, $email, $ip);
            $stmt->execute();
            $stmt->close();
        }
        return [
            'locked' => true,
            'locked_until' => $lockoutUntilTs,
            'lockout_seconds' => 60,
            'attempts_remaining' => 0,
        ];
    }
    return [
        'locked' => false,
        'attempts_remaining' => max(0, 5 - $attempts),
    ];
}

function aidfleet_clear_login_attempts(mysqli $db, string $email, string $ip): void
{
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?');
    if ($stmt) {
        $stmt->bind_param('ss', $email, $ip);
        $stmt->execute();
        $stmt->close();
    }
}
