<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

tv_require_organization();

$stmt = db()->prepare(
    'SELECT e.id, e.title, e.slug, e.status, e.visibility, e.start_at, c.name AS channel_name
     FROM tv_events e
     JOIN tv_channels c ON c.id = e.channel_id
     WHERE e.organization_id = :organization_id
     ORDER BY e.start_at DESC'
);
$stmt->execute(['organization_id' => (int)tv_active_organization()['id']]);
Response::ok(['data' => $stmt->fetchAll()]);

