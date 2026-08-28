<?php
/** Admin: attach / detach a company to a group. Body: { group_id, company_id, action: attach|detach } */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/GroupsService.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$groupId   = (int)($in['group_id'] ?? 0);
$companyId = (int)($in['company_id'] ?? 0);
$action    = (string)($in['action'] ?? 'attach');
if ($groupId <= 0 || $companyId <= 0) {
    Response::error('group_id and company_id are required.', 422);
}

try {
    if ($action === 'detach') {
        GroupsService::detachCompany($groupId, $companyId, (int)$admin['id'], true);
    } else {
        GroupsService::attachCompany($groupId, $companyId, (int)$admin['id'], true);
    }
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok(['action' => $action]);
