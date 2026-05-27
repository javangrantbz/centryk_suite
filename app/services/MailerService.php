<?php
require_once __DIR__ . '/../core/Env.php';

Env::load(__DIR__ . '/../../.env');

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

class MailerService
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/mail.php';
    }

    public function send(string $to, string $subject, string $html, string $text = ''): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid recipient email: ' . $to);
        }

        $driver = $this->config['default_driver'];

        if ($driver !== 'smtp') {
            return ['status' => 'logged', 'note' => 'MAIL_DRIVER is not smtp — set it in .env to send real emails.'];
        }

        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new RuntimeException('PHPMailer not found. Run: composer require phpmailer/phpmailer');
        }

        $smtp = $this->config['smtp'];
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mailer->CharSet = 'UTF-8';
            $mailer->isHTML(true);
            $mailer->setFrom($this->config['from_email'], $this->config['from_name']);
            $mailer->addAddress($to);
            $mailer->Subject  = $subject;
            $mailer->Body     = $html;
            $mailer->AltBody  = $text ?: trim(strip_tags($html));

            if (!empty($smtp['host'])) {
                $mailer->isSMTP();
                $mailer->Host       = $smtp['host'];
                $mailer->Port       = $smtp['port'];
                $mailer->SMTPAuth   = !empty($smtp['username']);
                if ($mailer->SMTPAuth) {
                    $mailer->Username = $smtp['username'];
                    $mailer->Password = $smtp['password'];
                }
                if (in_array($smtp['encryption'], ['tls', 'ssl'], true)) {
                    $mailer->SMTPSecure = $smtp['encryption'];
                }
            } else {
                $mailer->isMail();
            }

            $mailer->send();
            return ['status' => 'sent'];
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            throw new RuntimeException('Mail send failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
