<?php
/**
 * Customer-facing: a company admin asks about a Centryk Business package from
 * the "Explore more services" page. Creates a lead and notifies every platform
 * admin — it never activates anything.
 *
 * POST { company_id, package_key?, message? }
 *   package_key omitted / "" = a general "tell me more" enquiry.
 */
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/services/NotificationService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    $in = $_POST;
}

$companyId  = (int)($in['company_id'] ?? 0);
$packageKey = trim((string)($in['package_key'] ?? ''));
$message    = trim((string)($in['message'] ?? ''));

if ($companyId <= 0) {
    Response::error('A company is required.', 422);
}
if (mb_strlen($message) > 500) {
    $message = mb_substr($message, 0, 500);
}

$pdo = DB::pdo();

// Requester must be an active admin of the company.
$check = $pdo->prepare("
    SELECT c.name
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.company_id = :cid AND cm.user_id = :uid
      AND cm.role = 'admin' AND cm.status = 'active' AND c.status = 'active'
    LIMIT 1
");
$check->execute(['cid' => $companyId, 'uid' => (int)$user['id']]);
$company = $check->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    Response::error('Only a company admin can request this.', 403);
}

// Validate the package (blank is allowed — a general enquiry).
if ($packageKey !== '') {
    $pkg = $pdo->prepare("SELECT `key`, label FROM business_packages WHERE `key` = :k AND status = 'active' LIMIT 1");
    $pkg->execute(['k' => $packageKey]);
    $pkg = $pkg->fetch(PDO::FETCH_ASSOC);
    if (!$pkg) {
        Response::error('That package is not available.', 422);
    }
} else {
    $pkg = null;
}

// One open request per (company, package) — treat NULL package as its own slot.
$dup = $pdo->prepare("
    SELECT id FROM business_package_requests
    WHERE company_id = :cid
      AND status IN ('pending', 'contacted')
      AND ((:pkey = '' AND package_key IS NULL) OR package_key = :pkey2)
    LIMIT 1
");
$dup->execute(['cid' => $companyId, 'pkey' => $packageKey, 'pkey2' => $packageKey]);
if ($dup->fetch()) {
    Response::ok(['already' => true, 'message' => "You've already asked about this — a Centryk advisor will be in touch."]);
}

$pdo->prepare("
    INSERT INTO business_package_requests (company_id, package_key, requested_by, message)
    VALUES (:cid, :pkey, :uid, :msg)
")->execute([
    'cid'  => $companyId,
    'pkey' => $packageKey !== '' ? $packageKey : null,
    'uid'  => (int)$user['id'],
    'msg'  => $message,
]);

$companyName = (string)($company['name'] ?? 'A company');
$requester   = trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? ''))) ?: 'Someone';
$what        = $pkg ? $pkg['label'] : 'Centryk Business';

$admins = $pdo->query("SELECT id FROM users WHERE is_admin = 1 AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($admins as $adminId) {
    NotificationService::create([
        'user_id'    => (int)$adminId,
        'company_id' => $companyId,
        'app_key'    => 'centryk',
        'type'       => 'business_package_request',
        'title'      => 'Centryk Business enquiry',
        'body'       => $requester . ' at ' . $companyName . ' is interested in ' . $what . '.',
        'url'        => 'admin-business-packages.php',
        'icon'       => 'briefcase',
        'color'      => '#7c3aed',
    ]);
}

Response::ok(['message' => 'Thanks — a Centryk advisor will reach out about ' . $what . '.']);
