<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('POST');
$userData = aidfleet_require_auth();
$db = aidfleet_db();

$input = aidfleet_get_json_body();
$dispatch_id = $input['dispatch_id'] ?? null;
$role = $input['role'] ?? null; // 'requester' or 'driver'
$rating = (int)($input['rating'] ?? 5);
$comment = trim($input['comment'] ?? '');

if (!$dispatch_id || !in_array($role, ['requester', 'driver'])) {
    aidfleet_send_json(['success' => false, 'message' => 'Invalid parameters'], 400);
}
if ($rating < 1 || $rating > 5) {
    aidfleet_send_json(['success' => false, 'message' => 'Rating must be between 1 and 5'], 400);
}

// Get dispatch details to verify permissions
$dispatch = aidfleet_db_one($db, "SELECT d.request_id, d.driver_id, r.user_id, d.dispatch_status 
                                  FROM dispatch_records d
                                  JOIN emergency_requests r ON d.request_id = r.request_id
                                  WHERE d.dispatch_id = ?", "i", [$dispatch_id]);

if (!$dispatch) {
    aidfleet_send_json(['success' => false, 'message' => 'Dispatch not found'], 404);
}

if ($role === 'requester' && $dispatch['user_id'] != $userData['id']) {
    aidfleet_send_json(['success' => false, 'message' => 'Unauthorized'], 403);
}
if ($role === 'driver' && $dispatch['driver_id'] != $userData['id']) {
    aidfleet_send_json(['success' => false, 'message' => 'Unauthorized'], 403);
}

// Update dispatch_records
$fieldPrefix = $role === 'requester' ? 'requester' : 'driver';
$stmt = $db->prepare("UPDATE dispatch_records SET rating_by_{$fieldPrefix} = ?, comment_by_{$fieldPrefix} = ? WHERE dispatch_id = ?");
$stmt->bind_param("isi", $rating, $comment, $dispatch_id);
$stmt->execute();
$stmt->close();

// Recalculate average rating for the target
// If requester rates driver, update driver's avg_rating and If driver rates requester, update user's avg_rating
$targetTable = $role === 'requester' ? 'drivers' : 'users';
$targetIdField = $role === 'requester' ? 'driver_id' : 'user_id';
$targetId = $role === 'requester' ? $dispatch['driver_id'] : $dispatch['user_id'];
$ratingCol = $role === 'requester' ? 'rating_by_requester' : 'rating_by_driver';

$avgRow = aidfleet_db_one($db, "SELECT AVG($ratingCol) as new_avg, COUNT($ratingCol) as total 
                                FROM dispatch_records d
                                JOIN emergency_requests r ON d.request_id = r.request_id
                                WHERE " . ($role === 'requester' ? "d.driver_id = ?" : "r.user_id = ?") . " 
                                AND $ratingCol IS NOT NULL", "i", [$targetId]);

if ($avgRow && $avgRow['total'] > 0) {
    $newAvg = round((float)$avgRow['new_avg'], 1);
    $total = (int)$avgRow['total'];
    $updateStmt = $db->prepare("UPDATE {$targetTable} SET avg_rating = ?, total_ratings = ? WHERE {$targetIdField} = ?");
    $updateStmt->bind_param("dii", $newAvg, $total, $targetId);
    $updateStmt->execute();
    $updateStmt->close();
}

aidfleet_send_json(['success' => true, 'message' => 'Rating submitted successfully']);
