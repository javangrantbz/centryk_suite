<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/services/MailerService.php';
require_once __DIR__ . '/../../../app/services/MyPayWebhook.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body        = json_decode(file_get_contents('php://input'), true);
$email       = strtolower(trim($body['email'] ?? ''));
$firstName   = trim($body['first_name'] ?? '');
$companyName = trim($body['company_name'] ?? '');

if ($email === '' || $firstName === '' || $companyName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email, first name, and company name are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (strlen($firstName) > 80) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'First name is too long.']);
    exit;
}

try {
    $pdo = DB::pdo();

    // Check if already registered
    $existing = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $existing->execute(['email' => $email]);
    if ($existing->fetch()) {
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists. Please log in.']);
        exit;
    }

    // Generate temp password
    $chars   = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $tempPass = '';
    $arr = random_bytes(10);
    for ($i = 0; $i < 10; $i++) {
        $tempPass .= $chars[ord($arr[$i]) % strlen($chars)];
    }

    $pdo->beginTransaction();

    // Create user
    $pdo->prepare("
        INSERT INTO users (first_name, last_name, email, password_hash, status)
        VALUES (:first_name, '', :email, :hash, 'active')
    ")->execute([
        'first_name' => $firstName,
        'email'      => $email,
        'hash'       => password_hash($tempPass, PASSWORD_DEFAULT),
    ]);
    $userId = (int)$pdo->lastInsertId();

    // Grant all active apps
    $apps = $pdo->query("SELECT id FROM apps WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($apps as $app) {
        $pdo->prepare("INSERT IGNORE INTO user_app_access (user_id, app_id) VALUES (:uid, :aid)")
            ->execute(['uid' => $userId, 'aid' => $app['id']]);
    }

    // Create company and add user as admin
    $uuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    $pdo->prepare('INSERT INTO companies (uuid, name, owner_id) VALUES (:uuid, :name, :owner_id)')
        ->execute(['uuid' => $uuid, 'name' => $companyName, 'owner_id' => $userId]);
    $companyId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO company_members (company_id, user_id, role) VALUES (:cid, :uid, "admin")')
        ->execute(['cid' => $companyId, 'uid' => $userId]);

    $pdo->commit();

    // Real-time roster sync to MyPay (fire-and-forget; never blocks signup).
    MyPayWebhook::memberSynced($pdo, $companyId, $userId, 'centryk', 'added');

    // Send welcome email
    $config   = require __DIR__ . '/../../../app/config/mail.php';
    $loginUrl = $config['app_url'];

    $html = '
<div style="font-family:Arial,sans-serif;line-height:1.7;color:#0f172a;max-width:580px">
  <h2 style="margin:0 0 16px;font-size:22px">Welcome to Centryk, ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '!</h2>
  <p style="margin:0 0 12px">Your account is ready. Here are your login details:</p>
  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin:0 0 20px">
    <p style="margin:0 0 6px"><strong>Login URL:</strong> <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#1d4ed8">' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '</a></p>
    <p style="margin:0 0 6px"><strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>
    <p style="margin:0"><strong>Temporary password:</strong> <span style="font-family:monospace;background:#e2e8f0;padding:2px 6px;border-radius:4px">' . htmlspecialchars($tempPass, ENT_QUOTES, 'UTF-8') . '</span></p>
  </div>
  <p style="margin:0 0 12px">Once you log in you can access <strong>OnePay</strong> (inventory &amp; POS) and <strong>MyPay</strong> (HR &amp; payroll) from your dashboard.</p>
  <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"
     style="display:inline-block;background:#0f172a;color:#fff;font-weight:bold;padding:14px 32px;border-radius:10px;text-decoration:none;font-size:15px">
    Sign in to Centryk →
  </a>
  <p style="margin:20px 0 0;font-size:12px;color:#94a3b8">Please change your password after your first login.</p>
</div>';

    $text = "Welcome to Centryk, {$firstName}!\n\n"
        . "Your account is ready.\n\n"
        . "Login URL: {$loginUrl}\n"
        . "Email: {$email}\n"
        . "Temporary password: {$tempPass}\n\n"
        . "Please change your password after your first login.\n";

    try {
        $mailer = new MailerService();
        $mailer->send($email, 'Welcome to Centryk — your account is ready', $html, $text);
    } catch (Throwable $e) {
        // Email failure doesn't block account creation
    }

    // Notify admin
    try {
        $adminEmail = $config['admin_email'];
        $adminHtml  = '
<div style="font-family:Arial,sans-serif;line-height:1.6;color:#0f172a">
  <h2 style="margin:0 0 12px">New Centryk signup</h2>
  <p style="margin:0 0 8px"><strong>Name:</strong> ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') . '</p>
  <p style="margin:0 0 8px"><strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>
  <p style="margin:0 0 8px"><strong>Signed up:</strong> ' . date('Y-m-d H:i:s') . '</p>
  <p style="margin:12px 0 0;color:#475569;font-size:13px">Account was auto-provisioned with access to all active apps.</p>
</div>';
        $mailer->send($adminEmail, 'New Centryk signup — ' . $firstName . ' (' . $email . ')', $adminHtml);
    } catch (Throwable $e) {
        // Non-fatal
    }

    echo json_encode([
        'success' => true,
        'message' => "Your account is ready! Check your email ({$email}) for your login details.",
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
