<?php
require_once __DIR__ . '/../core/DB.php';

/**
 * Read-only snapshot of a company's OnePay setup (stores, staff, inventory,
 * promotions, registers, restaurant tables, sales summary, customer count)
 * for the platform-admin company profile page. Same dual-path pattern as
 * OnePayStoreInventory: signed HTTP call to OnePay first, falling back to a
 * local cross-database read (this XAMPP dev box only - see localFallback()).
 */
class OnePayCompanyProfile
{
    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function empty(): array
    {
        return [
            'company' => null,
            'stores' => [],
            'staff' => [],
            'pending_invites' => [],
            'inventory' => ['categories' => [], 'items' => []],
            'promotions' => [],
            'registers' => [],
            'restaurant_tables' => [],
            'sales_summary' => [
                'all_time' => ['count' => 0, 'total' => 0.0],
                'last_30_days' => ['count' => 0, 'total' => 0.0],
            ],
            'customers_count' => 0,
        ];
    }

    public static function fetch(string $companyUuid): array
    {
        self::$lastError = '';
        $companyUuid = trim($companyUuid);
        if ($companyUuid === '') {
            self::$lastError = 'Missing company UUID.';
            return self::empty();
        }

        $url = self::endpointUrl();
        $secret = (string)($_ENV['ONEPAY_WEBHOOK_SECRET'] ?? '');
        if ($url === '' || $secret === '' || !function_exists('curl_init')) {
            self::$lastError = $url === ''
                ? 'OnePay company-profile endpoint is not configured.'
                : ($secret === '' ? 'OnePay webhook secret is not configured.' : 'PHP cURL is not enabled.');
            return self::localFallback($companyUuid);
        }

        $body = json_encode(['company_uuid' => $companyUuid, 'sent_at' => gmdate('c')], JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            self::$lastError = 'Could not build OnePay company-profile request.';
            return self::empty();
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
                ? 'Could not reach OnePay company-profile endpoint: ' . $curlError
                : 'OnePay company-profile endpoint returned HTTP ' . $status . '.';
            return self::localFallback($companyUuid);
        }

        $payload = json_decode((string)$raw, true);
        if (!is_array($payload) || empty($payload['success'])) {
            self::$lastError = 'OnePay company-profile endpoint returned an unexpected response.';
            return self::localFallback($companyUuid);
        }

        return self::normalize($payload);
    }

    /** Item detail for the company-profile item modal: identifiers, modifiers, QR/promo link, active promotions, recent scans. */
    public static function fetchItemDetail(string $companyUuid, int $itemId): array
    {
        self::$lastError = '';
        $companyUuid = trim($companyUuid);
        $emptyDetail = ['item' => null, 'modifiers' => [], 'promotional_links' => [], 'active_promotions' => [], 'recent_scans' => []];
        if ($companyUuid === '' || $itemId <= 0) {
            self::$lastError = 'Missing company UUID or item ID.';
            return $emptyDetail;
        }

        $url = self::endpointUrl('centryk-item-detail.php', 'ONEPAY_ITEM_DETAIL_URL');
        $secret = (string)($_ENV['ONEPAY_WEBHOOK_SECRET'] ?? '');
        if ($url === '' || $secret === '' || !function_exists('curl_init')) {
            self::$lastError = $url === ''
                ? 'OnePay item-detail endpoint is not configured.'
                : ($secret === '' ? 'OnePay webhook secret is not configured.' : 'PHP cURL is not enabled.');
            return self::localItemDetailFallback($companyUuid, $itemId);
        }

        $body = json_encode(['company_uuid' => $companyUuid, 'item_id' => $itemId, 'sent_at' => gmdate('c')], JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            self::$lastError = 'Could not build OnePay item-detail request.';
            return $emptyDetail;
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
                ? 'Could not reach OnePay item-detail endpoint: ' . $curlError
                : 'OnePay item-detail endpoint returned HTTP ' . $status . '.';
            return self::localItemDetailFallback($companyUuid, $itemId);
        }

        $payload = json_decode((string)$raw, true);
        if (!is_array($payload) || empty($payload['success'])) {
            self::$lastError = 'OnePay item-detail endpoint returned an unexpected response.';
            return self::localItemDetailFallback($companyUuid, $itemId);
        }

        return [
            'item' => $payload['item'] ?? null,
            'modifiers' => is_array($payload['modifiers'] ?? null) ? $payload['modifiers'] : [],
            'promotional_links' => is_array($payload['promotional_links'] ?? null) ? $payload['promotional_links'] : [],
            'active_promotions' => is_array($payload['active_promotions'] ?? null) ? $payload['active_promotions'] : [],
            'recent_scans' => is_array($payload['recent_scans'] ?? null) ? $payload['recent_scans'] : [],
        ];
    }

    private static function localItemDetailFallback(string $companyUuid, int $itemId): array
    {
        $result = ['item' => null, 'modifiers' => [], 'promotional_links' => [], 'active_promotions' => [], 'recent_scans' => []];
        try {
            $pdo = DB::pdo();

            $itemStmt = $pdo->prepare('
                SELECT ci.*, s.name AS store_name, s.id AS verified_store_id, cc.name AS category_name
                FROM onepay.catalog_items ci
                JOIN onepay.stores s ON s.id = ci.store_id
                JOIN onepay.companies oc ON oc.id = s.company_id
                LEFT JOIN onepay.catalog_categories cc ON cc.id = ci.category_id
                WHERE ci.id = :item_id AND oc.centryk_uuid = :uuid
                LIMIT 1
            ');
            $itemStmt->execute(['item_id' => $itemId, 'uuid' => $companyUuid]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                self::$lastError = 'Item not found for this company.';
                return $result;
            }
            $result['item'] = $item;
            $storeId = (int)$item['verified_store_id'];

            $groupsStmt = $pdo->prepare('SELECT id, name, selection_type, required, sort_order FROM onepay.item_modifier_groups WHERE item_id = :item_id ORDER BY sort_order ASC, id ASC');
            $groupsStmt->execute(['item_id' => $itemId]);
            $groups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($groups) {
                $groupIds = array_map(static fn($g) => (int)$g['id'], $groups);
                $in = implode(',', array_fill(0, count($groupIds), '?'));
                $optStmt = $pdo->prepare("SELECT id, group_id, name, price_delta, sort_order FROM onepay.item_modifier_options WHERE group_id IN ($in) ORDER BY sort_order ASC, id ASC");
                $optStmt->execute($groupIds);
                $optionsByGroup = [];
                foreach ($optStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
                    $optionsByGroup[(int)$opt['group_id']][] = $opt;
                }
                foreach ($groups as $g) {
                    $g['options'] = $optionsByGroup[(int)$g['id']] ?? [];
                    $result['modifiers'][] = $g;
                }
            }

            $linkStmt = $pdo->prepare('
                SELECT id, token, title, campaign_type, campaign_name, starts_at, ends_at, active, scan_count, last_scanned_at, created_at
                FROM onepay.promotional_links
                WHERE item_id = :item_id
                ORDER BY active DESC, created_at DESC
            ');
            $linkStmt->execute(['item_id' => $itemId]);
            $promoLinks = $linkStmt->fetchAll(PDO::FETCH_ASSOC);
            $onePayBase = self::onePayBaseUrl();
            foreach ($promoLinks as &$link) {
                $link['public_url'] = $onePayBase !== '' ? $onePayBase . '/promo.php?t=' . rawurlencode($link['token']) : '';
            }
            unset($link);
            $result['promotional_links'] = $promoLinks;

            $promoRulesStmt = $pdo->prepare("
                SELECT pr.id, pr.name, pr.promo_code, pr.promo_type, pr.discount_value, pr.starts_at, pr.ends_at, pr.active
                FROM onepay.promotion_rule_items pri
                JOIN onepay.promotion_rules pr ON pr.id = pri.promotion_rule_id
                WHERE pri.item_id = :item_id AND pr.store_id = :store_id AND pr.active = 1
                  AND (pr.starts_at IS NULL OR pr.starts_at <= NOW())
                  AND (pr.ends_at IS NULL OR pr.ends_at >= NOW())
                ORDER BY pr.name ASC
            ");
            $promoRulesStmt->execute(['item_id' => $itemId, 'store_id' => $storeId]);
            $result['active_promotions'] = $promoRulesStmt->fetchAll(PDO::FETCH_ASSOC);

            $scansStmt = $pdo->prepare('
                SELECT scan_source, scanned_value, success, created_at
                FROM onepay.scan_events
                WHERE matched_item_id = :item_id
                ORDER BY created_at DESC
                LIMIT 10
            ');
            $scansStmt->execute(['item_id' => $itemId]);
            $result['recent_scans'] = $scansStmt->fetchAll(PDO::FETCH_ASSOC);

            return $result;
        } catch (Throwable $e) {
            if (self::$lastError === '') {
                self::$lastError = 'Could not load OnePay item detail.';
            }
            return $result;
        }
    }

    /** Best-effort OnePay base URL, for building promo.php links in the local fallback. */
    private static function onePayBaseUrl(): string
    {
        $syncUrl = trim((string)($_ENV['ONEPAY_SYNC_URL'] ?? ''));
        if ($syncUrl !== '') {
            $parts = parse_url($syncUrl);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                return $parts['scheme'] . '://' . $parts['host'] . (!empty($parts['port']) ? ':' . $parts['port'] : '');
            }
        }
        return '';
    }

    private static function endpointUrl(string $filename = 'centryk-company-profile.php', string $envKey = 'ONEPAY_COMPANY_PROFILE_URL'): string
    {
        $explicit = trim((string)($_ENV[$envKey] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $syncUrl = trim((string)($_ENV['ONEPAY_SYNC_URL'] ?? ''));
        if ($syncUrl !== '') {
            return preg_replace('~/api/webhooks/[^/?#]+(?:[?#].*)?$~', '/api/webhooks/' . $filename, $syncUrl) ?: '';
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
                    return $base . '/api/webhooks/' . $filename;
                }
            }
        } catch (Throwable $e) {
            return '';
        }

        return '';
    }

    private static function normalize(array $payload): array
    {
        $base = self::empty();
        return [
            'company' => $payload['company'] ?? $base['company'],
            'stores' => is_array($payload['stores'] ?? null) ? $payload['stores'] : $base['stores'],
            'staff' => is_array($payload['staff'] ?? null) ? $payload['staff'] : $base['staff'],
            'pending_invites' => is_array($payload['pending_invites'] ?? null) ? $payload['pending_invites'] : $base['pending_invites'],
            'inventory' => is_array($payload['inventory'] ?? null) ? $payload['inventory'] : $base['inventory'],
            'promotions' => is_array($payload['promotions'] ?? null) ? $payload['promotions'] : $base['promotions'],
            'registers' => is_array($payload['registers'] ?? null) ? $payload['registers'] : $base['registers'],
            'restaurant_tables' => is_array($payload['restaurant_tables'] ?? null) ? $payload['restaurant_tables'] : $base['restaurant_tables'],
            'sales_summary' => is_array($payload['sales_summary'] ?? null) ? $payload['sales_summary'] : $base['sales_summary'],
            'customers_count' => (int)($payload['customers_count'] ?? 0),
        ];
    }

    private static function localFallback(string $companyUuid): array
    {
        try {
            $pdo = DB::pdo();

            $companyStmt = $pdo->prepare('
                SELECT id, name, legal_name, business_type, email, phone, address, status, created_at
                FROM onepay.companies WHERE centryk_uuid = :uuid LIMIT 1
            ');
            $companyStmt->execute(['uuid' => $companyUuid]);
            $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
            if (!$company) {
                self::$lastError = 'No matching OnePay company found for this UUID.';
                return self::empty();
            }

            $storesStmt = $pdo->prepare('SELECT id, name, legal_name, email, phone, status FROM onepay.stores WHERE company_id = :cid ORDER BY name ASC');
            $storesStmt->execute(['cid' => $company['id']]);
            $stores = $storesStmt->fetchAll(PDO::FETCH_ASSOC);
            $storeIds = array_map(static fn($s) => (int)$s['id'], $stores);

            $result = self::empty();
            $result['company'] = $company;
            $result['stores'] = $stores;
            if (!$storeIds) {
                return $result;
            }

            $in = implode(',', array_fill(0, count($storeIds), '?'));

            $staffStmt = $pdo->prepare("
                SELECT sm.id, sm.status, sm.joined_at, u.first_name, u.last_name, u.email, s.name AS store_name,
                       GROUP_CONCAT(DISTINCT r.label ORDER BY r.label SEPARATOR ', ') AS role_labels
                FROM onepay.store_memberships sm
                JOIN onepay.users u ON u.id = sm.user_id
                JOIN onepay.stores s ON s.id = sm.store_id
                LEFT JOIN onepay.membership_roles mr ON mr.membership_id = sm.id
                LEFT JOIN onepay.roles r ON r.id = mr.role_id
                WHERE sm.store_id IN ($in)
                GROUP BY sm.id
                ORDER BY sm.status ASC, u.first_name ASC
            ");
            $staffStmt->execute($storeIds);
            $result['staff'] = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

            $invitesStmt = $pdo->prepare("
                SELECT ui.email, ui.status, ui.expires_at, ui.created_at, s.name AS store_name
                FROM onepay.user_invitations ui
                JOIN onepay.stores s ON s.id = ui.store_id
                WHERE ui.store_id IN ($in) AND ui.status = 'pending'
                ORDER BY ui.created_at DESC
            ");
            $invitesStmt->execute($storeIds);
            $result['pending_invites'] = $invitesStmt->fetchAll(PDO::FETCH_ASSOC);

            $catStmt = $pdo->prepare("SELECT id, store_id, name, active FROM onepay.catalog_categories WHERE store_id IN ($in) ORDER BY name ASC");
            $catStmt->execute($storeIds);
            $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

            $itemsStmt = $pdo->prepare("
                SELECT ci.id, ci.name, ci.sku, ci.item_type, ci.price, ci.track_inventory, ci.stock_qty, ci.active,
                       cc.name AS category_name, s.name AS store_name
                FROM onepay.catalog_items ci
                JOIN onepay.stores s ON s.id = ci.store_id
                LEFT JOIN onepay.catalog_categories cc ON cc.id = ci.category_id
                WHERE ci.store_id IN ($in)
                ORDER BY s.name ASC, ci.name ASC
            ");
            $itemsStmt->execute($storeIds);
            $result['inventory'] = ['categories' => $categories, 'items' => $itemsStmt->fetchAll(PDO::FETCH_ASSOC)];

            $promoStmt = $pdo->prepare("
                SELECT p.id, p.name, p.promo_code, p.promo_type, p.discount_value, p.usage_limit, p.usage_count,
                       p.starts_at, p.ends_at, p.active, s.name AS store_name
                FROM onepay.promotion_rules p
                JOIN onepay.stores s ON s.id = p.store_id
                WHERE p.store_id IN ($in)
                ORDER BY p.active DESC, p.name ASC
            ");
            $promoStmt->execute($storeIds);
            $result['promotions'] = $promoStmt->fetchAll(PDO::FETCH_ASSOC);

            $regStmt = $pdo->prepare("
                SELECT r.id, r.name, r.register_code, r.active, r.is_main, s.name AS store_name
                FROM onepay.registers r
                JOIN onepay.stores s ON s.id = r.store_id
                WHERE r.store_id IN ($in)
                ORDER BY s.name ASC, r.name ASC
            ");
            $regStmt->execute($storeIds);
            $result['registers'] = $regStmt->fetchAll(PDO::FETCH_ASSOC);

            $tablesStmt = $pdo->prepare("
                SELECT t.id, t.label, t.section, t.seats, t.status, s.name AS store_name
                FROM onepay.restaurant_tables t
                JOIN onepay.stores s ON s.id = t.store_id
                WHERE t.store_id IN ($in)
                ORDER BY s.name ASC, t.sort_order ASC
            ");
            $tablesStmt->execute($storeIds);
            $result['restaurant_tables'] = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

            $allTimeStmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total
                FROM onepay.sales WHERE store_id IN ($in) AND status IN ('completed', 'partially_refunded')
            ");
            $allTimeStmt->execute($storeIds);
            $allTime = $allTimeStmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'total' => 0];

            $last30Stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total
                FROM onepay.sales WHERE store_id IN ($in) AND status IN ('completed', 'partially_refunded')
                  AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $last30Stmt->execute($storeIds);
            $last30 = $last30Stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'total' => 0];

            $result['sales_summary'] = [
                'all_time' => ['count' => (int)$allTime['cnt'], 'total' => (float)$allTime['total']],
                'last_30_days' => ['count' => (int)$last30['cnt'], 'total' => (float)$last30['total']],
            ];

            $custStmt = $pdo->prepare("SELECT COUNT(*) FROM onepay.customer_store_profiles WHERE store_id IN ($in) AND active = 1");
            $custStmt->execute($storeIds);
            $result['customers_count'] = (int)$custStmt->fetchColumn();

            return $result;
        } catch (Throwable $e) {
            if (self::$lastError === '') {
                self::$lastError = 'Could not load OnePay company profile.';
            }
            return self::empty();
        }
    }
}
