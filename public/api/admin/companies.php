<?php
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

require_admin();

$pdo = DB::pdo();

try {
    $stmt = $pdo->query("
        SELECT
            c.id,
            c.uuid,
            c.name,
            c.logo,
            c.status,
            c.created_at,
            owner.id AS admin_id,
            owner.first_name AS admin_first_name,
            owner.last_name AS admin_last_name,
            owner.email AS admin_email,
            owner.last_login_at AS admin_last_login_at,
            owner.status AS admin_status,
            COUNT(DISTINCT CASE WHEN cm.status = 'active' THEN cm.user_id END) AS employee_count,
            CASE
                WHEN owner.last_login_at IS NOT NULL
                 AND owner.last_login_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
                THEN 1
                ELSE 0
            END AS is_active_recently
        FROM companies c
        JOIN users owner ON owner.id = c.owner_id
        LEFT JOIN company_members cm ON cm.company_id = c.id
        GROUP BY
            c.id, c.uuid, c.name, c.logo, c.status, c.created_at,
            owner.id, owner.first_name, owner.last_name, owner.email,
            owner.last_login_at, owner.status
        ORDER BY c.created_at DESC, c.id DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    Response::error('Company registry is not available. Check that the company tables and profile fields are migrated.', 500);
}

$companies = array_map(static function (array $row): array {
    $adminName = trim(($row['admin_first_name'] ?? '') . ' ' . ($row['admin_last_name'] ?? ''));
    $isActive = (bool)((int)($row['is_active_recently'] ?? 0));

    return [
        'id' => (int)$row['id'],
        'uuid' => $row['uuid'],
        'name' => $row['name'],
        'logo' => $row['logo'] ?: null,
        'status' => $row['status'],
        'registered_at' => $row['created_at'],
        'employee_count' => (int)($row['employee_count'] ?? 0),
        'admin' => [
            'id' => (int)$row['admin_id'],
            'name' => $adminName !== '' ? $adminName : 'Unnamed admin',
            'email' => $row['admin_email'],
            'status' => $row['admin_status'],
            'last_login_at' => $row['admin_last_login_at'] ?: null,
        ],
        'activity' => [
            'status' => $isActive ? 'active' : 'inactive',
            'message' => $isActive ? null : 'No active session for a month',
        ],
    ];
}, $rows);

$activeCompanies = array_filter($companies, static fn (array $company): bool => $company['activity']['status'] === 'active');
$employeeTotal = array_reduce($companies, static fn (int $carry, array $company): int => $carry + $company['employee_count'], 0);

Response::ok([
    'companies' => $companies,
    'stats' => [
        'total_companies' => count($companies),
        'active_companies' => count($activeCompanies),
        'inactive_companies' => count($companies) - count($activeCompanies),
        'total_employees' => $employeeTotal,
    ],
]);
