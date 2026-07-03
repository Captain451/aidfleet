<?php

require_once __DIR__ . '/../bootstrap.php';

aidfleet_require_method('GET');

aidfleet_require_auth(['requester']);

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    aidfleet_send_json(['success' => false, 'message' => 'lat and lng are required'], 400);
}

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLon / 2) ** 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

$db = aidfleet_db();
$drivers = aidfleet_db_all($db, '
    SELECT driver_id, full_name, phone, email, license_number, ambulance_registration, ambulance_type, last_lat, last_lng, avg_rating, profile_image
    FROM drivers
    WHERE verification_status = "approved"
      AND availability_status = "available"
      AND last_lat IS NOT NULL AND last_lng IS NOT NULL
');

foreach ($drivers as &$d) {
    $d['distance_km'] = haversine_km($lat, $lng, (float)$d['last_lat'], (float)$d['last_lng']);
}
unset($d);
usort($drivers, fn($a, $b) => ($a['distance_km'] <=> $b['distance_km']));

aidfleet_send_json(['success' => true, 'drivers' => $drivers]);

