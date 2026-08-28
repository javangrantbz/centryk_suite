<?php
/**
 * Centryk Business admin — grant a package to a company.
 *
 * Opens a company_subscriptions row (the commercial agreement) and, in the same
 * transaction, activates the matching company_entitlements row via
 * Entitlements::grant(). A package with an existing non-terminal subscription is
 * rejected — resume/convert that one instead.
 *
 * POST {
 *   company_id, package_key,
 *   price?, billing_interval? (monthly|annual),
 *   contract_ref?, notes?,
 *   trial? (bool), trial_ends_at? (YYYY-MM-DD, required when trial)
 * }
 */
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/core/Entitlements.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$companyId   = (int)($in['company_id'] ?? 0);
$packageKey  = trim((string)($in['package_key'] ?? ''));
$price       = round((float)($in['price'] ?? 0), 2);
$interval    = in_array($in['billing_interval'] ?? '', ['monthly', 'annual'], true)
    ? $in['billing_interval']
    : 'monthly';
$contractRef = trim((string)($in['contract_ref'] ?? ''));
$notes       = trim((string)($in['notes'] ?? ''));
$isTrial     = !empty($in['trial']);
$trialEndsAt = trim((string)($in['trial_ends_at'] ?? ''));

if ($companyId <= 0 || $packageKey === '') {
    Response::error('company_id and package_key are required.', 422);
}
if ($price < 0) {
    Response::error('Price cannot be negative.', 422);
}
if ($isTrial) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trialEndsAt) || strtotime($trialEndsAt) === false) {
        Response::error('A valid trial end date (YYYY-MM-DD) is required for a trial.', 422);
    }
    if (strtotime($trialEndsAt) < strtotime('today')) {
        Response::error('The trial end date is in the past.', 422);
    }
}

$pdo = DB::pdo();

$company = $pdo->prepare('SELECT id, name FROM companies WHERE id = :id LIMIT 1');
$company->execute(['id' => $companyId]);
$company = $company->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    Response::error('Company not found.', 404);
}

$package = $pdo->prepare('SELECT `key`, label, status FROM business_packages WHERE `key` = :k LIMIT 1');
$package->execute(['k' => $packageKey]);
$package = $package->fetch(PDO::FETCH_ASSOC);
if (!$package || $package['status'] !== 'active') {
    Response::error('That package is not available.', 422);
}

$existing = $pdo->prepare(
    'SELECT id FROM company_subscriptions
      WHERE company_id = :c AND package_key = :k
        AND status IN ("trialing", "active", "past_due", "paused")
      LIMIT 1'
);
$existing->execute(['c' => $companyId, 'k' => $packageKey]);
if ($existing->fetch()) {
    Response::error($company['name'] . ' already has an open ' . $package['label'] . ' subscription.', 409);
}

$subStatus = $isTrial ? 'trialing' : 'active';

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare(
        'INSERT INTO company_subscriptions
             (company_id, package_key, status, price, billing_interval,
              trial_ends_at, contract_ref, created_by)
         VALUES
             (:company_id, :package_key, :status, :price, :billing_interval,
              :trial_ends_at, :contract_ref, :created_by)'
    );
    $insert->execute([
        'company_id'       => $companyId,
        'package_key'      => $packageKey,
        'status'           => $subStatus,
        'price'            => $price,
        'billing_interval' => $interval,
        'trial_ends_at'    => $isTrial ? $trialEndsAt . ' 23:59:59' : null,
        'contract_ref'     => $contractRef,
        'created_by'       => (int)$admin['id'],
    ]);
    $subscriptionId = (int)$pdo->lastInsertId();

    Entitlements::grant(
        $companyId,
        $packageKey,
        $subscriptionId,
        (int)$admin['id'],
        $isTrial ? 'trial' : 'admin_grant',
        null,
        $notes
    );

    Audit::log([
        'actor_user_id' => (int)$admin['id'],
        'company_id'    => $companyId,
        'event_type'    => 'subscription.created',
        'summary'       => trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''))
            . ' opened a ' . $package['label'] . ' subscription for ' . $company['name']
            . ($isTrial ? ' (trial)' : ''),
        'metadata'      => [
            'subscription_id'  => $subscriptionId,
            'package_key'      => $packageKey,
            'status'           => $subStatus,
            'price'            => $price,
            'billing_interval' => $interval,
            'contract_ref'     => $contractRef,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Response::error('Could not grant the package. ' . $e->getMessage(), 500);
}

Response::ok(['subscription_id' => $subscriptionId]);
