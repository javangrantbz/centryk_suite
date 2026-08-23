<?php

/**
 * Distinguishes "no capacity right now" from "unauthorized" in
 * authorizePublish() - api/stream/on_publish.php uses this to answer with a
 * 503 (try again / alert ops) instead of a 403 (this key will never work),
 * which matters once there's real monitoring on the ingest endpoint.
 */
class TvStreamCapacityExceededException extends RuntimeException
{
}

class StreamingService
{
    public static function issueStreamKey(int $organizationId, ?int $channelId, ?int $eventId, int $userId): array
    {
        $rawKey = 'ctv_live_' . bin2hex(random_bytes(18));
        $hash = hash('sha256', $rawKey);
        $encrypted = tv_encrypt_secret($rawKey);

        db()->prepare(
            'INSERT INTO tv_stream_keys (
                organization_id, channel_id, event_id, stream_key_prefix, stream_key_hash,
                stream_key_encrypted, last_rotated_at, created_by, created_at, updated_at
             ) VALUES (
                :organization_id, :channel_id, :event_id, :prefix, :stream_key_hash,
                :stream_key_encrypted, NOW(), :created_by, NOW(), NOW()
             )'
        )->execute([
            'organization_id' => $organizationId,
            'channel_id' => $channelId,
            'event_id' => $eventId,
            'prefix' => substr($rawKey, 0, 18),
            'stream_key_hash' => $hash,
            'stream_key_encrypted' => $encrypted,
            'created_by' => $userId,
        ]);

        return [
            'id' => (int)db()->lastInsertId(),
            'raw_key' => $rawKey,
            'hash' => $hash,
        ];
    }

    public static function regenerateChannelStreamKey(int $organizationId, int $channelId, int $userId): array
    {
        db()->prepare(
            'UPDATE tv_stream_keys
             SET revoked_at = NOW(), updated_at = NOW()
             WHERE organization_id = :organization_id
               AND channel_id = :channel_id
               AND event_id IS NULL
               AND revoked_at IS NULL'
        )->execute([
            'organization_id' => $organizationId,
            'channel_id' => $channelId,
        ]);

        $key = self::issueStreamKey($organizationId, $channelId, null, $userId);
        tv_record_audit($organizationId, $userId, 'regenerate_stream_key', 'channel', $channelId, [
            'stream_key_id' => $key['id'],
        ]);

        return $key;
    }

    public static function assignEventStreamKey(int $organizationId, int $channelId, int $eventId, int $userId): array
    {
        db()->prepare(
            'UPDATE tv_stream_keys
             SET revoked_at = NOW(), updated_at = NOW()
             WHERE event_id = :event_id AND revoked_at IS NULL'
        )->execute(['event_id' => $eventId]);

        $key = self::issueStreamKey($organizationId, $channelId, $eventId, $userId);

        db()->prepare(
            'UPDATE tv_events
             SET stream_key_id = :stream_key_id, updated_at = NOW()
             WHERE id = :event_id'
        )->execute([
            'stream_key_id' => $key['id'],
            'event_id' => $eventId,
        ]);

        return $key;
    }

    public static function decryptStreamKey(?string $encrypted): ?string
    {
        return tv_decrypt_secret($encrypted);
    }

    public static function getIngestUrl(?string $custom = null): string
    {
        $url = $custom ?: (string)tv_config('stream_ingest_url');
        return $url !== '' ? $url : 'rtmp://stream.example.com/live';
    }

    public static function generatePlaybackToken(string $resourceId, int $expires): string
    {
        $secret = (string)tv_config('stream_signing_secret');
        if ($secret === '') {
            return '';
        }

        return hash_hmac('sha256', $resourceId . '|' . $expires, $secret);
    }

    public static function getPlaybackUrl(array $event, int $ttl = 300): ?string
    {
        $base = (string)tv_config('stream_playback_base_url');
        if ($base === '') {
            return null;
        }

        $resource = ($event['channel_slug'] ?? 'channel') . '.m3u8';
        $expires = time() + $ttl;
        $token = self::generatePlaybackToken((string)$event['id'], $expires);
        return $base . '/' . rawurlencode($resource) . '?expires=' . $expires . '&token=' . urlencode($token) . '&event=' . (int)$event['id'];
    }

    /**
     * Prefers the real ingest signal (tv_stream_keys.is_publishing, set by
     * authorizePublish()/recordPublishEnded() off the streaming server's own
     * on_publish/on_publish_done callbacks) over the editorial status
     * column, closing the "future integration point" streaming-integration.md
     * names but leaves the mocked mapping as a fallback for events with no
     * stream key yet - keeps the mock driver usable for admin-side UI work
     * with nothing actually streaming.
     *
     * An admin can still mark an event "Live" by hand in the dashboard
     * (services/TvManagementService.php) before anything is actually
     * publishing; without this, that manual claim alone would show viewers
     * a false "live" badge. Once the event has a stream key, only a real
     * publish makes it live - the status column can request it, but the
     * origin has to confirm it.
     */
    public static function getStreamStatus(array $event): string
    {
        $status = (string)($event['status'] ?? 'draft');
        if ($status === 'cancelled') {
            return 'error';
        }

        $streamKeyId = (int)($event['stream_key_id'] ?? 0);
        if ($streamKeyId > 0) {
            $isPublishing = db()->prepare('SELECT is_publishing FROM tv_stream_keys WHERE id = :id LIMIT 1');
            $isPublishing->execute(['id' => $streamKeyId]);
            $publishing = (bool)$isPublishing->fetchColumn();

            if ($publishing) {
                return 'live';
            }
            if ($status === 'live') {
                // Admin marked it live, but the origin hasn't confirmed a
                // real publish yet - don't tell viewers it's live when it isn't.
                return 'connecting';
            }
        }

        return match ($status) {
            'live' => 'live',
            'scheduled' => 'offline',
            'ended' => 'offline',
            default => 'connecting',
        };
    }

    /**
     * Verifies a viewer's playback token the same way the streaming origin
     * should: recompute the expected HMAC and compare in constant time, and
     * reject anything already past its expiry. This is the concrete
     * implementation of the "playback token validation" responsibility
     * docs/streaming-server.md already assigns to the streaming server -
     * exposed here so api/stream/authorize_playback.php (meant to be called
     * via the streaming server's own auth_request/Lua hook on every segment
     * request) and any future origin-side port of this logic share one
     * source of truth for what a valid token actually is.
     */
    public static function verifyPlaybackToken(string $resourceId, int $expires, string $token): bool
    {
        if ($expires < time() || $token === '') {
            return false;
        }
        $expected = self::generatePlaybackToken($resourceId, $expires);
        return $expected !== '' && hash_equals($expected, $token);
    }

    /**
     * Resolves and authorizes an incoming RTMP publish by its raw stream key
     * - the broadcast-side counterpart to getPlaybackUrl(), which only ever
     * authorized the viewer side. Called by api/stream/on_publish.php, which
     * the streaming server's on_publish callback hits for every publish
     * attempt (nginx-rtmp-module / SRS convention: a 2xx response allows the
     * publish, anything else rejects and disconnects the encoder).
     *
     * Marks the key as actively publishing and, if it's bound to a scheduled
     * or draft event, flips that event live - the objective "bytes are
     * actually flowing" signal getStreamStatus() should eventually trust
     * over the editorial status column alone. Returns null (reject) for an
     * unknown or revoked key.
     */
    public static function authorizePublish(string $rawKey, ?string $addr = null): ?array
    {
        $rawKey = trim($rawKey);
        if ($rawKey === '') {
            return null;
        }

        $stmt = db()->prepare(
            'SELECT * FROM tv_stream_keys WHERE stream_key_hash = :hash AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $rawKey)]);
        $key = $stmt->fetch();
        if (!$key) {
            return null;
        }

        // A reconnect from an already-publishing key isn't a NEW slot on the
        // server, so it never needs (and never fails) a fresh capacity check
        // - the check runs once, on the transition into "live".
        if (!$key['is_publishing']) {
            self::assertServerHasCapacity((int)($key['channel_id'] ?? 0));
        }

        db()->prepare(
            'UPDATE tv_stream_keys
                SET is_publishing = 1, last_published_at = NOW(), publish_ended_at = NULL,
                    publish_addr = :addr, updated_at = NOW()
              WHERE id = :id'
        )->execute(['addr' => $addr, 'id' => $key['id']]);

        if (!empty($key['channel_id'])) {
            db()->prepare('UPDATE tv_channels SET is_live = 1 WHERE id = :id')->execute(['id' => $key['channel_id']]);
        }

        if (!empty($key['event_id'])) {
            db()->prepare(
                "UPDATE tv_events SET status = 'live', updated_at = NOW()
                  WHERE id = :id AND status IN ('scheduled', 'draft')"
            )->execute(['id' => $key['event_id']]);
        }

        tv_record_audit((int)$key['organization_id'], null, 'stream_publish_started', 'stream_key', (int)$key['id'], [
            'channel_id' => $key['channel_id'],
            'event_id' => $key['event_id'],
            'addr' => $addr,
        ]);

        return $key;
    }

    /**
     * Rejects a new publish if the channel's streaming server is already at
     * its configured capacity - tv_stream_servers.capacity existed in the
     * schema with nothing anywhere enforcing it. A no-op (never throws) once
     * the server row has no capacity limit set, or when there's no single
     * server to check against yet.
     *
     * docs/streaming-server.md recommends starting with exactly one active
     * server row; most channels won't have stream_server_id explicitly set
     * yet, so an unassigned channel and the single active server are treated
     * as the same pool for counting current live streams. Once channels are
     * explicitly pinned to specific servers (multi-server rollout), this
     * still resolves correctly per-channel.
     */
    private static function assertServerHasCapacity(int $channelId): void
    {
        if ($channelId <= 0) {
            return;
        }

        $channelStmt = db()->prepare('SELECT stream_server_id FROM tv_channels WHERE id = :id LIMIT 1');
        $channelStmt->execute(['id' => $channelId]);
        $serverId = $channelStmt->fetchColumn();

        if (!$serverId) {
            $active = db()->query("SELECT id FROM tv_stream_servers WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            if (count($active) !== 1) {
                // Zero or multiple active servers with no explicit assignment -
                // nothing safe to check capacity against, so let it through.
                return;
            }
            $serverId = $active[0];
        }

        $server = db()->prepare('SELECT capacity FROM tv_stream_servers WHERE id = :id LIMIT 1');
        $server->execute(['id' => $serverId]);
        $capacity = $server->fetchColumn();
        if ($capacity === false || $capacity === null) {
            return;
        }

        $liveCount = db()->prepare(
            'SELECT COUNT(DISTINCT sk.channel_id)
               FROM tv_stream_keys sk
               JOIN tv_channels c ON c.id = sk.channel_id
              WHERE sk.is_publishing = 1
                AND (c.stream_server_id = :sid OR c.stream_server_id IS NULL)'
        );
        $liveCount->execute(['sid' => $serverId]);
        $live = (int)$liveCount->fetchColumn();

        if ($live >= (int)$capacity) {
            throw new TvStreamCapacityExceededException(
                "Stream server #{$serverId} is at capacity ({$live}/{$capacity})."
            );
        }
    }

    /**
     * Counterpart to authorizePublish() - called by api/stream/
     * on_publish_done.php when the streaming server's on_publish_done
     * callback fires (the encoder disconnected or was stopped). Never
     * rejects: an unknown key here just means there is nothing to clean up.
     */
    public static function recordPublishEnded(string $rawKey): ?array
    {
        $rawKey = trim($rawKey);
        if ($rawKey === '') {
            return null;
        }

        $stmt = db()->prepare('SELECT * FROM tv_stream_keys WHERE stream_key_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => hash('sha256', $rawKey)]);
        $key = $stmt->fetch();
        if (!$key) {
            return null;
        }

        db()->prepare(
            'UPDATE tv_stream_keys SET is_publishing = 0, publish_ended_at = NOW(), updated_at = NOW() WHERE id = :id'
        )->execute(['id' => $key['id']]);

        if (!empty($key['channel_id'])) {
            db()->prepare('UPDATE tv_channels SET is_live = 0 WHERE id = :id')->execute(['id' => $key['channel_id']]);
        }

        if (!empty($key['event_id'])) {
            db()->prepare(
                "UPDATE tv_events
                    SET status = 'ended', updated_at = NOW(),
                        end_at = COALESCE(end_at, NOW()),
                        duration_seconds = TIMESTAMPDIFF(SECOND, start_at, NOW())
                  WHERE id = :id AND status = 'live'"
            )->execute(['id' => $key['event_id']]);

            // Recording is opt-in per event (is_replay_enabled) - flip to
            // "processing" only for events that asked for a replay, so the
            // admin UI can show something other than the default "none"
            // while the VPS-side FFmpeg remux (docs/streaming-server.md)
            // finishes and calls back to finalizeReplay().
            db()->prepare(
                "UPDATE tv_events
                    SET replay_status = 'processing', updated_at = NOW()
                  WHERE id = :id AND is_replay_enabled = 1 AND replay_status = 'none'"
            )->execute(['id' => $key['event_id']]);
        }

        tv_record_audit((int)$key['organization_id'], null, 'stream_publish_ended', 'stream_key', (int)$key['id'], [
            'channel_id' => $key['channel_id'],
            'event_id' => $key['event_id'],
        ]);

        return $key;
    }

    /**
     * Called back by the VPS-side recording job (see docs/streaming-server.md's
     * "Replay/VOD recording" section) once it has finished remuxing a
     * completed publish's raw recording, or given up. Resolves the stream
     * key the same way authorizePublish()/recordPublishEnded() do rather
     * than trusting a bare event id, so this can only ever be called by
     * whatever process actually held the real stream key for that session.
     *
     * $replayPath is relative to STREAM_PLAYBACK_BASE_URL, matching how
     * getPlaybackUrl() already treats getIngestUrl()'s sibling config value
     * as the join point between the app's URLs and the streaming origin's
     * actual file layout.
     */
    public static function finalizeReplay(string $rawKey, string $status, ?string $replayPath = null): ?array
    {
        $rawKey = trim($rawKey);
        if ($rawKey === '' || !in_array($status, ['available', 'failed'], true)) {
            return null;
        }

        $stmt = db()->prepare('SELECT * FROM tv_stream_keys WHERE stream_key_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => hash('sha256', $rawKey)]);
        $key = $stmt->fetch();
        if (!$key || empty($key['event_id'])) {
            return null;
        }

        if ($status === 'available') {
            $replayPath = trim((string)$replayPath, '/');
            if ($replayPath === '') {
                return null;
            }
            db()->prepare(
                "UPDATE tv_events SET replay_status = 'available', replay_url = :url, updated_at = NOW() WHERE id = :id"
            )->execute(['url' => $replayPath, 'id' => $key['event_id']]);
        } else {
            db()->prepare(
                "UPDATE tv_events SET replay_status = 'failed', updated_at = NOW() WHERE id = :id"
            )->execute(['id' => $key['event_id']]);
        }

        tv_record_audit((int)$key['organization_id'], null, 'replay_' . $status, 'event', (int)$key['event_id'], [
            'replay_path' => $replayPath,
        ]);

        return $key;
    }

    /**
     * Signed playback URL for a finished replay - same HMAC scheme as
     * getPlaybackUrl(), so it goes through the same authorize_playback.php
     * check, just against a longer default ttl (6 hours vs live's 5
     * minutes): a replay viewer plausibly pauses, scrubs, or leaves the tab
     * open far longer than someone tuning into something actually live, and
     * re-minting the URL on every page load (same as live) is what keeps
     * this from being a permanent public link rather than the ttl alone.
     */
    public static function getReplayUrl(array $event, int $ttl = 21600): ?string
    {
        if ((string)($event['replay_status'] ?? '') !== 'available' || empty($event['replay_url'])) {
            return null;
        }

        $base = (string)tv_config('stream_playback_base_url');
        if ($base === '') {
            return null;
        }

        $expires = time() + $ttl;
        $token = self::generatePlaybackToken((string)$event['id'], $expires);
        return $base . '/' . ltrim((string)$event['replay_url'], '/')
            . '?expires=' . $expires . '&token=' . urlencode($token) . '&event=' . (int)$event['id'];
    }
}

