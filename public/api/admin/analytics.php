<?php
require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');
aidfleet_require_auth(['admin']);
$db = aidfleet_db();

$driversTotal   = (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM drivers')['c'] ?? 0);
$verifiedDrivers = (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM drivers WHERE verification_status = "approved"')['c'] ?? 0);
$pendingDrivers  = (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM drivers WHERE verification_status = "pending"')['c'] ?? 0);
$rejectedDrivers = (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM drivers WHERE verification_status = "rejected"')['c'] ?? 0);

$onlineDrivers = (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM drivers WHERE availability_status != "offline" AND verification_status = "approved"')['c'] ?? 0);

$totalUsers = (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM users')['c'] ?? 0);

$totalRequests = (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM emergency_requests')['c'] ?? 0);

// Requests by status
$statusRows = aidfleet_db_all($db,
    'SELECT request_status AS status, COUNT(*) AS cnt FROM emergency_requests GROUP BY request_status'
);
$requestsByStatus = [];
foreach ($statusRows as $row) {
    $requestsByStatus[$row['status']] = (int)$row['cnt'];
}

// Requests by emergency type
$typeRows = aidfleet_db_all($db,
    'SELECT emergency_type AS etype, COUNT(*) AS cnt FROM emergency_requests GROUP BY emergency_type ORDER BY cnt DESC'
);
$requestsByType = [];
foreach ($typeRows as $row) {
    $requestsByType[$row['etype']] = (int)$row['cnt'];
}

$completedTrips = (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM dispatch_records WHERE dispatch_status = "completed"')['c'] ?? 0);

$avgResponseData = aidfleet_db_one($db, '
    SELECT
        AVG(
            TIMESTAMPDIFF(
                SECOND,
                dr.dispatch_time,
                COALESCE(
                    dr.driver_response_time,
                    (
                        SELECT MIN(sl.created_at)
                        FROM system_logs sl
                        WHERE sl.entity_type = "dispatch_records"
                          AND sl.entity_id = dr.dispatch_id
                          AND sl.action = "DISPATCH_ACCEPTED"
                    )
                )
            )
        ) AS avg_seconds,
        COUNT(*) AS sample_count
    FROM dispatch_records dr
    WHERE dr.dispatch_time IS NOT NULL
      AND dr.dispatch_status IN ("accepted", "arrived", "enroute_hospital", "completed")
      AND (
        dr.driver_response_time IS NOT NULL
        OR EXISTS (
            SELECT 1
            FROM system_logs sl
            WHERE sl.entity_type = "dispatch_records"
              AND sl.entity_id = dr.dispatch_id
              AND sl.action = "DISPATCH_ACCEPTED"
        )
      )
');
$sampleCount = (int)($avgResponseData['sample_count'] ?? 0);
$avgResponseSeconds = ($sampleCount > 0 && $avgResponseData['avg_seconds'] !== null)
    ? max(0, (float)$avgResponseData['avg_seconds'])
    : null;

$avgResponseMin = 0.0;
$avgResponseFormatted = 'N/A';
if ($avgResponseSeconds !== null) {
    $avgResponseMin = round($avgResponseSeconds / 60, 1);
    if ($avgResponseSeconds < 60) {
        $avgResponseFormatted = (int)round($avgResponseSeconds) . ' sec';
    } else {
        $avgResponseFormatted = $avgResponseMin . ' min';
    }
}

$driverGrowthRows = aidfleet_db_all($db, "
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key,
           DATE_FORMAT(created_at, '%b') AS month_label,
           COUNT(*) AS cnt
    FROM drivers
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
");
$driverGrowth = ['labels' => [], 'values' => []];
foreach ($driverGrowthRows as $row) {
    $driverGrowth['labels'][] = $row['month_label'];
    $driverGrowth['values'][] = (int)$row['cnt'];
}

$userGrowthRows = aidfleet_db_all($db, "
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key,
           DATE_FORMAT(created_at, '%b') AS month_label,
           COUNT(*) AS cnt
    FROM users
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
");
$userGrowth = ['labels' => [], 'values' => []];
foreach ($userGrowthRows as $row) {
    $userGrowth['labels'][] = $row['month_label'];
    $userGrowth['values'][] = (int)$row['cnt'];
}

$totalLogs = (int)(aidfleet_db_one($db, 'SELECT COUNT(*) AS c FROM system_logs')['c'] ?? 0);

if (ob_get_level()) ob_clean();
aidfleet_send_json([
    'success' => true,
    'analytics' => [
        'users' => [
            'total' => $totalUsers,
            'growth' => $userGrowth,
        ],
        'drivers' => [
            'total'    => $driversTotal,
            'verified' => $verifiedDrivers,
            'pending'  => $pendingDrivers,
            'rejected' => $rejectedDrivers,
            'online'   => $onlineDrivers,
            'growth'   => $driverGrowth,
        ],
        'requests' => [
            'total'     => $totalRequests,
            'by_status' => $requestsByStatus,
            'by_type'   => $requestsByType,
        ],
        'performance' => [
            'avg_response_time'     => $avgResponseFormatted,
            'avg_response_minutes'  => $avgResponseMin,
            'avg_response_seconds'  => $avgResponseSeconds !== null ? (int)round($avgResponseSeconds) : null,
            'response_sample_count' => $sampleCount,
            'completed_trips'       => $completedTrips,
        ],
        'system' => [
            'total_logs' => $totalLogs,
        ],
    ]
]);
