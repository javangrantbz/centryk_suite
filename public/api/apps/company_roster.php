<?php
/**
 * Server-to-server endpoint: return the roster of people in a Centryk company.
 * Called by spoke apps (MyPay payroll, OnePay, etc.) to pull everyone who
 * belongs to a company — regardless of which app first created them — so each
 * app can build its own list (e.g. who is available for payroll) on top of the
 * shared Centryk identity directory.
 *
 * READ-ONLY. This endpoint never writes to or modifies any data.
 *
 * Requires the shared PROVISION_SECRET set in Centryk's .env (same secret used
 * by provision_user.php / user_status.php).
 *
 * Expected request:
 *   POST /api/apps/company_roster.php
 *   Body: {
 *     "provision_secret": "<shared secret>",
 *     "company_uuid":     "<company uuid>",
 *     "include_inactive":  false           // optional; default false
 *   }
 *
 * Response:
 *   {
 *     "success": true,
 *     "company": { "uuid": "...", "name": "..." },
 *     "members": [
 *       {
 *         "user_id":        12,
 *         "uuid":           "....",
 *         "first_name":     "Jane",
 *         "last_name":      "Doe",
 *         "email":          "jane@example.com",
 *         "phone":          null,
 *         "role":           "employee",      // company role
 *         "member_status":  "active",        // company_members.status
 *         "account_status": "active",        // users.status
 *         "joined_at":      "2026-06-01 09:12:00",
 *         "apps":           ["onepay", "mypay"]
 *       }
 *     ]
 *   }
 */
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$secret      = trim((string)($body['provision_secret'] ?? ''));
$companyUuid = trim((string)($body['company_uuid'] ?? ''));
$includeInactive = filter_var($body['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);

$expected = $_ENV['PROVISION_SECRET'] ?? '';
if ($expected === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}

if ($companyUuid === '') {
    Response::error('company_uuid is required.');
}

$pdo = DB::pdo();

// Resolve the company (must be active).
$companyStmt = $pdo->prepare('SELECT id, uuid, name FROM companies WHERE uuid = :uuid AND status = "active" LIMIT 1');
$companyStmt->execute(['uuid' => $companyUuid]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    Response::error('Unknown company.', 404);
}
$companyId = (int)$company['id'];

// Pull the member roster. Default to active memberships only so callers don't
// pick up terminated staff unless they explicitly ask for them.
$memberSql = '
    SELECT u.id, u.uuid, u.first_name, u.last_name, u.email, u.phone,
           u.status   AS account_status,
           cm.role,
           cm.status  AS member_status,
           cm.created_at AS joined_at
    FROM company_members cm
    JOIN users u ON u.id = cm.user_id
    WHERE cm.company_id = :cid
';
if (!$includeInactive) {
    $memberSql .= ' AND cm.status = "active"';
}
$memberSql .= ' ORDER BY FIELD(cm.role, "admin", "manager", "employee"), u.first_name ASC, u.last_name ASC';

$memberStmt = $pdo->prepare($memberSql);
$memberStmt->execute(['cid' => $companyId]);
$rows = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch app enrolments for all members in one query (avoids N+1).
$appsByUser = [];
$userIds = array_map(static fn ($r) => (int)$r['id'], $rows);
if ($userIds !== []) {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $appStmt = $pdo->prepare(
        'SELECT uaa.user_id, a.`key`
         FROM user_app_access uaa
         JOIN apps a ON a.id = uaa.app_id
         WHERE a.status = "active" AND uaa.user_id IN (' . $placeholders . ')'
    );
    $appStmt->execute($userIds);
    foreach ($appStmt->fetchAll(PDO::FETCH_ASSOC) as $accessRow) {
        $appsByUser[(int)$accessRow['user_id']][] = $accessRow['key'];
    }
}

$members = array_map(static function (array $r) use ($appsByUser) {
    $uid = (int)$r['id'];
    return [
        'user_id'        => $uid,
        'uuid'           => $r['uuid'],
        'first_name'     => $r['first_name'],
        'last_name'      => $r['last_name'],
        'email'          => $r['email'],
        'phone'          => $r['phone'],
        'role'           => $r['role'],
        'member_status'  => $r['member_status'],
        'account_status' => $r['account_status'],
        'joined_at'      => $r['joined_at'],
        'apps'           => $appsByUser[$uid] ?? [],
    ];
}, $rows);

Response::ok([
    'company' => [
        'uuid' => $company['uuid'],
        'name' => $company['name'],
    ],
    'members' => $members,
]);
