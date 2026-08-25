<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}

function profile_fetch_app_access(PDO $pdo, string $appKey, string $email, string $secret): array
{
    if ($secret === '' || $email === '') {
        return [];
    }

    $stmt = $pdo->prepare("SELECT url_production FROM apps WHERE `key` = :k LIMIT 1");
    $stmt->execute(['k' => $appKey]);
    $url = (string)($stmt->fetchColumn() ?: '');
    if ($url === '') {
        return [];
    }

    $base = preg_replace('#/[^/]*$#', '', rtrim($url, '/'));
    $ch = curl_init($base . '/api/account/access.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['secret' => $secret, 'email' => $email]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 4,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) {
        return [];
    }

    $data = json_decode($res, true);
    return (is_array($data) && !empty($data['rows'])) ? $data['rows'] : [];
}

$pdo = DB::pdo();
$email = (string)($user['email'] ?? '');
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host) === 1;

$onePayStores = [];
$myPayAccess = [];

if ($isLocal) {
    try {
        $s = $pdo->prepare("
            SELECT s.name
            FROM onepay.stores s
            JOIN onepay.store_memberships sm ON sm.store_id = s.id
            JOIN onepay.users u ON u.id = sm.user_id
            WHERE u.email = :email AND sm.status = 'active'
            ORDER BY s.name
        ");
        $s->execute(['email' => $email]);
        $onePayStores = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $onePayStores = [];
    }

    try {
        $s = $pdo->prepare("
            SELECT c.name
            FROM payroll.users u
            JOIN payroll.user_company_assignments uca ON uca.user_id = u.id
            JOIN payroll.companies c ON c.id = uca.company_id
            WHERE u.email = :email
            ORDER BY c.name
        ");
        $s->execute(['email' => $email]);
        $myPayAccess = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $myPayAccess = [];
    }
} else {
    $onePayStores = profile_fetch_app_access($pdo, 'onepay', $email, $_ENV['PROVISION_SECRET'] ?? '');
    $myPayAccess = profile_fetch_app_access($pdo, 'mypay', $email, $_ENV['MYPAY_WEBHOOK_SECRET'] ?? '');
}

Response::ok([
    'app_notes' => [
        'onepay' => !empty($onePayStores)
            ? count($onePayStores) . ' store ' . (count($onePayStores) === 1 ? 'assignment' : 'assignments') . ' found.'
            : 'Access granted. Store assignment is still pending.',
        'mypay' => !empty($myPayAccess)
            ? count($myPayAccess) . ' payroll ' . (count($myPayAccess) === 1 ? 'company' : 'companies') . ' found.'
            : 'Access granted. Payroll company assignment is still pending.',
    ],
]);
