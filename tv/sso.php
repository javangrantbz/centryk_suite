<?php
require_once __DIR__ . '/includes/bootstrap.php';

$token = trim((string)($_GET['sso_token'] ?? ''));
if ($token === '') {
    tv_redirect(tv_url());
}

$user = Auth::consumeToken($token, 'tv');
if (!$user) {
    http_response_code(401);
    exit('Invalid or expired SSO token.');
}

Auth::login((int)$user['id']);

if (!empty($_GET['company_uuid'])) {
    $stmt = db()->prepare('SELECT id FROM companies WHERE uuid = :uuid LIMIT 1');
    $stmt->execute(['uuid' => trim((string)$_GET['company_uuid'])]);
    $companyId = (int)($stmt->fetchColumn() ?: 0);
    if ($companyId > 0) {
        $org = db()->prepare('SELECT id FROM tv_organizations WHERE company_id = :company_id LIMIT 1');
        $org->execute(['company_id' => $companyId]);
        $orgId = (int)($org->fetchColumn() ?: 0);
        if ($orgId > 0) {
            $_SESSION['tv_organization_id'] = $orgId;
        }
    }
}

tv_redirect(tv_url('dashboard'));

