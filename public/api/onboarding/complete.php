<?php
/**
 * Complete (or skip) a company's first-login setup wizard.
 * Sets business type + customer noun on the company, grants the owner access to
 * the apps they chose (Calendar is always on), marks the company onboarded, and
 * pushes the new profile to OnePay so its invoicing wording updates immediately.
 *
 * POST JSON: {
 *   company_id (required),
 *   skip (bool) — just mark onboarded, change nothing else,
 *   business_type, customer_noun_singular, customer_noun_plural,
 *   apps: ["onepay","invoice", ...]
 * }
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/OnePayWebhook.php';

Auth::start();
$caller = Auth::user();
if (!$caller) {
    Response::error('Unauthorized.', 401);
}

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$companyId = (int)($body['company_id'] ?? 0);
if (!$companyId) {
    Response::error('company_id is required.');
}

$pdo = DB::pdo();

// Caller must administer this company (or be a site admin).
if (empty($caller['is_admin'])) {
    $chk = $pdo->prepare('SELECT 1 FROM company_members
                          WHERE company_id = :cid AND user_id = :uid
                            AND role = "admin" AND status = "active" LIMIT 1');
    $chk->execute(['cid' => $companyId, 'uid' => (int)$caller['id']]);
    if (!$chk->fetch()) {
        Response::error('You are not an admin of this company.', 403);
    }
}

// Skip: just stop the wizard from showing again, change nothing else.
if (!empty($body['skip'])) {
    $pdo->prepare('UPDATE companies SET onboarded_at = NOW() WHERE id = :id')->execute(['id' => $companyId]);
    Response::ok(['skipped' => true]);
}

// Business type → default customer noun. The user may override the noun; if they
// leave it blank we fall back to this map, else to Customer/Customers.
$NOUNS = [
    'school'     => ['Student',  'Students'],
    'gym'        => ['Member',   'Members'],
    'clinic'     => ['Patient',  'Patients'],
    'salon'      => ['Client',   'Clients'],
    'grocery'    => ['Customer', 'Customers'],
    'services'   => ['Client',   'Clients'],
    'property'   => ['Tenant',   'Tenants'],
    'retail'     => ['Customer', 'Customers'],
    'restaurant' => ['Customer', 'Customers'],
    'ice_cream'  => ['Customer', 'Customers'],
    'meat_shop'  => ['Customer', 'Customers'],
    'cafeteria'  => ['Customer', 'Customers'],
    'other'      => ['Customer', 'Customers'],
];

$businessType = strtolower(trim((string)($body['business_type'] ?? '')));
if ($businessType !== '' && !isset($NOUNS[$businessType])) {
    $businessType = 'other';
}
$default = $NOUNS[$businessType] ?? ['Customer', 'Customers'];

$nounS = trim((string)($body['customer_noun_singular'] ?? '')) ?: $default[0];
$nounP = trim((string)($body['customer_noun_plural']   ?? '')) ?: $default[1];

$pdo->prepare('UPDATE companies
               SET business_type = :bt, customer_noun_singular = :ns,
                   customer_noun_plural = :np, onboarded_at = NOW()
               WHERE id = :id')
    ->execute([
        'bt' => $businessType !== '' ? $businessType : null,
        'ns' => $nounS, 'np' => $nounP, 'id' => $companyId,
    ]);

// Grant the owner access to the chosen apps. Calendar is always included.
// Additive only (INSERT IGNORE) — we never revoke here.
$want = array_values(array_unique(array_merge(
    ['calendar'],
    array_filter(array_map(static fn($k) => strtolower(trim((string)$k)), (array)($body['apps'] ?? [])))
)));

$grant   = $pdo->prepare('INSERT IGNORE INTO user_app_access (user_id, app_id)
                          SELECT :uid, id FROM apps WHERE `key` = :key AND status = "active"');
$granted = [];
foreach ($want as $key) {
    if (!in_array($key, ['onepay', 'mypay', 'calendar', 'invoice'], true)) continue;
    $grant->execute(['uid' => (int)$caller['id'], 'key' => $key]);
    $granted[] = $key;
}

// Push the fresh profile (business type + noun) to OnePay now. No-op unless the
// OnePay webhook is configured; never throws.
OnePayWebhook::companyProfileSynced($pdo, $companyId);

Response::ok([
    'business_type'          => $businessType,
    'customer_noun_singular' => $nounS,
    'customer_noun_plural'   => $nounP,
    'apps_granted'           => $granted,
]);
