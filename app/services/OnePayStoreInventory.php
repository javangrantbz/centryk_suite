<?php
require_once __DIR__ . '/../core/DB.php';

class OnePayStoreInventory
{
    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function fetch(string $companyUuid): array
    {
        self::$lastError = '';
        $companyUuid = trim($companyUuid);
        if ($companyUuid === '') {
            self::$lastError = 'Missing company UUID.';
            return [];
        }

        $url = self::endpointUrl();
        $secret = (string)($_ENV['ONEPAY_WEBHOOK_SECRET'] ?? '');
        if ($url === '' || $secret === '' || !function_exists('curl_init')) {
            self::$lastError = $url === ''
                ? 'OnePay inventory endpoint is not configured.'
                : ($secret === '' ? 'OnePay webhook secret is not configured.' : 'PHP cURL is not enabled.');
            return self::localFallback($companyUuid);
        }

        $body = json_encode([
            'company_uuid' => $companyUuid,
            'sent_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            self::$lastError = 'Could not build OnePay inventory request.';
            return [];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Centryk-Signature: sha256=' . hash_hmac('sha256', $body, $secret),
            ],
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            self::$lastError = $raw === false
                ? 'Could not reach OnePay inventory endpoint: ' . $curlError
                : 'OnePay inventory endpoint returned HTTP ' . $status . '.';
            return self::localFallback($companyUuid);
        }

        $payload = json_decode((string)$raw, true);
        if (!is_array($payload) || empty($payload['success']) || !isset($payload['items']) || !is_array($payload['items'])) {
            self::$lastError = 'OnePay inventory endpoint returned an unexpected response.';
            return self::localFallback($companyUuid);
        }

        $items = self::normalizeItems($payload['items']);
        if (!$items) {
            self::$lastError = 'OnePay returned no active inventory items for this company.';
        }
        return $items;
    }

    private static function endpointUrl(): string
    {
        $explicit = trim((string)($_ENV['ONEPAY_STORE_INVENTORY_URL'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $syncUrl = trim((string)($_ENV['ONEPAY_SYNC_URL'] ?? ''));
        if ($syncUrl !== '') {
            return preg_replace('~/api/webhooks/[^/?#]+(?:[?#].*)?$~', '/api/webhooks/centryk-store-inventory.php', $syncUrl) ?: '';
        }

        try {
            $stmt = DB::pdo()->prepare('SELECT url_local, url_production FROM apps WHERE `key` = "onepay" AND status = "active" LIMIT 1');
            $stmt->execute();
            $app = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $isLocal = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host) === 1;
            $launch = ($isLocal || empty($app['url_production'])) ? (string)($app['url_local'] ?? '') : (string)($app['url_production'] ?? '');
            if ($launch !== '') {
                $parts = parse_url($launch);
                if (!empty($parts['scheme']) && !empty($parts['host'])) {
                    $base = $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
                    return $base . '/api/webhooks/centryk-store-inventory.php';
                }
            }
        } catch (Throwable $e) {
            return '';
        }

        return '';
    }

    private static function normalizeItems(array $items): array
    {
        return array_map(static function (array $item): array {
            return [
                'id' => (int)($item['id'] ?? 0),
                'name' => (string)($item['name'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'sku' => (string)($item['sku'] ?? ''),
                'price' => (float)($item['price'] ?? 0),
                'stock_qty' => (float)($item['stock_qty'] ?? 0),
                'track_inventory' => (int)($item['track_inventory'] ?? 0),
                'active' => (int)($item['active'] ?? 1),
                'image_url' => (string)($item['image_url'] ?? ''),
                'store_name' => (string)($item['store_name'] ?? ''),
            ];
        }, array_values(array_filter($items, static function ($item): bool {
            return is_array($item) && (int)($item['id'] ?? 0) > 0;
        })));
    }

    private static function localFallback(string $companyUuid): array
    {
        try {
            $stmt = DB::pdo()->prepare("
                SELECT ci.id, ci.name, ci.description, ci.sku, ci.price, ci.stock_qty, ci.track_inventory,
                       ci.active, ci.image_url, s.name AS store_name
                FROM onepay.catalog_items ci
                JOIN onepay.stores s ON s.id = ci.store_id
                JOIN onepay.companies oc ON oc.id = s.company_id
                WHERE oc.centryk_uuid = :uuid
                  AND s.status = 'active'
                  AND ci.active = 1
                ORDER BY s.name ASC, ci.name ASC
            ");
            $stmt->execute(['uuid' => $companyUuid]);
            $items = self::normalizeItems($stmt->fetchAll(PDO::FETCH_ASSOC));
            if (!$items && self::$lastError === '') {
                self::$lastError = 'No active local OnePay inventory items found for this company.';
            }
            return $items;
        } catch (Throwable $e) {
            if (self::$lastError === '') {
                self::$lastError = 'Could not load OnePay inventory.';
            }
            return [];
        }
    }
}
