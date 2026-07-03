<?php

require_once __DIR__ . '/bootstrap.php';

aidfleet_require_method('GET');

$user = aidfleet_current_user();
if (!$user) {
    aidfleet_send_json(['success' => false, 'message' => 'Unauthorized'], 401);
}

$key = env('GOOGLE_MAPS_API_KEY', '');

if (!$key || $key === 'YOUR_GOOGLE_MAPS_API_KEY_HERE' || $key === 'your-google-maps-api-key') {
    aidfleet_send_json(['success' => false, 'message' => 'Google Maps API key not configured'], 500);
}

header('Cache-Control: private, max-age=3600');
aidfleet_send_json(['success' => true, 'key' => $key]);
