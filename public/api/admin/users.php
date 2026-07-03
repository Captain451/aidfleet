<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

aidfleet_require_auth(['admin']);
$db = aidfleet_db();
aidfleet_ensure_user_status_schema();

$rows = aidfleet_db_all($db, '
    SELECT u.user_id, u.full_name, u.email, u.phone, u.created_at, u.account_status, u.account_note,
           (SELECT COUNT(*) FROM emergency_requests er WHERE er.user_id = u.user_id) AS total_requests,
           (SELECT COUNT(*) FROM emergency_requests er WHERE er.user_id = u.user_id AND er.request_status = "completed") AS completed_requests
    FROM users u
    ORDER BY u.created_at DESC
');

aidfleet_send_json(['success' => true, 'users' => $rows]);

