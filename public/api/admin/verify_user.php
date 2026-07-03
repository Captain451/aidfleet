<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$admin = aidfleet_require_auth(['admin']);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;

aidfleet_require_fields($in, ['user_id', 'decision']);
$user_id = (int)$in['user_id'];
$decision = trim((string)$in['decision']); // 'disabled' or 'active'
$reason = isset($in['reason']) ? trim((string)$in['reason']) : '';

if (!in_array($decision, ['active', 'disabled'], true)) {
    aidfleet_send_json(['success' => false, 'message' => 'Decision must be active or disabled'], 400);
}

$db = aidfleet_db();
aidfleet_ensure_user_status_schema();
$user = aidfleet_db_one($db, 'SELECT user_id, full_name, email FROM users WHERE user_id = ?', 'i', [$user_id]);
if (!$user) {
    aidfleet_send_json(['success' => false, 'message' => 'User not found'], 404);
}

$note = ($decision === 'disabled') ? $reason : '';

$stmt = $db->prepare('UPDATE users SET account_status = ?, account_note = ? WHERE user_id = ?');
$stmt->bind_param('ssi', $decision, $note, $user_id);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    aidfleet_send_json(['success' => false, 'message' => 'Update failed'], 500);
}

aidfleet_log('admin', (int)$admin['id'], 'USER_STATUS', 'users', $user_id, "User {$decision}" . ($reason ? " ({$reason})" : ''));

aidfleet_send_json(['success' => true, 'message' => "User {$decision}", 'note' => $note]);
