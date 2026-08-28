<?php
/** Attach or detach a company. Body: { group_id, company_id, action: attach|detach } */
require_once __DIR__ . '/../../../app/core/group_guard.php';

[$userId, $groupId, $in] = group_guard(true);

$companyId = (int)($in['company_id'] ?? 0);
$action    = (string)($in['action'] ?? 'attach');
if ($companyId <= 0) {
    Response::error('company_id is required.', 422);
}

try {
    if ($action === 'detach') {
        GroupsService::detachCompany($groupId, $companyId, $userId);
    } else {
        GroupsService::attachCompany($groupId, $companyId, $userId);
    }
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
}

Response::ok([]);
