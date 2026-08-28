<?php
/** Customer picklist for adding stops. Body: { company_id, q? } */
require_once __DIR__ . '/../../../app/core/business_guard.php';

[, $companyId, $in] = business_guard('routes', false);

$q = trim((string)($in['q'] ?? ''));
$sql = "SELECT id, name, company FROM customers WHERE company_id = :cid";
$args = ['cid' => $companyId];
if ($q !== '') {
    $sql .= " AND (name LIKE :q OR company LIKE :q)";
    $args['q'] = '%' . $q . '%';
}
$sql .= " ORDER BY name ASC LIMIT 50";

$stmt = DB::pdo()->prepare($sql);
$stmt->execute($args);

Response::ok(['customers' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
