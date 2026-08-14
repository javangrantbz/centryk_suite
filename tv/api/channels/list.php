<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

tv_require_organization();

$stmt = db()->prepare('SELECT id, name, slug, visibility, status, description FROM tv_channels WHERE organization_id = :organization_id ORDER BY created_at DESC');
$stmt->execute(['organization_id' => (int)tv_active_organization()['id']]);
Response::ok(['data' => $stmt->fetchAll()]);

