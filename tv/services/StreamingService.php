<?php

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

    public static function getStreamStatus(array $event): string
    {
        $status = (string)($event['status'] ?? 'draft');
        return match ($status) {
            'live' => 'live',
            'scheduled' => 'offline',
            'ended' => 'offline',
            'cancelled' => 'error',
            default => 'connecting',
        };
    }
}

