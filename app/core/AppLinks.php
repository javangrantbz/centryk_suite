<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Env.php';

/**
 * Public URLs for spoke apps, resolved from the `apps` registry (local vs
 * production) so the hub can link straight to a spoke's public pages without
 * hard-coding domains.
 */
class AppLinks
{
    /** @var array<string,string> */
    private static array $baseCache = [];

    /**
     * Web origin + path for a spoke, with any trailing `/sso.php` (or other
     * entry filename) trimmed. Empty string if the app isn't registered.
     */
    public static function base(string $appKey): string
    {
        if (array_key_exists($appKey, self::$baseCache)) {
            return self::$baseCache[$appKey];
        }

        $base = '';
        try {
            $stmt = DB::pdo()->prepare(
                "SELECT url_local, url_production FROM apps WHERE `key` = :k AND status = 'active' LIMIT 1"
            );
            $stmt->execute(['k' => $appKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($row) {
                $raw = Env::isProduction()
                    ? (($row['url_production'] ?? '') ?: ($row['url_local'] ?? ''))
                    : (($row['url_local'] ?? '') ?: ($row['url_production'] ?? ''));
                $base = rtrim((string)preg_replace('#/[^/]*\.php$#', '', rtrim((string)$raw, '/')), '/');
            }
        } catch (Throwable $e) {
            $base = '';
        }

        return self::$baseCache[$appKey] = $base;
    }

    /**
     * The MyPay public job board. Pass a company's Centryk UUID to scope it to
     * that company's openings. Empty string if MyPay isn't registered.
     */
    public static function jobBoard(?string $companyUuid = null): string
    {
        $base = self::base('mypay');
        if ($base === '') {
            return '';
        }
        $url = $base . '/views/careers/index.php';
        if ($companyUuid !== null && $companyUuid !== '') {
            $url .= '?company=' . urlencode($companyUuid);
        }
        return $url;
    }

    /**
     * Absolute URL of MyPay's public "company openings" endpoint for a company
     * (by Centryk UUID). Callers fetch this from the browser so a slow spoke
     * never blocks a hub page. Empty string if MyPay isn't registered.
     */
    public static function jobOpeningsEndpoint(string $companyUuid): string
    {
        $base = self::base('mypay');
        if ($base === '' || $companyUuid === '') {
            return '';
        }
        return $base . '/api/public/company-openings.php?company=' . urlencode($companyUuid);
    }
}
