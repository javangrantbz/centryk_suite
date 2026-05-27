<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/core/Audit.php';
require_once __DIR__ . '/../../../app/core/DB.php';

$admin = require_admin();

$body       = json_decode(file_get_contents('php://input'), true);
$requestId  = (int)($body['request_id'] ?? 0);
$firstName  = trim((string)($body['first_name'] ?? ''));
$lastName   = trim((string)($body['last_name'] ?? ''));
$email      = strtolower(trim((string)($body['email'] ?? '')));
$tempPass   = (string)($body['temp_password'] ?? '');
$appKeys    = array_filter((array)($body['app_keys'] ?? []));

if (!$requestId || !$firstName || !$email || !$tempPass) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'First name, email, and temporary password are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Invalid email address.']);
    exit;
}

if (strlen($tempPass) < 6) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Temporary password must be at least 6 characters.']);
    exit;
}

$pdo = DB::pdo();

$req = $pdo->prepare('SELECT id, status FROM account_requests WHERE id = :id LIMIT 1');
$req->execute(['id' => $requestId]);
$reqRow = $req->fetch(PDO::FETCH_ASSOC);

if (!$reqRow) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Access request not found.']);
    exit;
}
if ($reqRow['status'] === 'approved') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'This request has already been approved.']);
    exit;
}

$pdo->beginTransaction();

try {
    $hash = password_hash($tempPass, PASSWORD_DEFAULT);

    // Create or update user
    $existing = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $existing->execute(['email' => $email]);
    $existingUser = $existing->fetch(PDO::FETCH_ASSOC);

    if ($existingUser) {
        $userId = (int)$existingUser['id'];
        $pdo->prepare("
            UPDATE users SET first_name = :fn, last_name = :ln, password_hash = :hash, status = 'active'
            WHERE id = :id
        ")->execute(['fn' => $firstName, 'ln' => $lastName ?: null, 'hash' => $hash, 'id' => $userId]);
    } else {
        $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password_hash, status)
            VALUES (:fn, :ln, :email, :hash, 'active')
        ")->execute(['fn' => $firstName, 'ln' => $lastName ?: null, 'email' => $email, 'hash' => $hash]);
        $userId = (int)$pdo->lastInsertId();
    }

    // Grant app access
    if (empty($appKeys)) {
        // Default: grant all active apps
        $appRows = $pdo->query("SELECT id FROM apps WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        $appIds  = array_column($appRows, 'id');
    } else {
        $placeholders = implode(',', array_fill(0, count($appKeys), '?'));
        $appStmt = $pdo->prepare("SELECT id FROM apps WHERE `key` IN ({$placeholders}) AND status = 'active'");
        $appStmt->execute(array_values($appKeys));
        $appIds = array_column($appStmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    }

    foreach ($appIds as $appId) {
        $pdo->prepare("INSERT IGNORE INTO user_app_access (user_id, app_id) VALUES (:uid, :aid)")
            ->execute(['uid' => $userId, 'aid' => $appId]);
    }

    // Mark request approved
    $pdo->prepare("
        UPDATE account_requests
        SET status = 'approved', reviewed_at = NOW(), reviewed_by = :reviewed_by
        WHERE id = :id
    ")->execute(['reviewed_by' => $admin['id'], 'id' => $requestId]);

    $pdo->commit();

    Audit::log([
        'actor_user_id' => $admin['id'],
        'target_user_id' => $userId,
        'event_type' => 'request.provisioned',
        'summary' => 'Provisioned account request #' . $requestId . ' for ' . $email,
        'metadata' => [
            'request_id' => $requestId,
            'email' => $email,
            'apps' => implode(', ', $appKeys ?: ['all active apps']),
        ],
    ]);

    echo json_encode([
        'ok'           => true,
        'user_id'      => $userId,
        'email'        => $email,
        'first_name'   => $firstName,
        'temp_password' => $tempPass,
    ]);

} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
