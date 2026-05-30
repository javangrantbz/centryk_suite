<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../app/services/MailerService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true);
$name    = trim($body['name']    ?? '');
$email   = strtolower(trim($body['email']   ?? ''));
$company = trim($body['company'] ?? '');
$subject = trim($body['subject'] ?? 'general');
$message = trim($body['message'] ?? '');

if ($name === '' || $email === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name, email, and message are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (strlen($message) > 5000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Message is too long (max 5000 characters).']);
    exit;
}

$subjectLabels = [
    'general'  => 'General Enquiry',
    'demo'     => 'Request a Demo',
    'onepay'   => 'OnePay Question',
    'mypay'    => 'MyPay Question',
    'onelink'  => 'OneLink / Payments',
    'support'  => 'Technical Support',
    'billing'  => 'Billing',
    'other'    => 'Other',
];
$subjectLabel = $subjectLabels[$subject] ?? 'General Enquiry';

try {
    $config     = require __DIR__ . '/../../../app/config/mail.php';
    $adminEmail = $config['admin_email'];

    $html = '
<div style="font-family:Arial,sans-serif;line-height:1.7;color:#0f172a;max-width:580px">
  <h2 style="margin:0 0 16px;font-size:20px">New Contact Message — Centryk</h2>
  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin:0 0 20px">
    <p style="margin:0 0 6px"><strong>Name:</strong> '    . htmlspecialchars($name,    ENT_QUOTES, 'UTF-8') . '</p>
    <p style="margin:0 0 6px"><strong>Email:</strong> '   . htmlspecialchars($email,   ENT_QUOTES, 'UTF-8') . '</p>
    <p style="margin:0 0 6px"><strong>Company:</strong> ' . htmlspecialchars($company ?: '—', ENT_QUOTES, 'UTF-8') . '</p>
    <p style="margin:0"><strong>Subject:</strong> '       . htmlspecialchars($subjectLabel, ENT_QUOTES, 'UTF-8') . '</p>
  </div>
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px">
    <p style="margin:0 0 8px;font-size:13px;color:#64748b;font-weight:bold;text-transform:uppercase;letter-spacing:0.05em">Message</p>
    <p style="margin:0;white-space:pre-wrap">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
  </div>
  <p style="margin:16px 0 0;font-size:12px;color:#94a3b8">Sent ' . date('Y-m-d H:i:s') . ' via Centryk contact form</p>
</div>';

    $mailer = new MailerService();
    $mailer->send(
        $adminEmail,
        '[Centryk Contact] ' . $subjectLabel . ' — ' . $name,
        $html,
        "Name: {$name}\nEmail: {$email}\nCompany: {$company}\nSubject: {$subjectLabel}\n\nMessage:\n{$message}"
    );

    // Auto-reply to sender
    $replyHtml = '
<div style="font-family:Arial,sans-serif;line-height:1.7;color:#0f172a;max-width:580px">
  <h2 style="margin:0 0 12px;font-size:20px">Thanks for reaching out, ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '!</h2>
  <p style="margin:0 0 12px">We received your message and will get back to you within one business day.</p>
  <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin:0 0 20px">
    <p style="margin:0 0 4px;font-size:12px;color:#64748b;font-weight:bold;text-transform:uppercase;letter-spacing:0.05em">Your message</p>
    <p style="margin:0;font-size:14px;color:#475569;white-space:pre-wrap">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
  </div>
  <p style="margin:0 0 8px">In the meantime, feel free to explore what Centryk has to offer:</p>
  <a href="' . htmlspecialchars($config['app_url'], ENT_QUOTES, 'UTF-8') . '/index.php"
     style="display:inline-block;background:#0f172a;color:#fff;font-weight:bold;padding:12px 28px;border-radius:10px;text-decoration:none;font-size:14px">
    Explore Centryk →
  </a>
  <p style="margin:20px 0 0;font-size:12px;color:#94a3b8">&copy; ' . date('Y') . ' Centryk. Belize City, Belize.</p>
</div>';

    try {
        $mailer->send($email, 'We received your message — Centryk', $replyHtml);
    } catch (Throwable $e) {
        // Auto-reply failure is non-fatal
    }

    echo json_encode(['success' => true, 'message' => 'Message sent! We\'ll be in touch shortly.']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again or email us directly.']);
}
