<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../app/services/MailerService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body        = json_decode(file_get_contents('php://input'), true);
$yourName    = trim($body['your_name']    ?? '');
$yourEmail   = strtolower(trim($body['your_email']   ?? ''));
$bizName     = trim($body['biz_name']     ?? '');
$industry    = trim($body['industry']     ?? '');
$contactName = trim($body['contact_name'] ?? '');
$contactInfo = trim($body['contact_info'] ?? '');
$apps        = is_array($body['apps'] ?? null) ? $body['apps'] : [];
$notes       = trim($body['notes']        ?? '');

if ($yourName === '' || $yourEmail === '' || $bizName === '' || $contactInfo === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Your name, your email, business name, and contact info are required.']);
    exit;
}

if (!filter_var($yourEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$industryLabels = [
    'retail'        => 'Retail / Grocery',
    'restaurant'    => 'Restaurant / Food & Beverage',
    'pharmacy'      => 'Pharmacy',
    'hospitality'   => 'Hotel / Hospitality',
    'construction'  => 'Construction / Trades',
    'services'      => 'Professional Services',
    'other'         => 'Other',
];
$industryLabel = $industryLabels[$industry] ?? $industry;
$appsLabel     = !empty($apps) ? implode(', ', array_map('htmlspecialchars', $apps)) : 'Not specified';

try {
    $config     = require __DIR__ . '/../../../app/config/mail.php';
    $adminEmail = $config['admin_email'];

    $html = '
<div style="font-family:Arial,sans-serif;line-height:1.7;color:#0f172a;max-width:580px">
  <h2 style="margin:0 0 16px;font-size:20px">New Business Referral — Centryk</h2>

  <p style="margin:0 0 10px;font-size:13px;color:#64748b;font-weight:bold;text-transform:uppercase;letter-spacing:0.05em">Referred By</p>
  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin:0 0 16px">
    <p style="margin:0 0 4px"><strong>Name:</strong> '  . htmlspecialchars($yourName,  ENT_QUOTES, 'UTF-8') . '</p>
    <p style="margin:0"><strong>Email:</strong> '        . htmlspecialchars($yourEmail, ENT_QUOTES, 'UTF-8') . '</p>
  </div>

  <p style="margin:0 0 10px;font-size:13px;color:#64748b;font-weight:bold;text-transform:uppercase;letter-spacing:0.05em">Business Being Referred</p>
  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin:0 0 16px">
    <p style="margin:0 0 4px"><strong>Business Name:</strong> '  . htmlspecialchars($bizName,     ENT_QUOTES, 'UTF-8') . '</p>
    <p style="margin:0 0 4px"><strong>Industry:</strong> '       . htmlspecialchars($industryLabel, ENT_QUOTES, 'UTF-8') . '</p>
    <p style="margin:0 0 4px"><strong>Contact Person:</strong> ' . htmlspecialchars($contactName ?: '—', ENT_QUOTES, 'UTF-8') . '</p>
    <p style="margin:0 0 4px"><strong>Contact Info:</strong> '   . htmlspecialchars($contactInfo,  ENT_QUOTES, 'UTF-8') . '</p>
    <p style="margin:0"><strong>Apps Needed:</strong> '          . htmlspecialchars($appsLabel,    ENT_QUOTES, 'UTF-8') . '</p>
  </div>

  ' . ($notes !== '' ? '
  <p style="margin:0 0 10px;font-size:13px;color:#64748b;font-weight:bold;text-transform:uppercase;letter-spacing:0.05em">Additional Notes</p>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin:0 0 16px">
    <p style="margin:0;white-space:pre-wrap">' . htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') . '</p>
  </div>' : '') . '

  <p style="margin:16px 0 0;font-size:12px;color:#94a3b8">Submitted ' . date('Y-m-d H:i:s') . ' via Centryk referral form</p>
</div>';

    $mailer = new MailerService();
    $mailer->send(
        $adminEmail,
        '[Centryk Referral] ' . $bizName . ' — referred by ' . $yourName,
        $html,
        "Referred by: {$yourName} ({$yourEmail})\n\nBusiness: {$bizName}\nIndustry: {$industryLabel}\nContact: {$contactName}\nContact Info: {$contactInfo}\nApps: {$appsLabel}\nNotes: {$notes}"
    );

    // Thank the referrer
    $replyHtml = '
<div style="font-family:Arial,sans-serif;line-height:1.7;color:#0f172a;max-width:580px">
  <h2 style="margin:0 0 12px;font-size:20px">Thanks for the referral, ' . htmlspecialchars($yourName, ENT_QUOTES, 'UTF-8') . '!</h2>
  <p style="margin:0 0 12px">We\'ve received your referral for <strong>' . htmlspecialchars($bizName, ENT_QUOTES, 'UTF-8') . '</strong> and our team will reach out to them shortly.</p>
  <p style="margin:0 0 20px;color:#475569">We appreciate you helping grow the Centryk community in Belize.</p>
  <a href="' . htmlspecialchars($config['app_url'], ENT_QUOTES, 'UTF-8') . '"
     style="display:inline-block;background:#0f172a;color:#fff;font-weight:bold;padding:12px 28px;border-radius:10px;text-decoration:none;font-size:14px">
    Visit Centryk →
  </a>
  <p style="margin:20px 0 0;font-size:12px;color:#94a3b8">&copy; ' . date('Y') . ' Centryk. Belize City, Belize.</p>
</div>';

    try {
        $mailer->send($yourEmail, 'Thanks for referring ' . $bizName . ' to Centryk!', $replyHtml);
    } catch (Throwable $e) {
        // Non-fatal
    }

    echo json_encode(['success' => true, 'message' => 'Referral submitted! Thank you for spreading the word.']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
