<?php

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';

/**
 * Centryk Sales Leads — a free, native hub feature for companies with field
 * sales agents (e.g. a meat shop selling packaged meats to other businesses).
 * An agent records who they sold to (business, contact, order, address);
 * admin/manager get a company-wide roster to follow up on — reorder calls,
 * "how's it selling" check-ins — with a per-lead activity log.
 *
 * Deliberately separate from invoice-maker/receivables' `customers` table
 * (billing records with tax_number/credit_limit/ar_status) — a sales lead is
 * a prospect/contact an agent met in the field, not a billing party.
 *
 * RBAC is plain company_members.role, same as Case Management: any active
 * member may add a lead and see the ones they added; admin/manager see and
 * manage the whole roster and log follow-ups. All reads/writes are
 * company-scoped.
 */
class SalesLeadService
{
    public const STATUSES = ['new', 'contacted', 'reordered', 'inactive'];

    private static function pdo(): PDO
    {
        return DB::pdo();
    }

    /** Every active company this user belongs to, with their role. */
    public static function companiesFor(int $userId): array
    {
        $st = self::pdo()->prepare("
            SELECT c.id, c.uuid, c.name, cm.role
            FROM company_members cm
            JOIN companies c ON c.id = cm.company_id
            WHERE cm.user_id = :uid AND cm.status = 'active' AND c.status = 'active'
            ORDER BY c.name ASC
        ");
        $st->execute(['uid' => $userId]);
        return $st->fetchAll();
    }

    public static function create(int $companyId, int $actorId, array $in): int
    {
        $businessName = trim((string)($in['business_name'] ?? ''));
        if ($businessName === '') {
            throw new InvalidArgumentException('Business name is required.');
        }
        $contactName = trim((string)($in['contact_name'] ?? ''));
        $phone       = trim((string)($in['phone'] ?? ''));
        $email       = trim((string)($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('That email address doesn\'t look right.');
        }
        $address      = trim((string)($in['address'] ?? ''));
        $orderDetails = trim((string)($in['order_details'] ?? ''));
        $orderValue   = null;
        if (isset($in['order_value']) && $in['order_value'] !== '') {
            if (!is_numeric($in['order_value']) || (float)$in['order_value'] < 0) {
                throw new InvalidArgumentException('Order value must be a positive number.');
            }
            $orderValue = round((float)$in['order_value'], 2);
        }

        self::pdo()->prepare("
            INSERT INTO sales_leads
                (company_id, business_name, contact_name, phone, email, address, order_details, order_value, created_by)
            VALUES
                (:cid, :biz, :contact, :phone, :email, :addr, :order, :val, :creator)
        ")->execute([
            'cid' => $companyId, 'biz' => $businessName, 'contact' => $contactName,
            'phone' => $phone, 'email' => $email, 'addr' => $address,
            'order' => $orderDetails, 'val' => $orderValue, 'creator' => $actorId,
        ]);
        $id = (int)self::pdo()->lastInsertId();

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'sales_lead.created',
            'summary'       => 'Added sales lead "' . $businessName . '"',
            'metadata'      => ['lead_id' => $id],
        ]);

        return $id;
    }

    /** The most recently added leads by $userId (their own), for the mobile entry page. */
    public static function recentByAgent(int $companyId, int $userId, int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        $st = self::pdo()->prepare("
            SELECT id, business_name, contact_name, phone, created_at
            FROM sales_leads
            WHERE company_id = :cid AND created_by = :uid
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $st->execute(['cid' => $companyId, 'uid' => $userId]);
        return $st->fetchAll();
    }

    /**
     * Leads visible to $userId in $companyId. Admin/manager see every lead;
     * everyone else sees only leads they added.
     */
    public static function list(int $companyId, int $userId, string $role, array $filters = []): array
    {
        $where  = ['l.company_id = :cid'];
        $params = ['cid' => $companyId];

        if (!in_array($role, ['admin', 'manager'], true)) {
            $where[] = 'l.created_by = :uid';
            $params['uid'] = $userId;
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'l.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['due'])) {
            $where[] = "l.next_follow_up_date IS NOT NULL AND l.next_follow_up_date <= CURDATE() AND l.status <> 'inactive'";
        }
        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(l.business_name LIKE :q1 OR l.contact_name LIKE :q2)';
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }

        $sql = "
            SELECT l.id, l.business_name, l.contact_name, l.phone, l.email, l.address,
                   l.order_details, l.order_value, l.status, l.next_follow_up_date, l.created_at,
                   u.first_name AS agent_first, u.last_name AS agent_last
            FROM sales_leads l
            JOIN users u ON u.id = l.created_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (l.next_follow_up_date IS NOT NULL AND l.next_follow_up_date <= CURDATE()) DESC,
                     l.created_at DESC
            LIMIT 500
        ";
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** A single lead, only if $userId can see it (same rule as list()). */
    public static function get(int $id, int $companyId, int $userId, string $role): ?array
    {
        $st = self::pdo()->prepare("
            SELECT l.*, u.first_name AS agent_first, u.last_name AS agent_last
            FROM sales_leads l
            JOIN users u ON u.id = l.created_by
            WHERE l.id = :id AND l.company_id = :cid
            LIMIT 1
        ");
        $st->execute(['id' => $id, 'cid' => $companyId]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $isManager = in_array($role, ['admin', 'manager'], true);
        if (!$isManager && (int)$row['created_by'] !== $userId) {
            return null;
        }
        return $row;
    }

    /** Log a follow-up (call/email/visit note) and optionally change status / next-follow-up date. */
    public static function addFollowUp(int $leadId, int $companyId, int $actorId, array $in): int
    {
        $cur = self::pdo()->prepare("SELECT status, business_name FROM sales_leads WHERE id = :id AND company_id = :cid");
        $cur->execute(['id' => $leadId, 'cid' => $companyId]);
        $row = $cur->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Lead not found.');
        }

        $note = trim((string)($in['note'] ?? ''));
        $newStatus = (string)($in['status'] ?? '');
        $changeStatus = $newStatus !== '' && in_array($newStatus, self::STATUSES, true) && $newStatus !== $row['status'];
        if ($note === '' && !$changeStatus) {
            throw new InvalidArgumentException('Add a note or change the status.');
        }

        $nextDate = null;
        if (!empty($in['next_follow_up_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['next_follow_up_date'])) {
            $nextDate = $in['next_follow_up_date'];
        }

        $sets = ['updated_at = NOW()'];
        $params = ['id' => $leadId];
        if ($changeStatus) {
            $sets[] = 'status = :status';
            $params['status'] = $newStatus;
        }
        // next_follow_up_date is always settable (even to null, to clear it) when the key is present.
        if (array_key_exists('next_follow_up_date', $in)) {
            $sets[] = 'next_follow_up_date = :next';
            $params['next'] = $nextDate;
        }
        self::pdo()->prepare("UPDATE sales_leads SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);

        self::pdo()->prepare("
            INSERT INTO sales_lead_follow_ups (lead_id, actor_user_id, note, from_status, to_status)
            VALUES (:lid, :uid, :note, :from, :to)
        ")->execute([
            'lid' => $leadId, 'uid' => $actorId,
            'note' => $note !== '' ? $note : null,
            'from' => $changeStatus ? $row['status'] : null,
            'to'   => $changeStatus ? $newStatus : null,
        ]);
        $id = (int)self::pdo()->lastInsertId();

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'sales_lead.follow_up',
            'summary'       => 'Follow-up logged for "' . $row['business_name'] . '"',
            'metadata'      => ['lead_id' => $leadId],
        ]);

        return $id;
    }

    /** The follow-up timeline for a lead, oldest first. */
    public static function followUps(int $leadId): array
    {
        $st = self::pdo()->prepare("
            SELECT f.id, f.note, f.from_status, f.to_status, f.created_at,
                   u.first_name, u.last_name
            FROM sales_lead_follow_ups f
            JOIN users u ON u.id = f.actor_user_id
            WHERE f.lead_id = :id
            ORDER BY f.created_at ASC, f.id ASC
        ");
        $st->execute(['id' => $leadId]);
        return $st->fetchAll();
    }

    /** Quick counts for a company's roster header (admin/manager view). */
    public static function stats(int $companyId): array
    {
        $st = self::pdo()->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'new') AS new_count,
                SUM(next_follow_up_date IS NOT NULL AND next_follow_up_date <= CURDATE() AND status <> 'inactive') AS due_count
            FROM sales_leads
            WHERE company_id = :cid
        ");
        $st->execute(['cid' => $companyId]);
        $row = $st->fetch() ?: [];
        return [
            'total' => (int)($row['total'] ?? 0),
            'new'   => (int)($row['new_count'] ?? 0),
            'due'   => (int)($row['due_count'] ?? 0),
        ];
    }
}
