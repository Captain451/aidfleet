<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['driver']);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;
aidfleet_require_fields($in, ['lat', 'lng']);

$lat = (float)$in['lat'];
$lng = (float)$in['lng'];
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid coordinates'], 400);
}

$db = aidfleet_db();
$stmt = $db->prepare('UPDATE drivers SET last_lat = ?, last_lng = ? WHERE driver_id = ?');
$stmt->bind_param('ddi', $lat, $lng, $u['id']);
$ok = $stmt->execute();
$stmt->close();
if (!$ok) aidfleet_send_json(['success' => false, 'message' => 'Update failed'], 500);

aidfleet_send_json(['success' => true]);

