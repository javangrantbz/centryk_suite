<?php
/**
 * Short public-store links: centryk.bz/s/<slug>.
 *
 * The slug is derived from the company name — lowercased, apostrophes removed
 * (so "Bell's" -> "bells", not "bell-s"), every other run of non-alphanumerics
 * collapsed to a single hyphen. Globally unique; collisions get "-2", "-3", …
 *
 * Generated lazily the first time a company's store page is viewed
 * (see public/store.php) and backfilled by
 * database/backfill_company_store_slug.php.
 */
class StoreLink
{
    public static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        // Drop apostrophes outright rather than turning them into hyphens.
        $value = str_replace(["'", "\u{2019}", "\u{2018}", '`'], '', $value);
        $slug  = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $slug  = trim($slug, '-');
        if ($slug === '') {
            $slug = 'store';
        }
        return substr($slug, 0, 60);
    }

    /**
     * The company's existing slug, or a freshly generated unique one (persisted).
     * Safe to call on every store-page view — it's a no-op once a slug exists.
     */
    public static function ensure(PDO $pdo, int $companyId, string $name): string
    {
        $read = $pdo->prepare('SELECT store_slug FROM companies WHERE id = :id');
        $read->execute(['id' => $companyId]);
        $existing = trim((string)($read->fetchColumn() ?: ''));
        if ($existing !== '') {
            return $existing;
        }

        $base  = self::slugify($name);
        $check = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE store_slug = :s AND id <> :id');
        $slug  = $base;
        $n     = 1;
        while (true) {
            $check->execute(['s' => $slug, 'id' => $companyId]);
            if ((int)$check->fetchColumn() === 0) {
                break;
            }
            $slug = $base . '-' . (++$n);
        }

        try {
            $pdo->prepare('UPDATE companies SET store_slug = :s WHERE id = :id')
                ->execute(['s' => $slug, 'id' => $companyId]);
        } catch (Throwable $e) {
            // Lost a race for the same slug — re-read whatever won.
            $read->execute(['id' => $companyId]);
            $again = trim((string)($read->fetchColumn() ?: ''));
            if ($again !== '') {
                return $again;
            }
            throw $e;
        }

        return $slug;
    }

    /** Resolve a slug to an active company's uuid, or null. */
    public static function resolve(PDO $pdo, string $slug): ?string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9-]{1,64}$/', $slug)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT uuid FROM companies WHERE store_slug = :s AND status = "active" LIMIT 1');
        $stmt->execute(['s' => $slug]);
        $uuid = $stmt->fetchColumn();
        return $uuid !== false ? (string)$uuid : null;
    }
}
