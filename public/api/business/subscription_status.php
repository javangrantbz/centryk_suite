<?php
/**
 * Centryk Business admin — move a subscription through its lifecycle.
 *
 * Updates company_subscriptions.status, then Entitlements::syncFromSubscription()
 * maps that onto the entitlement's state:
 *   active | trialing  -> entitlement active   (full access)
 *   past_due | paused   -> entitlement suspended (read-only)
 *   canceled            -> entitlement revoked   (no access, data retained)
 *
 * POST { subscription_id, status, cancel_reason? }
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

$subscriptionId = (int)($in['subscription_id'] ?? 0);
$status         = trim((string)($in['status'] ?? ''));
$cancelReason   = trim((string)($in['cancel_reason'] ?? ''));

$allowed = ['trialing', 'active', 'past_due', 'paused', 'canceled'];
if ($subscriptionId <= 0 || !in_array($status, $allowed, true)) {
    Response::error('A subscription_id and a valid status are required.', 422);
}

$pdo = DB::pdo();

$stmt = $pdo->prepare(
    'SELECT s.id, s.company_id, s.package_key, s.status, bp.label, c.name AS company_name
       FROM company_subscriptions s
       JOIN business_packages bp ON bp.`key` = s.package_key
       JOIN companies c ON c.id = s.company_id
      WHERE s.id = :id
      LIMIT 1'
);
$stmt->execute(['id' => $subscriptionId]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sub) {
    Response::error('Subscription not found.', 404);
}

if ($sub['status'] === $status) {
    Response::ok(['status' => $status, 'unchanged' => true]);
}

try {
    $pdo->beginTransaction();

    if ($status === 'canceled') {
        $upd = $pdo->prepare(
            'UPDATE company_subscriptions
                SET status = :status, canceled_at = NOW(), cancel_reason = :reason
              WHERE id = :id'
        );
        $upd->execute(['status' => $status, 'reason' => $cancelReason !== '' ? $cancelReason : null, 'id' => $subscriptionId]);
    } else {
        $upd = $pdo->prepare(
            'UPDATE company_subscriptions
                SET status = :status, canceled_at = NULL, cancel_reason = NULL
              WHERE id = :id'
        );
        $upd->execute(['status' => $status, 'id' => $subscriptionId]);
    }

    // Push the new commercial state onto the entitlement (logs its own audit event).
    Entitlements::syncFromSubscription($subscriptionId, (int)$admin['id']);

    Audit::log([
        'actor_user_id' => (int)$admin['id'],
        'company_id'    => (int)$sub['company_id'],
        'event_type'    => $status === 'canceled' ? 'subscription.canceled' : 'subscription.updated',
        'summary'       => trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''))
            . ' set ' . $sub['company_name'] . "'s " . $sub['label']
            . ' subscription to ' . $status,
        'metadata'      => [
            'subscription_id' => $subscriptionId,
            'package_key'     => $sub['package_key'],
            'from'            => $sub['status'],
            'to'              => $status,
            'cancel_reason'   => $status === 'canceled' ? $cancelReason : null,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Response::error('Could not update the subscription. ' . $e->getMessage(), 500);
}

Response::ok(['status' => $status]);
