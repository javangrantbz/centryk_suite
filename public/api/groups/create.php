<?php
/**
 * Self-serve: a company admin creates their own company group (no advisor).
 * The group is switched on immediately (Enterprise entitlement granted).
 *
 * POST { name }  →  { group_id }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/GroupsService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

try {
    $groupId = GroupsService::createForUser((int)$user['id'], (string)($in['name'] ?? ''));
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 403);
}

Response::ok(['group_id' => $groupId]);
