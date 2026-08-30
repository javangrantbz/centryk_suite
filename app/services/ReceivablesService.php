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
                WHERE p.company_id = :cid2 AND p.clearance_status <> 'bounced'
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
              AND i.status IN ('draft','sent','overdue','paid','written_off')
            ORDER BY i.issue_date DESC, i.id DESC
        ");
        $inv->execute(['cid' => $companyId, 'cust' => $customerId]);
        $invoices = $inv->fetchAll(PDO::FETCH_ASSOC);

        $pay = $pdo->prepare("
            SELECT p.id, p.received_on, p.amount, p.method, p.reference, p.notes, p.created_at,
                   p.cheque_number, p.cheque_date, p.clearance_status, p.bounce_reason,
                   COALESCE(SUM(a.amount), 0) AS allocated
            FROM ar_payments p
            LEFT JOIN ar_payment_allocations a ON a.ar_payment_id = p.id
            WHERE p.company_id = :cid AND p.customer_id = :cust
            GROUP BY p.id
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
            if ($p['clearance_status'] === 'bounced') {
                continue; // a bounced cheque is not money
            }
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

        $wo = $pdo->prepare("
            SELECT w.id, w.invoice_id, i.invoice_number, w.amount, w.kind, w.reason, w.status,
                   w.proposed_at, w.decided_at, w.decision_note,
                   TRIM(CONCAT(COALESCE(pu.first_name,''),' ',COALESCE(pu.last_name,''))) AS proposed_by_name,
                   TRIM(CONCAT(COALESCE(au.first_name,''),' ',COALESCE(au.last_name,''))) AS approved_by_name
            FROM ar_writeoffs w
            JOIN invoices i ON i.id = w.invoice_id
            LEFT JOIN users pu ON pu.id = w.proposed_by
            LEFT JOIN users au ON au.id = w.approved_by
            WHERE w.company_id = :cid AND w.customer_id = :cust AND w.status <> 'void'
            ORDER BY (w.status = 'pending') DESC, w.id DESC
        ");
        $wo->execute(['cid' => $companyId, 'cust' => $customerId]);

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
            'writeoffs'          => $wo->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Everything a printable customer statement needs: the company letterhead,
     * the customer, a chronological ledger (invoice = charge, receipt = credit)
     * with a running balance, and the aging split.
     */
    public static function statementDocument(int $companyId, int $customerId): ?array
    {
        $s = self::statement($companyId, $customerId);
        if ($s === null) {
            return null;
        }
        $pdo = DB::pdo();

        $lh = $pdo->prepare("
            SELECT COALESCE(NULLIF(TRIM(v.business_name),''), c.name)    AS name,
                   COALESCE(NULLIF(TRIM(v.business_email),''), c.email)   AS email,
                   COALESCE(NULLIF(TRIM(v.business_phone),''), c.phone)   AS phone,
                   COALESCE(NULLIF(TRIM(v.business_address),''), c.address) AS address,
                   COALESCE(NULLIF(TRIM(v.currency_symbol),''), 'BZD ')   AS currency,
                   v.business_tax_number AS tax_number, v.invoice_terms AS terms
            FROM companies c LEFT JOIN invoice_settings v ON v.company_id = c.id
            WHERE c.id = :cid LIMIT 1
        ");
        $lh->execute(['cid' => $companyId]);
        $letterhead = $lh->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Company', 'currency' => 'BZD '];

        // Payments already tied to an invoice via allocations — so we don't
        // double-count when an invoice's amount_paid was set some other way.
        $alloc = $pdo->prepare("
            SELECT a.invoice_id, COALESCE(SUM(a.amount), 0) AS allocated
            FROM ar_payment_allocations a
            JOIN ar_payments p ON p.id = a.ar_payment_id
            WHERE p.company_id = :cid AND p.customer_id = :cust
            GROUP BY a.invoice_id
        ");
        $alloc->execute(['cid' => $companyId, 'cust' => $customerId]);
        $allocated = [];
        foreach ($alloc->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $allocated[(int)$r['invoice_id']] = (float)$r['allocated'];
        }
        $writtenOff = self::writeoffsByInvoice($companyId, $customerId);

        // Chronological ledger.
        $entries = [];
        foreach ($s['invoices'] as $i) {
            if (!in_array($i['status'], ['sent', 'overdue', 'paid', 'written_off'], true)) {
                continue;
            }
            $isWo = $i['status'] === 'written_off';
            $entries[] = [
                'date'    => $i['issue_date'],
                'ref'     => $i['invoice_number'],
                'detail'  => 'Invoice' . ($isWo ? ' (written off)' : (in_array($i['status'], ['sent', 'overdue'], true) ? ' (due ' . $i['effective_due'] . ')' : '')),
                'charge'  => (float)$i['total'],
                'credit'  => 0.0,
            ];
            $woAmt = round($writtenOff[(int)$i['id']] ?? 0, 2);
            if ($woAmt > 0.004) {
                $entries[] = [
                    'date'   => $i['issue_date'],
                    'ref'    => $i['invoice_number'],
                    'detail' => 'Written off / credit adjustment',
                    'charge' => 0.0,
                    'credit' => $woAmt,
                ];
            }
            $offBooks = round((float)$i['amount_paid'] - ($allocated[(int)$i['id']] ?? 0) - $woAmt, 2);
            if ($offBooks > 0.004) {
                $entries[] = [
                    'date'   => $i['issue_date'],
                    'ref'    => $i['invoice_number'],
                    'detail' => 'Payment applied',
                    'charge' => 0.0,
                    'credit' => $offBooks,
                ];
            }
        }
        foreach ($s['payments'] as $p) {
            $isCheque = $p['method'] === 'cheque';
            $status   = $p['clearance_status'] ?? 'n/a';
            $label = 'Payment received (' . $p['method'] . ')';
            if ($isCheque && $p['cheque_number']) {
                $label = 'Cheque ' . $p['cheque_number']
                    . ($status === 'pending' ? ' (uncleared)' : ($status === 'cleared' ? '' : ' — BOUNCED'));
            }
            $entries[] = [
                'date'   => $p['received_on'],
                'ref'    => $p['reference'] ?: ('Receipt #' . $p['id']),
                'detail' => $label,
                'charge' => 0.0,
                'credit' => (float)$p['amount'],
            ];
            // A bounced cheque: the credit above is undone by an equal charge back.
            if ($status === 'bounced') {
                $entries[] = [
                    'date'   => $p['received_on'],
                    'ref'    => $p['reference'] ?: ('Receipt #' . $p['id']),
                    'detail' => 'Cheque returned — amount charged back',
                    'charge' => (float)$p['amount'],
                    'credit' => 0.0,
                ];
            }
        }
        usort($entries, static fn ($a, $b) => [$a['date'], $a['ref']] <=> [$b['date'], $b['ref']]);

        $running = (float)$s['customer']['opening_balance'];
        if (abs($running) > 0.004) {
            array_unshift($entries, ['date' => null, 'ref' => '', 'detail' => 'Opening balance', 'charge' => 0.0, 'credit' => 0.0, 'balance' => $running]);
        }
        foreach ($entries as &$e) {
            if (!isset($e['balance'])) {
                $running = round($running + $e['charge'] - $e['credit'], 2);
                $e['balance'] = $running;
            }
        }
        unset($e);

        // Aging from open invoices.
        $aging = ['current' => 0.0, 'b_1_30' => 0.0, 'b_31_60' => 0.0, 'b_61_90' => 0.0, 'b_90p' => 0.0];
        foreach ($s['invoices'] as $i) {
            if (!in_array($i['status'], ['sent', 'overdue'], true) || (float)$i['outstanding'] <= 0) {
                continue;
            }
            $d = (int)$i['days_overdue'];
            $out = (float)$i['outstanding'];
            if ($d <= 0)        { $aging['current'] += $out; }
            elseif ($d <= 30)   { $aging['b_1_30']  += $out; }
            elseif ($d <= 60)   { $aging['b_31_60'] += $out; }
            elseif ($d <= 90)   { $aging['b_61_90'] += $out; }
            else                { $aging['b_90p']   += $out; }
        }
        foreach ($aging as $k => $v) { $aging[$k] = round($v, 2); }

        return [
            'letterhead' => $letterhead,
            'customer'   => $s['customer'],
            'as_of'      => date('Y-m-d'),
            'entries'    => $entries,
            'balance'    => $s['balance'],
            'aging'      => $aging,
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

        return [
            'subject'  => $subject,
            'body'     => $body,
            'balance'  => $s['balance'],
            'overdue'  => round($overdue, 2),
            'to_email' => trim((string)($s['customer']['email'] ?? '')) ?: null,
        ];
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
     * Email a reminder to the customer and record it as sent. On a dev box where
     * MAIL_DRIVER isn't smtp the mailer just logs — the reminder is still saved
     * with a 'logged' delivery note so the workflow is testable.
     *
     * @return array{reminder_id:int, delivery:string, note:?string}
     */
    public static function emailReminder(int $companyId, int $customerId, array $d, ?int $actorId): array
    {
        $pdo = DB::pdo();
        $cust = $pdo->prepare("SELECT id, name, email FROM customers WHERE id = :id AND company_id = :cid LIMIT 1");
        $cust->execute(['id' => $customerId, 'cid' => $companyId]);
        $cust = $cust->fetch(PDO::FETCH_ASSOC);
        if (!$cust) {
            throw new RuntimeException('Customer not found.');
        }
        $to = trim((string)$cust['email']);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('This customer has no valid email address on file.');
        }

        $draft   = self::reminderDraft($companyId, $customerId);
        $kind    = in_array($d['kind'] ?? '', ['statement', 'due_soon', 'overdue', 'final_notice'], true) ? $d['kind'] : 'overdue';
        $subject = mb_substr(trim((string)($d['subject'] ?? $draft['subject'])), 0, 190) ?: $draft['subject'];
        $body    = trim((string)($d['body'] ?? $draft['body'])) ?: $draft['body'];

        require_once __DIR__ . '/MailerService.php';
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1a1a1a">'
            . nl2br(htmlspecialchars($body, ENT_QUOTES)) . '</div>';
        try {
            $res = (new MailerService())->send($to, $subject, $html, $body, 'ar_reminder');
        } catch (Throwable $e) {
            throw new RuntimeException('Could not send the email: ' . $e->getMessage(), 0, $e);
        }
        $delivery = $res['status'] ?? 'unknown';

        $pdo->prepare("
            INSERT INTO ar_reminders (company_id, customer_id, kind, channel, subject, body, balance_at, overdue_at, sent_at, created_by)
            VALUES (:cid, :cust, :kind, 'email', :subj, :body, :bal, :od, NOW(), :by)
        ")->execute([
            'cid' => $companyId, 'cust' => $customerId, 'kind' => $kind,
            'subj' => $subject, 'body' => $body,
            'bal' => $draft['balance'], 'od' => $draft['overdue'], 'by' => $actorId,
        ]);
        $id = (int)$pdo->lastInsertId();

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'receivables.reminder.sent',
            'summary'       => "Emailed {$kind} reminder to {$cust['name']} <{$to}> ({$delivery})",
            'metadata'      => ['reminder_id' => $id, 'customer_id' => $customerId, 'kind' => $kind, 'channel' => 'email', 'delivery' => $delivery],
        ]);

        return ['reminder_id' => $id, 'delivery' => $delivery, 'note' => $res['note'] ?? null];
    }

    /**
     * Email the customer their statement of account (the same ledger as the
     * printable one) and record it as a sent 'statement' reminder.
     *
     * @return array{reminder_id:int, delivery:string, note:?string}
     */
    public static function emailStatement(int $companyId, int $customerId, ?int $actorId): array
    {
        $doc = self::statementDocument($companyId, $customerId);
        if ($doc === null) {
            throw new RuntimeException('Customer not found.');
        }
        $to = trim((string)($doc['customer']['email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('This customer has no valid email address on file.');
        }

        $cur     = trim((string)$doc['letterhead']['currency']) ?: 'BZD';
        $bizName = (string)$doc['letterhead']['name'];
        $money   = static fn ($v) => $cur . ' ' . number_format((float)$v, 2);
        $esc     = static fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
        $subject = 'Statement of account — ' . $bizName;

        $rows = '';
        foreach ($doc['entries'] as $e) {
            $rows .= '<tr>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee">' . $esc($e['date'] ? date('j M Y', strtotime($e['date'])) : '') . '</td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee">' . $esc($e['ref']) . '</td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee">' . $esc($e['detail']) . '</td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right">' . ($e['charge'] > 0.004 ? number_format($e['charge'], 2) : '') . '</td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right">' . ($e['credit'] > 0.004 ? number_format($e['credit'], 2) : '') . '</td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right">' . number_format($e['balance'], 2) . '</td>'
                . '</tr>';
        }
        $a = $doc['aging'];
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:13px;color:#1a1a1a">'
            . '<p>Dear ' . $esc($doc['customer']['name']) . ',</p>'
            . '<p>Please find your statement of account with ' . $esc($bizName) . ' as of '
            . $esc(date('j M Y', strtotime($doc['as_of']))) . '. The balance due is <strong>' . $money($doc['balance']) . '</strong>.</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:12px;margin:12px 0">'
            . '<thead><tr>'
            . '<th style="text-align:left;padding:4px 8px;border-bottom:2px solid #333">Date</th>'
            . '<th style="text-align:left;padding:4px 8px;border-bottom:2px solid #333">Reference</th>'
            . '<th style="text-align:left;padding:4px 8px;border-bottom:2px solid #333">Detail</th>'
            . '<th style="text-align:right;padding:4px 8px;border-bottom:2px solid #333">Charges</th>'
            . '<th style="text-align:right;padding:4px 8px;border-bottom:2px solid #333">Payments</th>'
            . '<th style="text-align:right;padding:4px 8px;border-bottom:2px solid #333">Balance</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p style="font-size:12px;color:#555">Aged: current ' . $money($a['current']) . ' · 1–30 ' . $money($a['b_1_30'])
            . ' · 31–60 ' . $money($a['b_31_60']) . ' · 61–90 ' . $money($a['b_61_90']) . ' · 90+ ' . $money($a['b_90p']) . '</p>'
            . '<p>If you have already settled this balance, thank you — please disregard this notice. '
            . 'Otherwise please arrange payment or reply to let us know when we can expect it.</p>'
            . '<p>Regards,<br>' . $esc($bizName) . '</p></div>';

        $text = "Statement of account with {$bizName} as of " . date('j M Y', strtotime($doc['as_of']))
            . ". Balance due: " . $money($doc['balance']) . ".";

        require_once __DIR__ . '/MailerService.php';
        try {
            $res = (new MailerService())->send($to, $subject, $html, $text, 'ar_statement');
        } catch (Throwable $e) {
            throw new RuntimeException('Could not send the email: ' . $e->getMessage(), 0, $e);
        }
        $delivery = $res['status'] ?? 'unknown';

        $overdue = round(
            (float)$a['b_1_30'] + (float)$a['b_31_60'] + (float)$a['b_61_90'] + (float)$a['b_90p'],
            2
        );
        DB::pdo()->prepare("
            INSERT INTO ar_reminders (company_id, customer_id, kind, channel, subject, body, balance_at, overdue_at, sent_at, created_by)
            VALUES (:cid, :cust, 'statement', 'email', :subj, :body, :bal, :od, NOW(), :by)
        ")->execute([
            'cid' => $companyId, 'cust' => $customerId,
            'subj' => mb_substr($subject, 0, 190), 'body' => $text,
            'bal' => $doc['balance'], 'od' => $overdue, 'by' => $actorId,
        ]);
        $id = (int)DB::pdo()->lastInsertId();

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'receivables.statement.emailed',
            'summary'       => "Emailed statement to {$doc['customer']['name']} <{$to}> ({$delivery})",
            'metadata'      => ['reminder_id' => $id, 'customer_id' => $customerId, 'channel' => 'email', 'delivery' => $delivery],
        ]);

        return ['reminder_id' => $id, 'delivery' => $delivery, 'note' => $res['note'] ?? null];
    }

    /**
     * Month-end statement run: email a statement to every active customer with
     * an outstanding balance. $mode = 'all' | 'overdue' (only accounts with
     * something past due). Skips accounts with no email on file.
     *
     * @return array{sent:int, skipped_no_email:int, failed:int, no_email:array<string>}
     */
    public static function statementRun(int $companyId, ?int $actorId, string $mode = 'all'): array
    {
        $p = self::portfolio($companyId);
        $out = ['sent' => 0, 'skipped_no_email' => 0, 'failed' => 0, 'no_email' => []];

        foreach ($p['customers'] as $c) {
            if (abs((float)$c['balance']) <= 0.004) {
                continue;
            }
            if ($mode === 'overdue' && (float)$c['overdue'] <= 0.004) {
                continue;
            }
            if (!filter_var(trim((string)($c['email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
                $out['skipped_no_email']++;
                $out['no_email'][] = $c['name'];
                continue;
            }
            try {
                self::emailStatement($companyId, (int)$c['id'], $actorId);
                $out['sent']++;
            } catch (Throwable $e) {
                $out['failed']++;
            }
        }

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'receivables.statement_run',
            'summary'       => "Statement run ({$mode}): {$out['sent']} sent, {$out['skipped_no_email']} without email, {$out['failed']} failed",
            'metadata'      => $out,
        ]);

        return $out;
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

    /**
     * Bulk-load an AR customer list from CSV — for onboarding a company that
     * already has a book of accounts. Columns (header row, case-insensitive,
     * order-free): name (required), company, email, phone, credit_limit,
     * payment_terms_days, opening_balance. Existing customers are matched by
     * name (case-insensitive) within the company and updated; the rest are
     * created. Nothing is deleted.
     *
     * @return array{created:int, updated:int, skipped:int, errors:array<string>}
     */
    public static function importCustomers(int $companyId, string $csv, ?int $actorId): array
    {
        $csv = trim(str_replace(["\r\n", "\r"], "\n", $csv));
        $lines = array_values(array_filter(explode("\n", $csv), static fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw new InvalidArgumentException('Need a header row and at least one customer.');
        }

        $header = array_map(static fn ($h) => strtolower(trim($h, " \t\"'")), str_getcsv(array_shift($lines)));
        $alias = [
            'name' => ['name', 'customer', 'customer name', 'account', 'account name'],
            'company' => ['company', 'business', 'business name', 'trading name'],
            'email' => ['email', 'e-mail', 'email address'],
            'phone' => ['phone', 'telephone', 'tel', 'mobile', 'contact'],
            'credit_limit' => ['credit_limit', 'credit limit', 'limit'],
            'payment_terms_days' => ['payment_terms_days', 'terms', 'payment terms', 'terms days', 'net'],
            'opening_balance' => ['opening_balance', 'opening balance', 'balance', 'opening', 'brought forward'],
        ];
        $col = [];
        foreach ($alias as $key => $names) {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false) { $col[$key] = $i; break; }
            }
        }
        if (!isset($col['name'])) {
            throw new InvalidArgumentException('Could not find a "name" column in the header.');
        }

        $pdo = DB::pdo();
        $existing = [];
        $ex = $pdo->prepare("SELECT id, LOWER(TRIM(name)) AS n FROM customers WHERE company_id = :cid");
        $ex->execute(['cid' => $companyId]);
        foreach ($ex->fetchAll(PDO::FETCH_ASSOC) as $r) { $existing[$r['n']] = (int)$r['id']; }

        $num = static function ($v) {
            $v = trim((string)$v);
            if ($v === '') { return null; }
            $v = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $v));
            return $v === '' ? null : (float)$v;
        };

        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $rowNo = 1;
        foreach ($lines as $line) {
            $rowNo++;
            $cells = str_getcsv($line);
            $name = trim((string)($cells[$col['name']] ?? ''));
            if ($name === '') { $out['skipped']++; $out['errors'][] = "Row {$rowNo}: no name"; continue; }

            $data = ['name' => $name];
            foreach (['company', 'email', 'phone'] as $f) {
                if (isset($col[$f])) { $data[$f] = trim((string)($cells[$col[$f]] ?? '')); }
            }
            if (isset($col['credit_limit']))       { $data['credit_limit'] = $num($cells[$col['credit_limit']] ?? ''); }
            if (isset($col['payment_terms_days'])) { $data['payment_terms_days'] = (int)($num($cells[$col['payment_terms_days']] ?? '') ?? 0); }
            if (isset($col['opening_balance']))    { $data['opening_balance'] = $num($cells[$col['opening_balance']] ?? '') ?? 0; }

            $key = mb_strtolower($name);
            if (isset($existing[$key])) { $data['id'] = $existing[$key]; }

            try {
                self::saveCustomer($companyId, $data, $actorId);
                isset($data['id']) ? $out['updated']++ : $out['created']++;
            } catch (Throwable $e) {
                $out['skipped']++;
                $out['errors'][] = "Row {$rowNo} ({$name}): " . $e->getMessage();
            }
        }

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'receivables.customers.imported',
            'summary'       => "Customer import: {$out['created']} created, {$out['updated']} updated, {$out['skipped']} skipped",
            'metadata'      => ['created' => $out['created'], 'updated' => $out['updated'], 'skipped' => $out['skipped']],
        ]);

        $out['errors'] = array_slice($out['errors'], 0, 20);
        return $out;
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
        $source     = mb_substr(trim((string)($data['source'] ?? 'manual')), 0, 20) ?: 'manual';
        $sourceRef  = mb_substr(trim((string)($data['source_ref'] ?? '')), 0, 120);
        $settleRef  = mb_substr(trim((string)($data['settlement_ref'] ?? '')), 0, 120);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
        if (!in_array($method, ['cash', 'card', 'bank_transfer', 'xfer', 'cheque', 'other'], true)) {
            $method = 'other';
        }

        // Cheque details — a cheque receipt starts life 'pending' until it clears.
        $chequeNumber = '';
        $chequeBank   = '';
        $chequeDate   = null;
        $clearance    = 'n/a';
        if ($method === 'cheque') {
            $chequeNumber = mb_substr(trim((string)($data['cheque_number'] ?? '')), 0, 50);
            $chequeBank   = mb_substr(trim((string)($data['cheque_bank'] ?? '')), 0, 120);
            $cd = trim((string)($data['cheque_date'] ?? ''));
            $chequeDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $cd) ? $cd : $receivedOn;
            $clearance = 'pending';
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

        // Idempotency for machine-fed receipts (OnePay re-sync): a receipt with
        // this source + source_ref already exists — return it, don't duplicate.
        if ($source !== 'manual' && $sourceRef !== '') {
            $dup = $pdo->prepare("SELECT id FROM ar_payments WHERE company_id = :c AND source = :s AND source_ref = :r LIMIT 1");
            $dup->execute(['c' => $companyId, 's' => $source, 'r' => $sourceRef]);
            $dupId = (int)($dup->fetchColumn() ?: 0);
            if ($dupId) {
                return ['payment_id' => $dupId, 'allocated' => 0.0, 'credit' => 0.0, 'invoices' => 0, 'duplicate' => true];
            }
        }

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            $pdo->prepare("
                INSERT INTO ar_payments (company_id, customer_id, received_on, amount, method, source, source_ref, settlement_ref,
                                         cheque_number, cheque_bank, cheque_date, clearance_status, reference, notes, created_by)
                VALUES (:cid, :cust, :on, :amt, :method, :src, :sref, :setref,
                        :cno, :cbank, :cdate, :clr, :ref, :notes, :by)
            ")->execute([
                'cid' => $companyId, 'cust' => $customerId, 'on' => $receivedOn, 'amt' => $amount,
                'method' => $method, 'src' => $source, 'sref' => $sourceRef, 'setref' => $settleRef,
                'cno' => $chequeNumber, 'cbank' => $chequeBank, 'cdate' => $chequeDate, 'clr' => $clearance,
                'ref' => $reference, 'notes' => $notes, 'by' => $actorId,
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
     * Turn OnePay electronic collections into first-class ledger receipts.
     *
     * OnePay posts a sale as an invoice with amount_paid already set. Where that
     * paid amount is not yet backed by an ar_payments receipt, create one
     * (method 'card', source 'onepay') allocated straight to the invoice — the
     * invoice's amount_paid / status are already correct, so we only fill the
     * ledger gap. Idempotent by (source, source_ref); safe to run daily.
     *
     * @return array{created:int, amount:float}
     */
    public static function syncOnepayReceipts(int $companyId, ?int $actorId): array
    {
        $pdo = DB::pdo();
        $rows = $pdo->prepare("
            SELECT i.id, i.source_ref, i.issue_date, i.customer_id, i.amount_paid, i.batch_id,
                   COALESCE(a.allocated, 0) AS allocated
            FROM invoices i
            LEFT JOIN (
                SELECT al.invoice_id, SUM(al.amount) AS allocated
                FROM ar_payment_allocations al
                JOIN ar_payments p ON p.id = al.ar_payment_id
                WHERE p.company_id = :cid1
                GROUP BY al.invoice_id
            ) a ON a.invoice_id = i.id
            WHERE i.company_id = :cid2 AND i.source_app = 'onepay'
              AND i.amount_paid > 0
              AND i.amount_paid - COALESCE(a.allocated, 0) > 0.004
        ");
        $rows->execute(['cid1' => $companyId, 'cid2' => $companyId]);

        $created = 0;
        $sum = 0.0;
        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) { $pdo->beginTransaction(); }
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $gap = round((float)$r['amount_paid'] - (float)$r['allocated'], 2);
                $res = self::postOnepayReceipt(
                    $companyId, (int)$r['id'], (int)$r['customer_id'], $gap,
                    (string)$r['source_ref'], (string)($r['batch_id'] ?? ''),
                    $r['issue_date'] ?: date('Y-m-d'), $actorId
                );
                if (!empty($res['created'])) { $created++; $sum += $gap; }
            }
            if ($created > 0) {
                Audit::log([
                    'actor_user_id' => $actorId, 'company_id' => $companyId,
                    'event_type' => 'receivables.onepay.synced',
                    'summary' => 'Auto-posted ' . $created . ' OnePay payment(s) to the ledger ('
                        . number_format($sum, 2) . ')',
                    'metadata' => ['created' => $created, 'amount' => round($sum, 2)],
                ]);
            }
            if ($ownTxn) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        return ['created' => $created, 'amount' => round($sum, 2)];
    }

    /**
     * Post one OnePay electronic payment as a receipt allocated straight to its
     * invoice. Idempotent by (source='onepay', source_ref). The invoice's
     * amount_paid / status are already set by OnePay, so this only fills the
     * ledger gap — it does not touch the invoice.
     *
     * @return array{created:bool, payment_id:int}
     */
    public static function postOnepayReceipt(
        int $companyId, int $invoiceId, int $customerId, float $amount,
        string $saleRef, string $settlementRef, string $receivedOn, ?int $actorId
    ): array {
        $amount = round($amount, 2);
        if ($amount <= 0.004 || $customerId <= 0) {
            return ['created' => false, 'payment_id' => 0];
        }
        $pdo = DB::pdo();
        $ref = 'sale:' . $saleRef;

        $dup = $pdo->prepare("SELECT id FROM ar_payments WHERE company_id = :c AND source = 'onepay' AND source_ref = :r LIMIT 1");
        $dup->execute(['c' => $companyId, 'r' => $ref]);
        if ($dupId = (int)($dup->fetchColumn() ?: 0)) {
            return ['created' => false, 'payment_id' => $dupId];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedOn)) {
            $receivedOn = date('Y-m-d');
        }

        $pdo->prepare("
            INSERT INTO ar_payments (company_id, customer_id, received_on, amount, method, source, source_ref, settlement_ref, reference, notes, created_by)
            VALUES (:c, :cust, :on, :amt, 'card', 'onepay', :sref, :setref, :ref, 'Auto-posted from OnePay', :by)
        ")->execute([
            'c' => $companyId, 'cust' => $customerId, 'on' => $receivedOn, 'amt' => $amount,
            'sref' => mb_substr($ref, 0, 120), 'setref' => mb_substr($settlementRef, 0, 120),
            'ref' => 'OnePay ' . $saleRef, 'by' => $actorId,
        ]);
        $pid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO ar_payment_allocations (ar_payment_id, invoice_id, amount) VALUES (:p, :i, :amt)")
            ->execute(['p' => $pid, 'i' => $invoiceId, 'amt' => $amount]);

        return ['created' => true, 'payment_id' => $pid];
    }

    /**
     * Reverse a receipt: undo each allocation (drop invoice.amount_paid, reopen
     * status) and delete the ar_payments row (allocations cascade).
     * Used when a bank-reconciliation match is undone.
     */
    public static function voidPayment(int $companyId, int $paymentId, ?int $actorId): void
    {
        $pdo = DB::pdo();

        $pay = $pdo->prepare("SELECT id, amount, bank_txn_id FROM ar_payments WHERE id = :id AND company_id = :cid LIMIT 1");
        $pay->execute(['id' => $paymentId, 'cid' => $companyId]);
        $pay = $pay->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            throw new RuntimeException('Receipt not found.');
        }
        if ($pay['bank_txn_id'] !== null) {
            throw new RuntimeException('This receipt is reconciled to a bank deposit — unmatch that deposit first.');
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

    // ── Cheque tracking ─────────────────────────────────────────────────────

    /**
     * Cheques the company has taken, newest first. $filter['status'] =
     * pending | cleared | bounced | all. Each row carries a post-dated flag
     * and how many days it has been held.
     */
    public static function chequeRegister(int $companyId, array $filter = []): array
    {
        $where = ["p.company_id = :cid", "p.method = 'cheque'"];
        $args  = ['cid' => $companyId];
        $status = $filter['status'] ?? 'pending';
        if (in_array($status, ['pending', 'cleared', 'bounced'], true)) {
            $where[] = "p.clearance_status = :st";
            $args['st'] = $status;
        }

        $stmt = DB::pdo()->prepare("
            SELECT p.id, p.customer_id, c.name AS customer_name, p.amount, p.received_on,
                   p.cheque_number, p.cheque_bank, p.cheque_date, p.clearance_status,
                   p.cleared_on, p.bounce_reason, p.reference, p.bank_txn_id,
                   DATEDIFF(CURDATE(), p.received_on) AS days_held,
                   (p.cheque_date > CURDATE()) AS post_dated,
                   COALESCE(SUM(a.amount), 0) AS allocated
            FROM ar_payments p
            JOIN customers c ON c.id = p.customer_id
            LEFT JOIN ar_payment_allocations a ON a.ar_payment_id = p.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY p.id
            ORDER BY (p.clearance_status = 'pending') DESC, p.received_on DESC, p.id DESC
            LIMIT 300
        ");
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']         = (int)$r['id'];
            $r['customer_id'] = (int)$r['customer_id'];
            $r['amount']     = round((float)$r['amount'], 2);
            $r['days_held']  = (int)$r['days_held'];
            $r['post_dated'] = (bool)$r['post_dated'];
        }
        return $rows;
    }

    /** Count + value of cheques by state — for the register header and Insights. */
    public static function chequesSummary(int $companyId): array
    {
        $r = DB::pdo()->prepare("
            SELECT
                SUM(clearance_status = 'pending')                                         AS pending_n,
                COALESCE(SUM(CASE WHEN clearance_status = 'pending' THEN amount END), 0)   AS pending_value,
                SUM(clearance_status = 'pending' AND cheque_date > CURDATE())              AS postdated_n,
                COALESCE(SUM(CASE WHEN clearance_status = 'pending' AND cheque_date > CURDATE() THEN amount END), 0) AS postdated_value,
                SUM(clearance_status = 'bounced' AND received_on >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) AS bounced_12m_n,
                COALESCE(SUM(CASE WHEN clearance_status = 'bounced' AND received_on >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR) THEN amount END), 0) AS bounced_12m_value
            FROM ar_payments WHERE company_id = :cid AND method = 'cheque'
        ");
        $r->execute(['cid' => $companyId]);
        $row = $r->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'pending_count'    => (int)($row['pending_n'] ?? 0),
            'pending_value'    => round((float)($row['pending_value'] ?? 0), 2),
            'postdated_count'  => (int)($row['postdated_n'] ?? 0),
            'postdated_value'  => round((float)($row['postdated_value'] ?? 0), 2),
            'bounced_12m_count' => (int)($row['bounced_12m_n'] ?? 0),
            'bounced_12m_value' => round((float)($row['bounced_12m_value'] ?? 0), 2),
        ];
    }

    /** Mark a pending cheque cleared. */
    public static function clearCheque(int $companyId, int $paymentId, ?string $clearedOn, ?int $actorId): void
    {
        $pdo = DB::pdo();
        $p = $pdo->prepare("SELECT id, amount, customer_id, clearance_status FROM ar_payments WHERE id = :id AND company_id = :cid AND method = 'cheque' LIMIT 1");
        $p->execute(['id' => $paymentId, 'cid' => $companyId]);
        $pay = $p->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            throw new RuntimeException('Cheque not found.');
        }
        if ($pay['clearance_status'] !== 'pending') {
            throw new RuntimeException('This cheque is already ' . $pay['clearance_status'] . '.');
        }
        $clearedOn = $clearedOn && preg_match('/^\d{4}-\d{2}-\d{2}$/', $clearedOn) ? $clearedOn : date('Y-m-d');

        $pdo->prepare("UPDATE ar_payments SET clearance_status = 'cleared', cleared_on = :on WHERE id = :id")
            ->execute(['on' => $clearedOn, 'id' => $paymentId]);

        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId,
            'event_type' => 'receivables.cheque.cleared',
            'summary' => 'Cheque #' . $paymentId . ' cleared (' . number_format((float)$pay['amount'], 2) . ')',
            'metadata' => ['payment_id' => $paymentId, 'cleared_on' => $clearedOn],
        ]);
    }

    /**
     * A cheque bounced. Reverse its allocations (the customer owes again), keep
     * the receipt row marked 'bounced' for the record, and note it on the
     * customer. Any bounce fee is invoiced separately.
     */
    public static function bounceCheque(int $companyId, int $paymentId, string $reason, ?int $actorId): void
    {
        $pdo = DB::pdo();
        $p = $pdo->prepare("SELECT id, amount, customer_id, clearance_status, bank_txn_id FROM ar_payments WHERE id = :id AND company_id = :cid AND method = 'cheque' LIMIT 1");
        $p->execute(['id' => $paymentId, 'cid' => $companyId]);
        $pay = $p->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            throw new RuntimeException('Cheque not found.');
        }
        if ($pay['clearance_status'] === 'bounced') {
            throw new RuntimeException('This cheque is already recorded as bounced.');
        }
        if ($pay['bank_txn_id'] !== null) {
            throw new RuntimeException('This cheque is reconciled to a bank deposit — unmatch that first.');
        }
        $reason = mb_substr(trim($reason), 0, 190);

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) { $pdo->beginTransaction(); }

            $allocs = $pdo->prepare("SELECT invoice_id, amount FROM ar_payment_allocations WHERE ar_payment_id = :pid");
            $allocs->execute(['pid' => $paymentId]);
            foreach ($allocs->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $inv = $pdo->prepare("
                    SELECT i.total, i.amount_paid, " . self::DUE_EXPR . " AS effective_due
                    FROM invoices i JOIN customers c ON c.id = i.customer_id
                    WHERE i.id = :id AND i.company_id = :cid LIMIT 1
                ");
                $inv->execute(['id' => (int)$a['invoice_id'], 'cid' => $companyId]);
                $row = $inv->fetch(PDO::FETCH_ASSOC);
                if (!$row) { continue; }
                $newPaid = max(0, round((float)$row['amount_paid'] - (float)$a['amount'], 2));
                $newStatus = $newPaid + 0.004 >= (float)$row['total'] ? 'paid'
                    : (strtotime((string)$row['effective_due']) < strtotime(date('Y-m-d')) ? 'overdue' : 'sent');
                $pdo->prepare("UPDATE invoices SET amount_paid = :p, status = :s WHERE id = :id")
                    ->execute(['p' => $newPaid, 's' => $newStatus, 'id' => (int)$a['invoice_id']]);
            }
            $pdo->prepare("DELETE FROM ar_payment_allocations WHERE ar_payment_id = :pid")->execute(['pid' => $paymentId]);

            $pdo->prepare("UPDATE ar_payments SET clearance_status = 'bounced', bounce_reason = :r WHERE id = :id")
                ->execute(['r' => $reason, 'id' => $paymentId]);

            Audit::log([
                'actor_user_id' => $actorId, 'company_id' => $companyId,
                'event_type' => 'receivables.cheque.bounced',
                'summary' => 'Cheque #' . $paymentId . ' BOUNCED (' . number_format((float)$pay['amount'], 2) . ')'
                    . ($reason !== '' ? ' — ' . $reason : '') . ' — customer balance restored',
                'metadata' => ['payment_id' => $paymentId, 'customer_id' => (int)$pay['customer_id'], 'amount' => (float)$pay['amount'], 'reason' => $reason],
            ]);

            if ($ownTxn) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    // ── Write-offs & credit adjustments (maker-checker) ──────────────────────

    private const WRITEOFF_KINDS = ['bad_debt', 'damaged_goods', 'price_adjustment', 'other'];

    private static function isCompanyAdmin(int $companyId, int $userId): bool
    {
        $m = DB::pdo()->prepare("
            SELECT 1 FROM company_members
            WHERE company_id = :c AND user_id = :u AND status = 'active' AND role = 'admin' LIMIT 1
        ");
        $m->execute(['c' => $companyId, 'u' => $userId]);
        return (bool)$m->fetchColumn();
    }

    /** Approved write-off total per invoice id, for a customer's statement. */
    public static function writeoffsByInvoice(int $companyId, int $customerId): array
    {
        $stmt = DB::pdo()->prepare("
            SELECT invoice_id, COALESCE(SUM(amount), 0) AS amt
            FROM ar_writeoffs
            WHERE company_id = :c AND customer_id = :cust AND status = 'approved'
            GROUP BY invoice_id
        ");
        $stmt->execute(['c' => $companyId, 'cust' => $customerId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['invoice_id']] = round((float)$r['amt'], 2);
        }
        return $out;
    }

    /**
     * Propose a write-off / credit adjustment against one open invoice.
     * Nothing changes on the ledger until it is approved.
     *
     * @param array{invoice_id:int, amount:float|string, kind?:string, reason?:string} $data
     */
    public static function proposeWriteoff(int $companyId, array $data, ?int $actorId): int
    {
        $pdo = DB::pdo();
        $invoiceId = (int)($data['invoice_id'] ?? 0);
        $amount    = round((float)($data['amount'] ?? 0), 2);
        $kind      = in_array($data['kind'] ?? '', self::WRITEOFF_KINDS, true) ? $data['kind'] : 'bad_debt';
        $reason    = mb_substr(trim((string)($data['reason'] ?? '')), 0, 255);

        $inv = $pdo->prepare("
            SELECT i.id, i.customer_id, i.invoice_number, i.total, i.amount_paid, i.status
            FROM invoices i WHERE i.id = :id AND i.company_id = :cid LIMIT 1
        ");
        $inv->execute(['id' => $invoiceId, 'cid' => $companyId]);
        $invoice = $inv->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }
        if (!in_array($invoice['status'], ['sent', 'overdue'], true)) {
            throw new RuntimeException('Only an open (sent or overdue) invoice can be written off.');
        }

        $outstanding = round((float)$invoice['total'] - (float)$invoice['amount_paid'], 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Enter an amount greater than zero.');
        }
        if ($amount > $outstanding + 0.005) {
            throw new InvalidArgumentException('That is more than the ' . number_format($outstanding, 2)
                . ' still outstanding on ' . $invoice['invoice_number'] . '.');
        }

        $pdo->prepare("
            INSERT INTO ar_writeoffs (company_id, customer_id, invoice_id, amount, kind, reason, status, proposed_by)
            VALUES (:c, :cust, :inv, :amt, :kind, :reason, 'pending', :by)
        ")->execute([
            'c' => $companyId, 'cust' => (int)$invoice['customer_id'], 'inv' => $invoiceId,
            'amt' => $amount, 'kind' => $kind, 'reason' => $reason, 'by' => $actorId,
        ]);
        $id = (int)$pdo->lastInsertId();

        Audit::log([
            'actor_user_id' => $actorId, 'company_id' => $companyId,
            'event_type' => 'receivables.writeoff.proposed',
            'summary' => 'Proposed ' . str_replace('_', ' ', $kind) . ' write-off of '
                . number_format($amount, 2) . ' on ' . $invoice['invoice_number']
                . ($amount + 0.005 >= $outstanding ? ' (full)' : ' (partial)'),
            'metadata' => ['writeoff_id' => $id, 'invoice_id' => $invoiceId, 'amount' => $amount, 'kind' => $kind],
        ]);
        return $id;
    }

    /**
     * Approve or reject a pending write-off. Approval is a company-admin action;
     * self-approval (proposer === approver) is allowed but flagged in the audit.
     * On approval the invoice's amount_paid rises by the write-off amount and,
     * if that clears the balance, the invoice moves to 'written_off'.
     *
     * @param 'approve'|'reject' $action
     */
    public static function decideWriteoff(int $companyId, int $writeoffId, string $action, array $data, int $actorId): void
    {
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Unknown action.');
        }
        if (!self::isCompanyAdmin($companyId, $actorId)) {
            throw new RuntimeException('Only a company admin can approve or reject a write-off.');
        }

        $pdo = DB::pdo();
        $w = $pdo->prepare("
            SELECT w.*, i.invoice_number, i.total, i.amount_paid, i.status AS inv_status
            FROM ar_writeoffs w JOIN invoices i ON i.id = w.invoice_id
            WHERE w.id = :id AND w.company_id = :cid LIMIT 1
        ");
        $w->execute(['id' => $writeoffId, 'cid' => $companyId]);
        $wo = $w->fetch(PDO::FETCH_ASSOC);
        if (!$wo) {
            throw new RuntimeException('Write-off not found.');
        }
        if ($wo['status'] !== 'pending') {
            throw new RuntimeException('This write-off has already been ' . $wo['status'] . '.');
        }
        $note = mb_substr(trim((string)($data['note'] ?? '')), 0, 255);

        if ($action === 'reject') {
            $pdo->prepare("UPDATE ar_writeoffs SET status = 'rejected', approved_by = :by, decided_at = NOW(), decision_note = :n WHERE id = :id")
                ->execute(['by' => $actorId, 'n' => $note, 'id' => $writeoffId]);
            Audit::log([
                'actor_user_id' => $actorId, 'company_id' => $companyId,
                'event_type' => 'receivables.writeoff.rejected',
                'summary' => 'Rejected write-off #' . $writeoffId . ' on ' . $wo['invoice_number']
                    . ($note !== '' ? ' — ' . $note : ''),
                'metadata' => ['writeoff_id' => $writeoffId, 'invoice_id' => (int)$wo['invoice_id']],
            ]);
            return;
        }

        // approve
        $selfApproved = (int)$wo['proposed_by'] === $actorId;
        $outstanding  = round((float)$wo['total'] - (float)$wo['amount_paid'], 2);
        if (!in_array($wo['inv_status'], ['sent', 'overdue'], true)) {
            throw new RuntimeException('The invoice is no longer open — nothing to write off.');
        }
        $amount = min(round((float)$wo['amount'], 2), $outstanding);   // cap: a payment may have arrived since
        if ($amount <= 0) {
            throw new RuntimeException('The invoice has since been settled — nothing to write off.');
        }

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) { $pdo->beginTransaction(); }

            $newPaid  = round((float)$wo['amount_paid'] + $amount, 2);
            $full     = $newPaid + 0.005 >= (float)$wo['total'];
            $newStatus = $full ? 'written_off' : $wo['inv_status'];

            $pdo->prepare("UPDATE invoices SET amount_paid = :p, status = :s WHERE id = :id")
                ->execute(['p' => $newPaid, 's' => $newStatus, 'id' => (int)$wo['invoice_id']]);

            $pdo->prepare("
                UPDATE ar_writeoffs SET status = 'approved', amount = :amt, approved_by = :by,
                       decided_at = NOW(), decision_note = :n WHERE id = :id
            ")->execute(['amt' => $amount, 'by' => $actorId, 'n' => $note, 'id' => $writeoffId]);

            Audit::log([
                'actor_user_id' => $actorId, 'company_id' => $companyId,
                'event_type' => 'receivables.writeoff.approved',
                'summary' => 'Approved ' . str_replace('_', ' ', (string)$wo['kind']) . ' write-off of '
                    . number_format($amount, 2) . ' on ' . $wo['invoice_number']
                    . ($full ? ' — invoice written off' : ' — partial')
                    . ($selfApproved ? ' (SELF-APPROVED)' : ''),
                'metadata' => [
                    'writeoff_id' => $writeoffId, 'invoice_id' => (int)$wo['invoice_id'],
                    'amount' => $amount, 'kind' => $wo['kind'], 'full' => $full, 'self_approved' => $selfApproved,
                ],
            ]);

            if ($ownTxn) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /** Reverse an approved write-off (a mistake). Admin only. */
    public static function reverseWriteoff(int $companyId, int $writeoffId, int $actorId, string $note = ''): void
    {
        if (!self::isCompanyAdmin($companyId, $actorId)) {
            throw new RuntimeException('Only a company admin can reverse a write-off.');
        }
        $pdo = DB::pdo();
        $w = $pdo->prepare("
            SELECT w.*, i.invoice_number, i.total, i.amount_paid, i.status AS inv_status,
                   " . self::DUE_EXPR . " AS effective_due
            FROM ar_writeoffs w
            JOIN invoices i ON i.id = w.invoice_id
            JOIN customers c ON c.id = i.customer_id
            WHERE w.id = :id AND w.company_id = :cid LIMIT 1
        ");
        $w->execute(['id' => $writeoffId, 'cid' => $companyId]);
        $wo = $w->fetch(PDO::FETCH_ASSOC);
        if (!$wo) {
            throw new RuntimeException('Write-off not found.');
        }
        if ($wo['status'] !== 'approved') {
            throw new RuntimeException('Only an approved write-off can be reversed.');
        }

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) { $pdo->beginTransaction(); }

            $newPaid = max(0, round((float)$wo['amount_paid'] - (float)$wo['amount'], 2));
            $newStatus = $wo['inv_status'];
            if ($newStatus === 'written_off') {
                $newStatus = strtotime((string)$wo['effective_due']) < strtotime(date('Y-m-d')) ? 'overdue' : 'sent';
            }
            $pdo->prepare("UPDATE invoices SET amount_paid = :p, status = :s WHERE id = :id")
                ->execute(['p' => $newPaid, 's' => $newStatus, 'id' => (int)$wo['invoice_id']]);

            $mark = mb_substr(trim('REVERSED. ' . $note), 0, 255);
            $pdo->prepare("UPDATE ar_writeoffs SET status = 'void', decided_at = NOW(), decision_note = :n WHERE id = :id")
                ->execute(['n' => $mark, 'id' => $writeoffId]);

            Audit::log([
                'actor_user_id' => $actorId, 'company_id' => $companyId,
                'event_type' => 'receivables.writeoff.reversed',
                'summary' => 'Reversed write-off #' . $writeoffId . ' of ' . number_format((float)$wo['amount'], 2)
                    . ' on ' . $wo['invoice_number'] . ($note !== '' ? ' — ' . $note : ''),
                'metadata' => ['writeoff_id' => $writeoffId, 'invoice_id' => (int)$wo['invoice_id'], 'amount' => (float)$wo['amount']],
            ]);

            if ($ownTxn) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    /**
     * Write-off list for the company. $filters['status'] = pending|approved|rejected|void|all.
     */
    public static function writeoffs(int $companyId, array $filters = []): array
    {
        $where = ['w.company_id = :cid'];
        $args  = ['cid' => $companyId];
        $status = $filters['status'] ?? 'pending';
        if (in_array($status, ['pending', 'approved', 'rejected', 'void'], true)) {
            $where[] = 'w.status = :st';
            $args['st'] = $status;
        }
        if (!empty($filters['customer_id'])) {
            $where[] = 'w.customer_id = :cust';
            $args['cust'] = (int)$filters['customer_id'];
        }

        $stmt = DB::pdo()->prepare("
            SELECT w.id, w.invoice_id, i.invoice_number, w.customer_id, c.name AS customer_name,
                   w.amount, w.kind, w.reason, w.status, w.proposed_at, w.decided_at, w.decision_note,
                   TRIM(CONCAT(COALESCE(pu.first_name,''),' ',COALESCE(pu.last_name,''))) AS proposed_by_name,
                   TRIM(CONCAT(COALESCE(au.first_name,''),' ',COALESCE(au.last_name,''))) AS approved_by_name
            FROM ar_writeoffs w
            JOIN invoices i  ON i.id = w.invoice_id
            JOIN customers c ON c.id = w.customer_id
            LEFT JOIN users pu ON pu.id = w.proposed_by
            LEFT JOIN users au ON au.id = w.approved_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (w.status = 'pending') DESC, w.id DESC
            LIMIT 200
        ");
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Bad-debt / adjustment totals over a date range, split by kind. For the
     * aging report footer and the write-offs panel header.
     */
    public static function badDebtReport(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $from = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-01-01');
        $to   = $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : date('Y-m-d');

        $stmt = DB::pdo()->prepare("
            SELECT kind, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
            FROM ar_writeoffs
            WHERE company_id = :cid AND status = 'approved'
              AND decided_at >= :from AND decided_at < DATE_ADD(:to, INTERVAL 1 DAY)
            GROUP BY kind
        ");
        $stmt->execute(['cid' => $companyId, 'from' => $from, 'to' => $to]);

        $byKind = [];
        $total = 0.0;
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byKind[$r['kind']] = ['count' => (int)$r['n'], 'total' => round((float)$r['total'], 2)];
            $total += (float)$r['total'];
            $count += (int)$r['n'];
        }
        $pStmt = DB::pdo()->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount),0) t FROM ar_writeoffs WHERE company_id = :c AND status = 'pending'");
        $pStmt->execute(['c' => $companyId]);
        $p = $pStmt->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 't' => 0];

        return [
            'from' => $from, 'to' => $to,
            'total' => round($total, 2), 'count' => $count,
            'by_kind' => $byKind,
            'pending_count' => (int)$p['n'], 'pending_total' => round((float)$p['t'], 2),
        ];
    }
}
