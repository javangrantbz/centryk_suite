<?php

require_once __DIR__ . '/../core/Auth.php';

/**
 * Server-side session proxy into MyPay, for the mobile hub's HR Requests
 * view. MyPay's leave/time-off APIs only accept MyPay's own browser
 * session - there's no other server-to-server door into them - so this
 * logs itself in on the user's behalf (via a token minted right here in
 * Centryk, redeemed against MyPay's own sso.php exactly as a real browser
 * would) and holds the resulting session cookie to replay on every
 * subsequent call. Nothing in MyPay's code changes for this to work.
 *
 * Unlike the standalone-app version of this class, minting the token is a
 * plain in-process call to Auth::issueToken() - no HTTP round-trip or
 * shared secret needed, since this now lives in the same codebase as the
 * thing that issues the token.
 */
class MyPayClient
{
    private const SESSION_COOKIE_NAME = 'PHPSESSID';

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim($_ENV['MYPAY_API_URL'] ?? 'http://localhost/myPay', '/');
    }

    /** Logs into MyPay as this Centryk user, returns the session cookie value to hold, or null on failure. */
    public function login(int $centrykUserId): ?string
    {
        $token = Auth::issueToken($centrykUserId, 'mypay');

        $ch = curl_init($this->baseUrl . '/sso.php?sso_token=' . urlencode($token));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw === false) {
            return null;
        }

        if (preg_match('/^Set-Cookie:\s*' . preg_quote(self::SESSION_COOKIE_NAME, '/') . '=([^;]+)/mi', $raw, $m)) {
            return $m[1];
        }

        return null;
    }

    /** All statuses - callers filter to 'pending' for the approval queue. */
    public function listLeaveRequests(string $cookie): ?array
    {
        $data = $this->request('GET', '/api/leave/list.php', $cookie);
        return ($data && !empty($data['success'])) ? $data['requests'] : null;
    }

    public function approveLeaveRequest(string $cookie, int $id, string $notes = ''): array
    {
        return $this->request('POST', '/api/leave/approve.php', $cookie, ['id' => $id, 'notes' => $notes])
            ?? ['success' => false, 'message' => 'MyPay is unreachable right now.'];
    }

    public function rejectLeaveRequest(string $cookie, int $id, string $reason = ''): array
    {
        return $this->request('POST', '/api/leave/reject.php', $cookie, ['id' => $id, 'reason' => $reason])
            ?? ['success' => false, 'message' => 'MyPay is unreachable right now.'];
    }

    private function request(string $method, string $path, string $cookie, array $formData = []): ?array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . self::SESSION_COOKIE_NAME . '=' . $cookie]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($formData));
        }
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
