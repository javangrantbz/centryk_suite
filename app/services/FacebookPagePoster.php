<?php

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/FormsService.php';

/**
 * Per-company Facebook Page connection for Centryk Forms, and posting an
 * approved review to that Page through the Graph API.
 *
 * Each company connects its own Page (Page ID + a Page access token that has
 * pages_manage_posts). Nothing here is global or tied to a particular company.
 * The token is stored server-side and never sent back to the browser.
 */
class FacebookPagePoster
{
    private const GRAPH = 'https://graph.facebook.com/v21.0';

    /** Approved reviews a company may push to its Page per hour (keeps the Page from being flooded). */
    private const MAX_POSTS_PER_HOUR = 12;

    /** Public view of the connection (never includes the token). */
    public static function connection(int $companyId): ?array
    {
        $st = DB::pdo()->prepare("
            SELECT page_id, page_name, updated_at FROM form_facebook_pages WHERE company_id = :cid
        ");
        $st->execute(['cid' => $companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Validate the Page ID + token with Facebook, then save. Returns the public
     * connection. Throws RuntimeException with a message the admin can act on.
     */
    public static function connect(int $companyId, int $userId, string $pageId, string $token): array
    {
        $pageId = trim($pageId);
        $token = trim($token);
        if (!preg_match('/^\d{5,30}$/', $pageId)) {
            throw new RuntimeException('The Page ID should be a number (find it under Page settings → Page transparency).');
        }
        if ($token === '') {
            throw new RuntimeException('A Page access token is required.');
        }

        // Ask "who does this token belong to?" rather than reading the Page by ID:
        // /me works for any valid token, while reading /<page-id> needs extra
        // permissions (pages_read_engagement) that posting itself doesn't.
        $res = self::graph('GET', '/me', ['fields' => 'id,name', 'access_token' => $token]);
        if (empty($res['id'])) {
            throw new RuntimeException('Facebook did not accept that token: ' . ($res['error'] ?? 'no details returned') . '.');
        }
        if ((string)$res['id'] !== $pageId) {
            throw new RuntimeException(
                'That token belongs to "' . ($res['name'] ?? 'someone else') . '" (ID ' . $res['id'] . '), not the Page ID you entered. '
                . 'You probably pasted your personal user token. Run GET /me/accounts in the Graph API Explorer and use the access_token '
                . 'listed for your Page (its id is the Page ID).'
            );
        }
        if (empty($res['name'])) {
            $res['name'] = $pageId;
        }

        DB::pdo()->prepare("
            INSERT INTO form_facebook_pages (company_id, page_id, page_name, access_token, connected_by)
            VALUES (:cid, :pid, :name, :tok, :uid)
            ON DUPLICATE KEY UPDATE page_id = VALUES(page_id), page_name = VALUES(page_name),
                access_token = VALUES(access_token), connected_by = VALUES(connected_by)
        ")->execute([
            'cid' => $companyId, 'pid' => $pageId, 'name' => mb_substr((string)$res['name'], 0, 200),
            'tok' => $token, 'uid' => $userId,
        ]);

        return self::connection($companyId);
    }

    public static function disconnect(int $companyId): void
    {
        DB::pdo()->prepare("DELETE FROM form_facebook_pages WHERE company_id = :cid")
            ->execute(['cid' => $companyId]);
    }

    /**
     * Post an approved review to the company's Page and record the result on the
     * response. Never throws: the outcome is returned so the moderator's approve
     * still stands even when Facebook is down or the token has expired.
     *
     * @return array{posted:bool, message:string}
     */
    public static function postReview(int $companyId, int $responseId, string $shareUrl): array
    {
        $st = DB::pdo()->prepare("SELECT page_id, access_token FROM form_facebook_pages WHERE company_id = :cid");
        $st->execute(['cid' => $companyId]);
        $page = $st->fetch(PDO::FETCH_ASSOC);
        if (!$page) {
            return ['posted' => false, 'message' => 'No Facebook Page connected. Copy the text below to post it manually.'];
        }

        $r = DB::pdo()->prepare("
            SELECT r.post_text, r.moderation_status, r.fb_post_id
            FROM form_responses r JOIN form_forms f ON f.id = r.form_id
            WHERE r.id = :id AND f.company_id = :cid
        ");
        $r->execute(['id' => $responseId, 'cid' => $companyId]);
        $resp = $r->fetch(PDO::FETCH_ASSOC);
        if (!$resp || $resp['moderation_status'] !== 'approved' || trim((string)$resp['post_text']) === '') {
            return ['posted' => false, 'message' => 'Only approved reviews can be posted.'];
        }
        if (!empty($resp['fb_post_id'])) {
            return ['posted' => true, 'message' => 'Already posted to Facebook.'];
        }

        $recent = DB::pdo()->prepare("
            SELECT COUNT(*) FROM form_responses r JOIN form_forms f ON f.id = r.form_id
            WHERE f.company_id = :cid AND r.fb_posted_at > (NOW() - INTERVAL 1 HOUR)
        ");
        $recent->execute(['cid' => $companyId]);
        if ((int)$recent->fetchColumn() >= self::MAX_POSTS_PER_HOUR) {
            $msg = 'Hourly posting limit reached (' . self::MAX_POSTS_PER_HOUR . '). Use "Post now" in a little while.';
            FormsService::recordFacebookResult($responseId, null, $msg);
            return ['posted' => false, 'message' => $msg];
        }

        $res = self::graph('POST', '/' . $page['page_id'] . '/feed', [
            'message'      => $resp['post_text'],
            'link'         => $shareUrl,
            'access_token' => $page['access_token'],
        ]);

        if (!empty($res['id'])) {
            FormsService::recordFacebookResult($responseId, (string)$res['id'], null);
            return ['posted' => true, 'message' => 'Posted to your Facebook Page.'];
        }
        $err = 'Facebook said: ' . ($res['error'] ?? 'unknown error');
        FormsService::recordFacebookResult($responseId, null, $err);
        return ['posted' => false, 'message' => $err];
    }

    /**
     * Minimal Graph API call. Returns the decoded JSON; on failure returns
     * ['error' => '<message>'] so callers never deal with curl details.
     */
    private static function graph(string $method, string $path, array $params): array
    {
        $url = self::GRAPH . $path;
        $ch = curl_init();
        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
        } else {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['error' => 'could not reach Facebook (' . $curlErr . ')'];
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return ['error' => 'unexpected response from Facebook'];
        }
        if (isset($json['error'])) {
            return ['error' => (string)($json['error']['message'] ?? 'request rejected')];
        }
        return $json;
    }
}
