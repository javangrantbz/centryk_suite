<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../app/core/require_admin.php';
require_once __DIR__ . '/../../../app/services/MailerService.php';

require_admin();

$body      = json_decode(file_get_contents('php://input'), true);
$email     = trim((string)($body['email'] ?? ''));
$firstName = trim((string)($body['first_name'] ?? ''));
$company   = trim((string)($body['company'] ?? ''));
$tempPass  = trim((string)($body['temp_password'] ?? ''));

if (!$email) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Email is required.']);
    exit;
}

$config   = require __DIR__ . '/../../../app/config/mail.php';
$loginUrl = $config['app_url'];
$greeting = $firstName ?: 'there';
$biz      = $company ?: 'your business';

$passLine = $tempPass
    ? '<p style="margin:0 0 12px">Your temporary password is: <strong style="font-family:monospace">' . htmlspecialchars($tempPass, ENT_QUOTES, 'UTF-8') . '</strong><br>Please change it after your first login.</p>'
    : '';

$html = '
<div style="font-family:Arial,sans-serif;line-height:1.7;color:#0f172a;max-width:580px">
  <h2 style="margin:0 0 16px;font-size:22px">Welcome to Centryk!</h2>
  <p style="margin:0 0 12px">Hi ' . htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') . ',</p>
  <p style="margin:0 0 12px">Your Centryk account for <strong>' . htmlspecialchars($biz, ENT_QUOTES, 'UTF-8') . '</strong> is ready. You now have access to OnePay and MyPay through a single login.</p>
  ' . $passLine . '
  <p style="margin:0 0 24px">Click the button below to sign in:</p>
  <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '"
     style="display:inline-block;background:#0f172a;color:#fff;font-weight:bold;padding:14px 32px;border-radius:10px;text-decoration:none;font-size:15px">
    Sign in to Centryk →
  </a>
  <p style="margin:24px 0 8px;font-size:12px;color:#64748b">
    Login URL: <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#1d4ed8">' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '</a>
  </p>
  <p style="margin:12px 0 0;font-size:12px;color:#94a3b8">
    Questions? Reply to this email — we\'re happy to help.
  </p>
</div>';

$text = "Welcome to Centryk!\n\nHi {$greeting},\n\nYour Centryk account for {$biz} is ready.\n"
    . ($tempPass ? "Temporary password: {$tempPass} (please change after first login)\n" : '')
    . "\nSign in at: {$loginUrl}\n\nQuestions? Reply to this email.\n";

try {
    $mailer = new MailerService();
    $mailer->send($email, 'Welcome to Centryk — your account is ready', $html, $text);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
