<?php

require_once __DIR__ . '/response.php';

function aidfleet_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = require __DIR__ . '/../../config/app.php';
    return $cfg;
}

function aidfleet_db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) return $conn;

    $cfg = aidfleet_config()['db'];
    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'], (int)$cfg['port']);
    if ($conn->connect_error) {
        aidfleet_send_json(['success' => false, 'message' => 'Database connection failed'], 500);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function aidfleet_db_one(mysqli $db, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function aidfleet_db_all(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

