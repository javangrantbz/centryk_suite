<?php
/**
 * Centryk Business admin — read model for admin-business-packages.php.
 *
 * POST {} ................................ catalog + current customers + requests
 * POST {"q": "acme"} .................... + company search results
 * POST {"company_id": 12} .............. + that company's entitlements & subscriptions
 */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$companyId = (int)($in['company_id'] ?? 0);
$q         = trim((string)($in['q'] ?? ''));

$pdo = DB::pdo();

$catalog = $pdo->query(
    'SELECT `key`, label, description, monthly_price, currency, is_app, app_key, sort_order
       FROM business_packages
      WHERE status = "active"
      ORDER BY sort_order ASC'
)->fetchAll(PDO::FETCH_ASSOC);

// Companies that currently hold at least one non-revoked entitlement.
$customers = $pdo->query(
    'SELECT c.id, c.name, c.status,
            GROUP_CONCAT(e.package_key ORDER BY bp.sort_order SEPARATOR ",") AS packages,
            SUM(e.state = "active")    AS active_count,
            SUM(e.state = "suspended") AS suspended_count
       FROM company_entitlements e
       JOIN companies c        ON c.id = e.company_id
       JOIN business_packages bp ON bp.`key` = e.package_key
      WHERE e.state <> "revoked"
      GROUP BY c.id, c.name, c.status
      ORDER BY c.name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$requests = $pdo->query(
    'SELECT r.id, r.company_id, c.name AS company_name, r.package_key,
            r.message, r.status, r.created_at, r.handled_at,
            TRIM(CONCAT(COALESCE(u.first_name, ""), " ", COALESCE(u.last_name, ""))) AS requested_by_name,
            u.email AS requested_by_email
       FROM business_package_requests r
       JOIN companies c ON c.id = r.company_id
       LEFT JOIN users u ON u.id = r.requested_by
      ORDER BY (r.status = "pending") DESC, r.created_at DESC
      LIMIT 100'
)->fetchAll(PDO::FETCH_ASSOC);

$out = [
    'catalog'   => $catalog,
    'customers' => $customers,
    'requests'  => $requests,
];

if ($q !== '') {
    $stmt = $pdo->prepare(
        'SELECT id, name, status FROM companies
          WHERE name LIKE :like
          ORDER BY (status = "active") DESC, name ASC
          LIMIT 25'
    );
    $stmt->execute(['like' => '%' . $q . '%']);
    $out['search_results'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($companyId > 0) {
    $stmt = $pdo->prepare(
        'SELECT c.id, c.name, c.status, c.created_at,
                TRIM(CONCAT(COALESCE(o.first_name, ""), " ", COALESCE(o.last_name, ""))) AS owner_name,
                o.email AS owner_email
           FROM companies c
           LEFT JOIN users o ON o.id = c.owner_id
          WHERE c.id = :id
          LIMIT 1'
    );
    $stmt->execute(['id' => $companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$company) {
        Response::error('Company not found.', 404);
    }

    $entStmt = $pdo->prepare(
        'SELECT e.package_key, bp.label, e.state, e.source, e.subscription_id,
                e.granted_at, e.expires_at, e.suspended_at, e.revoked_at, e.notes
           FROM company_entitlements e
           JOIN business_packages bp ON bp.`key` = e.package_key
          WHERE e.company_id = :id
          ORDER BY bp.sort_order ASC'
    );
    $entStmt->execute(['id' => $companyId]);

    $subStmt = $pdo->prepare(
        'SELECT s.id, s.package_key, bp.label, s.status, s.price, s.currency,
                s.billing_interval, s.trial_ends_at, s.contract_ref,
                s.started_at, s.canceled_at, s.cancel_reason
           FROM company_subscriptions s
           JOIN business_packages bp ON bp.`key` = s.package_key
          WHERE s.company_id = :id
          ORDER BY s.started_at DESC, s.id DESC'
    );
    $subStmt->execute(['id' => $companyId]);

    $out['company']       = $company;
    $out['entitlements']  = $entStmt->fetchAll(PDO::FETCH_ASSOC);
    $out['subscriptions'] = $subStmt->fetchAll(PDO::FETCH_ASSOC);
}

Response::ok($out);
