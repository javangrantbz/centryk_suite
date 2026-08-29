<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';

/**
 * Receivables (Centryk Business) — the account-level layer over the invoicing
 * tables. Every method is company-scoped; callers must already have checked the
 * user's company membership and the 'receivables' entitlement.
 *
 * Balance model:
 *   customer balance = opening_balance
 *                    + Σ (invoice.total - invoice.amount_paid)   [open invoices]
 *                    - unallocated credit from ar_payments
 *
 * "Open" invoice = status in ('sent','overdue'). 'draft' and 'cancelled' never
 * touch the ledger; 'paid' contributes nothing.
 */
class ReceivablesService
{
    private const OPEN = "'sent','overdue'";

    /** SQL expression for an invoice's effective due date. */
    private const DUE_EXPR =
        "COALESCE(i.due_date, DATE_ADD(i.issue_date, INTERVAL COALESCE(c.payment_terms_days, 0) DAY))";

    /**
     * Portfolio view: every customer with balance, overdue amount and aging.
     * @return array{customers: array<int,array>, totals: array<string,float>}
     */
    public static function portfolio(int $companyId): array
    {
        $pdo = DB::pdo();

        $stmt = $pdo->prepare("
            SELECT
                c.id, c.name, c.company, c.email, c.phone,
                c.credit_limit, c.payment_terms_days, c.on_hold, c.opening_balance,
                COALESCE(inv.open_total, 0)   AS open_total,
                COALESCE(inv.overdue_total, 0) AS overdue_total,
                COALESCE(inv.b_current, 0) AS b_current,
                COALESCE(inv.b_1_30, 0)   AS b_1_30,
                COALESCE(inv.b_31_60, 0)  AS b_31_60,
                COALESCE(inv.b_61_90, 0)  AS b_61_90,
                COALESCE(inv.b_90p, 0)    AS b_90p,
                COALESCE(cr.credit, 0)    AS unallocated_credit
            FROM customers c
            LEFT JOIN (
                SELECT i.customer_id,
                    SUM(i.total - i.amount_paid) AS open_total,
                    SUM(CASE WHEN " . self::DUE_EXPR . " < CURDATE() THEN i.total - i.amount_paid ELSE 0 END) AS overdue_total,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), " . self::DUE_EXPR . ") <= 0                                    THEN i.total - i.amount_paid ELSE 0 END) AS b_current,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), " . self::DUE_EXPR . ") BETWEEN 1 AND 30                        THEN i.total - i.amount_paid ELSE 0 END) AS b_1_30,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), " . self::DUE_EXPR . ") BETWEEN 31 AND 60                       THEN i.total - i.amount_paid ELSE 0 END) AS b_31_60,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), " . self::DUE_EXPR . ") BETWEEN 61 AND 90                       THEN i.total - i.amount_paid ELSE 0 END) AS b_61_90,
                    SUM(CASE WHEN DATEDIFF(CURDATE(), " . self::DUE_EXPR . ") > 90                                    THEN i.total - i.amount_paid ELSE 0 END) AS b_90p
                FROM invoices i
                JOIN customers c ON c.id = i.customer_id
                WHERE i.company_id = :cid1 AND i.status IN (" . self::OPEN . ")
                GROUP BY i.customer_id
            ) inv ON inv.customer_id = c.id
            LEFT JOIN (
                SELECT p.customer_id,
                       SUM(p.amount) - COALESCE(SUM(a.allocated), 0) AS credit
                FROM ar_payments p
                LEFT JOIN (
                    SELECT ar_payment_id, SUM(amount) AS allocated
                    FROM ar_payment_allocations GROUP BY ar_payment_id
                ) a ON a.ar_payment_id = p.id
                WHERE p.company_id = :cid2
                GROUP BY p.customer_id
            ) cr ON cr.customer_id = c.id
            WHERE c.company_id = :cid3 AND c.ar_status = 'active'
            ORDER BY c.name ASC
        ");
        $stmt->execute(['cid1' => $companyId, 'cid2' => $companyId, 'cid3' => $companyId]);

        $customers = [];
        $totals = ['balance' => 0.0, 'overdue' => 0.0, 'current' => 0.0, 'b_1_30' => 0.0, 'b_31_60' => 0.0, 'b_61_90' => 0.0, 'b_90p' => 0.0, 'over_limit' => 0, 'on_hold' => 0];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $balance = (float)$r['opening_balance'] + (float)$r['open_total'] - (float)$r['unallocated_credit'];
            $overLimit = $r['credit_limit'] !== null && $balance > (float)$r['credit_limit'];

            $customers[] = [
                'id'                 => (int)$r['id'],
                'name'               => $r['name'],
                'company'            => $r['company'],
                'email'              => $r['email'],
                'phone'              => $r['phone'],
                'credit_limit'       => $r['credit_limit'] !== null ? (float)$r['credit_limit'] : null,
                'payment_terms_days' => (int)$r['payment_terms_days'],
                'on_hold'            => (bool)$r['on_hold'],
                'opening_balance'    => (float)$r['opening_balance'],
                'balance'            => round($balance, 2),
                'overdue'            => round((float)$r['overdue_total'], 2),
                'unallocated_credit' => round((float)$r['unallocated_credit'], 2),
                'over_limit'         => $overLimit,
                'aging'              => [
                    'current' => round((float)$r['b_current'], 2),
                    'b_1_30'  => round((float)$r['b_1_30'], 2),
                    'b_31_60' => round((float)$r['b_31_60'], 2),
                    'b_61_90' => round((float)$r['b_61_90'], 2),
                    'b_90p'   => round((float)$r['b_90p'], 2),
                ],
            ];

            $totals['balance']  += $balance;
            $totals['overdue']  += (float)$r['overdue_total'];
            $totals['current']  += (float)$r['b_current'];
            $totals['b_1_30']   += (float)$r['b_1_30'];
            $totals['b_31_60']  += (float)$r['b_31_60'];
            $totals['b_61_90']  += (float)$r['b_61_90'];
            $totals['b_90p']    += (float)$r['b_90p'];
            $totals['over_limit'] += $overLimit ? 1 : 0;
            $totals['on_hold']    += $r['on_hold'] ? 1 : 0;
        }

        foreach ($totals as $k => $v) {
            $totals[$k] = is_float($v) ? round($v, 2) : $v;
        }

        return ['customers' => $customers, 'totals' => $totals];
    }

    /**
     * One customer's statement: their invoices and receipts, plus computed balance.
     */
    public static function statement(int $companyId, int $customerId): ?array
    {
        $pdo = DB::pdo();

        $cs = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND company_id = :cid LIMIT 1");
        $cs->execute(['id' => $customerId, 'cid' => $companyId]);
        $customer = $cs->fetch(PDO::FETCH_ASSOC);
        if (!$customer) {
            return null;
        }

        $inv = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.status, i.issue_date, i.due_date,
                   " . self::DUE_EXPR . " AS effective_due,
                   i.total, i.amount_paid, (i.total - i.amount_paid) AS outstanding,
                   DATEDIFF(CURDATE(), " . self::DUE_EXPR . ") AS days_overdue
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            WHERE i.company_id = :cid AND i.customer_id = :cust
              AND i.status IN ('draft','sent','overdue','paid')
            ORDER BY i.issue_date DESC, i.id DESC
        ");
        $inv->execute(['cid' => $companyId, 'cust' => $customerId]);
        $invoices = $inv->fetchAll(PDO::FETCH_ASSOC);

        $pay = $pdo->prepare("
            SELECT p.id, p.received_on, p.amount, p.method, p.reference, p.notes, p.created_at,
                   COALESCE(SUM(a.amount), 0) AS allocated
            FROM ar_payments p
            LEFT JOIN ar_payment_allocations a ON a.ar_payment_id = p.id
            WHERE p.company_id = :cid AND p.customer_id = :cust
            GROUP BY p.id, p.received_on, p.amount, p.method, p.reference, p.notes, p.created_at
            ORDER BY p.received_on DESC, p.id DESC
        ");
        $pay->execute(['cid' => $companyId, 'cust' => $customerId]);
        $payments = $pay->fetchAll(PDO::FETCH_ASSOC);

        $openOutstanding = 0.0;
        foreach ($invoices as $i) {
            if (in_array($i['status'], ['sent', 'overdue'], true)) {
                $openOutstanding += (float)$i['outstanding'];
            }
        }
        $credit = 0.0;
        foreach ($payments as $p) {
            $credit += (float)$p['amount'] - (float)$p['allocated'];
        }
        $balance = (float)$customer['opening_balance'] + $openOutstanding - $credit;

        $rem = $pdo->prepare("
            SELECT r.id, r.kind, r.channel, r.subject, r.balance_at, r.overdue_at, r.sent_at, r.created_at,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS by_name
            FROM ar_reminders r
            LEFT JOIN users u ON u.id = r.created_by
            WHERE r.company_id = :cid AND r.customer_id = :cust
            ORDER BY r.created_at DESC LIMIT 20
        ");
        $rem->execute(['cid' => $companyId, 'cust' => $customerId]);

        return [
            'customer'           => [
                'id'                 => (int)$customer['id'],
                'name'               => $customer['name'],
                'company'            => $customer['company'],
                'email'              => $customer['email'],
                'phone'              => $customer['phone'],
                'address'            => $customer['address'],
                'credit_limit'       => $customer['credit_limit'] !== null ? (float)$customer['credit_limit'] : null,
                'payment_terms_days' => (int)$customer['payment_terms_days'],
                'on_hold'            => (bool)$customer['on_hold'],
                'opening_balance'    => (float)$customer['opening_balance'],
            ],
            'balance'            => round($balance, 2),
            'unallocated_credit' => round($credit, 2),
            'invoices'           => $invoices,
            'payments'           => $payments,
            'reminders'          => $rem->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Overdue accounts, worst first — the collections work list.
     */
    public static function collections(int $companyId): array
    {
        $due = self::DUE_EXPR;
        $stmt = DB::pdo()->prepare("
            SELECT c.id, c.name, c.email, c.phone, c.on_hold, c.credit_limit,
                   SUM(i.total - i.amount_paid) AS overdue_total,
                   MAX(DATEDIFF(CURDATE(), {$due})) AS oldest_days,
                   COUNT(*) AS overdue_invoices,
                   (SELECT MAX(r.created_at) FROM ar_reminders r WHERE r.customer_id = c.id) AS last_reminder_at,
                   (SELECT COUNT(*) FROM ar_reminders r WHERE r.customer_id = c.id) AS reminder_count
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            WHERE i.company_id = :cid AND i.status IN ('sent','overdue')
              AND (i.total - i.amount_paid) > 0 AND {$due} < CURDATE()
            GROUP BY c.id, c.name, c.email, c.phone, c.on_hold, c.credit_limit
            ORDER BY overdue_total DESC, oldest_days DESC
        ");
        $stmt->execute(['cid' => $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['overdue_total'] = round((float)$r['overdue_total'], 2);
            $r['oldest_days'] = (int)$r['oldest_days'];
            $r['overdue_invoices'] = (int)$r['overdue_invoices'];
            $r['reminder_count'] = (int)$r['reminder_count'];
            $r['on_hold'] = (bool)$r['on_hold'];
        }
        return $rows;
    }

    /** Suggested subject + body for chasing a customer's overdue balance. */
    public static function reminderDraft(int $companyId, int $customerId): array
    {
        $s = self::statement($companyId, $customerId);
        if ($s === null) {
            throw new RuntimeException('Customer not found.');
        }
        $pdo = DB::pdo();
        $biz = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(s.business_name), ''), c.name) AS name
            FROM companies c LEFT JOIN invoice_settings s ON s.company_id = c.id
            WHERE c.id = :cid LIMIT 1
        ");
        $biz->execute(['cid' => $companyId]);
        $bizName = (string)($biz->fetchColumn() ?: 'our company');

        $overdue = 0.0;
        $oldest = null;
        foreach ($s['invoices'] as $i) {
            if (in_array($i['status'], ['sent', 'overdue'], true) && (int)$i['days_overdue'] > 0) {
                $overdue += (float)$i['outstanding'];
                if ($oldest === null || (int)$i['days_overdue'] > $oldest['days']) {
                    $oldest = ['num' => $i['invoice_number'], 'days' => (int)$i['days_overdue']];
                }
            }
        }
        $cust = $s['customer']['name'];
        $money = static fn ($v) => number_format($v, 2);

        $subject = "Overdue account — {$bizName}";
        $body = "Hello {$cust},\n\n"
            . "Your account with {$bizName} shows a balance of BZD {$money($s['balance'])}, "
            . "of which BZD {$money($overdue)} is past due.\n";
        if ($oldest) {
            $body .= "The oldest outstanding invoice is {$oldest['num']}, {$oldest['days']} days past due.\n";
        }
        $body .= "\nPlease arrange payment, or reply to let us know when we can expect it. "
            . "If you've already paid, thank you — please disregard this notice.\n\n"
            . "Regards,\n{$bizName}";

        return ['subject' => $subject, 'body' => $body, 'balance' => $s['balance'], 'overdue' => round($overdue, 2)];
    }

    public static function logReminder(int $companyId, int $customerId, array $d, ?int $actorId): int
    {
        $pdo = DB::pdo();
        $cust = $pdo->prepare("SELECT id, name FROM customers WHERE id = :id AND company_id = :cid LIMIT 1");
        $cust->execute(['id' => $customerId, 'cid' => $companyId]);
        $cust = $cust->fetch(PDO::FETCH_ASSOC);
        if (!$cust) {
            throw new RuntimeException('Customer not found.');
        }

        $draft = self::reminderDraft($companyId, $customerId);
        $kind    = in_array($d['kind'] ?? '', ['statement', 'due_soon', 'overdue', 'final_notice'], true) ? $d['kind'] : 'overdue';
        $channel = in_array($d['channel'] ?? '', ['email', 'phone', 'in_person', 'other'], true) ? $d['channel'] : 'email';
        $markSent = !empty($d['mark_sent']);

        $pdo->prepare("
            INSERT INTO ar_reminders (company_id, customer_id, kind, channel, subject, body, balance_at, overdue_at, sent_at, created_by)
            VALUES (:cid, :cust, :kind, :ch, :subj, :body, :bal, :od, :sent, :by)
        ")->execute([
            'cid' => $companyId, 'cust' => $customerId, 'kind' => $kind, 'ch' => $channel,
            'subj' => mb_substr(trim((string)($d['subject'] ?? $draft['subject'])), 0, 190),
            'body' => (string)($d['body'] ?? $draft['body']),
            'bal' => $draft['balance'], 'od' => $draft['overdue'],
            'sent' => $markSent ? date('Y-m-d H:i:s') : null, 'by' => $actorId,
        ]);
        $id = (int)$pdo->lastInsertId();

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => $markSent ? 'receivables.reminder.sent' : 'receivables.reminder.logged',
            'summary'       => ($markSent ? 'Sent' : 'Logged') . " {$kind} reminder to {$cust['name']} ({$channel})",
            'metadata'      => ['reminder_id' => $id, 'customer_id' => $customerId, 'kind' => $kind, 'channel' => $channel],
        ]);
        return $id;
    }

    /**
     * Credit standing for a customer — for other apps to gate new orders/invoices.
     * @return array{status:string, balance:float, credit_limit:?float, available:?float, on_hold:bool}
     */
    public static function creditStatus(int $companyId, int $customerId): array
    {
        $s = self::statement($companyId, $customerId);
        if ($s === null) {
            throw new RuntimeException('Customer not found.');
        }
        $bal   = $s['balance'];
        $limit = $s['customer']['credit_limit'];
        $hold  = $s['customer']['on_hold'];
        $over  = $limit !== null && $bal > $limit;

        $status = 'ok';
        if ($hold && $over) { $status = 'blocked'; }
        elseif ($hold)      { $status = 'hold'; }
        elseif ($over)      { $status = 'over_limit'; }

        return [
            'status'       => $status,
            'balance'      => $bal,
            'credit_limit' => $limit,
            'available'    => $limit !== null ? round($limit - $bal, 2) : null,
            'on_hold'      => $hold,
        ];
    }

    /**
     * Create or update a customer's AR profile. Returns the customer id.
     */
    public static function saveCustomer(int $companyId, array $data, ?int $actorId): int
    {
        $pdo = DB::pdo();

        $id      = (int)($data['id'] ?? 0);
        $name    = trim((string)($data['name'] ?? ''));
        $company = trim((string)($data['company'] ?? '')) ?: null;
        $email   = trim((string)($data['email'] ?? '')) ?: null;
        $phone   = trim((string)($data['phone'] ?? '')) ?: null;
        $limitRaw = $data['credit_limit'] ?? null;
        $limit   = ($limitRaw === '' || $limitRaw === null) ? null : round((float)$limitRaw, 2);
        $terms   = max(0, (int)($data['payment_terms_days'] ?? 0));
        $opening = round((float)($data['opening_balance'] ?? 0), 2);

        if ($name === '') {
            throw new InvalidArgumentException('Customer name is required.');
        }

        if ($id > 0) {
            $chk = $pdo->prepare("SELECT id FROM customers WHERE id = :id AND company_id = :cid LIMIT 1");
            $chk->execute(['id' => $id, 'cid' => $companyId]);
            if (!$chk->fetch()) {
                throw new RuntimeException('Customer not found.');
            }
            $pdo->prepare("
                UPDATE customers SET name = :name, company = :company, email = :email, phone = :phone,
                       credit_limit = :limit, payment_terms_days = :terms, opening_balance = :opening
                WHERE id = :id
            ")->execute([
                'name' => $name, 'company' => $company, 'email' => $email, 'phone' => $phone,
                'limit' => $limit, 'terms' => $terms, 'opening' => $opening, 'id' => $id,
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO customers (company_id, name, company, email, phone, credit_limit, payment_terms_days, opening_balance, created_by)
                VALUES (:cid, :name, :company, :email, :phone, :limit, :terms, :opening, :by)
            ")->execute([
                'cid' => $companyId, 'name' => $name, 'company' => $company, 'email' => $email, 'phone' => $phone,
                'limit' => $limit, 'terms' => $terms, 'opening' => $opening, 'by' => $actorId,
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'receivables.customer.saved',
            'summary'       => 'Saved AR customer ' . $name,
            'metadata'      => ['customer_id' => $id, 'credit_limit' => $limit, 'terms' => $terms],
        ]);

        return $id;
    }

    public static function setHold(int $companyId, int $customerId, bool $onHold, ?int $actorId): void
    {
        $pdo = DB::pdo();
        $upd = $pdo->prepare("UPDATE customers SET on_hold = :h WHERE id = :id AND company_id = :cid");
        $upd->execute(['h' => $onHold ? 1 : 0, 'id' => $customerId, 'cid' => $companyId]);
        if ($upd->rowCount() === 0) {
            throw new RuntimeException('Customer not found.');
        }
        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => $onHold ? 'receivables.customer.held' : 'receivables.customer.released',
            'summary'       => ($onHold ? 'Placed on' : 'Released from') . ' credit hold: customer #' . $customerId,
            'metadata'      => ['customer_id' => $customerId],
        ]);
    }

    /**
     * Record a receipt against a customer account and apply it to open invoices,
     * oldest due first. Any remainder is kept as an unallocated credit.
     *
     * @return array{payment_id:int, allocated:float, credit:float, invoices:int}
     */
    public static function recordPayment(int $companyId, int $customerId, array $data, ?int $actorId): array
    {
        $pdo = DB::pdo();

        $amount     = round((float)($data['amount'] ?? 0), 2);
        $method     = (string)($data['method'] ?? 'other');
        $receivedOn = (string)($data['received_on'] ?? date('Y-m-d'));
        $reference  = trim((string)($data['reference'] ?? ''));
        $notes      = trim((string)($data['notes'] ?? ''));

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
        if (!in_array($method, ['cash', 'card', 'bank_transfer', 'xfer', 'cheque', 'other'], true)) {
            $method = 'other';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedOn) || strtotime($receivedOn) === false) {
            $receivedOn = date('Y-m-d');
        }

        $cust = $pdo->prepare("SELECT id, name FROM customers WHERE id = :id AND company_id = :cid LIMIT 1");
        $cust->execute(['id' => $customerId, 'cid' => $companyId]);
        $cust = $cust->fetch(PDO::FETCH_ASSOC);
        if (!$cust) {
            throw new RuntimeException('Customer not found.');
        }

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            $pdo->prepare("
                INSERT INTO ar_payments (company_id, customer_id, received_on, amount, method, reference, notes, created_by)
                VALUES (:cid, :cust, :on, :amt, :method, :ref, :notes, :by)
            ")->execute([
                'cid' => $companyId, 'cust' => $customerId, 'on' => $receivedOn, 'amt' => $amount,
                'method' => $method, 'ref' => $reference, 'notes' => $notes, 'by' => $actorId,
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            // A caller (e.g. bank reconciliation) can name the invoice this
            // receipt clears; it's filled first, then the rest ages oldest-first.
            $targetInvoiceId = (int)($data['target_invoice_id'] ?? 0);

            $open = $pdo->prepare("
                SELECT i.id, i.total, i.amount_paid,
                       COALESCE(i.due_date, DATE_ADD(i.issue_date, INTERVAL COALESCE(c.payment_terms_days,0) DAY)) AS effective_due
                FROM invoices i
                JOIN customers c ON c.id = i.customer_id
                WHERE i.company_id = :cid AND i.customer_id = :cust AND i.status IN ('sent','overdue')
                  AND (i.total - i.amount_paid) > 0
                ORDER BY (i.id = :target) DESC, effective_due ASC, i.id ASC
            ");
            $open->execute(['cid' => $companyId, 'cust' => $customerId, 'target' => $targetInvoiceId]);

            $remaining = $amount;
            $touched = 0;
            foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $i) {
                if ($remaining <= 0.004) {
                    break;
                }
                $outstanding = round((float)$i['total'] - (float)$i['amount_paid'], 2);
                $alloc = min($remaining, $outstanding);
                if ($alloc <= 0) {
                    continue;
                }

                $pdo->prepare("
                    INSERT INTO ar_payment_allocations (ar_payment_id, invoice_id, amount)
                    VALUES (:pid, :iid, :amt)
                ")->execute(['pid' => $paymentId, 'iid' => (int)$i['id'], 'amt' => $alloc]);

                $newPaid = round((float)$i['amount_paid'] + $alloc, 2);
                $newStatus = $newPaid + 0.004 >= (float)$i['total'] ? 'paid' : 'sent';
                $pdo->prepare("UPDATE invoices SET amount_paid = :paid, status = :st WHERE id = :id")
                    ->execute(['paid' => $newPaid, 'st' => $newStatus, 'id' => (int)$i['id']]);

                $remaining = round($remaining - $alloc, 2);
                $touched++;
            }

            Audit::log([
                'actor_user_id' => $actorId,
                'company_id'    => $companyId,
                'event_type'    => 'receivables.payment.recorded',
                'summary'       => 'Recorded ' . number_format($amount, 2) . ' from ' . $cust['name']
                    . ' (' . $method . '), applied to ' . $touched . ' invoice(s)',
                'metadata'      => [
                    'payment_id'  => $paymentId,
                    'customer_id' => $customerId,
                    'amount'      => $amount,
                    'method'      => $method,
                    'allocated'   => round($amount - $remaining, 2),
                    'credit'      => $remaining,
                ],
            ]);

            if ($ownTxn) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'payment_id' => $paymentId,
            'allocated'  => round($amount - $remaining, 2),
            'credit'     => round($remaining, 2),
            'invoices'   => $touched,
        ];
    }

    /**
     * Reverse a receipt: undo each allocation (drop invoice.amount_paid, reopen
     * status) and delete the ar_payments row (allocations cascade).
     * Used when a bank-reconciliation match is undone.
     */
    public static function voidPayment(int $companyId, int $paymentId, ?int $actorId): void
    {
        $pdo = DB::pdo();

        $pay = $pdo->prepare("SELECT id, amount FROM ar_payments WHERE id = :id AND company_id = :cid LIMIT 1");
        $pay->execute(['id' => $paymentId, 'cid' => $companyId]);
        $pay = $pay->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            throw new RuntimeException('Receipt not found.');
        }

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            $allocs = $pdo->prepare("SELECT invoice_id, amount FROM ar_payment_allocations WHERE ar_payment_id = :pid");
            $allocs->execute(['pid' => $paymentId]);
            foreach ($allocs->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $inv = $pdo->prepare("SELECT total, amount_paid FROM invoices WHERE id = :id AND company_id = :cid LIMIT 1");
                $inv->execute(['id' => (int)$a['invoice_id'], 'cid' => $companyId]);
                $row = $inv->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    continue;
                }
                $newPaid = max(0, round((float)$row['amount_paid'] - (float)$a['amount'], 2));
                $newStatus = $newPaid + 0.004 >= (float)$row['total'] ? 'paid' : 'sent';
                $pdo->prepare("UPDATE invoices SET amount_paid = :paid, status = :st WHERE id = :id")
                    ->execute(['paid' => $newPaid, 'st' => $newStatus, 'id' => (int)$a['invoice_id']]);
            }

            $pdo->prepare("DELETE FROM ar_payments WHERE id = :id")->execute(['id' => $paymentId]);

            Audit::log([
                'actor_user_id' => $actorId,
                'company_id'    => $companyId,
                'event_type'    => 'receivables.payment.voided',
                'summary'       => 'Voided receipt #' . $paymentId . ' (' . number_format((float)$pay['amount'], 2) . ')',
                'metadata'      => ['payment_id' => $paymentId, 'amount' => (float)$pay['amount']],
            ]);

            if ($ownTxn) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
