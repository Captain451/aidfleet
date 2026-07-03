<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * Public statistics endpoint for the AidFleet homepage.
 * Returns real counts from the database for display on landing pages.
 */

aidfleet_require_method('GET');

$db = aidfleet_db();

// Trips Completed
$tripsRow = aidfleet_db_one($db, "SELECT COUNT(*) AS cnt FROM dispatch_records WHERE dispatch_status = 'completed'");
$tripsCompleted = $tripsRow ? (int)$tripsRow['cnt'] : 0;

// Verified Drivers
$driversRow = aidfleet_db_one($db, "SELECT COUNT(*) AS cnt FROM drivers WHERE verification_status = 'approved'");
$verifiedDrivers = $driversRow ? (int)$driversRow['cnt'] : 0;

// Avg Response Time (minutes)
$avgRow = aidfleet_db_one($db,
    "SELECT
        AVG(
            TIMESTAMPDIFF(
                SECOND,
                dr.dispatch_time,
                COALESCE(
                    dr.driver_response_time,
                    (
                        SELECT MIN(sl.created_at)
                        FROM system_logs sl
                        WHERE sl.entity_type = 'dispatch_records'
                          AND sl.entity_id = dr.dispatch_id
                          AND sl.action = 'DISPATCH_ACCEPTED'
                    )
                )
            )
        ) AS avg_seconds,
        COUNT(*) AS sample_count
     FROM dispatch_records dr
     WHERE dr.dispatch_time IS NOT NULL
       AND dr.dispatch_status IN ('accepted', 'arrived', 'enroute_hospital', 'completed')
       AND (
         dr.driver_response_time IS NOT NULL
         OR EXISTS (
             SELECT 1 FROM system_logs sl
             WHERE sl.entity_type = 'dispatch_records'
               AND sl.entity_id = dr.dispatch_id
               AND sl.action = 'DISPATCH_ACCEPTED'
         )
       )"
);
$avgResponseMin = 0;
$sampleCount = (int)($avgRow['sample_count'] ?? 0);
if ($sampleCount > 0 && $avgRow['avg_seconds'] !== null) {
    $avgSeconds = max(0, (float)$avgRow['avg_seconds']);
    $avgResponseMin = max(1, (int)round($avgSeconds / 60));
    if ($avgSeconds < 60) {
        $avgResponseMin = 1;
    }
}

// Registered Users (requesters)
$usersRow = aidfleet_db_one($db, "SELECT COUNT(*) AS cnt FROM users");
$totalUsers = $usersRow ? (int)$usersRow['cnt'] : 0;

aidfleet_send_json([
    'success' => true,
    'stats' => [
        'trips_completed'  => $tripsCompleted,
        'verified_drivers' => $verifiedDrivers,
        'avg_response_min' => $avgResponseMin,
        'total_users'      => $totalUsers,
    ],
]);
