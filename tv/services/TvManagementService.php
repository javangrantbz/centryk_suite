<?php

class TvManagementService
{
    public static function createChannel(int $organizationId, int $userId, array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Channel name is required.');
        }

        $slug = trim((string)($data['slug'] ?? ''));
        $slug = $slug !== '' ? tv_slugify($slug) : tv_slugify($name);

        $description = trim((string)($data['description'] ?? ''));
        $visibility = (string)($data['visibility'] ?? 'public');
        $status = (string)($data['status'] ?? 'active');

        $check = db()->prepare('SELECT id FROM tv_channels WHERE organization_id = :organization_id AND slug = :slug LIMIT 1');
        $check->execute([
            'organization_id' => $organizationId,
            'slug' => $slug,
        ]);
        if ($check->fetch()) {
            throw new InvalidArgumentException('That channel slug is already in use.');
        }

        $logoPath = null;
        $coverImagePath = null;
        if (!empty($_FILES['logo']['name'])) {
            $logoPath = tv_upload_image('logo', 'channels');
        }
        if (!empty($_FILES['cover_image']['name'])) {
            $coverImagePath = tv_upload_image('cover_image', 'channels');
        }

        db()->prepare(
            'INSERT INTO tv_channels (
                organization_id, name, slug, description, logo_path, cover_image_path,
                visibility, status, created_by, created_at, updated_at
             ) VALUES (
                :organization_id, :name, :slug, :description, :logo_path, :cover_image_path,
                :visibility, :status, :created_by, NOW(), NOW()
             )'
        )->execute([
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description ?: null,
            'logo_path' => $logoPath,
            'cover_image_path' => $coverImagePath,
            'visibility' => $visibility,
            'status' => $status,
            'created_by' => $userId,
        ]);

        $channelId = (int)db()->lastInsertId();
        $key = StreamingService::regenerateChannelStreamKey($organizationId, $channelId, $userId);

        tv_record_audit($organizationId, $userId, 'create_channel', 'channel', $channelId, [
            'name' => $name,
            'slug' => $slug,
        ]);

        return [
            'id' => $channelId,
            'name' => $name,
            'slug' => $slug,
            'stream_key' => $key['raw_key'],
        ];
    }

    public static function createEvent(int $organizationId, int $userId, array $data): array
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Event title is required.');
        }

        $channelId = (int)($data['channel_id'] ?? 0);
        if ($channelId <= 0) {
            throw new InvalidArgumentException('Choose a channel for the event.');
        }

        $channel = db()->prepare('SELECT id, slug FROM tv_channels WHERE id = :id AND organization_id = :organization_id LIMIT 1');
        $channel->execute([
            'id' => $channelId,
            'organization_id' => $organizationId,
        ]);
        $channelRow = $channel->fetch();
        if (!$channelRow) {
            throw new InvalidArgumentException('Selected channel was not found.');
        }

        $slug = trim((string)($data['slug'] ?? ''));
        $slug = $slug !== '' ? tv_slugify($slug) : tv_slugify($title);
        $check = db()->prepare('SELECT id FROM tv_events WHERE slug = :slug LIMIT 1');
        $check->execute(['slug' => $slug]);
        if ($check->fetch()) {
            $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        $thumbnail = null;
        if (!empty($_FILES['thumbnail']['name'])) {
            $thumbnail = tv_upload_image('thumbnail', 'events');
        }

        db()->prepare(
            'INSERT INTO tv_events (
                organization_id, channel_id, title, slug, description, event_type,
                thumbnail_path, start_at, end_at, status, visibility, viewer_limit,
                replay_url, replay_status, duration_seconds, is_replay_enabled, created_by,
                created_at, updated_at
             ) VALUES (
                :organization_id, :channel_id, :title, :slug, :description, :event_type,
                :thumbnail_path, :start_at, :end_at, :status, :visibility, :viewer_limit,
                :replay_url, :replay_status, :duration_seconds, :is_replay_enabled, :created_by,
                NOW(), NOW()
             )'
        )->execute([
            'organization_id' => $organizationId,
            'channel_id' => $channelId,
            'title' => $title,
            'slug' => $slug,
            'description' => trim((string)($data['description'] ?? '')) ?: null,
            'event_type' => (string)($data['event_type'] ?? 'other'),
            'thumbnail_path' => $thumbnail,
            'start_at' => (string)($data['start_at'] ?? ''),
            'end_at' => trim((string)($data['end_at'] ?? '')) ?: null,
            'status' => (string)($data['status'] ?? 'scheduled'),
            'visibility' => (string)($data['visibility'] ?? 'public'),
            'viewer_limit' => (int)($data['viewer_limit'] ?? 0) ?: null,
            'replay_url' => trim((string)($data['replay_url'] ?? '')) ?: null,
            'replay_status' => trim((string)($data['replay_status'] ?? 'none')) ?: 'none',
            'duration_seconds' => (int)($data['duration_seconds'] ?? 0) ?: null,
            'is_replay_enabled' => !empty($data['is_replay_enabled']) ? 1 : 0,
            'created_by' => $userId,
        ]);

        $eventId = (int)db()->lastInsertId();
        $key = StreamingService::assignEventStreamKey($organizationId, $channelId, $eventId, $userId);

        if ((string)($data['event_type'] ?? '') === 'sports') {
            $homeLogo = !empty($_FILES['home_logo']['name']) ? tv_upload_image('home_logo', 'teams') : null;
            $awayLogo = !empty($_FILES['away_logo']['name']) ? tv_upload_image('away_logo', 'teams') : null;
            db()->prepare(
                'INSERT INTO tv_sports_event_details (
                    event_id, sport, home_team, away_team, home_logo_path, away_logo_path,
                    venue, competition, round_name, home_score, away_score, updated_at
                 ) VALUES (
                    :event_id, :sport, :home_team, :away_team, :home_logo_path, :away_logo_path,
                    :venue, :competition, :round_name, :home_score, :away_score, NOW()
                 )'
            )->execute([
                'event_id' => $eventId,
                'sport' => trim((string)($data['sport'] ?? '')),
                'home_team' => trim((string)($data['home_team'] ?? '')),
                'away_team' => trim((string)($data['away_team'] ?? '')),
                'home_logo_path' => $homeLogo,
                'away_logo_path' => $awayLogo,
                'venue' => trim((string)($data['venue'] ?? '')) ?: null,
                'competition' => trim((string)($data['competition'] ?? '')) ?: null,
                'round_name' => trim((string)($data['round_name'] ?? '')) ?: null,
                'home_score' => (int)($data['home_score'] ?? 0),
                'away_score' => (int)($data['away_score'] ?? 0),
            ]);
        }

        tv_record_audit($organizationId, $userId, 'create_event', 'event', $eventId, [
            'title' => $title,
            'slug' => $slug,
            'channel_id' => $channelId,
            'stream_key_id' => $key['id'],
        ]);

        return [
            'id' => $eventId,
            'title' => $title,
            'slug' => $slug,
            'stream_key' => $key['raw_key'],
            'channel_slug' => (string)$channelRow['slug'],
        ];
    }

    public static function updateEventStatus(int $organizationId, int $eventId, string $status, int $userId): void
    {
        $allowed = ['draft', 'scheduled', 'live', 'ended', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid event status.');
        }

        db()->prepare(
            'UPDATE tv_events
             SET status = :status, updated_at = NOW()
             WHERE id = :event_id AND organization_id = :organization_id'
        )->execute([
            'status' => $status,
            'event_id' => $eventId,
            'organization_id' => $organizationId,
        ]);

        tv_record_audit($organizationId, $userId, 'update_event_status', 'event', $eventId, [
            'status' => $status,
        ]);
    }

    public static function updateSportsScore(int $organizationId, int $eventId, int $homeScore, int $awayScore, int $userId): void
    {
        $check = db()->prepare(
            'SELECT e.id
             FROM tv_events e
             JOIN tv_sports_event_details sed ON sed.event_id = e.id
             WHERE e.id = :event_id AND e.organization_id = :organization_id'
        );
        $check->execute([
            'event_id' => $eventId,
            'organization_id' => $organizationId,
        ]);
        if (!$check->fetch()) {
            throw new InvalidArgumentException('Sports event was not found.');
        }

        db()->prepare(
            'UPDATE tv_sports_event_details
             SET home_score = :home_score, away_score = :away_score, updated_at = NOW()
             WHERE event_id = :event_id'
        )->execute([
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'event_id' => $eventId,
        ]);

        tv_record_audit($organizationId, $userId, 'update_score', 'event', $eventId, [
            'home_score' => $homeScore,
            'away_score' => $awayScore,
        ]);
    }

    public static function updateOrganizationProfile(int $organizationId, int $userId, array $data): void
    {
        $logoPath = null;
        $bannerPath = null;
        if (!empty($_FILES['logo']['name'])) {
            $logoPath = tv_upload_image('logo', 'organizations');
        }
        if (!empty($_FILES['banner']['name'])) {
            $bannerPath = tv_upload_image('banner', 'organizations');
        }

        $fields = [
            'name' => trim((string)($data['name'] ?? '')),
            'description' => trim((string)($data['description'] ?? '')) ?: null,
            'email' => trim((string)($data['email'] ?? '')) ?: null,
            'phone' => trim((string)($data['phone'] ?? '')) ?: null,
            'website' => trim((string)($data['website'] ?? '')) ?: null,
            'timezone' => trim((string)($data['timezone'] ?? tv_config('timezone'))) ?: tv_config('timezone'),
        ];
        if ($fields['name'] === '') {
            throw new InvalidArgumentException('Organization name is required.');
        }

        db()->prepare(
            'UPDATE tv_organizations
             SET name = :name,
                 description = :description,
                 email = :email,
                 phone = :phone,
                 website = :website,
                 timezone = :timezone,
                 logo_path = COALESCE(:logo_path, logo_path),
                 banner_path = COALESCE(:banner_path, banner_path),
                 updated_at = NOW()
             WHERE id = :organization_id'
        )->execute([
            'name' => $fields['name'],
            'description' => $fields['description'],
            'email' => $fields['email'],
            'phone' => $fields['phone'],
            'website' => $fields['website'],
            'timezone' => $fields['timezone'],
            'logo_path' => $logoPath,
            'banner_path' => $bannerPath,
            'organization_id' => $organizationId,
        ]);

        tv_record_audit($organizationId, $userId, 'update_settings', 'organization', $organizationId, [
            'name' => $fields['name'],
        ]);
    }
}

