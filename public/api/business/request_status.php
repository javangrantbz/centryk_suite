<?php
/**
 * Centryk Business admin — triage an inbound "Explore more services" lead.
 *
 * POST { request_id, status }   status ∈ pending|contacted|converted|declined
 */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Audit.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$requestId = (int)($in['request_id'] ?? 0);
$status    = trim((string)($in['status'] ?? ''));

$allowed = ['pending', 'contacted', 'converted', 'declined'];
if ($requestId <= 0 || !in_array($status, $allowed, true)) {
    Response::error('A request_id and a valid status are required.', 422);
}

$pdo = DB::pdo();

$stmt = $pdo->prepare(
    'SELECT r.id, r.company_id, r.package_key, r.status, c.name AS company_name
       FROM business_package_requests r
       JOIN companies c ON c.id = r.company_id
      WHERE r.id = :id LIMIT 1'
);
$stmt->execute(['id' => $requestId]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$req) {
    Response::error('Request not found.', 404);
}

$handled = $status === 'pending' ? null : date('Y-m-d H:i:s');
$handledBy = $status === 'pending' ? null : (int)$admin['id'];

$pdo->prepare(
    'UPDATE business_package_requests
        SET status = :status, handled_by = :handled_by, handled_at = :handled_at
      WHERE id = :id'
)->execute([
    'status'     => $status,
    'handled_by' => $handledBy,
    'handled_at' => $handled,
    'id'         => $requestId,
]);

Audit::log([
    'actor_user_id' => (int)$admin['id'],
    'company_id'    => (int)$req['company_id'],
    'event_type'    => 'package.request.updated',
    'summary'       => trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''))
        . ' marked ' . $req['company_name'] . "'s "
        . ($req['package_key'] ?: 'general') . ' request as ' . $status,
    'metadata'      => [
        'request_id'  => $requestId,
        'package_key' => $req['package_key'],
        'from'        => $req['status'],
        'to'          => $status,
    ],
]);

Response::ok(['status' => $status]);
