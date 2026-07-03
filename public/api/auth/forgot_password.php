<?php

require_once __DIR__ . '/../bootstrap.php';


aidfleet_require_method('POST');

// Ensure required tables exist (auto-migration)
(function () {
    $db = aidfleet_db();
    $db->query("
        CREATE TABLE IF NOT EXISTS password_reset_otps (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            email      VARCHAR(190) NOT NULL,
            otp_code   VARCHAR(10) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used       TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY unique_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->query("
        CREATE TABLE IF NOT EXISTS password_reset_attempts (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            email         VARCHAR(190) NOT NULL,
            ip_address    VARCHAR(45) NOT NULL,
            attempts      INT NOT NULL DEFAULT 0,
            lockout_until DATETIME NULL,
            last_attempt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_email_ip (email, ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
})();

$in   = aidfleet_get_json_body();
$step = isset($in['step']) ? (string)$in['step'] : '';

function _forgot_password_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function _forgot_password_check_rate_limit(mysqli $db, string $email): void
{
    $ip = _forgot_password_client_ip();
    $row = aidfleet_db_one(
        $db,
        'SELECT attempts, lockout_until FROM password_reset_attempts WHERE email = ? AND ip_address = ?',
        'ss',
        [$email, $ip]
    );

    if ($row && !empty($row['lockout_until'])) {
        $lockoutTime = strtotime((string)$row['lockout_until']);
        if ($lockoutTime > time()) {
            aidfleet_send_json([
                'success' => false,
                'message' => 'Too many reset attempts. Please try again in 3 minutes.',
            ], 429);
        }
        $stmt = $db->prepare('UPDATE password_reset_attempts SET attempts = 0, lockout_until = NULL WHERE email = ? AND ip_address = ?');
        if ($stmt) {
            $stmt->bind_param('ss', $email, $ip);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function _forgot_password_record_failure(mysqli $db, string $email): void
{
    $ip = _forgot_password_client_ip();
    $stmt = $db->prepare('INSERT INTO password_reset_attempts (email, ip_address, attempts) VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE attempts = attempts + 1');
    if ($stmt) {
        $stmt->bind_param('ss', $email, $ip);
        $stmt->execute();
        $stmt->close();
    }

    $row = aidfleet_db_one(
        $db,
        'SELECT attempts FROM password_reset_attempts WHERE email = ? AND ip_address = ?',
        'ss',
        [$email, $ip]
    );
    $attempts = (int)($row['attempts'] ?? 0);
    if ($attempts >= 5) {
        $lockoutUntil = date('Y-m-d H:i:s', time() + 180);
        $stmt = $db->prepare('UPDATE password_reset_attempts SET lockout_until = ? WHERE email = ? AND ip_address = ?');
        if ($stmt) {
            $stmt->bind_param('sss', $lockoutUntil, $email, $ip);
            $stmt->execute();
            $stmt->close();
        }
        aidfleet_send_json([
            'success' => false,
            'message' => 'Too many reset attempts. Please try again in 3 minutes.',
        ], 429);
    }
}

function _forgot_password_clear_attempts(mysqli $db, string $email): void
{
    $ip = _forgot_password_client_ip();
    $stmt = $db->prepare('DELETE FROM password_reset_attempts WHERE email = ? AND ip_address = ?');
    if ($stmt) {
        $stmt->bind_param('ss', $email, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

function _is_admin_email(mysqli $db, string $email): bool
{
    return (bool) aidfleet_db_one($db, 'SELECT admin_id FROM administrators WHERE email = ?', 's', [$email]);
}


 // Find requester or driver account by email
 
function _find_account_by_email(mysqli $db, string $email): ?array
{
    if (_is_admin_email($db, $email)) {
        return null;
    }

    $tables = [
        ['table' => 'users', 'id_col' => 'user_id', 'role' => 'requester'],
        ['table' => 'drivers', 'id_col' => 'driver_id', 'role' => 'driver'],
    ];

    foreach ($tables as $t) {
        $row = aidfleet_db_one($db, "SELECT {$t['id_col']}, full_name FROM {$t['table']} WHERE email = ?", 's', [$email]);
        if ($row) {
            return array_merge($t, ['row' => $row]);
        }
    }
    return null;
}

// Step 1: send OTP
if ($step === 'send_otp' || $step === 'check_email') {
    $email = isset($in['email']) ? trim((string)$in['email']) : '';

    if ($email === '') {
        aidfleet_send_json(['success' => false, 'message' => 'Email is required'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        aidfleet_send_json(['success' => false, 'message' => 'Please enter a valid email address'], 400);
    }

    $db = aidfleet_db();
    _forgot_password_check_rate_limit($db, $email);

    if (_is_admin_email($db, $email)) {
        aidfleet_send_json([
            'success' => false,
            'message' => 'Password reset is not available for this account.',
        ], 403);
    }

    $info = _find_account_by_email($db, $email);

    if (!$info) {
        aidfleet_send_json([
            'success' => true,
            'message' => 'If an account exists for this email, a verification code has been sent.',
            'masked_email' => aidfleet_mask_email($email),
        ]);
    }

    $otp = aidfleet_generate_and_store_otp($email);
    $userName  = $info['row']['full_name'] ?? 'User';
    $emailSent = aidfleet_send_otp_email($email, $userName, $otp, 'Password Reset');

    $actorRole = $info['role'];
    $actorId   = (int)($info['row'][$info['id_col']] ?? 0);
    aidfleet_log($actorRole, $actorId, 'PASSWORD_RESET_OTP_SENT', $info['table'], $actorId, 'OTP sent to ' . $email);

    if (!$emailSent) {
        aidfleet_send_json(['success' => false, 'message' => 'Could not send verification email. Please try again later.'], 500);
    }

    aidfleet_send_json([
        'success' => true,
        'message' => 'Verification code sent to your email.',
        'masked_email' => aidfleet_mask_email($email),
        'role' => $info['role'],
    ]);

// Step 2: verify OTP
} elseif ($step === 'verify_otp') {
    $email = isset($in['email']) ? trim((string)$in['email']) : '';
    $otp   = isset($in['otp'])   ? trim((string)$in['otp'])   : '';

    if ($email === '' || $otp === '') {
        aidfleet_send_json(['success' => false, 'message' => 'Email and OTP are required'], 400);
    }

    $db = aidfleet_db();
    _forgot_password_check_rate_limit($db, $email);

    if (_is_admin_email($db, $email)) {
        aidfleet_send_json(['success' => false, 'message' => 'Password reset is not available for this account.'], 403);
    }

    if (!_find_account_by_email($db, $email)) {
        _forgot_password_record_failure($db, $email);
        aidfleet_send_json(['success' => false, 'message' => 'Invalid verification code'], 400);
    }

    $otpResult = aidfleet_verify_otp_secure($email, $otp);
    if (!$otpResult['valid']) {
        if (empty($otpResult['locked'])) {
            _forgot_password_record_failure($db, $email);
        }
        aidfleet_send_json([
            'success' => false,
            'message' => $otpResult['message'],
            'locked' => !empty($otpResult['locked']),
            'locked_until' => $otpResult['locked_until'] ?? null,
        ], !empty($otpResult['locked']) ? 429 : 400);
    }

    _forgot_password_clear_attempts($db, $email);
    aidfleet_send_json(['success' => true, 'message' => 'Code verified successfully']);

// Step 3: reset password
} elseif ($step === 'reset_password') {
    $email = isset($in['email'])        ? trim((string)$in['email']) : '';
    $newPw = isset($in['new_password']) ? (string)$in['new_password'] : '';
    $otp   = isset($in['otp'])          ? trim((string)$in['otp'])   : '';
    if ($email === '' || $newPw === '') {
        aidfleet_send_json(['success' => false, 'message' => 'Email and new password are required'], 400);
    }
    if ($otp === '') {
        aidfleet_send_json(['success' => false, 'message' => 'Verification code is required'], 400);
    }
    if (strlen($newPw) < 6) {
        aidfleet_send_json(['success' => false, 'message' => 'Password must be at least 6 characters'], 400);
    }

    $db = aidfleet_db();
    _forgot_password_check_rate_limit($db, $email);

    if (_is_admin_email($db, $email)) {
        aidfleet_send_json(['success' => false, 'message' => 'Password reset is not available for this account.'], 403);
    }

    $info = _find_account_by_email($db, $email);
    if (!$info) {
        aidfleet_send_json(['success' => false, 'message' => 'Account not found'], 404);
    }

    $otpResult = aidfleet_verify_otp_secure($email, $otp);
    if (!$otpResult['valid']) {
        if (empty($otpResult['locked'])) {
            _forgot_password_record_failure($db, $email);
        }
        aidfleet_send_json([
            'success' => false,
            'message' => $otpResult['message'],
            'locked' => !empty($otpResult['locked']),
        ], !empty($otpResult['locked']) ? 429 : 400);
    }

    $hash = password_hash($newPw, PASSWORD_DEFAULT);
    $idCol = $info['id_col'];
    $uid   = (int)$info['row'][$idCol];
    $tbl   = $info['table'];

    $stmt = $db->prepare("UPDATE {$tbl} SET password_hash = ? WHERE {$idCol} = ?");
    if (!$stmt) {
        aidfleet_send_json(['success' => false, 'message' => 'Server error'], 500);
    }
    $stmt->bind_param('si', $hash, $uid);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        aidfleet_send_json(['success' => false, 'message' => 'Could not update password'], 500);
    }

    aidfleet_mark_otp_used($email);
    _forgot_password_clear_attempts($db, $email);

    $action = strtoupper($info['role']) . '_PASSWORD_RESET';
    aidfleet_log($info['role'], $uid, $action, $tbl, $uid, 'Password reset via forgot-password flow');

    aidfleet_send_json(['success' => true, 'message' => 'Password reset successfully']);

} else {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid step parameter. Use: send_otp, verify_otp, or reset_password'], 400);
}
