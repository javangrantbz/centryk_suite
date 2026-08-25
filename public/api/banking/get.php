<?php
require_once __DIR__ . '/../../../app/core/Auth.php';
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    Response::error('Unauthorized', 401);
}

$companyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : 0;
if (!$companyId) {
    Response::error('Company ID is required.', 422);
}

$pdo = DB::pdo();
$isPlatformAdmin = !empty($user['is_admin']);

if (!$isPlatformAdmin) {
    $check = $pdo->prepare("
        SELECT id
        FROM company_members
        WHERE company_id = :cid
          AND user_id = :uid
          AND role = 'admin'
          AND status = 'active'
        LIMIT 1
    ");
    $check->execute([
        'cid' => $companyId,
        'uid' => $user['id'],
    ]);
    if (!$check->fetch()) {
        Response::error('Permission denied.', 403);
    }
}

function banking_get_account(PDO $pdo, int $companyId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT bank_name, account_holder, account_number, branch, onelink_synced_at, onelink_sync_error
            FROM company_bank_accounts
            WHERE company_id = :cid
            LIMIT 1
        ");
        $stmt->execute(['cid' => $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("
                SELECT bank_name, account_holder, account_number, branch
                FROM company_bank_accounts
                WHERE company_id = :cid
                LIMIT 1
            ");
            $stmt->execute(['cid' => $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($row) {
                $row['onelink_synced_at'] = null;
                $row['onelink_sync_error'] = null;
            }
            return $row;
        } catch (Throwable $inner) {
            error_log('banking/get account fallback failed: ' . $inner->getMessage());
            return [];
        }
    }
}

function banking_get_gateway(PDO $pdo, int $companyId): ?array
{
    try {
        $stmt = $pdo->prepare("
            SELECT base_url, terminal_id, salt, token, access_code, enabled, provision_error
            FROM onelink_credentials
            WHERE company_id = :cid
            LIMIT 1
        ");
        $stmt->execute(['cid' => $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("
                SELECT base_url, terminal_id, salt, token, enabled
                FROM onelink_credentials
                WHERE company_id = :cid
                LIMIT 1
            ");
            $stmt->execute(['cid' => $companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                $row['access_code'] = '';
                $row['provision_error'] = '';
            }
            return $row;
        } catch (Throwable $inner) {
            error_log('banking/get gateway fallback failed: ' . $inner->getMessage());
            return null;
        }
    }
}

function banking_get_last_request_status(PDO $pdo, int $companyId)
{
    try {
        $stmt = $pdo->prepare("
            SELECT status
            FROM banking_requests
            WHERE company_id = :cid
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['cid' => $companyId]);
        return $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('banking/get request status fallback failed: ' . $e->getMessage());
        return false;
    }
}

try {
    $acct = banking_get_account($pdo, $companyId);
    $gw = banking_get_gateway($pdo, $companyId);

    $gateway = [
        'configured' => (bool) $gw,
        'enabled' => $gw ? (bool) (int) ($gw['enabled'] ?? 0) : false,
    ];

    if ($isPlatformAdmin) {
        $gateway['base_url'] = $gw['base_url'] ?? 'https://op.onelink.bz';
        $gateway['terminal_id'] = $gw['terminal_id'] ?? '';
        $gateway['salt_set'] = $gw ? (($gw['salt'] ?? '') !== '') : false;
        $gateway['token_set'] = $gw ? (($gw['token'] ?? '') !== '') : false;
    }

    if (!empty($gateway['enabled']) && !empty($gw['access_code'])) {
        $gateway['access_code'] = $gw['access_code'];
        $gateway['onelink_login_url'] = 'https://op.onelink.bz/docs.php';
    }

    if (empty($gateway['enabled']) && !empty($gw['provision_error'])) {
        $gateway['provision_error'] = $gw['provision_error'];
    }

    $wantsOnelink = true;
    if (empty($gateway['enabled'])) {
        $lastReq = banking_get_last_request_status($pdo, $companyId);
        if ($lastReq === 'dismissed') {
            $wantsOnelink = false;
        }
    }

    Response::ok([
        'is_platform_admin' => $isPlatformAdmin,
        'wants_onelink' => $wantsOnelink,
        'account' => [
            'bank_name' => (string) ($acct['bank_name'] ?? ''),
            'account_holder' => (string) ($acct['account_holder'] ?? ''),
            'account_number' => (string) ($acct['account_number'] ?? ''),
            'branch' => (string) ($acct['branch'] ?? ''),
            'onelink_synced_at' => $acct['onelink_synced_at'] ?? null,
            'onelink_sync_error' => $acct['onelink_sync_error'] ?? null,
        ],
        'gateway' => $gateway,
    ]);
} catch (Throwable $e) {
    error_log('banking/get fatal for company ' . $companyId . ': ' . $e->getMessage());
    Response::ok([
        'is_platform_admin' => $isPlatformAdmin,
        'wants_onelink' => true,
        'account' => [
            'bank_name' => '',
            'account_holder' => '',
            'account_number' => '',
            'branch' => '',
            'onelink_synced_at' => null,
            'onelink_sync_error' => null,
        ],
        'gateway' => [
            'configured' => false,
            'enabled' => false,
        ],
    ]);
}
