<?php
/**
 * Online conferences hosted on Centryk TV, attached to Calendar events.
 *
 * A conference is a calendar event (events + event_attendees) plus one
 * event_conferences row holding the start time, an unguessable room token and
 * the live/ended state. Access is by invitation: the event's creator and its
 * attendees, nobody else. The creator is the host/moderator.
 *
 * Every wall-clock comparison uses PHP's clock (events.event_date and
 * start_time are naive local values), never MySQL NOW().
 */
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/NotificationService.php';

class ConferenceService
{
    /** Heartbeat freshness that counts a conference as "active". */
    public const ACTIVE_WINDOW_SECONDS = 90;
    /** Participants may join this long before the start time. */
    public const EARLY_JOIN_MINUTES = 15;
    /** An unstarted conference stays joinable this long past its scheduled end. */
    public const GRACE_MINUTES = 30;
    /** In-app reminder goes out this long before the start. */
    public const REMINDER_MINUTES = 15;

    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $pdo = DB::pdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS event_conferences (
                event_id         INT UNSIGNED NOT NULL PRIMARY KEY,
                room_token       CHAR(32)     NOT NULL,
                start_time       TIME         NOT NULL,
                duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
                status           ENUM('scheduled','live','ended') NOT NULL DEFAULT 'scheduled',
                started_at       DATETIME     NULL,
                ended_at         DATETIME     NULL,
                last_active_at   DATETIME     NULL,
                reminder_sent_at DATETIME     NULL,
                created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_conference_token (room_token),
                CONSTRAINT fk_conference_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS conference_presence (
                event_id     INT UNSIGNED NOT NULL,
                user_id      INT UNSIGNED NOT NULL,
                joined_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_id, user_id),
                CONSTRAINT fk_presence_conference FOREIGN KEY (event_id) REFERENCES event_conferences(event_id) ON DELETE CASCADE,
                CONSTRAINT fk_presence_user       FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        self::$schemaReady = true;
    }

    // ── URLs / provider config ───────────────────────────────────────────────

    public static function tvBaseUrl(): string
    {
        $configured = rtrim((string)($_ENV['TV_APP_URL'] ?? ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'http://localhost/centryk/public'), '/');
        if (substr($appUrl, -7) === '/public') {
            return substr($appUrl, 0, -7) . '/tv';
        }
        return $appUrl . '/tv';
    }

    public static function joinUrl(string $token): string
    {
        return self::tvBaseUrl() . '/conference.php?c=' . rawurlencode($token);
    }

    public static function jitsiDomain(): string
    {
        $d = trim((string)($_ENV['JITSI_DOMAIN'] ?? ''));
        return $d !== '' ? $d : 'meet.jit.si';
    }

    public static function roomName(string $token): string
    {
        return 'centryk-' . $token;
    }

    /**
     * HS256 JWT for a self-hosted Jitsi with token auth. Returns null when no
     * JITSI_APP_ID / JITSI_APP_SECRET is configured (public-room mode, where
     * the unguessable room name is the only barrier).
     */
    public static function jitsiJwt(array $conf, array $user, bool $moderator): ?string
    {
        $appId = trim((string)($_ENV['JITSI_APP_ID'] ?? ''));
        $secret = (string)($_ENV['JITSI_APP_SECRET'] ?? '');
        if ($appId === '' || $secret === '') {
            return null;
        }
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $name = trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? '')));
        $payload = [
            'aud' => (string)($_ENV['JITSI_JWT_AUD'] ?? 'jitsi'),
            'iss' => $appId,
            'sub' => self::jitsiDomain(),
            'room' => self::roomName((string)$conf['room_token']),
            'exp' => time() + 4 * 3600,
            'context' => ['user' => [
                'name' => $name !== '' ? $name : (string)($user['email'] ?? 'Guest'),
                'email' => (string)($user['email'] ?? ''),
                'moderator' => $moderator ? 'true' : 'false',
            ]],
        ];
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode($payload));
        return $head . '.' . $body . '.' . $b64(hash_hmac('sha256', $head . '.' . $body, $secret, true));
    }

    // ── Persistence from the calendar ────────────────────────────────────────

    /**
     * Create, update or remove the conference on an event.
     * $conf = null/['enabled'=>false] removes it; otherwise needs start_time
     * (HH:MM) and optional duration_minutes.
     */
    public static function saveForEvent(int $eventId, ?array $conf, string $eventDate): array
    {
        self::ensureSchema();
        $pdo = DB::pdo();
        $existing = self::row($eventId);

        if (!$conf || empty($conf['enabled'])) {
            if ($existing) {
                $pdo->prepare('DELETE FROM event_conferences WHERE event_id = :id')->execute(['id' => $eventId]);
            }
            return ['enabled' => false, 'created' => false, 'rescheduled' => false, 'removed' => (bool)$existing];
        }

        $start = self::normalizeTime((string)($conf['start_time'] ?? ''));
        if ($start === null) {
            throw new InvalidArgumentException('A start time is required for an online conference.');
        }
        $duration = max(5, min(720, (int)($conf['duration_minutes'] ?? 60)));

        if (!$existing) {
            $pdo->prepare('
                INSERT INTO event_conferences (event_id, room_token, start_time, duration_minutes)
                VALUES (:id, :token, :start, :dur)
            ')->execute(['id' => $eventId, 'token' => bin2hex(random_bytes(16)), 'start' => $start, 'dur' => $duration]);
            return ['enabled' => true, 'created' => true, 'rescheduled' => false, 'removed' => false];
        }

        $rescheduled = $existing['start_time'] !== $start
            || (int)$existing['duration_minutes'] !== $duration
            || $existing['event_date'] !== $eventDate;
        // Time moved: re-arm the reminder and reopen a conference that had ended.
        $pdo->prepare('
            UPDATE event_conferences
               SET start_time = :start, duration_minutes = :dur,
                   reminder_sent_at = IF(:moved = 1, NULL, reminder_sent_at),
                   status = IF(:moved2 = 1 AND status = "ended", "scheduled", status)
             WHERE event_id = :id
        ')->execute([
            'start' => $start, 'dur' => $duration, 'moved' => $rescheduled ? 1 : 0,
            'moved2' => $rescheduled ? 1 : 0, 'id' => $eventId,
        ]);
        return ['enabled' => true, 'created' => false, 'rescheduled' => $rescheduled, 'removed' => false];
    }

    private static function normalizeTime(string $t): ?string
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', trim($t), $m)) {
            return null;
        }
        return sprintf('%02d:%s:00', (int)$m[1], $m[2]);
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /** Conference + event + host for one event id. */
    public static function row(int $eventId): ?array
    {
        self::ensureSchema();
        $stmt = DB::pdo()->prepare(self::baseSql() . ' WHERE c.event_id = :id LIMIT 1');
        $stmt->execute(['id' => $eventId]);
        $r = $stmt->fetch();
        return $r ? self::decorate($r) : null;
    }

    /** What the calendar UI needs about an event's conference (null when none). */
    public static function summary(?array $c): ?array
    {
        if (!$c) {
            return null;
        }
        return [
            'start_time' => substr((string)$c['start_time'], 0, 5),
            'duration_minutes' => (int)$c['duration_minutes'],
            'state' => $c['state'],
            'join_url' => $c['join_url'],
        ];
    }

    /** Conferences for a set of event ids, keyed by event id. */
    public static function forEvents(array $eventIds): array
    {
        $eventIds = array_values(array_filter(array_map('intval', $eventIds)));
        if (!$eventIds) {
            return [];
        }
        self::ensureSchema();
        $in = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = DB::pdo()->prepare(self::baseSql() . " WHERE c.event_id IN ($in)");
        $stmt->execute($eventIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int)$r['event_id']] = self::decorate($r);
        }
        return $out;
    }

    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        self::ensureSchema();
        $stmt = DB::pdo()->prepare(self::baseSql() . ' WHERE c.room_token = :t LIMIT 1');
        $stmt->execute(['t' => $token]);
        $r = $stmt->fetch();
        return $r ? self::decorate($r) : null;
    }

    private static function baseSql(): string
    {
        return '
            SELECT c.event_id, c.room_token, c.start_time, c.duration_minutes, c.status,
                   c.started_at, c.ended_at, c.last_active_at, c.reminder_sent_at,
                   e.company_id, e.title, e.description, e.event_date, e.created_by,
                   TRIM(CONCAT(h.first_name, " ", h.last_name)) AS host_name,
                   co.name AS company_name
            FROM event_conferences c
            JOIN events e ON e.id = c.event_id
            JOIN users h ON h.id = e.created_by
            LEFT JOIN companies co ON co.id = e.company_id';
    }

    /** Adds start_ts / end_ts / state / join_url to a base row. */
    private static function decorate(array $r): array
    {
        $start = strtotime($r['event_date'] . ' ' . $r['start_time']);
        $r['start_ts'] = $start;
        $r['end_ts'] = $start + ((int)$r['duration_minutes']) * 60;
        $r['start_time'] = (string)$r['start_time'];
        $r['join_url'] = self::joinUrl((string)$r['room_token']);
        $r['state'] = self::state($r, time());
        return $r;
    }

    /**
     * active   - someone is in the room right now (fresh heartbeat)
     * due      - start time reached (or within the early-join window), nobody in yet
     * upcoming - later
     * ended    - host ended it, or it was never used and has long expired
     */
    public static function state(array $r, int $now): string
    {
        if ($r['status'] === 'ended') {
            return 'ended';
        }
        if (!empty($r['last_active_at']) && strtotime((string)$r['last_active_at']) >= $now - self::ACTIVE_WINDOW_SECONDS) {
            return 'active';
        }
        if ($now > $r['end_ts'] + self::GRACE_MINUTES * 60) {
            return 'ended';
        }
        if ($now >= $r['start_ts'] - self::EARLY_JOIN_MINUTES * 60) {
            return 'due';
        }
        return 'upcoming';
    }

    /** Creator + attendees of the event, as user ids. */
    public static function participantIds(int $eventId): array
    {
        $stmt = DB::pdo()->prepare('
            SELECT created_by AS uid FROM events WHERE id = :id1
            UNION
            SELECT user_id FROM event_attendees WHERE event_id = :id2
        ');
        $stmt->execute(['id1' => $eventId, 'id2' => $eventId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Conference row if $userId is an invited participant and still an active company member. */
    public static function accessFor(int $userId, string $token): ?array
    {
        $conf = self::byToken($token);
        if (!$conf || !in_array($userId, self::participantIds((int)$conf['event_id']), true)) {
            return null;
        }
        $stmt = DB::pdo()->prepare('SELECT 1 FROM company_members WHERE user_id = :u AND company_id = :c AND status = "active" LIMIT 1');
        $stmt->execute(['u' => $userId, 'c' => (int)$conf['company_id']]);
        return $stmt->fetchColumn() ? $conf : null;
    }

    /**
     * What the always-visible header pill shows: the user's active, due and
     * next-24h conferences. Also drives the lazy reminder sweep, so reminders
     * need no cron - any invited person's open page polls this.
     */
    public static function mine(int $userId): array
    {
        self::ensureSchema();
        self::sendDueReminders();

        $now = time();
        $stmt = DB::pdo()->prepare(self::baseSql() . '
            WHERE c.status <> "ended"
              AND e.event_date BETWEEN :d1 AND :d2
              AND (e.created_by = :u1 OR EXISTS (
                    SELECT 1 FROM event_attendees ea WHERE ea.event_id = e.id AND ea.user_id = :u2))
              AND EXISTS (SELECT 1 FROM company_members cm
                           WHERE cm.user_id = :u3 AND cm.company_id = e.company_id AND cm.status = "active")
            ORDER BY e.event_date ASC, c.start_time ASC');
        $stmt->execute([
            'd1' => date('Y-m-d', $now - 86400), 'd2' => date('Y-m-d', $now + 2 * 86400),
            'u1' => $userId, 'u2' => $userId, 'u3' => $userId,
        ]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $c = self::decorate($r);
            if ($c['state'] === 'ended' || ($c['state'] === 'upcoming' && $c['start_ts'] > $now + 86400)) {
                continue;
            }
            $c['is_host'] = (int)$c['created_by'] === $userId;
            $out[] = $c;
        }
        $rank = ['active' => 0, 'due' => 1, 'upcoming' => 2];
        usort($out, static fn($a, $b) => [$rank[$a['state']], $a['start_ts']] <=> [$rank[$b['state']], $b['start_ts']]);
        return $out;
    }

    // ── Room lifecycle ───────────────────────────────────────────────────────

    /** Record a participant entering the room; host entering flips it live and notifies everyone. */
    public static function recordJoin(array $conf, int $userId): void
    {
        $pdo = DB::pdo();
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('
            INSERT INTO conference_presence (event_id, user_id, joined_at, last_seen_at)
            VALUES (:e, :u, :n1, :n2)
            ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)
        ')->execute(['e' => $conf['event_id'], 'u' => $userId, 'n1' => $now, 'n2' => $now]);
        $pdo->prepare('UPDATE event_conferences SET last_active_at = :n WHERE event_id = :e')
            ->execute(['n' => $now, 'e' => $conf['event_id']]);

        if ((int)$conf['created_by'] === $userId) {
            // Claim the scheduled -> live transition atomically so exactly one
            // request sends the "is live" notification.
            $claim = $pdo->prepare('
                UPDATE event_conferences SET status = "live", started_at = :n
                 WHERE event_id = :e AND status = "scheduled"
            ');
            $claim->execute(['n' => $now, 'e' => $conf['event_id']]);
            if ($claim->rowCount() === 1) {
                self::notify($conf, 'conference.live',
                    ($conf['host_name'] ?: 'The host') . ' started a conference',
                    $conf['title'] . ' is live - join now.', [$userId]);
            }
        }
    }

    public static function heartbeat(array $conf, int $userId): void
    {
        $pdo = DB::pdo();
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE conference_presence SET last_seen_at = :n WHERE event_id = :e AND user_id = :u')
            ->execute(['n' => $now, 'e' => $conf['event_id'], 'u' => $userId]);
        $pdo->prepare('UPDATE event_conferences SET last_active_at = :n WHERE event_id = :e AND status <> "ended"')
            ->execute(['n' => $now, 'e' => $conf['event_id']]);
    }

    public static function leave(array $conf, int $userId): void
    {
        DB::pdo()->prepare('DELETE FROM conference_presence WHERE event_id = :e AND user_id = :u')
            ->execute(['e' => $conf['event_id'], 'u' => $userId]);
    }

    /** Host-only. */
    public static function end(array $conf, int $userId): bool
    {
        if ((int)$conf['created_by'] !== $userId) {
            return false;
        }
        $pdo = DB::pdo();
        $pdo->prepare('UPDATE event_conferences SET status = "ended", ended_at = :n WHERE event_id = :e')
            ->execute(['n' => date('Y-m-d H:i:s'), 'e' => $conf['event_id']]);
        $pdo->prepare('DELETE FROM conference_presence WHERE event_id = :e')->execute(['e' => $conf['event_id']]);
        return true;
    }

    /** People currently in the room (seen within the active window). */
    public static function participantsNow(int $eventId): array
    {
        $stmt = DB::pdo()->prepare('
            SELECT u.id, TRIM(CONCAT(u.first_name, " ", u.last_name)) AS name
            FROM conference_presence p JOIN users u ON u.id = p.user_id
            WHERE p.event_id = :e AND p.last_seen_at >= :cut
            ORDER BY p.joined_at ASC
        ');
        $stmt->execute(['e' => $eventId, 'cut' => date('Y-m-d H:i:s', time() - self::ACTIVE_WINDOW_SECONDS)]);
        return $stmt->fetchAll();
    }

    // ── Notifications ────────────────────────────────────────────────────────

    /** Notify participants (minus $exceptIds, or only $onlyIds). Returns how many were created. */
    public static function notify(array $conf, string $type, string $title, string $body, array $exceptIds = [], ?array $onlyIds = null): int
    {
        $ids = $onlyIds ?? self::participantIds((int)$conf['event_id']);
        $ids = array_values(array_diff($ids, $exceptIds));
        $n = 0;
        foreach ($ids as $uid) {
            $made = NotificationService::create([
                'user_id' => (int)$uid,
                'company_id' => (int)$conf['company_id'],
                'app_key' => 'tv',
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'url' => $type === 'conference.cancelled' ? '' : $conf['join_url'],
                'icon' => 'video',
                'color' => '#e11d48',
            ]);
            $n += $made > 0 ? 1 : 0;
        }
        return $n;
    }

    /** e.g. "Fri 3 Oct, 2:30 PM". */
    public static function whenLabel(array $conf): string
    {
        return date('D j M, g:i A', (int)$conf['start_ts']);
    }

    /** Reminders for conferences starting within REMINDER_MINUTES; idempotent. */
    public static function sendDueReminders(): void
    {
        $now = time();
        $stmt = DB::pdo()->prepare(self::baseSql() . '
            WHERE c.status = "scheduled" AND c.reminder_sent_at IS NULL
              AND e.event_date BETWEEN :d1 AND :d2');
        $stmt->execute(['d1' => date('Y-m-d', $now), 'd2' => date('Y-m-d', $now + 86400)]);
        foreach ($stmt->fetchAll() as $r) {
            $c = self::decorate($r);
            if ($c['start_ts'] > $now + self::REMINDER_MINUTES * 60 || $c['end_ts'] < $now) {
                continue;
            }
            $claim = DB::pdo()->prepare('UPDATE event_conferences SET reminder_sent_at = :n WHERE event_id = :e AND reminder_sent_at IS NULL');
            $claim->execute(['n' => date('Y-m-d H:i:s', $now), 'e' => $c['event_id']]);
            if ($claim->rowCount() === 1) {
                self::notify($c, 'conference.reminder',
                    'Conference starting soon: ' . $c['title'],
                    'Starts ' . self::whenLabel($c) . '. Join from Centryk TV.');
            }
        }
    }
}
