<?php

class Env
{
    private static bool $loaded = false;

    /**
     * Business timezone. Pinned in code rather than left to php.ini because
     * MySQL writes timestamps on the server clock while PHP uses its own
     * setting - when the two disagree they land on different DATES for part
     * of every day, which silently corrupts anything keyed on "today":
     * receipt/sale number date prefixes, per-day sequence rollover, and every
     * date-range report filter. XAMPP ships date.timezone=Europe/Berlin,
     * which put PHP 8 hours ahead of the (Belize) server clock.
     */
    private const DEFAULT_TIMEZONE = 'America/Belize';

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        // Applied before the .env parse so it still holds if the file is
        // missing; APP_TIMEZONE below can override it.
        date_default_timezone_set(self::DEFAULT_TIMEZONE);

        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key   = trim($parts[0]);
            $value = trim($parts[1]);

            if ($key === '') {
                continue;
            }

            if (
                (strpos($value, '"') === 0 && substr($value, -1) === '"') ||
                (strpos($value, "'") === 0 && substr($value, -1) === "'")
            ) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        // Let a deployment override the default without a code change, but
        // ignore a bad value rather than falling back to php.ini's guess.
        $tz = trim((string)($_ENV['APP_TIMEZONE'] ?? ''));
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        }
    }

    public static function isProduction(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'local') !== 'local';
    }
}
