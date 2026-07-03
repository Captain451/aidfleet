<?php
/**
 * Shared email helpers for AidFleet.
 * Provides OTP email sending via PHPMailer.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an OTP verification email via PHPMailer.
 *
 * @param string $email   Recipient email
 * @param string $name    Recipient display name
 * @param string $otp     6-digit OTP code
 * @param string $purpose Purpose description (e.g. 'Password Reset', 'Password Change Verification')
 * @return bool True if email was sent successfully
 */
function aidfleet_send_otp_email(string $email, string $name, string $otp, string $purpose = 'Password Reset'): bool
{
    $cfg  = aidfleet_config();
    $smtp = $cfg['smtp'] ?? [];
    if (empty($smtp['enabled'])) return false;

    // PHPMailer is loaded via Composer autoloader in bootstrap.php
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtp['host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = (bool)($smtp['auth'] ?? true);
        $mail->Username   = $smtp['username'] ?? '';
        $mail->Password   = $smtp['password'] ?? '';
        $mail->SMTPSecure = $smtp['secure'] ?? 'tls';
        $mail->Port       = (int)($smtp['port'] ?? 587);

        $mail->setFrom($smtp['from_email'] ?? $smtp['username'], $smtp['from_name'] ?? 'AidFleet');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'AidFleet - ' . $purpose . ' Code';
        $mail->Body    = aidfleet_otp_email_body($name, $otp, $purpose);
        $mail->AltBody = "Hi $name,\n\nYour AidFleet verification code is: $otp\n\nThis code expires in 10 minutes.\n\nIf you did not request this, please ignore this email.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("[AidFleet PHPMailer] Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate the HTML body for an OTP verification email.
 */
function aidfleet_otp_email_body(string $name, string $otp, string $purpose = 'Password Reset'): string
{
    return '
<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:\'Segoe UI\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:40px 0;">
  <tr><td align="center">
    <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
      <!-- Header -->
      <tr>
        <td style="background:linear-gradient(135deg,#1e293b,#0f172a);padding:32px 40px;text-align:center;">
          <table cellpadding="0" cellspacing="0" style="margin:0 auto;"><tr>
            <td style="vertical-align:middle;padding-right:12px;">
              <div style="width:44px;height:44px;border-radius:12px;background:#dc2626;display:inline-block;text-align:center;line-height:44px;">
                <span style="color:#ffffff;font-size:22px;font-weight:900;">✚</span>
              </div>
            </td>
            <td style="vertical-align:middle;"><span style="font-size:24px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">AidFleet</span></td>
          </tr></table>
          <p style="margin:8px 0 0;font-size:13px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:2px;">
            Emergency Response
          </p>
        </td>
      </tr>
      <!-- Body -->
      <tr>
        <td style="padding:40px;">
          <h2 style="margin:0 0 8px;font-size:20px;font-weight:700;color:#1e293b;">' . htmlspecialchars($purpose) . ' Request</h2>
          <p style="margin:0 0 24px;font-size:15px;color:#64748b;line-height:1.6;">
            Hi <strong style="color:#1e293b;">' . htmlspecialchars($name) . '</strong>, we received a request to verify your identity. Use the verification code below:
          </p>
          <table cellpadding="0" cellspacing="0" style="margin:0 auto 24px;" align="center">
            <tr>
              <td style="text-align:center;">
                <table cellpadding="0" cellspacing="0" style="margin:0 auto;background:#f1f5f9;border:1px dashed #cbd5e1;border-radius:10px;padding:0;" align="center">
                  <tr>
                    <td style="padding:16px 32px;text-align:center;">
                      <span style="font-size:13px;color:#94a3b8;display:block;margin-bottom:6px;">Your verification code</span>
                      <span style="font-size:32px;font-weight:800;color:#1e293b;letter-spacing:8px;font-family:\'Courier New\',monospace;">' . $otp . '</span>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
          <p style="text-align:center;font-size:13px;color:#94a3b8;margin:0 0 24px;">
            This code expires in <strong style="color:#64748b;">10 minutes</strong>.
          </p>
          <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
          <p style="font-size:13px;color:#94a3b8;line-height:1.6;">
            If you didn\'t request this, you can safely ignore this email. Your account remains secure.
          </p>
        </td>
      </tr>
      <!-- Footer -->
      <tr>
        <td style="background:#f8fafc;padding:20px 40px;text-align:center;border-top:1px solid #e2e8f0;">
          <p style="margin:0;font-size:12px;color:#94a3b8;">
            &copy; ' . date('Y') . ' AidFleet Emergency Response &bull; Smart Fleet Management
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>';
}

/**
 * Send an account status notification email (Approved/Rejected)
 */
function aidfleet_send_status_email(string $email, string $name, string $status, string $reason = '', string $context = ''): bool
{
    $cfg  = aidfleet_config();
    $smtp = $cfg['smtp'] ?? [];
    if (empty($smtp['enabled'])) return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtp['host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = (bool)($smtp['auth'] ?? true);
        $mail->Username   = $smtp['username'] ?? '';
        $mail->Password   = $smtp['password'] ?? '';
        $mail->SMTPSecure = $smtp['secure'] ?? 'tls';
        $mail->Port       = (int)($smtp['port'] ?? 587);

        $mail->setFrom($smtp['from_email'] ?? $smtp['username'], $smtp['from_name'] ?? 'AidFleet');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        
        $isReactivated = ($context === 'reactivated');
        $isRevoked = ($context === 'temporarily_disabled');
        $subject = $status === 'approved' 
            ? ($isReactivated ? 'AidFleet - Your account has been re-activated!' : 'AidFleet - Your account has been approved!') 
            : ($isRevoked ? 'AidFleet - Your Account has been Temporarily Disabled' : 'AidFleet - Account Application Update');
        $mail->Subject = $subject;
        
        $mail->Body    = aidfleet_status_email_body($name, $status, $reason, $context);
        $mail->AltBody = "Hi $name,\n\nYour AidFleet account " . ($isRevoked ? "has been temporarily disabled." : ($isReactivated ? "has been re-activated." : "application has been " . $status . ".")) . "\n" . ($reason ? "Reason: $reason\n" : "") . "\nThanks,\nThe AidFleet Team";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("[AidFleet PHPMailer] Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate the HTML body for a status update email.
 */
function aidfleet_status_email_body(string $name, string $status, string $reason = '', string $context = ''): string
{
    $isApproved = ($status === 'approved');
    $isReactivated = ($context === 'reactivated');
    $isRevoked = ($context === 'temporarily_disabled');
    $statusColor = $isApproved ? '#22c55e' : '#ef4444';
    $statusIcon = $isApproved ? '&#10003;' : '&#10005;'; 
    
    $title = $isApproved ? ($isReactivated ? 'Account Reactivated' : 'Account Approved') : ($isRevoked ? 'Account Temporarily Disabled' : 'Application Update');
    
    if ($isApproved) {
        if ($isReactivated) {
            $message = 'Great news! Your AidFleet driver account has been reviewed and <strong>re-activated</strong>. You can now log in to your dashboard and start receiving emergency dispatch requests again.';
        } else {
            $message = 'Great news! Your AidFleet driver application has been reviewed and <strong>approved</strong>. You can now log in to your dashboard and start receiving emergency dispatch requests.';
        }
    } else if ($isRevoked) {
        $message = 'Your AidFleet driver account has been <strong>temporarily disabled</strong> by the administrator. You will temporarily not be able to receive emergency dispatch requests or go online.';
    } else {
        $message = 'Your AidFleet driver application has been reviewed and unfortunately, it has been <strong>rejected</strong> at this time.';
    }
        
    $reasonBlock = '';
    if (!$isApproved && $reason) {
        $reasonBlock = '
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:16px;margin:24px 0;">
            <p style="margin:0;font-size:14px;color:#991b1b;">
                <strong>Reason:</strong> ' . htmlspecialchars($reason) . '
            </p>
        </div>';
    }

    $actionBtn = $isApproved 
        ? '<div style="text-align:center;margin:32px 0;">
             <a href="' . (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] : '') . '/auth/login.html" style="display:inline-block;background:#dc2626;color:#ffffff;text-decoration:none;font-weight:600;padding:14px 32px;border-radius:8px;">Log In to Dashboard</a>
           </div>'
        : '';

    return '
<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:\'Segoe UI\',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:40px 0;">
  <tr><td align="center">
    <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
      <!-- Header -->
      <tr>
        <td style="background:linear-gradient(135deg,#1e293b,#0f172a);padding:32px 40px;text-align:center;">
          <table cellpadding="0" cellspacing="0" style="margin:0 auto;"><tr>
            <td style="vertical-align:middle;padding-right:12px;">
              <div style="width:44px;height:44px;border-radius:12px;background:#dc2626;display:inline-block;text-align:center;line-height:44px;">
                <span style="color:#ffffff;font-size:22px;font-weight:900;">✚</span>
              </div>
            </td>
            <td style="vertical-align:middle;"><span style="font-size:24px;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">AidFleet</span></td>
          </tr></table>
          <p style="margin:8px 0 0;font-size:13px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:2px;">
            Emergency Response
          </p>
        </td>
      </tr>
      <!-- Body -->
      <tr>
        <td style="padding:40px;">
          <div style="text-align:center;margin-bottom:24px;">
              <div style="display:inline-block;width:64px;height:64px;border-radius:50%;background:' . $statusColor . '20;color:' . $statusColor . ';font-size:32px;line-height:64px;">
                  ' . $statusIcon . '
              </div>
          </div>
          <h2 style="margin:0 0 8px;font-size:20px;font-weight:700;color:#1e293b;text-align:center;">' . $title . '</h2>
          <p style="margin:0 0 16px;font-size:15px;color:#64748b;line-height:1.6;">
            Hi <strong style="color:#1e293b;">' . htmlspecialchars($name) . '</strong>,
          </p>
          <p style="margin:0 0 16px;font-size:15px;color:#64748b;line-height:1.6;">
            ' . $message . '
          </p>
          ' . $reasonBlock . '
          ' . $actionBtn . '
        </td>
      </tr>
      <!-- Footer -->
      <tr>
        <td style="background:#f8fafc;padding:20px 40px;text-align:center;border-top:1px solid #e2e8f0;">
          <p style="margin:0;font-size:12px;color:#94a3b8;">
            &copy; ' . date('Y') . ' AidFleet Emergency Response &bull; Smart Fleet Management
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>';
}

/**
 * Ensure email OTP table supports lockout tracking.
 */
function aidfleet_ensure_email_otp_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $db = aidfleet_db();
    $cols = $db->query("SHOW COLUMNS FROM password_reset_otps LIKE 'failed_attempts'");
    if ($cols && $cols->num_rows === 0) {
        $db->query("ALTER TABLE password_reset_otps ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0");
    }
    $lockedCols = $db->query("SHOW COLUMNS FROM password_reset_otps LIKE 'locked_until'");
    if ($lockedCols && $lockedCols->num_rows === 0) {
        $db->query("ALTER TABLE password_reset_otps ADD COLUMN locked_until DATETIME NULL");
    }
    $done = true;
}

/**
 * Generate a 6-digit OTP code and store it in the database.
 */
function aidfleet_generate_and_store_otp(string $email): string
{
    aidfleet_ensure_email_otp_schema();
    $db  = aidfleet_db();
    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $now = date('Y-m-d H:i:s');
    $exp = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $stmt = $db->prepare("
        INSERT INTO password_reset_otps (email, otp_code, created_at, expires_at, used, failed_attempts, locked_until)
        VALUES (?, ?, ?, ?, 0, 0, NULL)
        ON DUPLICATE KEY UPDATE otp_code = VALUES(otp_code),
                                 created_at = VALUES(created_at),
                                 expires_at = VALUES(expires_at),
                                 used = 0,
                                 failed_attempts = 0,
                                 locked_until = NULL
    ");
    if ($stmt) {
        $stmt->bind_param('ssss', $email, $otp, $now, $exp);
        $stmt->execute();
        $stmt->close();
    }

    return $otp;
}

/**
 * Mask an email for display (e.g. cl*********@gmail.com).
 */
function aidfleet_mask_email(string $email): string
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return $email;
    }
    $local = $parts[0];
    $visible = substr($local, 0, min(2, strlen($local)));
    return $visible . str_repeat('*', max(3, strlen($local) - strlen($visible))) . '@' . $parts[1];
}

/**
 * Verify an email OTP with attempt tracking and lockout.
 *
 * @param bool $resendOnlyLockout Settings flows: no timed lockout — request a new code after limit.
 * @return array{valid:bool,message:string,locked?:bool,locked_until?:int}
 */
function aidfleet_verify_otp_secure(string $email, string $otp, bool $resendOnlyLockout = false): array
{
    aidfleet_ensure_email_otp_schema();
    $db  = aidfleet_db();
    $row = aidfleet_db_one($db, "SELECT * FROM password_reset_otps WHERE email = ?", 's', [$email]);

    if (!$row) {
        return ['valid' => false, 'message' => 'Please request a new verification code.', 'locked' => true];
    }

    if (!$resendOnlyLockout && !empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time()) {
        $seconds = max(1, (int)(strtotime((string)$row['locked_until']) - time()));
        return [
            'valid' => false,
            'message' => "Too many failed attempts. Please try again after {$seconds} seconds or request a new code.",
            'locked' => true,
            'locked_until' => strtotime((string)$row['locked_until']),
        ];
    }

    if ((int)$row['used'] === 1 || (int)($row['failed_attempts'] ?? 0) >= 5) {
        $msg = $resendOnlyLockout
            ? 'Too many failed attempts. Please request a new code.'
            : 'Please request a new verification code.';
        return ['valid' => false, 'message' => $msg, 'locked' => !$resendOnlyLockout];
    }

    if (strtotime((string)$row['expires_at']) < time()) {
        return ['valid' => false, 'message' => 'Verification code has expired. Please request a new one.', 'locked' => false];
    }

    if ((string)$row['otp_code'] !== $otp) {
        $attempts = (int)($row['failed_attempts'] ?? 0) + 1;
        if ($attempts >= 5) {
            if ($resendOnlyLockout) {
                $stmt = $db->prepare("UPDATE password_reset_otps SET failed_attempts = ?, used = 1, locked_until = NULL WHERE email = ?");
                if ($stmt) {
                    $stmt->bind_param('is', $attempts, $email);
                    $stmt->execute();
                    $stmt->close();
                }
                return [
                    'valid' => false,
                    'message' => 'Too many failed attempts. Please request a new code.',
                    'locked' => false,
                ];
            }
            $lockedUntil = date('Y-m-d H:i:s', time() + 60);
            $stmt = $db->prepare("UPDATE password_reset_otps SET failed_attempts = ?, used = 1, locked_until = ? WHERE email = ?");
            if ($stmt) {
                $stmt->bind_param('iss', $attempts, $lockedUntil, $email);
                $stmt->execute();
                $stmt->close();
            }
            return [
                'valid' => false,
                'message' => 'Too many failed attempts. Please try again after 60 seconds or request a new code.',
                'locked' => true,
                'locked_until' => time() + 60,
            ];
        }

        $stmt = $db->prepare("UPDATE password_reset_otps SET failed_attempts = ? WHERE email = ?");
        if ($stmt) {
            $stmt->bind_param('is', $attempts, $email);
            $stmt->execute();
            $stmt->close();
        }
        $remaining = 5 - $attempts;
        return ['valid' => false, 'message' => "Invalid verification code. {$remaining} attempt(s) remaining.", 'locked' => false];
    }

    $stmt = $db->prepare("UPDATE password_reset_otps SET failed_attempts = 0, locked_until = NULL WHERE email = ?");
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();
    }

    return ['valid' => true, 'message' => 'OK', 'locked' => false];
}

/**
 * Verify an OTP code from the database.
 */
function aidfleet_verify_otp(string $email, string $otp): bool
{
    return aidfleet_verify_otp_secure($email, $otp)['valid'];
}

/**
 * Mark an OTP as used.
 */
function aidfleet_mark_otp_used(string $email): void
{
    $db = aidfleet_db();
    $stmt = $db->prepare('UPDATE password_reset_otps SET used = 1 WHERE email = ?');
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();
    }
}
