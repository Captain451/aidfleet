<?php

require_once __DIR__ . '/db.php';

function aidfleet_log(string $actor_type, ?int $actor_id, string $action, ?string $entity_type, ?int $entity_id, ?string $details): void
{
    $db = aidfleet_db();
    $stmt = $db->prepare('INSERT INTO system_logs (actor_type, actor_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?,?)');
    if (!$stmt) return;
    $stmt->bind_param(
        'sissis',
        $actor_type,
        $actor_id,
        $action,
        $entity_type,
        $entity_id,
        $details
    );
    $stmt->execute();
    $stmt->close();
}

