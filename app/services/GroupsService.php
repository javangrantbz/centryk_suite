<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';
require_once __DIR__ . '/../core/Entitlements.php';
require_once __DIR__ . '/ReceivablesService.php';
require_once __DIR__ . '/ReconciliationService.php';
require_once __DIR__ . '/RoutesService.php';

/**
 * Company groups (Enterprise package) — a parent that owns several companies,
 * with a consolidated view and group-level package entitlements the members
 * inherit.
 *
 * Membership/permission checks live here; the API guard (group_guard.php) also
 * confirms the group holds 'enterprise' before any of this is reachable from
 * the customer-facing page.
 */
class GroupsService
{
    /** Groups a user belongs to, with their role. */
    public static function forUser(int $userId): array
    {
        $stmt = DB::pdo()->prepare("
            SELECT g.id, g.name, g.status, m.role,
                   (SELECT COUNT(*) FROM companies c WHERE c.group_id = g.id) AS company_count
            FROM company_group_members m
            JOIN company_groups g ON g.id = m.group_id
            WHERE m.user_id = :uid AND m.status = 'active' AND g.status = 'active'
            ORDER BY g.name ASC
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function role(int $groupId, int $userId): ?string
    {
        $stmt = DB::pdo()->prepare("
            SELECT role FROM company_group_members
            WHERE group_id = :gid AND user_id = :uid AND status = 'active' LIMIT 1
        ");
        $stmt->execute(['gid' => $groupId, 'uid' => $userId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? $r['role'] : null;
    }

    /** Group meta + member companies (with inherited package levels) + members. */
    public static function detail(int $groupId): ?array
    {
        $pdo = DB::pdo();
        $g = $pdo->prepare("SELECT id, uuid, name, status, created_at FROM company_groups WHERE id = :id LIMIT 1");
        $g->execute(['id' => $groupId]);
        $group = $g->fetch(PDO::FETCH_ASSOC);
        if (!$group) {
            return null;
        }

        $co = $pdo->prepare("SELECT id, name, status FROM companies WHERE group_id = :gid ORDER BY name ASC");
        $co->execute(['gid' => $groupId]);
        $companies = $co->fetchAll(PDO::FETCH_ASSOC);
        foreach ($companies as &$c) {
            $c['id'] = (int)$c['id'];
            $c['entitlements'] = Entitlements::forCompany($c['id']);
        }
        unset($c);

        $mem = $pdo->prepare("
            SELECT m.user_id, m.role, m.status,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS name,
                   u.email
            FROM company_group_members m
            JOIN users u ON u.id = m.user_id
            WHERE m.group_id = :gid
            ORDER BY (m.role = 'group_admin') DESC, name ASC
        ");
        $mem->execute(['gid' => $groupId]);

        $group['companies']   = $companies;
        $group['members']     = $mem->fetchAll(PDO::FETCH_ASSOC);
        $group['entitlements'] = Entitlements::forGroup($groupId);
        return $group;
    }

    /** Companies the user administers that aren't in any group yet. */
    public static function attachableFor(int $userId): array
    {
        $stmt = DB::pdo()->prepare("
            SELECT c.id, c.name
            FROM company_members cm
            JOIN companies c ON c.id = cm.company_id
            WHERE cm.user_id = :uid AND cm.role = 'admin' AND cm.status = 'active'
              AND c.status = 'active' AND c.group_id IS NULL
            ORDER BY c.name ASC
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Consolidated numbers across the member companies. Each metric is only
     * included for companies actually entitled to that package.
     */
    public static function consolidated(int $groupId): array
    {
        $pdo = DB::pdo();
        $co = $pdo->prepare("SELECT id, name FROM companies WHERE group_id = :gid AND status = 'active' ORDER BY name ASC");
        $co->execute(['gid' => $groupId]);

        $rows = [];
        $tot = ['ar_outstanding' => 0.0, 'ar_overdue' => 0.0, 'cash_in_transit' => 0.0, 'unmatched_deposits' => 0, 'unmatched_value' => 0.0];

        foreach ($co->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cid = (int)$c['id'];
            $row = ['company_id' => $cid, 'name' => $c['name'], 'ar_outstanding' => null, 'ar_overdue' => null, 'cash_in_transit' => null, 'unmatched_value' => null];

            if (Entitlements::level($cid, 'receivables') !== Entitlements::NONE) {
                $p = ReceivablesService::portfolio($cid)['totals'];
                $row['ar_outstanding'] = $p['balance'];
                $row['ar_overdue']     = $p['overdue'];
                $tot['ar_outstanding'] += $p['balance'];
                $tot['ar_overdue']     += $p['overdue'];
            }
            if (Entitlements::level($cid, 'routes') !== Entitlements::NONE) {
                $s = RoutesService::summary($cid);
                $row['cash_in_transit'] = $s['cash_in_transit'];
                $tot['cash_in_transit'] += $s['cash_in_transit'];
            }
            if (Entitlements::level($cid, 'reconciliation') !== Entitlements::NONE) {
                $s = ReconciliationService::summary($cid);
                $row['unmatched_value'] = $s['unmatched_value'];
                $tot['unmatched_deposits'] += $s['unmatched_credits'];
                $tot['unmatched_value']    += $s['unmatched_value'];
            }
            $rows[] = $row;
        }

        foreach ($tot as $k => $v) {
            $tot[$k] = is_float($v) ? round($v, 2) : $v;
        }
        return ['companies' => $rows, 'totals' => $tot];
    }

    /**
     * Consolidated AR aging across every member company that holds Receivables.
     * Per-company aging buckets + a group total that sums them.
     *
     * @return array{companies:array<array>, totals:array<string,float>}
     */
    public static function consolidatedAging(int $groupId): array
    {
        $pdo = DB::pdo();
        $co = $pdo->prepare("SELECT id, name FROM companies WHERE group_id = :gid AND status = 'active' ORDER BY name ASC");
        $co->execute(['gid' => $groupId]);

        $companies = [];
        $t = ['current' => 0.0, 'b_1_30' => 0.0, 'b_31_60' => 0.0, 'b_61_90' => 0.0, 'b_90p' => 0.0,
              'balance' => 0.0, 'overdue' => 0.0, 'accounts' => 0];

        foreach ($co->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cid = (int)$c['id'];
            if (Entitlements::level($cid, 'receivables') === Entitlements::NONE) {
                $companies[] = ['company_id' => $cid, 'name' => $c['name'], 'entitled' => false];
                continue;
            }
            $p = ReceivablesService::portfolio($cid);
            $pt = $p['totals'];
            $accounts = 0;
            foreach ($p['customers'] as $cust) {
                if (abs((float)$cust['balance']) > 0.004) { $accounts++; }
            }
            $companies[] = [
                'company_id' => $cid,
                'name'       => $c['name'],
                'entitled'   => true,
                'current'    => $pt['current'],
                'b_1_30'     => $pt['b_1_30'],
                'b_31_60'    => $pt['b_31_60'],
                'b_61_90'    => $pt['b_61_90'],
                'b_90p'      => $pt['b_90p'],
                'balance'    => $pt['balance'],
                'overdue'    => $pt['overdue'],
                'accounts'   => $accounts,
            ];
            foreach (['current', 'b_1_30', 'b_31_60', 'b_61_90', 'b_90p', 'balance', 'overdue'] as $k) {
                $t[$k] += (float)$pt[$k];
            }
            $t['accounts'] += $accounts;
        }
        foreach ($t as $k => $v) { $t[$k] = is_float($v) ? round($v, 2) : $v; }

        return ['companies' => $companies, 'totals' => $t];
    }

    /**
     * Recent activity across the group — audit events for any member company,
     * plus the group's own entitlement / membership changes.
     */
    public static function activity(int $groupId, int $limit = 60): array
    {
        $pdo = DB::pdo();
        $ids = $pdo->prepare("SELECT id FROM companies WHERE group_id = :gid");
        $ids->execute(['gid' => $groupId]);
        $companyIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));

        $limit = max(1, min(200, $limit));
        $clauses = ["((a.event_type LIKE 'entitlement.group.%' OR a.event_type LIKE 'group.%') AND a.metadata_json REGEXP ?)"];
        $params  = ['"group_id":' . $groupId . '([,}])'];

        if ($companyIds) {
            $clauses[] = 'a.company_id IN (' . implode(',', array_fill(0, count($companyIds), '?')) . ')';
            $params = array_merge($params, $companyIds);
        }

        $sql = "
            SELECT a.event_type, a.summary, a.company_id, c.name AS company_name, a.created_at,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS actor_name
            FROM audit_events a
            LEFT JOIN companies c ON c.id = a.company_id
            LEFT JOIN users u ON u.id = a.actor_user_id
            WHERE " . implode(' OR ', $clauses) . "
            ORDER BY a.created_at DESC
            LIMIT " . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── mutations ──────────────────────────────────────────────────────────

    /**
     * Self-serve: a company admin creates their own group and it is switched on
     * immediately (the group gets the Enterprise entitlement so the consolidated
     * view works) — no Centryk advisor needed.
     *
     * @return int  the new group id
     */
    public static function createForUser(int $userId, string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Give the group a name.');
        }

        $isCompanyAdmin = DB::pdo()->prepare("
            SELECT 1 FROM company_members
            WHERE user_id = :uid AND role = 'admin' AND status = 'active' LIMIT 1
        ");
        $isCompanyAdmin->execute(['uid' => $userId]);
        if (!$isCompanyAdmin->fetch()) {
            throw new RuntimeException('Only a company admin can create a group.');
        }

        $groupId = self::saveGroup($userId, ['name' => $name]);
        Entitlements::grantGroup(
            $groupId,
            'enterprise',
            $userId,
            Entitlements::promoActive() ? 'Self-serve group (free preview)' : 'Self-serve group'
        );
        return $groupId;
    }

    /** Create a group (creator becomes owner + group_admin) or rename one. */
    public static function saveGroup(int $actorId, array $d, bool $actorIsPlatformAdmin = false): int
    {
        $pdo = DB::pdo();
        $id   = (int)($d['id'] ?? 0);
        $name = trim((string)($d['name'] ?? ''));
        $isRename = $id > 0;
        if ($name === '') {
            throw new InvalidArgumentException('Group name is required.');
        }

        if ($id > 0) {
            if (!$actorIsPlatformAdmin && self::role($id, $actorId) !== 'group_admin') {
                throw new RuntimeException('Only a group admin can rename the group.');
            }
            $pdo->prepare("UPDATE company_groups SET name = :n WHERE id = :id")->execute(['n' => $name, 'id' => $id]);
        } else {
            $pdo->prepare("
                INSERT INTO company_groups (uuid, name, owner_user_id, created_by)
                VALUES (:uuid, :n, :owner, :by)
            ")->execute(['uuid' => self::uuid(), 'n' => $name, 'owner' => $actorId, 'by' => $actorId]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare("
                INSERT INTO company_group_members (group_id, user_id, role) VALUES (:gid, :uid, 'group_admin')
            ")->execute(['gid' => $id, 'uid' => $actorId]);
        }

        Audit::log([
            'actor_user_id' => $actorId, 'event_type' => 'group.saved',
            'summary' => ($isRename ? 'Renamed' : 'Created') . " company group: {$name}",
            'metadata' => ['group_id' => $id],
        ]);
        return $id;
    }

    /**
     * Put a company under a group. The actor must be a group_admin (or platform
     * admin) and an admin of the company being attached.
     */
    public static function attachCompany(int $groupId, int $companyId, int $actorId, bool $actorIsPlatformAdmin = false): void
    {
        $pdo = DB::pdo();
        if (!$actorIsPlatformAdmin) {
            if (self::role($groupId, $actorId) !== 'group_admin') {
                throw new RuntimeException('Only a group admin can attach companies.');
            }
            $chk = $pdo->prepare("
                SELECT 1 FROM company_members
                WHERE company_id = :cid AND user_id = :uid AND role = 'admin' AND status = 'active' LIMIT 1
            ");
            $chk->execute(['cid' => $companyId, 'uid' => $actorId]);
            if (!$chk->fetch()) {
                throw new RuntimeException('You must be an admin of that company to add it.');
            }
        }

        $c = $pdo->prepare("SELECT id, name, group_id FROM companies WHERE id = :id LIMIT 1");
        $c->execute(['id' => $companyId]);
        $company = $c->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            throw new RuntimeException('Company not found.');
        }
        if ($company['group_id'] !== null && (int)$company['group_id'] !== $groupId) {
            throw new RuntimeException('That company already belongs to another group.');
        }

        $pdo->prepare("UPDATE companies SET group_id = :gid WHERE id = :id")
            ->execute(['gid' => $groupId, 'id' => $companyId]);

        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId, 'event_type' => 'group.company.attached',
            'summary' => "Added {$company['name']} to group #{$groupId}",
            'metadata' => ['group_id' => $groupId, 'company_id' => $companyId],
        ]);
    }

    public static function detachCompany(int $groupId, int $companyId, int $actorId, bool $actorIsPlatformAdmin = false): void
    {
        if (!$actorIsPlatformAdmin && self::role($groupId, $actorId) !== 'group_admin') {
            throw new RuntimeException('Only a group admin can remove companies.');
        }
        $upd = DB::pdo()->prepare("UPDATE companies SET group_id = NULL WHERE id = :id AND group_id = :gid");
        $upd->execute(['id' => $companyId, 'gid' => $groupId]);
        if ($upd->rowCount() === 0) {
            throw new RuntimeException('That company is not in this group.');
        }
        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId, 'event_type' => 'group.company.detached',
            'summary' => "Removed company #{$companyId} from group #{$groupId}",
            'metadata' => ['group_id' => $groupId, 'company_id' => $companyId],
        ]);
    }

    public static function setMember(int $groupId, int $userId, string $role, int $actorId, bool $actorIsPlatformAdmin = false): void
    {
        if (!$actorIsPlatformAdmin && self::role($groupId, $actorId) !== 'group_admin') {
            throw new RuntimeException('Only a group admin can manage members.');
        }
        if (!in_array($role, ['group_admin', 'group_viewer', 'remove'], true)) {
            throw new InvalidArgumentException('Unknown role.');
        }
        $pdo = DB::pdo();

        if ($role === 'remove' || $role === 'group_viewer') {
            // Don't strip the last admin.
            $admins = (int)$pdo->query("SELECT COUNT(*) FROM company_group_members WHERE group_id = " . (int)$groupId . " AND role = 'group_admin' AND status = 'active'")->fetchColumn();
            $cur = self::role($groupId, $userId);
            if ($cur === 'group_admin' && $admins <= 1) {
                throw new RuntimeException('A group must keep at least one admin.');
            }
        }

        if ($role === 'remove') {
            $pdo->prepare("DELETE FROM company_group_members WHERE group_id = :gid AND user_id = :uid")
                ->execute(['gid' => $groupId, 'uid' => $userId]);
        } else {
            $pdo->prepare("
                INSERT INTO company_group_members (group_id, user_id, role) VALUES (:gid, :uid, :role)
                ON DUPLICATE KEY UPDATE role = VALUES(role), status = 'active'
            ")->execute(['gid' => $groupId, 'uid' => $userId, 'role' => $role]);
        }

        Audit::log([
            'actor_user_id' => $actorId, 'target_user_id' => $userId, 'event_type' => 'group.member.set',
            'summary' => "Group #{$groupId} member #{$userId} -> {$role}",
            'metadata' => ['group_id' => $groupId, 'role' => $role],
        ]);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
