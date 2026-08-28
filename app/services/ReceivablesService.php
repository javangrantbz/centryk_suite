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

        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO ar_payments (company_id, customer_id, received_on, amount, method, reference, notes, created_by)
                VALUES (:cid, :cust, :on, :amt, :method, :ref, :notes, :by)
            ")->execute([
                'cid' => $companyId, 'cust' => $customerId, 'on' => $receivedOn, 'amt' => $amount,
                'method' => $method, 'ref' => $reference, 'notes' => $notes, 'by' => $actorId,
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            $open = $pdo->prepare("
                SELECT i.id, i.total, i.amount_paid,
                       COALESCE(i.due_date, DATE_ADD(i.issue_date, INTERVAL COALESCE(c.payment_terms_days,0) DAY)) AS effective_due
                FROM invoices i
                JOIN customers c ON c.id = i.customer_id
                WHERE i.company_id = :cid AND i.customer_id = :cust AND i.status IN ('sent','overdue')
                  AND (i.total - i.amount_paid) > 0
                ORDER BY effective_due ASC, i.id ASC
            ");
            $open->execute(['cid' => $companyId, 'cust' => $customerId]);

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

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
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
}
