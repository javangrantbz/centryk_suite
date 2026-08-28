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
            'SELECT state, expires_at
               FROM company_entitlements
              WHERE company_id = :company_id AND package_key = :package_key
              LIMIT 1'
        );
        $stmt->execute(['company_id' => $companyId, 'package_key' => $packageKey]);
        $row = $stmt->fetch();

        $level = self::resolve($row ?: null);
        self::$memo[$cacheKey] = $level;
        return $level;
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
            'SELECT package_key, state, expires_at
               FROM company_entitlements
              WHERE company_id = :company_id'
        );
        $stmt->execute(['company_id' => $companyId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $level = self::resolve($row);
            self::$memo[$companyId . ':' . $row['package_key']] = $level;
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
