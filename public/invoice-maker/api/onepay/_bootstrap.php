<?php
/**
 * Shared bootstrap for OnePay → Invoices server-to-server endpoints.
 * These are NOT session-based (unlike the sibling api/*.php files that use the
 * invoice-maker bootstrap + require_auth). They authenticate with the shared
 * PROVISION_SECRET, exactly like Centryk's public/api/apps/provision_user.php.
 */
require_once __DIR__ . '/../../../../app/core/DB.php';
require_once __DIR__ . '/../../../../app/core/Response.php';

/** Validate method + shared secret, return the decoded JSON body. Exits on failure. */
function onepay_request(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Response::error('Method not allowed.', 405);
    }
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $secret = trim((string)($body['provision_secret'] ?? ''));
    $expected = (string)($_ENV['PROVISION_SECRET'] ?? '');
    if ($expected === '' || !hash_equals($expected, $secret)) {
        Response::error('Unauthorized.', 401);
    }
    return is_array($body) ? $body : [];
}

/** Resolve the Centryk company id from company_uuid (OnePay's companies.centryk_uuid). Exits on failure. */
function onepay_company_id(PDO $pdo, array $body): int
{
    $uuid = trim((string)($body['company_uuid'] ?? ''));
    if ($uuid === '') {
        Response::error('company_uuid is required.');
    }
    $st = $pdo->prepare('SELECT id FROM companies WHERE uuid = :u AND status = "active" LIMIT 1');
    $st->execute(['u' => $uuid]);
    $id = (int)($st->fetchColumn() ?: 0);
    if (!$id) {
        Response::error('Unknown or inactive company.', 404);
    }
    return $id;
}
