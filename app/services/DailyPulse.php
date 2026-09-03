<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/PublicHolidays.php';
require_once __DIR__ . '/PeopleMilestones.php';

/**
 * Once-a-day "the app is paying attention" sweep: pushes Centryk notifications
 * for public holidays (everyone) and staff birthdays / work anniversaries
 * (members of that person's company). Idempotent per (event, date) via
 * notification_digests, so it is safe to run many times a day — from a cron
 * (scripts/daily_pulse.php) and/or the self-priming api/pulse/tick.php.
 *
 * Display only: does not touch payroll or any other calculation.
 */
class DailyPulse
{
    public static function run(): array
    {
        $out = ['holidays' => 0, 'headsup' => 0, 'birthdays' => 0, 'anniversaries' => 0, 'notifs' => 0];
        $today = date('Y-m-d');
        $allUsers = null;

        $activeUsers = static function () use (&$allUsers) {
            if ($allUsers === null) {
                $allUsers = DB::pdo()->query("SELECT id FROM users WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            }
            return $allUsers;
        };

        // 1. Public holiday today ────────────────────────────────────────────
        try {
            foreach (PublicHolidays::forRange($today, $today) as $h) {
                if (!self::claim('holiday:' . $h['holiday_date'])) {
                    continue;
                }
                $rate = PublicHolidays::rateLabel((float) $h['pay_rate']);
                $out['notifs'] += self::notifyMany($activeUsers(), [
                    'app_key' => 'calendar',
                    'type'    => 'holiday',
                    'title'   => 'Today is ' . $h['name'],
                    'body'    => $rate . ' pay for hours worked (Labour Act).',
                    'url'     => 'calendar.php',
                    'color'   => '#e11d48',
                ]);
                $out['holidays']++;
            }
        } catch (Throwable $e) {
            error_log('[daily_pulse] holiday today: ' . $e->getMessage());
        }

        // 2. Holiday heads-up (1–3 days out) ─────────────────────────────────
        try {
            $rows = PublicHolidays::forRange(date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+3 days')));
            foreach ($rows as $h) {
                if (!self::claim('holiday-soon:' . $h['holiday_date'])) {
                    continue;
                }
                $days = (int) round((strtotime($h['holiday_date']) - strtotime($today)) / 86400);
                $when = $days <= 1 ? 'is tomorrow' : ('is in ' . $days . ' days');
                $out['notifs'] += self::notifyMany($activeUsers(), [
                    'app_key' => 'calendar',
                    'type'    => 'holiday',
                    'title'   => $h['name'] . ' ' . $when,
                    'body'    => 'A public & bank holiday — plan for closures.',
                    'url'     => 'calendar.php',
                    'color'   => '#e11d48',
                ]);
                $out['headsup']++;
            }
        } catch (Throwable $e) {
            error_log('[daily_pulse] holiday heads-up: ' . $e->getMessage());
        }

        // 3. Birthdays & work anniversaries today ────────────────────────────
        try {
            foreach (PeopleMilestones::forRange($today, $today) as $m) {
                $kind  = (string) ($m['kind'] ?? '');
                $email = strtolower(trim((string) ($m['employee_email'] ?? '')));
                $cuuid = (string) ($m['company_uuid'] ?? '');
                if (!in_array($kind, ['birthday', 'anniversary'], true) || $cuuid === '') {
                    continue;
                }
                $dkey = ($kind === 'birthday' ? 'bday:' : 'anniv:') . $today . ':' . $email;
                if (!self::claim($dkey)) {
                    continue;
                }

                $co = DB::pdo()->prepare("SELECT id FROM companies WHERE uuid = :u AND status = 'active' LIMIT 1");
                $co->execute(['u' => $cuuid]);
                $cid = (int) ($co->fetchColumn() ?: 0);
                if (!$cid) {
                    continue;
                }

                $mem = DB::pdo()->prepare("
                    SELECT DISTINCT u.id
                    FROM company_members cm
                    JOIN users u ON u.id = cm.user_id AND u.status = 'active'
                    WHERE cm.company_id = :cid AND cm.status = 'active'
                ");
                $mem->execute(['cid' => $cid]);
                $memberIds = array_map('intval', $mem->fetchAll(PDO::FETCH_COLUMN));

                $person = DB::pdo()->prepare("SELECT id FROM users WHERE email = :e AND status = 'active' LIMIT 1");
                $person->execute(['e' => $email]);
                $personId = (int) ($person->fetchColumn() ?: 0);

                // The person gets a personal note instead of the broadcast one.
                $colleagues = array_values(array_diff($memberIds, [$personId]));

                $name   = trim((string) ($m['employee_name'] ?? 'A colleague'));
                $coName = (string) ($m['company_name'] ?? '');
                $years  = (int) ($m['years'] ?? 0);

                if ($kind === 'birthday') {
                    $out['birthdays']++;
                    $out['notifs'] += self::notifyMany($colleagues, [
                        'company_id' => $cid, 'app_key' => 'mypay', 'type' => 'birthday',
                        'title' => $name . ' has a birthday today', 'body' => 'Wish them well 🎂',
                        'url' => 'calendar.php', 'color' => '#ec4899',
                    ]);
                    if ($personId) {
                        NotificationService::create([
                            'user_id' => $personId, 'company_id' => $cid, 'app_key' => 'centryk', 'type' => 'birthday',
                            'title' => 'Happy birthday from Centryk 🎉', 'color' => '#ec4899',
                        ]);
                        $out['notifs']++;
                    }
                } else {
                    $out['anniversaries']++;
                    $label = $years . ' year' . ($years === 1 ? '' : 's') . ($coName !== '' ? ' at ' . $coName : '');
                    $out['notifs'] += self::notifyMany($colleagues, [
                        'company_id' => $cid, 'app_key' => 'mypay', 'type' => 'anniversary',
                        'title' => $name . ' — ' . $label . ' today', 'body' => 'A work anniversary 🎉',
                        'url' => 'calendar.php', 'color' => '#0d9488',
                    ]);
                    if ($personId) {
                        NotificationService::create([
                            'user_id' => $personId, 'company_id' => $cid, 'app_key' => 'centryk', 'type' => 'anniversary',
                            'title' => 'Happy work anniversary — ' . $label . ' 🎉', 'color' => '#0d9488',
                        ]);
                        $out['notifs']++;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[daily_pulse] milestones: ' . $e->getMessage());
        }

        return $out;
    }

    /** true only the first time a digest key is seen (INSERT IGNORE won the race). */
    private static function claim(string $key): bool
    {
        try {
            $stmt = DB::pdo()->prepare("INSERT IGNORE INTO notification_digests (digest_key) VALUES (:k)");
            $stmt->execute(['k' => substr($key, 0, 160)]);
            return $stmt->rowCount() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function notifyMany(array $userIds, array $n): int
    {
        $count = 0;
        foreach ($userIds as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            if (NotificationService::create(['user_id' => $uid] + $n) > 0) {
                $count++;
            }
        }
        return $count;
    }
}
