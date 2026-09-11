<?php

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';
require_once __DIR__ . '/NotificationService.php';

/**
 * Centryk Case Management — a free, native hub feature.
 *
 * A "case" is a generic trackable work item (intake -> assignment ->
 * resolution) with a status/priority, an activity timeline (status changes
 * and comments) and file attachments. Every company defines its own
 * categories/services — nothing here is hardcoded to one line of business.
 *
 * RBAC is plain company_members.role, same as Calendar: any active member
 * may open/track their own cases (their requests, and anything assigned to
 * them); admin/manager see and manage every case for the company, and manage
 * the categories/services. All reads/writes are company-scoped.
 */
class CaseManagementService
{
    public const STATUSES  = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    private static function pdo(): PDO
    {
        return DB::pdo();
    }

    // ── Company / membership ────────────────────────────────────────────

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

    /** Active members of a company, for the assignee picker. */
    public static function companyMembers(int $companyId): array
    {
        $st = self::pdo()->prepare("
            SELECT u.id, u.first_name, u.last_name, cm.role
            FROM company_members cm
            JOIN users u ON u.id = cm.user_id
            WHERE cm.company_id = :cid AND cm.status = 'active' AND u.status = 'active'
            ORDER BY u.first_name ASC, u.last_name ASC
        ");
        $st->execute(['cid' => $companyId]);
        return $st->fetchAll();
    }

    private static function isMember(int $userId, int $companyId): bool
    {
        $st = self::pdo()->prepare("
            SELECT 1 FROM company_members
            WHERE user_id = :u AND company_id = :c AND status = 'active' LIMIT 1
        ");
        $st->execute(['u' => $userId, 'c' => $companyId]);
        return (bool)$st->fetchColumn();
    }

    // ── Categories / services (admin/manager manage; everyone reads) ────

    public static function categories(int $companyId, bool $activeOnly = true): array
    {
        $sql = "SELECT id, company_id, name, sort_order, is_active FROM case_categories WHERE company_id = :cid";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, name ASC";
        $st = self::pdo()->prepare($sql);
        $st->execute(['cid' => $companyId]);
        return $st->fetchAll();
    }

    public static function services(int $companyId, bool $activeOnly = true): array
    {
        $sql = "
            SELECT s.id, s.company_id, s.category_id, s.name, s.description,
                   s.estimated_turnaround_days, s.is_active, c.name AS category_name
            FROM case_services s
            LEFT JOIN case_categories c ON c.id = s.category_id
            WHERE s.company_id = :cid";
        if ($activeOnly) {
            $sql .= " AND s.is_active = 1";
        }
        $sql .= " ORDER BY s.name ASC";
        $st = self::pdo()->prepare($sql);
        $st->execute(['cid' => $companyId]);
        return $st->fetchAll();
    }

    public static function saveCategory(int $companyId, array $in, int $actorId): int
    {
        $id   = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A category name is required.');
        }
        $active = array_key_exists('is_active', $in) ? (int)(bool)$in['is_active'] : 1;

        if ($id > 0) {
            self::pdo()->prepare("
                UPDATE case_categories SET name = :n, is_active = :a
                WHERE id = :id AND company_id = :cid
            ")->execute(['n' => $name, 'a' => $active, 'id' => $id, 'cid' => $companyId]);
        } else {
            $sortRow = self::pdo()->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM case_categories WHERE company_id = :cid");
            $sortRow->execute(['cid' => $companyId]);
            $sort = (int)$sortRow->fetchColumn();

            self::pdo()->prepare("
                INSERT INTO case_categories (company_id, name, sort_order, is_active)
                VALUES (:cid, :n, :s, :a)
            ")->execute(['cid' => $companyId, 'n' => $name, 's' => $sort, 'a' => $active]);
            $id = (int)self::pdo()->lastInsertId();
        }

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'case_category.save',
            'summary'       => 'Saved case category "' . $name . '"',
            'metadata'      => ['id' => $id],
        ]);
        return $id;
    }

    public static function saveService(int $companyId, array $in, int $actorId): int
    {
        $id   = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A service name is required.');
        }
        $categoryId = !empty($in['category_id']) ? (int)$in['category_id'] : null;
        if ($categoryId !== null) {
            $chk = self::pdo()->prepare("SELECT 1 FROM case_categories WHERE id = :id AND company_id = :cid");
            $chk->execute(['id' => $categoryId, 'cid' => $companyId]);
            if (!$chk->fetchColumn()) {
                $categoryId = null;
            }
        }
        $description = trim((string)($in['description'] ?? ''));
        $turnaround  = isset($in['estimated_turnaround_days']) && $in['estimated_turnaround_days'] !== ''
            ? max(0, (int)$in['estimated_turnaround_days']) : null;
        $active = array_key_exists('is_active', $in) ? (int)(bool)$in['is_active'] : 1;

        if ($id > 0) {
            self::pdo()->prepare("
                UPDATE case_services
                   SET category_id = :cat, name = :n, description = :d,
                       estimated_turnaround_days = :t, is_active = :a
                 WHERE id = :id AND company_id = :cid
            ")->execute([
                'cat' => $categoryId, 'n' => $name, 'd' => $description,
                't' => $turnaround, 'a' => $active, 'id' => $id, 'cid' => $companyId,
            ]);
        } else {
            self::pdo()->prepare("
                INSERT INTO case_services (company_id, category_id, name, description, estimated_turnaround_days, is_active)
                VALUES (:cid, :cat, :n, :d, :t, :a)
            ")->execute([
                'cid' => $companyId, 'cat' => $categoryId, 'n' => $name,
                'd' => $description, 't' => $turnaround, 'a' => $active,
            ]);
            $id = (int)self::pdo()->lastInsertId();
        }

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'case_service.save',
            'summary'       => 'Saved case service "' . $name . '"',
            'metadata'      => ['id' => $id],
        ]);
        return $id;
    }

    // ── Cases ─────────────────────────────────────────────────────────

    /**
     * Cases visible to $userId in $companyId. Admin/manager see every case;
     * everyone else sees only cases they filed or are assigned to.
     */
    public static function listCases(int $companyId, int $userId, string $role, array $filters = []): array
    {
        $where  = ['cr.company_id = :cid'];
        $params = ['cid' => $companyId];

        if (!in_array($role, ['admin', 'manager'], true)) {
            $where[] = '(cr.requester_user_id = :uid1 OR cr.assignee_user_id = :uid2)';
            $params['uid1'] = $userId;
            $params['uid2'] = $userId;
        } elseif (!empty($filters['mine'])) {
            $where[] = '(cr.requester_user_id = :uid1 OR cr.assignee_user_id = :uid2)';
            $params['uid1'] = $userId;
            $params['uid2'] = $userId;
        }

        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 'cr.status = :status';
            $params['status'] = $filters['status'];
        } elseif (empty($filters['include_closed'])) {
            $where[] = "cr.status <> 'closed'";
        }

        if (!empty($filters['priority']) && in_array($filters['priority'], self::PRIORITIES, true)) {
            $where[] = 'cr.priority = :priority';
            $params['priority'] = $filters['priority'];
        }

        $sql = "
            SELECT cr.id, cr.case_number, cr.subject, cr.priority, cr.status, cr.due_date,
                   cr.created_at, cr.updated_at,
                   s.name AS service_name, cat.name AS category_name,
                   ru.first_name AS req_first, ru.last_name AS req_last,
                   au.first_name AS asg_first, au.last_name AS asg_last
            FROM case_records cr
            LEFT JOIN case_services s ON s.id = cr.service_id
            LEFT JOIN case_categories cat ON cat.id = cr.category_id
            JOIN users ru ON ru.id = cr.requester_user_id
            LEFT JOIN users au ON au.id = cr.assignee_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY FIELD(cr.priority,'urgent','high','normal','low'), cr.created_at DESC
            LIMIT 200
        ";
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** A single case, only if $userId can see it (same rule as listCases). */
    public static function getCase(int $id, int $companyId, int $userId, string $role): ?array
    {
        $st = self::pdo()->prepare("
            SELECT cr.*, s.name AS service_name, cat.name AS category_name,
                   ru.first_name AS req_first, ru.last_name AS req_last, ru.email AS req_email,
                   au.first_name AS asg_first, au.last_name AS asg_last
            FROM case_records cr
            LEFT JOIN case_services s ON s.id = cr.service_id
            LEFT JOIN case_categories cat ON cat.id = cr.category_id
            JOIN users ru ON ru.id = cr.requester_user_id
            LEFT JOIN users au ON au.id = cr.assignee_user_id
            WHERE cr.id = :id AND cr.company_id = :cid
            LIMIT 1
        ");
        $st->execute(['id' => $id, 'cid' => $companyId]);
        $case = $st->fetch();
        if (!$case) {
            return null;
        }
        $isManager = in_array($role, ['admin', 'manager'], true);
        $isInvolved = (int)$case['requester_user_id'] === $userId || (int)($case['assignee_user_id'] ?? 0) === $userId;
        if (!$isManager && !$isInvolved) {
            return null;
        }
        return $case;
    }

    public static function createCase(int $companyId, int $requesterId, array $in): int
    {
        $subject = trim((string)($in['subject'] ?? ''));
        if ($subject === '') {
            throw new InvalidArgumentException('A subject is required.');
        }
        $description = trim((string)($in['description'] ?? ''));
        $priority = in_array($in['priority'] ?? '', self::PRIORITIES, true) ? $in['priority'] : 'normal';
        $dueDate  = !empty($in['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['due_date']) ? $in['due_date'] : null;

        $categoryId = !empty($in['category_id']) ? (int)$in['category_id'] : null;
        $serviceId  = !empty($in['service_id']) ? (int)$in['service_id'] : null;
        // A service implies its category unless one was explicitly given.
        if ($serviceId !== null) {
            $svc = self::pdo()->prepare("SELECT category_id FROM case_services WHERE id = :id AND company_id = :cid");
            $svc->execute(['id' => $serviceId, 'cid' => $companyId]);
            $svcRow = $svc->fetch();
            if (!$svcRow) {
                $serviceId = null;
            } elseif ($categoryId === null && $svcRow['category_id']) {
                $categoryId = (int)$svcRow['category_id'];
            }
        }

        self::pdo()->prepare("
            INSERT INTO case_records
                (company_id, category_id, service_id, subject, description, priority, requester_user_id, due_date)
            VALUES
                (:cid, :cat, :svc, :subj, :desc, :pri, :req, :due)
        ")->execute([
            'cid' => $companyId, 'cat' => $categoryId, 'svc' => $serviceId,
            'subj' => $subject, 'desc' => $description, 'pri' => $priority,
            'req' => $requesterId, 'due' => $dueDate,
        ]);
        $id = (int)self::pdo()->lastInsertId();

        self::pdo()->prepare("UPDATE case_records SET case_number = :num WHERE id = :id")
            ->execute(['num' => 'C-' . $id, 'id' => $id]);

        self::pdo()->prepare("
            INSERT INTO case_events (case_id, event_type, actor_user_id, to_value, note)
            VALUES (:id, 'created', :uid, 'open', :note)
        ")->execute(['id' => $id, 'uid' => $requesterId, 'note' => $subject]);

        Audit::log([
            'actor_user_id' => $requesterId,
            'company_id'    => $companyId,
            'event_type'    => 'case.created',
            'summary'       => 'Opened case C-' . $id . ': ' . $subject,
            'metadata'      => ['case_id' => $id],
        ]);

        return $id;
    }

    public static function changeStatus(int $caseId, int $companyId, int $actorId, string $newStatus, string $note = ''): void
    {
        if (!in_array($newStatus, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status.');
        }
        $cur = self::pdo()->prepare("SELECT status, requester_user_id, assignee_user_id, subject FROM case_records WHERE id = :id AND company_id = :cid");
        $cur->execute(['id' => $caseId, 'cid' => $companyId]);
        $row = $cur->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Case not found.');
        }
        $old = $row['status'];
        if ($old === $newStatus) {
            return;
        }

        $extra = '';
        if ($newStatus === 'resolved') {
            $extra = ', resolved_at = COALESCE(resolved_at, NOW())';
        } elseif ($newStatus === 'closed') {
            $extra = ', closed_at = COALESCE(closed_at, NOW())';
        }
        self::pdo()->prepare("UPDATE case_records SET status = :s {$extra} WHERE id = :id")
            ->execute(['s' => $newStatus, 'id' => $caseId]);

        self::pdo()->prepare("
            INSERT INTO case_events (case_id, event_type, actor_user_id, from_value, to_value, note)
            VALUES (:id, 'status_change', :uid, :from, :to, :note)
        ")->execute(['id' => $caseId, 'uid' => $actorId, 'from' => $old, 'to' => $newStatus, 'note' => $note]);

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'case.status_change',
            'summary'       => 'Case C-' . $caseId . ' ' . $old . ' -> ' . $newStatus,
            'metadata'      => ['case_id' => $caseId],
        ]);

        // Notify the requester (if someone else made the change).
        if ((int)$row['requester_user_id'] !== $actorId) {
            NotificationService::create([
                'user_id'    => (int)$row['requester_user_id'],
                'company_id' => $companyId,
                'app_key'    => 'centryk',
                'type'       => 'case_status',
                'title'      => 'Case C-' . $caseId . ' is now ' . str_replace('_', ' ', $newStatus),
                'body'       => $row['subject'],
                'url'        => 'case.php?id=' . $caseId,
                'icon'       => 'briefcase',
                'color'      => '#0f766e',
            ]);
        }
    }

    public static function assign(int $caseId, int $companyId, int $actorId, ?int $assigneeUserId): void
    {
        $cur = self::pdo()->prepare("SELECT assignee_user_id, subject FROM case_records WHERE id = :id AND company_id = :cid");
        $cur->execute(['id' => $caseId, 'cid' => $companyId]);
        $row = $cur->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Case not found.');
        }
        if ($assigneeUserId !== null && !self::isMember($assigneeUserId, $companyId)) {
            throw new InvalidArgumentException('That person is not a member of this company.');
        }
        $old = $row['assignee_user_id'] !== null ? (int)$row['assignee_user_id'] : null;
        if ($old === $assigneeUserId) {
            return;
        }

        self::pdo()->prepare("UPDATE case_records SET assignee_user_id = :a WHERE id = :id")
            ->execute(['a' => $assigneeUserId, 'id' => $caseId]);

        self::pdo()->prepare("
            INSERT INTO case_events (case_id, event_type, actor_user_id, from_value, to_value)
            VALUES (:id, 'assigned', :uid, :from, :to)
        ")->execute(['id' => $caseId, 'uid' => $actorId, 'from' => $old ?: null, 'to' => $assigneeUserId ?: null]);

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'case.assigned',
            'summary'       => 'Case C-' . $caseId . ' assigned',
            'metadata'      => ['case_id' => $caseId, 'assignee_user_id' => $assigneeUserId],
        ]);

        if ($assigneeUserId !== null && $assigneeUserId !== $actorId) {
            NotificationService::create([
                'user_id'    => $assigneeUserId,
                'company_id' => $companyId,
                'app_key'    => 'centryk',
                'type'       => 'case_assigned',
                'title'      => 'Case C-' . $caseId . ' assigned to you',
                'body'       => $row['subject'],
                'url'        => 'case.php?id=' . $caseId,
                'icon'       => 'briefcase',
                'color'      => '#0f766e',
            ]);
        }
    }

    public static function addComment(int $caseId, int $companyId, int $userId, string $body, bool $internal): int
    {
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('A comment cannot be empty.');
        }
        $cur = self::pdo()->prepare("SELECT requester_user_id, assignee_user_id, subject FROM case_records WHERE id = :id AND company_id = :cid");
        $cur->execute(['id' => $caseId, 'cid' => $companyId]);
        $row = $cur->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Case not found.');
        }

        self::pdo()->prepare("
            INSERT INTO case_events (case_id, event_type, actor_user_id, note, is_internal)
            VALUES (:id, 'comment', :uid, :note, :internal)
        ")->execute(['id' => $caseId, 'uid' => $userId, 'note' => $body, 'internal' => (int)$internal]);
        self::pdo()->prepare("UPDATE case_records SET updated_at = NOW() WHERE id = :id")->execute(['id' => $caseId]);
        $eventId = (int)self::pdo()->lastInsertId();

        if (!$internal) {
            foreach ([(int)$row['requester_user_id'], (int)($row['assignee_user_id'] ?? 0)] as $notify) {
                if ($notify > 0 && $notify !== $userId) {
                    NotificationService::create([
                        'user_id'    => $notify,
                        'company_id' => $companyId,
                        'app_key'    => 'centryk',
                        'type'       => 'case_comment',
                        'title'      => 'New comment on case C-' . $caseId,
                        'body'       => $row['subject'],
                        'url'        => 'case.php?id=' . $caseId,
                        'icon'       => 'briefcase',
                        'color'      => '#0f766e',
                    ]);
                }
            }
        }

        return $eventId;
    }

    /** The activity timeline for a case (status changes + comments), oldest first. */
    public static function events(int $caseId, bool $includeInternal = true): array
    {
        $sql = "
            SELECT ce.id, ce.event_type, ce.from_value, ce.to_value, ce.note, ce.is_internal, ce.created_at,
                   u.first_name, u.last_name
            FROM case_events ce
            LEFT JOIN users u ON u.id = ce.actor_user_id
            WHERE ce.case_id = :id";
        if (!$includeInternal) {
            $sql .= " AND ce.is_internal = 0";
        }
        $sql .= " ORDER BY ce.created_at ASC, ce.id ASC";
        $st = self::pdo()->prepare($sql);
        $st->execute(['id' => $caseId]);
        return $st->fetchAll();
    }

    public static function documents(int $caseId): array
    {
        $st = self::pdo()->prepare("
            SELECT d.id, d.original_filename, d.mime_type, d.file_size, d.created_at,
                   u.first_name, u.last_name
            FROM case_documents d
            JOIN users u ON u.id = d.uploaded_by
            WHERE d.case_id = :id
            ORDER BY d.created_at DESC
        ");
        $st->execute(['id' => $caseId]);
        return $st->fetchAll();
    }

    /** Where case document files are stored on disk (outside the web root). */
    public static function storageDir(int $companyId): string
    {
        return dirname(__DIR__, 2) . '/storage/cases/' . $companyId;
    }

    public static function addDocument(int $caseId, int $companyId, int $userId, string $originalName, string $storedName, ?string $mime, int $size): int
    {
        self::pdo()->prepare("
            INSERT INTO case_documents (case_id, uploaded_by, original_filename, stored_filename, mime_type, file_size)
            VALUES (:cid, :uid, :orig, :stored, :mime, :size)
        ")->execute([
            'cid' => $caseId, 'uid' => $userId, 'orig' => $originalName,
            'stored' => $storedName, 'mime' => $mime, 'size' => $size,
        ]);
        $id = (int)self::pdo()->lastInsertId();

        Audit::log([
            'actor_user_id' => $userId,
            'company_id'    => $companyId,
            'event_type'    => 'case.document_uploaded',
            'summary'       => 'Uploaded "' . $originalName . '" to case C-' . $caseId,
            'metadata'      => ['case_id' => $caseId, 'document_id' => $id],
        ]);

        return $id;
    }

    /** Quick counts for a company, scoped to what $userId can see (dashboard header). */
    public static function stats(int $companyId, int $userId, string $role): array
    {
        $isManager = in_array($role, ['admin', 'manager'], true);
        $scope = $isManager ? '' : ' AND (requester_user_id = :uid1 OR assignee_user_id = :uid2)';
        $params = ['cid' => $companyId];
        if (!$isManager) {
            $params['uid1'] = $userId;
            $params['uid2'] = $userId;
        }
        $st = self::pdo()->prepare("
            SELECT
                SUM(status IN ('open','in_progress','waiting')) AS open_count,
                SUM(status = 'open' AND assignee_user_id IS NULL) AS unassigned_count,
                SUM(status IN ('open','in_progress','waiting') AND due_date IS NOT NULL AND due_date < CURDATE()) AS overdue_count
            FROM case_records
            WHERE company_id = :cid {$scope}
        ");
        $st->execute($params);
        $row = $st->fetch() ?: [];
        return [
            'open'       => (int)($row['open_count'] ?? 0),
            'unassigned' => (int)($row['unassigned_count'] ?? 0),
            'overdue'    => (int)($row['overdue_count'] ?? 0),
        ];
    }
}
