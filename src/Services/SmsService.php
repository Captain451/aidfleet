<?php

use AfricasTalking\SDK\AfricasTalking;

/**
 * Normalize a phone number to E.164 format (+254XXXXXXXXX).
 */
function aidfleet_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);

    if (str_starts_with($digits, '254')) {
        $digits = substr($digits, 3);
    }
    if (str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
        return '+254' . $digits;
    }

    if (str_starts_with($phone, '+254') && preg_match('/^\+2547\d{8}$/', $phone)) {
        return $phone;
    }

    return '+254' . $digits;
}

/**
 * Map Africa's Talking gateway errors to a user-safe message.
 *
 * AT status codes: 402=InvalidSenderId, 403=InvalidPhoneNumber, 405=InsufficientBalance, 406=UserInBlacklist.
 * On Kenyan networks, 406 often means no approved shortcode was used — not that the phone is blocked.
 */
function aidfleet_sms_user_message(string $gatewaySummary): string
{
    $summary = trim($gatewaySummary);
    $lower = strtolower($summary);
    $senderHint = trim((string) env('SMS_SENDER_ID', ''));

    if ($summary === 'SMS gateway disabled') {
        return 'SMS verification is currently disabled.';
    }
    if (str_contains($lower, 'not configured') || str_contains($lower, 'missing api key')) {
        return 'SMS service is not configured. Please contact support.';
    }
    if (str_contains($lower, 'invalidsenderid') || str_contains($summary, '(402)')) {
        if ($senderHint !== '') {
            return 'SMS sender ID (' . $senderHint . ') is not active on your Africa\'s Talking app. Leave SMS_SENDER_ID empty unless you have an approved sender ID, shortcode, or alphanumeric ID.';
        }
        return 'Africa\'s Talking rejected the SMS sender. Confirm your live app username and API key match, your SMS wallet is funded, and SMS_SENDER_ID is empty unless you have an approved sender.';
    }
    if (str_contains($lower, 'insufficientbalance') || str_contains($summary, '(405)')) {
        return 'SMS wallet balance is low. Please top up your Africa\'s Talking account.';
    }
    if (str_contains($lower, 'invalidphonenumber') || str_contains($summary, '(403)')) {
        return 'Invalid phone number. Use a valid Kenyan mobile number (e.g. 0712 345 678).';
    }
    if (str_contains($lower, 'userinblacklist') || str_contains($summary, '(406)')) {
        return 'SMS could not be delivered to this number. Please enable promotional messages, then request a new code.';
    }
    if (str_contains($lower, 'ssl verification failed')) {
        return 'SMS connection error. Please try again shortly.';
    }

    if ((bool) env('APP_DEBUG', false) && $summary !== '') {
        return 'SMS failed: ' . $summary;
    }

    return 'Unable to send verification code. Please try again shortly.';
}

/** Max OTP SMS sends allowed per phone within the rate-limit window. */
const AIDFLEET_SMS_OTP_MAX_SENDS = 5;

/** Rolling window (seconds) for OTP send rate limiting. */
const AIDFLEET_SMS_OTP_WINDOW_SECONDS = 180;

/**
 * Check if the phone number has exceeded the SMS send limit.
 */
function aidfleet_check_sms_rate_limit(string $phone): bool
{
    return aidfleet_sms_rate_limit_status($phone)['allowed'];
}

/**
 * @return array{allowed:bool,remaining?:int,retry_after_seconds?:int,retry_after_minutes?:int}
 */
function aidfleet_sms_rate_limit_status(string $phone): array
{
    $db = aidfleet_db();
    $windowSeconds = AIDFLEET_SMS_OTP_WINDOW_SECONDS;
    $maxSends = AIDFLEET_SMS_OTP_MAX_SENDS;

    $countRow = aidfleet_db_one(
        $db,
        'SELECT COUNT(*) AS cnt FROM sms_rate_limits
         WHERE phone = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)',
        'si',
        [$phone, $windowSeconds]
    );
    $count = $countRow ? (int) $countRow['cnt'] : 0;

    if ($count < $maxSends) {
        return ['allowed' => true, 'remaining' => $maxSends - $count];
    }

    $oldest = aidfleet_db_one(
        $db,
        'SELECT UNIX_TIMESTAMP(sent_at) AS sent_ts FROM sms_rate_limits
         WHERE phone = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
         ORDER BY sent_at ASC LIMIT 1',
        'si',
        [$phone, $windowSeconds]
    );

    $retryAfterSeconds = $windowSeconds;
    if ($oldest && isset($oldest['sent_ts'])) {
        $retryAfterSeconds = max(1, (int) $oldest['sent_ts'] + $windowSeconds - time());
    }

    return [
        'allowed' => false,
        'retry_after_seconds' => $retryAfterSeconds,
        'retry_after_minutes' => max(1, (int) ceil($retryAfterSeconds / 60)),
    ];
}

function aidfleet_ensure_phone_otp_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $db = aidfleet_db();
    $cols = $db->query("SHOW COLUMNS FROM phone_otps LIKE 'locked_until'");
    if ($cols && $cols->num_rows === 0) {
        $db->query("ALTER TABLE phone_otps ADD COLUMN locked_until DATETIME NULL AFTER attempts");
    }
    $done = true;
}

/**
 * Record an SMS send event for rate-limiting.
 */
function aidfleet_record_sms_send(string $phone): void
{
    $db = aidfleet_db();
    $stmt = $db->prepare("INSERT INTO sms_rate_limits (phone) VALUES (?)");
    if ($stmt) {
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $stmt->close();
    }

    // Purge entries older than 1 hour to prevent table bloat
    $db->query("DELETE FROM sms_rate_limits WHERE sent_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
}

/**
 * Generate a cryptographically secure 6-digit OTP
 */
function aidfleet_generate_phone_otp(string $phone): string
{
    aidfleet_ensure_phone_otp_schema();
    $db = aidfleet_db();
    $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $now = date('Y-m-d H:i:s');
    $exp = date('Y-m-d H:i:s', strtotime('+5 minutes'));

    // DELETE any existing row for this phone first, then INSERT fresh.
    // This is safer than an UPSERT (ON DUPLICATE KEY UPDATE) which can silently
    // fail to update expires_at when certain MySQL column defaults are in play,
    // leaving the user with a stale / already-expired OTP row.
    $delStmt = $db->prepare("DELETE FROM phone_otps WHERE phone = ?");
    if ($delStmt) {
        $delStmt->bind_param('s', $phone);
        $delStmt->execute();
        $delStmt->close();
    } else {
        error_log('[AidFleet OTP] Failed to prepare DELETE for phone_otps: ' . $db->error);
    }

    $stmt = $db->prepare("
        INSERT INTO phone_otps (phone, otp_hash, attempts, created_at, expires_at, used, locked_until)
        VALUES (?, ?, 0, ?, ?, 0, NULL)
    ");
    if ($stmt) {
        $stmt->bind_param('ssss', $phone, $hash, $now, $exp);
        if (!$stmt->execute()) {
            error_log('[AidFleet OTP] Failed to insert OTP row for ' . $phone . ': ' . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log('[AidFleet OTP] Failed to prepare INSERT for phone_otps: ' . $db->error);
    }

    return $otp;
}

/**
 * Verify a phone OTP against the stored hash.
 */
function aidfleet_verify_phone_otp(string $phone, string $otp): array
{
    aidfleet_ensure_phone_otp_schema();
    $db = aidfleet_db();

    $row = aidfleet_db_one(
        $db,
        "SELECT * FROM phone_otps WHERE phone = ?",
        's',
        [$phone]
    );

    if (!$row) {
        return ['valid' => false, 'reason' => 'Please request a new verification code.', 'locked' => true];
    }

    if (!empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time()) {
        $seconds = max(1, (int)(strtotime((string)$row['locked_until']) - time()));
        return [
            'valid' => false,
            'reason' => "Too many failed attempts. Please try again after {$seconds} seconds or request a new code.",
            'locked' => true,
            'locked_until' => strtotime((string)$row['locked_until']),
        ];
    }

    if ((int)$row['used'] === 1 || (int)$row['attempts'] >= 5) {
        return ['valid' => false, 'reason' => 'Please request a new verification code.', 'locked' => true];
    }

    if (strtotime((string)$row['expires_at']) < time()) {
        return ['valid' => false, 'reason' => 'Verification code has expired. Please request a new one.', 'locked' => false];
    }

    if (!password_verify($otp, (string)$row['otp_hash'])) {
        $attempts = (int)$row['attempts'] + 1;
        if ($attempts >= 5) {
            $lockedUntil = date('Y-m-d H:i:s', time() + 60);
            $stmt = $db->prepare("UPDATE phone_otps SET attempts = ?, used = 1, locked_until = ? WHERE id = ?");
            if ($stmt) {
                $id = (int)$row['id'];
                $stmt->bind_param('isi', $attempts, $lockedUntil, $id);
                $stmt->execute();
                $stmt->close();
            }
            return [
                'valid' => false,
                'reason' => 'Too many failed attempts. Please try again after 60 seconds or request a new code.',
                'locked' => true,
                'locked_until' => time() + 60,
            ];
        }

        $stmt = $db->prepare("UPDATE phone_otps SET attempts = ? WHERE id = ?");
        if ($stmt) {
            $id = (int)$row['id'];
            $stmt->bind_param('ii', $attempts, $id);
            $stmt->execute();
            $stmt->close();
        }
        $remaining = 5 - $attempts;
        return ['valid' => false, 'reason' => "Incorrect code. {$remaining} attempt(s) remaining.", 'locked' => false];
    }

    $stmt = $db->prepare("UPDATE phone_otps SET used = 1, attempts = 0, locked_until = NULL WHERE id = ?");
    if ($stmt) {
        $id = (int)$row['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    return ['valid' => true, 'reason' => 'Phone verified successfully.', 'locked' => false];
}

/**
 * True when Africa's Talking sandbox credentials are in use (username is always "sandbox").
 */
function aidfleet_sms_is_sandbox(array $cfg): bool
{
    return strtolower(trim((string) ($cfg['username'] ?? ''))) === 'sandbox';
}

/**
 * Parse Africa's Talking SDK SMS send response into a normalized result.
 *
 * @return array{success:bool,recipients:array<int,array>,summary:string}
 */
function aidfleet_parse_at_sms_response(mixed $result): array
{
    $data = json_decode(json_encode($result), true);
    if (!is_array($data)) {
        return ['success' => false, 'recipients' => [], 'summary' => 'Invalid SMS gateway response'];
    }

    if (isset($data['status']) && strtolower((string) $data['status']) === 'error') {
        $summary = (string) ($data['data'] ?? $data['message'] ?? 'SMS gateway error');
        return ['success' => false, 'recipients' => [], 'summary' => $summary];
    }

    $smsData = $data['data']['SMSMessageData']
        ?? $data['SMSMessageData']
        ?? null;

    $recipients = is_array($smsData['Recipients'] ?? null) ? $smsData['Recipients'] : [];
    $summary = (string) ($smsData['Message'] ?? '');

    $success = false;
    $failures = [];

    foreach ($recipients as $recipient) {
        if (!is_array($recipient)) {
            continue;
        }
        $status = strtolower((string) ($recipient['status'] ?? ''));
        if (in_array($status, ['success', 'sent', 'queued', 'processed', 'submitted'], true)) {
            $success = true;
            continue;
        }
        $number = (string) ($recipient['number'] ?? 'unknown');
        $code = (string) ($recipient['statusCode'] ?? '');
        $detail = trim($status . ($code !== '' ? " ($code)" : ''));
        $failures[] = $number . ': ' . ($detail !== '' ? $detail : 'failed');
    }

    if (!$success && $failures !== []) {
        $summary = $summary !== '' ? $summary . ' — ' . implode('; ', $failures) : implode('; ', $failures);
    }

    return [
        'success' => $success,
        'recipients' => $recipients,
        'summary' => $summary !== '' ? $summary : ($success ? 'Sent' : 'Delivery failed'),
    ];
}

/**
 * Send an SMS message via AfricasTalking.
 */
function aidfleet_send_sms(string $phone, string $message): array
{
    $cfg = aidfleet_config()['sms'] ?? [];

    if (empty($cfg['enabled'])) {
        return ['sent' => false, 'response' => 'SMS gateway disabled'];
    }
    if (empty($cfg['api_key']) || empty($cfg['username'])) {
        return ['sent' => false, 'response' => 'SMS gateway not configured (missing API key or username)'];
    }

    $isSandbox = aidfleet_sms_is_sandbox($cfg);
    $senderId = trim((string) ($cfg['sender_id'] ?? ''));

    try {
        $at = new AfricasTalking($cfg['username'], $cfg['api_key'], $cfg['verify_ssl'] ?? true);
        $sms = $at->sms();

        $options = [
            'to'      => $phone,
            'message' => $message,
        ];

        if ($senderId !== '') {
            $options['from'] = $senderId;
        }

        if (!empty($cfg['enqueue'])) {
            $options['enqueue'] = true;
        }

        $result = $sms->send($options);
        $parsed = aidfleet_parse_at_sms_response($result);

        if (!$parsed['success']) {
            $mode = aidfleet_sms_is_sandbox($cfg) ? 'sandbox' : 'live';
            error_log('[AidFleet SMS] ' . ucfirst($mode) . ' delivery failed: ' . $parsed['summary']);
        }

        return [
            'sent' => $parsed['success'],
            'response' => $parsed['summary'],
            'raw' => $result,
        ];
    } catch (Exception $e) {
        $detail = $e->getMessage();
        if (stripos($detail, 'SSL') !== false || stripos($detail, 'certificate') !== false) {
            error_log('[AidFleet SMS] SSL verification failed. On local XAMPP set SMS_SSL_VERIFY=false. Detail: ' . $detail);
            return ['sent' => false, 'response' => 'SMS SSL verification failed'];
        }
        error_log('[AidFleet AfricasTalking] Error: ' . $detail);
        return ['sent' => false, 'response' => $detail];
    }
}

/**
 * Full OTP send flow.
 */
function aidfleet_send_phone_otp(string $phone): array
{
    $phone = aidfleet_normalize_phone($phone);

    $rateStatus = aidfleet_sms_rate_limit_status($phone);
    if (!$rateStatus['allowed']) {
        $secs = (int) ($rateStatus['retry_after_seconds'] ?? AIDFLEET_SMS_OTP_WINDOW_SECONDS);
        $mins = max(1, (int) ceil($secs / 60));
        return [
            'sent' => false,
            'rate_limited' => true,
            'retry_after_seconds' => $secs,
            'message' => "Too many verification requests (5 limit). Please resend after {$mins} minute(s).",
        ];
    }

    $otp = aidfleet_generate_phone_otp($phone);
    $message = "AidFleet: Your verification code is $otp. Expires in 5 mins.";

    $result = aidfleet_send_sms($phone, $message);

    if ($result['sent']) {
        aidfleet_record_sms_send($phone);
    } else {
        $detail = is_string($result['response'] ?? null) ? $result['response'] : 'unknown';
        error_log('[AidFleet SMS] OTP not delivered to ' . $phone . ': ' . $detail);
    }

    $gatewaySummary = is_string($result['response'] ?? null) ? $result['response'] : '';

    return [
        'sent' => $result['sent'],
        'message' => $result['sent']
            ? 'Verification code sent to your phone.'
            : aidfleet_sms_user_message($gatewaySummary),
    ];
}
