<?php
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Response.php';

/**
 * Centryk Business entitlements — the runtime gate for paid capability packages.
 *
 * `company_entitlements` holds one row per (company, package). This class is the
 * only thing hot-path code should read; it resolves a row to one of three
 * levels and enforces them on endpoints:
 *
 *   FULL  — state = active                → feature fully usable
 *   READ  — state = suspended (billing)   → view / export only, no writes
 *   NONE  — no row / revoked / expired    → feature hidden
 *
 * Commercial state (price, term, past_due, …) lives on `company_subscriptions`;
 * syncFromSubscription() maps that status onto the entitlement's state.
 *
 * Free-core companies have no rows here, so level() returns NONE for them and
 * nothing changes.
 */
class Entitlements
{
    public const FULL = 'full';
    public const READ = 'read';
    public const NONE = 'none';

    // ── Free preview promo ─────────────────────────────────────────────────
    // A company can opt into a limited-time free run of every Business package
    // from business.php. Each grant is an ordinary active entitlement with
    // source='promo' and this expiry — so every level() check keeps working
    // and the packages simply switch off after the date. Only the on-page
    // notice (promoInfo) treats it specially.
    public const PROMO_PACKAGES   = ['accounting', 'receivables', 'reconciliation', 'routes', 'enterprise'];
    public const PROMO_ENDS_ON    = '2027-12-31';
    public const PROMO_EXPIRES_AT = '2027-12-31 23:59:59';
    public const PROMO_PAID_FROM  = '2028-01-01';

    /** @var array<string,string> per-request memo, "companyId:packageKey" => level */
    private static array $memo = [];

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * Resolve a company's access level for one package key.
     */
    public static function level(int $companyId, string $packageKey): string
    {
        $cacheKey = $companyId . ':' . $packageKey;
        if (array_key_exists($cacheKey, self::$memo)) {
            return self::$memo[$cacheKey];
        }

        $stmt = DB::pdo()->prepare(
            'SELECT ce.state, ce.expires_at
               FROM company_entitlements ce
              WHERE ce.company_id = :company_id AND ce.package_key = :package_key
              LIMIT 1'
        );
        $stmt->execute(['company_id' => $companyId, 'package_key' => $packageKey]);
        $own = self::resolve($stmt->fetch() ?: null);

        // A company also inherits any package its parent group holds.
        if ($own !== self::FULL) {
            $g = DB::pdo()->prepare(
                'SELECT cge.state
                   FROM company_group_entitlements cge
                   JOIN companies c ON c.group_id = cge.group_id
                  WHERE c.id = :company_id AND cge.package_key = :package_key
                  LIMIT 1'
            );
            $g->execute(['company_id' => $companyId, 'package_key' => $packageKey]);
            $inherited = self::resolve($g->fetch() ?: null);
            $own = self::best($own, $inherited);
        }

        self::$memo[$cacheKey] = $own;
        return $own;
    }

    /** Higher of two levels (FULL > READ > NONE). */
    private static function best(string $a, string $b): string
    {
        $rank = [self::NONE => 0, self::READ => 1, self::FULL => 2];
        return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
    }

    /**
     * True only when the company can fully use the feature (state = active).
     */
    public static function has(int $companyId, string $packageKey): bool
    {
        return self::level($companyId, $packageKey) === self::FULL;
    }

    /**
     * Guard an endpoint. Sends a 402 JSON response and exits when the company
     * lacks the required level.
     *
     * @param bool $writing true (default) demands FULL; false also accepts READ
     *                       (so suspended companies can still view / export).
     */
    public static function assert(int $companyId, string $packageKey, bool $writing = true): void
    {
        $level = self::level($companyId, $packageKey);

        if ($level === self::FULL) {
            return;
        }
        if (!$writing && $level === self::READ) {
            return;
        }

        if ($level === self::READ) {
            Response::error(
                'Your Centryk Business subscription for this feature is inactive — it is read-only until billing is resolved.',
                402,
                ['entitlement' => $packageKey, 'level' => self::READ]
            );
        }

        Response::error(
            'This feature is part of Centryk Business.',
            402,
            ['entitlement' => $packageKey, 'level' => self::NONE]
        );
    }

    /**
     * Every non-NONE package for a company, as ['receivables' => 'full', …].
     * For UI gating — nav visibility and read-only banners.
     *
     * @return array<string,string>
     */
    public static function forCompany(int $companyId): array
    {
        $stmt = DB::pdo()->prepare(
            "SELECT package_key, state, expires_at, 'own' AS src
               FROM company_entitlements
              WHERE company_id = :cid1
             UNION ALL
             SELECT cge.package_key, cge.state, NULL AS expires_at, 'group' AS src
               FROM company_group_entitlements cge
               JOIN companies c ON c.group_id = cge.group_id
              WHERE c.id = :cid2"
        );
        $stmt->execute(['cid1' => $companyId, 'cid2' => $companyId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $level = self::resolve($row);
            $key = $row['package_key'];
            $out[$key] = isset($out[$key]) ? self::best($out[$key], $level) : $level;
        }
        foreach ($out as $key => $level) {
            self::$memo[$companyId . ':' . $key] = $level;
            if ($level === self::NONE) {
                unset($out[$key]);
            }
        }
        return $out;
    }

    /**
     * Resolve a group's own access level for a package (no inheritance upward).
     */
    public static function groupLevel(int $groupId, string $packageKey): string
    {
        $stmt = DB::pdo()->prepare(
            'SELECT state FROM company_group_entitlements
              WHERE group_id = :gid AND package_key = :key LIMIT 1'
        );
        $stmt->execute(['gid' => $groupId, 'key' => $packageKey]);
        return self::resolve($stmt->fetch() ?: null);
    }

    /** @return array<string,string> a group's non-NONE packages */
    public static function forGroup(int $groupId): array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT package_key, state FROM company_group_entitlements WHERE group_id = :gid'
        );
        $stmt->execute(['gid' => $groupId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $level = self::resolve($row);
            if ($level !== self::NONE) {
                $out[$row['package_key']] = $level;
            }
        }
        return $out;
    }

    // ── Lifecycle (used by the admin page + subscription sync) ───────────────

    /**
     * Grant or re-activate a package for a company. Idempotent on
     * (company_id, package_key). Writes an audit event.
     */
    public static function grant(
        int $companyId,
        string $packageKey,
        ?int $subscriptionId = null,
        ?int $actorUserId = null,
        string $source = 'admin_grant',
        ?string $expiresAt = null,
        string $notes = ''
    ): void {
        DB::pdo()->prepare(
            'INSERT INTO company_entitlements
                 (company_id, package_key, state, source, subscription_id, granted_by, expires_at, notes)
             VALUES
                 (:company_id, :package_key, "active", :source, :subscription_id, :granted_by, :expires_at, :notes)
             ON DUPLICATE KEY UPDATE
                 state           = "active",
                 source          = VALUES(source),
                 subscription_id = VALUES(subscription_id),
                 granted_by      = VALUES(granted_by),
                 expires_at      = VALUES(expires_at),
                 notes           = VALUES(notes),
                 suspended_at    = NULL,
                 revoked_at      = NULL'
        )->execute([
            'company_id'      => $companyId,
            'package_key'     => $packageKey,
            'source'          => $source,
            'subscription_id' => $subscriptionId,
            'granted_by'      => $actorUserId,
            'expires_at'      => $expiresAt,
            'notes'           => $notes,
        ]);

        self::$memo = [];
        Audit::log([
            'actor_user_id' => $actorUserId,
            'company_id'    => $companyId,
            'event_type'    => 'entitlement.granted',
            'summary'       => "Granted Centryk Business package: {$packageKey}",
            'metadata'      => [
                'package_key'     => $packageKey,
                'source'          => $source,
                'subscription_id' => $subscriptionId,
            ],
        ]);
    }

    /**
     * Billing lapsed — feature becomes read-only. No-op if there is no row.
     */
    public static function suspend(int $companyId, string $packageKey, ?int $actorUserId = null): void
    {
        self::transition($companyId, $packageKey, 'suspended', 'entitlement.suspended', $actorUserId, 'Suspended');
    }

    /**
     * Billing resolved — feature usable again.
     */
    public static function resume(int $companyId, string $packageKey, ?int $actorUserId = null): void
    {
        self::transition($companyId, $packageKey, 'active', 'entitlement.resumed', $actorUserId, 'Resumed');
    }

    /**
     * Turned off for good. Data is retained; the level resolves to NONE.
     */
    public static function revoke(int $companyId, string $packageKey, ?int $actorUserId = null): void
    {
        self::transition($companyId, $packageKey, 'revoked', 'entitlement.revoked', $actorUserId, 'Revoked');
    }

    // ── Free preview promo ─────────────────────────────────────────────────

    /** True while the free-preview offer is still open. */
    public static function promoActive(): bool
    {
        return date('Y-m-d') <= self::PROMO_ENDS_ON;
    }

    /**
     * Turn on the free preview for a company: grant every promo package with
     * the promo expiry. Never touches a package the company already holds
     * through a real grant (admin_grant / trial) — only fills the gaps.
     *
     * @return array{granted: array<string>, ends_on: string}
     * @throws RuntimeException if the offer has closed
     */
    public static function startPreview(int $companyId, ?int $actorUserId): array
    {
        if (!self::promoActive()) {
            throw new RuntimeException('The Centryk Business free preview has ended.');
        }

        $existing = DB::pdo()->prepare(
            "SELECT package_key, source FROM company_entitlements
              WHERE company_id = :c AND state <> 'revoked'"
        );
        $existing->execute(['c' => $companyId]);
        $have = [];
        foreach ($existing->fetchAll() as $row) {
            $have[$row['package_key']] = $row['source'];
        }

        $granted = [];
        foreach (self::PROMO_PACKAGES as $pkg) {
            if (isset($have[$pkg]) && $have[$pkg] !== 'promo') {
                continue; // a real grant is already in place — leave it alone
            }
            self::grant($companyId, $pkg, null, $actorUserId, 'promo', self::PROMO_EXPIRES_AT, 'Free preview');
            $granted[] = $pkg;
        }

        self::$memo = [];
        Audit::log([
            'actor_user_id' => $actorUserId,
            'company_id'    => $companyId,
            'event_type'    => 'entitlement.preview.started',
            'summary'       => 'Started the Centryk Business free preview (' . count($granted) . ' package(s), through ' . self::PROMO_ENDS_ON . ')',
            'metadata'      => ['granted' => $granted, 'ends_on' => self::PROMO_ENDS_ON],
        ]);

        return ['granted' => $granted, 'ends_on' => self::PROMO_ENDS_ON];
    }

    /**
     * Self-serve a single Centryk Business package — no advisor in the loop.
     * While the promo is open the grant carries the promo expiry; after it, the
     * package is granted as a trial with no end date (billing is handled
     * separately by the subscription system). An existing real grant is left
     * untouched.
     *
     * @return array{granted:bool, promo:bool, ends_on:string}
     */
    public static function startPackage(int $companyId, ?int $actorUserId, string $packageKey): array
    {
        if (!in_array($packageKey, self::PROMO_PACKAGES, true)) {
            throw new RuntimeException('Unknown Centryk Business package.');
        }

        $cur = DB::pdo()->prepare(
            "SELECT source, state FROM company_entitlements
              WHERE company_id = :c AND package_key = :k AND state <> 'revoked' LIMIT 1"
        );
        $cur->execute(['c' => $companyId, 'k' => $packageKey]);
        $row = $cur->fetch();
        if ($row && $row['source'] !== 'promo' && $row['state'] === 'active') {
            return ['granted' => false, 'promo' => false, 'ends_on' => self::PROMO_ENDS_ON];
        }

        $promo = self::promoActive();
        self::grant(
            $companyId,
            $packageKey,
            null,
            $actorUserId,
            $promo ? 'promo' : 'trial',
            $promo ? self::PROMO_EXPIRES_AT : null,
            $promo ? 'Self-serve (free preview)' : 'Self-serve trial'
        );

        return ['granted' => true, 'promo' => $promo, 'ends_on' => self::PROMO_ENDS_ON];
    }

    /**
     * Preview status for the on-page notice. Null when the company holds no
     * active promo entitlement.
     *
     * @return array{ends_on:string, paid_from:string, days_left:int, packages:int}|null
     */
    public static function promoInfo(int $companyId): ?array
    {
        $stmt = DB::pdo()->prepare(
            "SELECT COUNT(*) FROM company_entitlements
              WHERE company_id = :c AND source = 'promo' AND state = 'active'
                AND (expires_at IS NULL OR expires_at > NOW())"
        );
        $stmt->execute(['c' => $companyId]);
        $n = (int)$stmt->fetchColumn();
        if ($n === 0) {
            return null;
        }
        $daysLeft = (int)ceil((strtotime(self::PROMO_EXPIRES_AT) - time()) / 86400);
        return [
            'ends_on'   => self::PROMO_ENDS_ON,
            'paid_from' => self::PROMO_PAID_FROM,
            'days_left' => max(0, $daysLeft),
            'packages'  => $n,
        ];
    }

    // ── Group-level entitlements (Enterprise) ───────────────────────────────

    /**
     * Grant / re-activate a package for a whole company group. Member companies
     * inherit it. Idempotent on (group_id, package_key).
     */
    public static function grantGroup(int $groupId, string $packageKey, ?int $actorUserId = null, string $notes = ''): void
    {
        DB::pdo()->prepare(
            'INSERT INTO company_group_entitlements (group_id, package_key, state, granted_by, notes)
             VALUES (:gid, :key, "active", :by, :notes)
             ON DUPLICATE KEY UPDATE state = "active", granted_by = VALUES(granted_by),
                                     notes = VALUES(notes), revoked_at = NULL'
        )->execute(['gid' => $groupId, 'key' => $packageKey, 'by' => $actorUserId, 'notes' => $notes]);

        self::$memo = [];
        Audit::log([
            'actor_user_id' => $actorUserId,
            'event_type'    => 'entitlement.group.granted',
            'summary'       => "Granted group #{$groupId} the {$packageKey} package",
            'metadata'      => ['group_id' => $groupId, 'package_key' => $packageKey],
        ]);
    }

    public static function suspendGroup(int $groupId, string $packageKey, ?int $actorUserId = null): void
    {
        self::groupTransition($groupId, $packageKey, 'suspended', 'entitlement.group.suspended', $actorUserId, 'Suspended');
    }

    public static function resumeGroup(int $groupId, string $packageKey, ?int $actorUserId = null): void
    {
        self::groupTransition($groupId, $packageKey, 'active', 'entitlement.group.resumed', $actorUserId, 'Resumed');
    }

    public static function revokeGroup(int $groupId, string $packageKey, ?int $actorUserId = null): void
    {
        self::groupTransition($groupId, $packageKey, 'revoked', 'entitlement.group.revoked', $actorUserId, 'Revoked');
    }

    private static function groupTransition(int $groupId, string $packageKey, string $newState, string $eventType, ?int $actorUserId, string $verb): void
    {
        $sql = 'UPDATE company_group_entitlements SET state = :state';
        $sql .= $newState === 'revoked' ? ', revoked_at = NOW()' : ', revoked_at = NULL';
        $sql .= ' WHERE group_id = :gid AND package_key = :key';

        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute(['state' => $newState, 'gid' => $groupId, 'key' => $packageKey]);
        if ($stmt->rowCount() === 0) {
            return;
        }

        self::$memo = [];
        Audit::log([
            'actor_user_id' => $actorUserId,
            'event_type'    => $eventType,
            'summary'       => "{$verb} group #{$groupId} package: {$packageKey}",
            'metadata'      => ['group_id' => $groupId, 'package_key' => $packageKey],
        ]);
    }

    /**
     * Push a subscription's commercial status onto the entitlement it backs.
     * Call after any change to company_subscriptions.status.
     */
    public static function syncFromSubscription(int $subscriptionId, ?int $actorUserId = null): void
    {
        $stmt = DB::pdo()->prepare(
            'SELECT company_id, package_key, status FROM company_subscriptions WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $subscriptionId]);
        $sub = $stmt->fetch();
        if (!$sub) {
            return;
        }

        $target = match ($sub['status']) {
            'active', 'trialing' => 'active',
            'past_due', 'paused' => 'suspended',
            'canceled'           => 'revoked',
            default              => null,
        };
        if ($target === null) {
            return;
        }

        $companyId = (int)$sub['company_id'];
        $packageKey = (string)$sub['package_key'];
        $current = self::rawState($companyId, $packageKey);

        if ($current === null) {
            // No entitlement yet — only materialise one for a live subscription.
            if ($target === 'active') {
                self::grant($companyId, $packageKey, $subscriptionId, $actorUserId);
            }
            return;
        }
        if ($current === $target) {
            return;
        }

        match ($target) {
            'active'    => self::resume($companyId, $packageKey, $actorUserId),
            'suspended' => self::suspend($companyId, $packageKey, $actorUserId),
            'revoked'   => self::revoke($companyId, $packageKey, $actorUserId),
        };
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * @param array{state:string,expires_at:?string}|null $row
     */
    private static function resolve(?array $row): string
    {
        if ($row === null || $row['state'] === 'revoked') {
            return self::NONE;
        }
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) <= time()) {
            return self::NONE;
        }
        if ($row['state'] === 'suspended') {
            return self::READ;
        }
        if ($row['state'] === 'active') {
            return self::FULL;
        }
        return self::NONE;
    }

    private static function rawState(int $companyId, string $packageKey): ?string
    {
        $stmt = DB::pdo()->prepare(
            'SELECT state FROM company_entitlements WHERE company_id = :c AND package_key = :p LIMIT 1'
        );
        $stmt->execute(['c' => $companyId, 'p' => $packageKey]);
        $row = $stmt->fetch();
        return $row ? (string)$row['state'] : null;
    }

    private static function transition(
        int $companyId,
        string $packageKey,
        string $newState,
        string $eventType,
        ?int $actorUserId,
        string $summaryVerb
    ): void {
        $sql = 'UPDATE company_entitlements SET state = :state';
        if ($newState === 'active') {
            $sql .= ', suspended_at = NULL, revoked_at = NULL';
        } elseif ($newState === 'suspended') {
            $sql .= ', suspended_at = NOW()';
        } elseif ($newState === 'revoked') {
            $sql .= ', revoked_at = NOW()';
        }
        $sql .= ' WHERE company_id = :company_id AND package_key = :package_key';

        $affected = DB::pdo()->prepare($sql);
        $affected->execute([
            'state'       => $newState,
            'company_id'  => $companyId,
            'package_key' => $packageKey,
        ]);

        if ($affected->rowCount() === 0) {
            return; // nothing to transition — don't log a phantom event
        }

        self::$memo = [];
        Audit::log([
            'actor_user_id' => $actorUserId,
            'company_id'    => $companyId,
            'event_type'    => $eventType,
            'summary'       => "{$summaryVerb} Centryk Business package: {$packageKey}",
            'metadata'      => ['package_key' => $packageKey],
        ]);
    }
}
