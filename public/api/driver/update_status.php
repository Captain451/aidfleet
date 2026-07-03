<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$u = aidfleet_require_auth(['driver']);

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = (stripos($contentType, 'application/json') !== false) ? aidfleet_get_json_body() : $_POST;

aidfleet_require_fields($in, ['dispatch_id', 'status']);
$dispatch_id = (int)$in['dispatch_id'];
$status = $in['status']; // 'arrived' or 'enroute_hospital'

$db = aidfleet_db();

// Ensure dispatch_status ENUM includes 'arrived' and 'enroute_hospital'
$enumCheck = $db->query("SHOW COLUMNS FROM dispatch_records LIKE 'dispatch_status'");
if ($enumCheck && $enumCheck->num_rows > 0) {
    $enumRow = $enumCheck->fetch_assoc();
    $enumType = $enumRow['Type'] ?? '';
    if (strpos($enumType, 'arrived') === false || strpos($enumType, 'enroute_hospital') === false) {
        $db->query("ALTER TABLE dispatch_records MODIFY COLUMN dispatch_status ENUM('selected','accepted','rejected','arrived','enroute_hospital','completed') NOT NULL DEFAULT 'selected'");
    }
}

// Ensure hospital columns exist dynamically
$colCheck = $db->query("SHOW COLUMNS FROM dispatch_records LIKE 'hospital_name'");
if ($colCheck->num_rows === 0) {
    // Add missing columns
    $db->query("ALTER TABLE dispatch_records 
                ADD COLUMN hospital_name VARCHAR(255) DEFAULT NULL,
                ADD COLUMN hospital_lat DECIMAL(10,8) DEFAULT NULL,
                ADD COLUMN hospital_lng DECIMAL(11,8) DEFAULT NULL");
}

// Verify driver owns this dispatch
$dr = aidfleet_db_one($db, 'SELECT request_id, driver_id, dispatch_status FROM dispatch_records WHERE dispatch_id = ?', 'i', [$dispatch_id]);
if (!$dr || (int)$dr['driver_id'] !== (int)$u['id']) {
    aidfleet_send_json(['success' => false, 'message' => 'Dispatch not found'], 404);
}

// 3. Update Status
if ($status === 'arrived') {
    $stmt = $db->prepare('UPDATE dispatch_records SET dispatch_status = "arrived" WHERE dispatch_id = ?');
    $stmt->bind_param('i', $dispatch_id);
    $stmt->execute();
    $stmt->close();
    
    aidfleet_log('driver', (int)$u['id'], 'DISPATCH_ARRIVED', 'dispatch_records', $dispatch_id, 'Driver arrived at scene');
    aidfleet_send_json(['success' => true]);
} 
else if ($status === 'enroute_hospital') {
    aidfleet_require_fields($in, ['hospital_name', 'hospital_lat', 'hospital_lng']);
    
    $hName = $in['hospital_name'];
    $hLat = (float)$in['hospital_lat'];
    $hLng = (float)$in['hospital_lng'];
    
    $stmt = $db->prepare('UPDATE dispatch_records SET dispatch_status = "enroute_hospital", hospital_name = ?, hospital_lat = ?, hospital_lng = ? WHERE dispatch_id = ?');
    $stmt->bind_param('sddi', $hName, $hLat, $hLng, $dispatch_id);
    $stmt->execute();
    $stmt->close();
    
    aidfleet_log('driver', (int)$u['id'], 'DISPATCH_ENROUTE_HOSPITAL', 'dispatch_records', $dispatch_id, "Driver routing to $hName");
    aidfleet_send_json(['success' => true]);
}
else {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid status'], 400);
}
