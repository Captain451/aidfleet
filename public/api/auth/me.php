<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

$u = aidfleet_current_user();
if ($u) {
        $db = aidfleet_db();
    $table = $u['role'] === 'driver' ? 'drivers' : ($u['role'] === 'admin' ? 'administrators' : 'users');
    $idField = $u['role'] === 'driver' ? 'driver_id' : ($u['role'] === 'admin' ? 'admin_id' : 'user_id');
    
    if ($u['role'] === 'admin') {
        aidfleet_ensure_admin_profile_image_schema();
        $row = aidfleet_db_one($db, "SELECT profile_image FROM administrators WHERE admin_id = ?", "i", [(int)$u['id']]);
        if ($row && !empty($row['profile_image'])) {
            $u['profile_image'] = $row['profile_image'];
        }
    } else {
        $row = aidfleet_db_one($db, "SELECT profile_image, avg_rating, phone FROM {$table} WHERE {$idField} = ?", "i", [(int)$u['id']]);
        if ($row) {
            $u['profile_image'] = $row['profile_image'];
            $u['avg_rating'] = $row['avg_rating'];
            if (!empty($row['phone'])) {
                $u['phone'] = $row['phone'];
            }
        }
    }
}
aidfleet_send_json(['success' => true, 'user' => $u]);

