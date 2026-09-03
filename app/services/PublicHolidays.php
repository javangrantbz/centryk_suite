<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';

/**
 * Belize public & bank holidays (Holidays Act, Ch. 289) — national data,
 * not company-scoped. Read by the Centryk calendar and, over a signed
 * endpoint, by MyPay.
 *
 * `pay_rate` is the multiplier for hours WORKED on that day (Labour Act):
 * 2.00 for Good Friday / Easter Monday / Christmas Day, 1.50 for the rest.
 */
class PublicHolidays
{
    /** Holidays with a date in [$from, $to] (inclusive), soonest first. */
    public static function forRange(string $from, string $to): array
    {
        if (!self::validDate($from) || !self::validDate($to)) {
            return [];
        }
        $stmt = DB::pdo()->prepare(
            'SELECT id, holiday_date, name, category, pay_rate, observed_note
               FROM public_holidays
              WHERE active = 1 AND holiday_date BETWEEN :from AND :to
              ORDER BY holiday_date ASC'
        );
        $stmt->execute(['from' => $from, 'to' => $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The next $limit holidays on or after $from (default today). */
    public static function upcoming(int $limit = 5, ?string $from = null): array
    {
        $from = ($from !== null && self::validDate($from)) ? $from : date('Y-m-d');
        $limit = max(1, min(50, $limit));
        $stmt = DB::pdo()->prepare(
            "SELECT id, holiday_date, name, category, pay_rate, observed_note
               FROM public_holidays
              WHERE active = 1 AND holiday_date >= :from
              ORDER BY holiday_date ASC
              LIMIT {$limit}"
        );
        $stmt->execute(['from' => $from]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Every holiday in a calendar year (admin list; includes inactive). */
    public static function year(int $year): array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT id, holiday_date, name, category, pay_rate, observed_note, active
               FROM public_holidays
              WHERE YEAR(holiday_date) = :year
              ORDER BY holiday_date ASC'
        );
        $stmt->execute(['year' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Distinct years that have at least one holiday row. */
    public static function years(): array
    {
        $rows = DB::pdo()->query('SELECT DISTINCT YEAR(holiday_date) AS y FROM public_holidays ORDER BY y ASC')
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows);
    }

    /** Create or update a holiday. Returns the id. */
    public static function save(array $in, int $actorId): int
    {
        $id = (int)($in['id'] ?? 0);
        $date = trim((string)($in['holiday_date'] ?? ''));
        $name = trim((string)($in['name'] ?? ''));
        $category = in_array($in['category'] ?? '', ['public', 'bank', 'both'], true) ? $in['category'] : 'both';
        $rate = (float)($in['pay_rate'] ?? 1.5);
        $note = trim((string)($in['observed_note'] ?? ''));
        $active = !empty($in['active']) ? 1 : 0;

        if (!self::validDate($date)) {
            throw new InvalidArgumentException('A valid date is required.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('A name is required.');
        }
        if ($rate < 1 || $rate > 3) {
            throw new InvalidArgumentException('Pay rate must be between 1.00 and 3.00.');
        }

        $pdo = DB::pdo();
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE public_holidays
                    SET holiday_date = :d, name = :n, category = :c, pay_rate = :r,
                        observed_note = :note, active = :a
                  WHERE id = :id'
            )->execute(['d' => $date, 'n' => $name, 'c' => $category, 'r' => $rate, 'note' => $note, 'a' => $active, 'id' => $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO public_holidays (holiday_date, name, category, pay_rate, observed_note, active)
                 VALUES (:d, :n, :c, :r, :note, :a)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name), category = VALUES(category), pay_rate = VALUES(pay_rate),
                    observed_note = VALUES(observed_note), active = VALUES(active)'
            )->execute(['d' => $date, 'n' => $name, 'c' => $category, 'r' => $rate, 'note' => $note, 'a' => $active]);
            $id = (int)$pdo->lastInsertId();
            if ($id === 0) {
                $id = (int)$pdo->query('SELECT id FROM public_holidays WHERE holiday_date = ' . $pdo->quote($date))->fetchColumn();
            }
        }

        Audit::log([
            'actor_user_id' => $actorId,
            'event_type'    => 'holiday.save',
            'summary'       => $name . ' (' . $date . ') @ ' . number_format($rate, 2) . 'x',
            'metadata'      => ['id' => $id, 'date' => $date, 'pay_rate' => $rate, 'category' => $category, 'active' => $active],
        ]);
        return $id;
    }

    public static function delete(int $id, int $actorId): void
    {
        $stmt = DB::pdo()->prepare('SELECT holiday_date, name FROM public_holidays WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        DB::pdo()->prepare('DELETE FROM public_holidays WHERE id = :id')->execute(['id' => $id]);
        Audit::log([
            'actor_user_id' => $actorId,
            'event_type'    => 'holiday.delete',
            'summary'       => $row['name'] . ' (' . $row['holiday_date'] . ')',
            'metadata'      => ['id' => $id],
        ]);
    }

    /** "2×" / "1.5×" / "1×" for display. */
    public static function rateLabel(float $rate): string
    {
        if (abs($rate - round($rate)) < 0.001) {
            return (string)(int)round($rate) . '×';
        }
        return rtrim(rtrim(number_format($rate, 2), '0'), '.') . '×';
    }

    private static function validDate(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
    }
}
