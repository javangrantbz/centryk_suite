<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';
require_once __DIR__ . '/MailerService.php';

/**
 * Password reset flow for Centryk accounts.
 *
 * This is the source of truth for credentials: every login form (Centryk,
 * OnePay, MyPay) authenticates against centryk_core.users.password_hash, so a
 * reset MUST be performed here — not in an individual app's database.
 */
class PasswordResetService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DB::pdo();
    }

    /**
     * Create a reset token for an email and (optionally) email the link.
     *
     * @param bool $sendEmail When false, the email is not sent and the reset
     *                        link is returned instead (used by apps for staff
     *                        with placeholder @centryk.com addresses that have
     *                        no real inbox — the admin relays the link).
     * @return array{created: bool, reset_link?: string}
     */
    public function createReset(string $email, bool $sendEmail = true): array
    {
        $email = strtolower(trim($email));

        $stmt = $this->db->prepare(
            'SELECT id, first_name, email FROM users WHERE email = :email AND status = "active" LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        // Generic response — never reveal whether the email exists.
        if (!$user) {
            return ['created' => false];
        }

        // Invalidate any previous pending tokens for this user.
        $this->db->prepare(
            "UPDATE password_resets SET status = 'used', used_at = NOW()
             WHERE user_id = :uid AND status = 'pending'"
        )->execute(['uid' => $user['id']]);

        $token = bin2hex(random_bytes(32));
        $this->db->prepare(
            "INSERT INTO password_resets (user_id, email, token, status, expires_at)
             VALUES (:uid, :email, :token, 'pending', DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
        )->execute([
            'uid'   => $user['id'],
            'email' => $email,
            'token' => $token,
        ]);

        $config    = require __DIR__ . '/../config/mail.php';
        $base      = rtrim($config['app_url'], '/');
        $resetLink = $base . '/reset-password.php?token=' . $token;

        if ($sendEmail) {
            $greeting = htmlspecialchars($user['first_name'] ?: 'there', ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

            $html = '
<div style="font-family:Arial,sans-serif;line-height:1.7;color:#0f172a;max-width:560px">
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:20px">
    <span style="font-size:20px;font-weight:800;color:#0f172a">Centryk</span>
  </div>
  <h2 style="margin:0 0 16px;font-size:21px">Reset your Centryk password</h2>
  <p style="margin:0 0 12px">Hi ' . $greeting . ',</p>
  <p style="margin:0 0 16px">We received a request to reset the password for your Centryk account.
     Click the button below to choose a new password.</p>
  <p style="margin:0 0 24px">This link expires in <strong>30 minutes</strong> and can only be used once.</p>
  <a href="' . $safeLink . '"
     style="display:inline-block;background:#0f172a;color:#fff;font-weight:bold;padding:14px 32px;border-radius:10px;text-decoration:none;font-size:15px">
    Reset Password &rarr;
  </a>
  <p style="margin:24px 0 8px;font-size:12px;color:#64748b">
    If you did not request this, you can safely ignore this email — your password will not change.
  </p>
</div>';

            $text = "Reset your Centryk password\n\n"
                . "Hi {$greeting},\n\n"
                . "We received a request to reset the password for your Centryk account.\n"
                . "Open the link below to choose a new password (expires in 30 minutes):\n{$resetLink}\n\n"
                . "If you did not request this, you can safely ignore this email.\n";

            try {
                (new MailerService())->send($email, 'Reset your Centryk password', $html, $text);
            } catch (Throwable $e) {
                // Don't leak mail errors to the caller.
            }
        }

        try {
            Audit::log([
                'actor_user_id'  => (int)$user['id'],
                'target_user_id' => (int)$user['id'],
                'event_type'     => 'auth.password.reset_requested',
                'summary'        => 'Requested a password reset',
            ]);
        } catch (Throwable $e) { /* audit is best-effort */ }

        return ['created' => true, 'reset_link' => $resetLink];
    }

    /**
     * Look up a pending, unexpired token. Returns ['email','first_name'] or null.
     */
    public function validateToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT pr.email, u.first_name
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token = :token AND pr.status = 'pending' AND pr.expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute(['token' => $token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Consume a token and set the new password on centryk_core.users.
     */
    public function reset(string $token, string $password): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        $stmt = $this->db->prepare(
            "SELECT id, user_id FROM password_resets
             WHERE token = :token AND status = 'pending' AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute(['token' => $token]);
        $reset = $stmt->fetch();

        if (!$reset) {
            throw new InvalidArgumentException('This reset link is invalid or has expired.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute([
                    'hash' => password_hash($password, PASSWORD_BCRYPT),
                    'id'   => $reset['user_id'],
                ]);
            $this->db->prepare("UPDATE password_resets SET status = 'used', used_at = NOW() WHERE id = :id")
                ->execute(['id' => $reset['id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        try {
            Audit::log([
                'actor_user_id'  => (int)$reset['user_id'],
                'target_user_id' => (int)$reset['user_id'],
                'event_type'     => 'auth.password.reset_completed',
                'summary'        => 'Completed a password reset',
            ]);
        } catch (Throwable $e) { /* best-effort */ }
    }
}
