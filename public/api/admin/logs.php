<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

aidfleet_require_auth(['admin']);
$db = aidfleet_db();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
if ($limit < 1) $limit = 1;
if ($limit > 500) $limit = 500;

$rows = aidfleet_db_all($db, "SELECT log_id, actor_type, actor_id, action, entity_type, entity_id, details, created_at FROM system_logs ORDER BY created_at DESC LIMIT {$limit}");

aidfleet_send_json(['success' => true, 'logs' => $rows]);

