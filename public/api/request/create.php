<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['requester']);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;
aidfleet_require_fields($in, ['emergency_type', 'location', 'lat', 'lng', 'description']);

$emergency_type = trim((string)$in['emergency_type']);
$location = trim((string)$in['location']);
$lat = (float)$in['lat'];
$lng = (float)$in['lng'];
$description = trim((string)$in['description']);

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid coordinates'], 400);
}

$db = aidfleet_db();

// Enforce one active request per requester
$active = aidfleet_db_one($db, '
    SELECT request_id FROM emergency_requests
    WHERE user_id = ?
      AND request_status NOT IN ("completed","cancelled","rejected")
    ORDER BY request_time DESC
    LIMIT 1
', 'i', [(int)$u['id']]);

if ($active) {
    // If a previous active request exists,
    // treat this as overwriting the existing active request instead of blocking.
    $request_id = (int)$active['request_id'];
    $stmt = $db->prepare('UPDATE emergency_requests SET emergency_type = ?, location = ?, lat = ?, lng = ?, description = ? WHERE request_id = ?');
    $stmt->bind_param('ssddsi', $emergency_type, $location, $lat, $lng, $description, $request_id);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) aidfleet_send_json(['success' => false, 'message' => 'Could not update existing request'], 500);

    aidfleet_log('requester', (int)$u['id'], 'EMERGENCY_UPDATED', 'emergency_requests', $request_id, 'Active emergency request updated from form');
    aidfleet_send_json(['success' => true, 'request_id' => $request_id]);
}

$stmt = $db->prepare('INSERT INTO emergency_requests (user_id, emergency_type, location, lat, lng, description) VALUES (?,?,?,?,?,?)');
$stmt->bind_param('issdds', $u['id'], $emergency_type, $location, $lat, $lng, $description);
$ok = $stmt->execute();
$request_id = (int)$stmt->insert_id;
$stmt->close();
if (!$ok) aidfleet_send_json(['success' => false, 'message' => 'Could not create request'], 500);

aidfleet_log('requester', (int)$u['id'], 'EMERGENCY_CREATED', 'emergency_requests', $request_id, 'Emergency request created');
aidfleet_send_json(['success' => true, 'request_id' => $request_id]);

