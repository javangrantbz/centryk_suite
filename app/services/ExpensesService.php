<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';
require_once __DIR__ . '/../core/Ledger.php';
require_once __DIR__ . '/AccountingService.php';

/**
 * Centryk Business — Accounting: expenses / bills (AP-lite) and vendors.
 *
 * Recording an expense posts a journal straight away:
 *
 *   paid now   DR expense/COGS/asset (net)   DR GST Input (tax)   CR Bank/Cash (total)
 *   on account DR expense/COGS/asset (net)   DR GST Input (tax)   CR Accounts Payable (total)
 *
 * Paying an outstanding bill later posts   DR Accounts Payable   CR Bank/Cash.
 * Voiding reverses whatever was posted. Every method is company-scoped; the
 * caller must hold the 'accounting' entitlement.
 */
class ExpensesService
{
    // ── Vendors ──────────────────────────────────────────────────────────

    public static function vendors(int $companyId, bool $activeOnly = true): array
    {
        $sql = "SELECT v.*, a.code AS default_account_code, a.name AS default_account_name
                  FROM vendors v
                  LEFT JOIN gl_accounts a ON a.id = v.default_expense_account_id
                 WHERE v.company_id = :c";
        if ($activeOnly) {
            $sql .= " AND v.status = 'active'";
        }
        $sql .= ' ORDER BY v.name';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute(['c' => $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['default_expense_account_id'] = $r['default_expense_account_id'] !== null ? (int)$r['default_expense_account_id'] : null;
        }
        return $rows;
    }

    public static function saveVendor(int $companyId, array $data, ?int $userId): int
    {
        $id     = (int)($data['id'] ?? 0);
        $name   = mb_substr(trim((string)($data['name'] ?? '')), 0, 150);
        $email  = trim((string)($data['email'] ?? '')) ?: null;
        $phone  = trim((string)($data['phone'] ?? '')) ?: null;
        $addr   = trim((string)($data['address'] ?? '')) ?: null;
        $tin    = trim((string)($data['tax_number'] ?? '')) ?: null;
        $defAcc = !empty($data['default_expense_account_id']) ? (int)$data['default_expense_account_id'] : null;

        if ($name === '') {
            throw new InvalidArgumentException('A vendor name is required.');
        }
        if ($defAcc !== null && !AccountingService::account($companyId, $defAcc)) {
            throw new RuntimeException('The default account is not in this company\'s chart.');
        }

        $pdo = DB::pdo();
        if ($id > 0) {
            $chk = $pdo->prepare('SELECT id FROM vendors WHERE id = :id AND company_id = :c LIMIT 1');
            $chk->execute(['id' => $id, 'c' => $companyId]);
            if (!$chk->fetch()) {
                throw new RuntimeException('Vendor not found.');
            }
            $pdo->prepare(
                'UPDATE vendors SET name = :n, email = :e, phone = :p, address = :a, tax_number = :t,
                        default_expense_account_id = :d WHERE id = :id'
            )->execute(['n' => $name, 'e' => $email, 'p' => $phone, 'a' => $addr, 't' => $tin, 'd' => $defAcc, 'id' => $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO vendors (company_id, name, email, phone, address, tax_number, default_expense_account_id, created_by)
                 VALUES (:c, :n, :e, :p, :a, :t, :d, :by)'
            )->execute(['c' => $companyId, 'n' => $name, 'e' => $email, 'p' => $phone, 'a' => $addr, 't' => $tin, 'd' => $defAcc, 'by' => $userId]);
            $id = (int)$pdo->lastInsertId();
        }

        Audit::log([
            'actor_user_id' => $userId,
            'company_id'    => $companyId,
            'event_type'    => 'accounting.vendor.saved',
            'summary'       => 'Saved vendor ' . $name,
            'metadata'      => ['vendor_id' => $id],
        ]);
        return $id;
    }

    public static function archiveVendor(int $companyId, int $vendorId, ?int $userId): void
    {
        $upd = DB::pdo()->prepare("UPDATE vendors SET status = 'archived' WHERE id = :id AND company_id = :c");
        $upd->execute(['id' => $vendorId, 'c' => $companyId]);
        if ($upd->rowCount() === 0) {
            throw new RuntimeException('Vendor not found.');
        }
        Audit::log([
            'actor_user_id' => $userId, 'company_id' => $companyId,
            'event_type' => 'accounting.vendor.archived',
            'summary' => 'Archived vendor #' . $vendorId, 'metadata' => ['vendor_id' => $vendorId],
        ]);
    }

    // ── Expenses ─────────────────────────────────────────────────────────

    /**
     * @param array $filters ['from','to','status','vendor_id','q','limit','offset']
     */
    public static function expenses(int $companyId, array $filters = []): array
    {
        $where = ['e.company_id = :c'];
        $params = ['c' => $companyId];

        if (!empty($filters['from'])) { $where[] = 'e.expense_date >= :from'; $params['from'] = $filters['from']; }
        if (!empty($filters['to']))   { $where[] = 'e.expense_date <= :to';   $params['to']   = $filters['to']; }
        if (!empty($filters['status']) && in_array($filters['status'], ['unpaid', 'paid', 'void'], true)) {
            $where[] = 'e.status = :st';
            $params['st'] = $filters['status'];
        }
        if (!empty($filters['vendor_id'])) { $where[] = 'e.vendor_id = :v'; $params['v'] = (int)$filters['vendor_id']; }
        if (!empty($filters['q'])) {
            $where[] = '(e.description LIKE :q OR e.vendor_name LIKE :q OR v.name LIKE :q OR e.reference LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $limit  = min(200, max(1, (int)($filters['limit'] ?? 100)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $sql = "SELECT e.*, a.code AS account_code, a.name AS account_name,
                       COALESCE(v.name, e.vendor_name) AS vendor_display,
                       pa.code AS paid_from_code, pa.name AS paid_from_name
                  FROM expenses e
                  JOIN gl_accounts a ON a.id = e.account_id
                  LEFT JOIN vendors v ON v.id = e.vendor_id
                  LEFT JOIN gl_accounts pa ON pa.id = e.paid_from_account_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY e.expense_date DESC, e.id DESC
                 LIMIT " . $limit . " OFFSET " . $offset;

        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']           = (int)$r['id'];
            $r['net_amount']   = (float)$r['net_amount'];
            $r['tax_amount']   = (float)$r['tax_amount'];
            $r['total_amount'] = (float)$r['total_amount'];
        }
        return $rows;
    }

    public static function expense(int $companyId, int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM expenses WHERE id = :id AND company_id = :c LIMIT 1');
        $stmt->execute(['id' => $id, 'c' => $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Record an expense / bill and post it.
     *
     * @param array $data vendor_id?, vendor_name?, expense_date, account_id, description,
     *                    net_amount, tax_amount, status ('paid'|'unpaid'),
     *                    paid_from_account_id (required when paid), reference?
     * @return int  the expense id
     */
    public static function saveExpense(int $companyId, array $data, ?int $userId): int
    {
        if (!Ledger::isActivated($companyId)) {
            throw new RuntimeException('Set up accounting for this company first.');
        }
        $pdo = DB::pdo();

        $date = (string)($data['expense_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            throw new InvalidArgumentException('A valid expense date is required.');
        }

        $accountId = (int)($data['account_id'] ?? 0);
        $account   = AccountingService::account($companyId, $accountId);
        if (!$account) {
            throw new InvalidArgumentException('Pick an account to charge the expense to.');
        }
        if ($account['is_control']) {
            throw new InvalidArgumentException('An expense cannot be charged to a control account.');
        }

        $net = round((float)($data['net_amount'] ?? 0), 2);
        $tax = round((float)($data['tax_amount'] ?? 0), 2);
        if ($net < 0 || $tax < 0) {
            throw new InvalidArgumentException('Amounts cannot be negative.');
        }
        $total = round($net + $tax, 2);
        if ($total <= Ledger::EPSILON) {
            throw new InvalidArgumentException('Enter an amount.');
        }

        $status = ($data['status'] ?? 'paid') === 'unpaid' ? 'unpaid' : 'paid';
        $vendorId   = !empty($data['vendor_id']) ? (int)$data['vendor_id'] : null;
        $vendorName = mb_substr(trim((string)($data['vendor_name'] ?? '')), 0, 150);
        if ($vendorId !== null) {
            $v = $pdo->prepare('SELECT name FROM vendors WHERE id = :id AND company_id = :c LIMIT 1');
            $v->execute(['id' => $vendorId, 'c' => $companyId]);
            $vn = $v->fetchColumn();
            if ($vn === false) {
                throw new RuntimeException('Vendor not found.');
            }
            $vendorName = (string)$vn;
        }

        $paidFrom = null;
        if ($status === 'paid') {
            $paidFrom = (int)($data['paid_from_account_id'] ?? 0);
            $pfAcct = AccountingService::account($companyId, $paidFrom);
            if (!$pfAcct || $pfAcct['type'] !== 'asset') {
                throw new InvalidArgumentException('Choose the bank or cash account it was paid from.');
            }
        }

        $reference   = mb_substr(trim((string)($data['reference'] ?? '')), 0, 120);
        $description = mb_substr(trim((string)($data['description'] ?? '')), 0, 255);
        $memo = $description !== '' ? $description : ('Expense' . ($vendorName !== '' ? ' — ' . $vendorName : ''));

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            $lines = [
                ['account_id' => $accountId, 'debit' => $net, 'credit' => 0, 'vendor_id' => $vendorId],
            ];
            if ($tax > Ledger::EPSILON) {
                $lines[] = ['slot' => 'gst_input', 'debit' => $tax, 'credit' => 0];
            }
            if ($status === 'paid') {
                $lines[] = ['account_id' => $paidFrom, 'debit' => 0, 'credit' => $total];
            } else {
                $lines[] = ['slot' => 'ap', 'debit' => 0, 'credit' => $total, 'vendor_id' => $vendorId];
            }

            $journalId = Ledger::post($companyId, [
                'date'    => $date,
                'memo'    => $memo,
                'source'  => 'expense',
                'system'  => true, // may touch AP (control) and GST Input
                'user_id' => $userId,
                'lines'   => $lines,
            ]);

            $pdo->prepare(
                'INSERT INTO expenses
                     (company_id, vendor_id, vendor_name, expense_date, account_id, description,
                      net_amount, tax_amount, total_amount, status, paid_from_account_id, reference, journal_id, created_by)
                 VALUES
                     (:c, :vid, :vn, :d, :acct, :desc, :net, :tax, :total, :st, :pf, :ref, :j, :by)'
            )->execute([
                'c' => $companyId, 'vid' => $vendorId, 'vn' => $vendorName, 'd' => $date, 'acct' => $accountId,
                'desc' => $description, 'net' => $net, 'tax' => $tax, 'total' => $total, 'st' => $status,
                'pf' => $paidFrom, 'ref' => $reference, 'j' => $journalId, 'by' => $userId,
            ]);
            $expenseId = (int)$pdo->lastInsertId();

            Audit::log([
                'actor_user_id' => $userId,
                'company_id'    => $companyId,
                'event_type'    => 'accounting.expense.recorded',
                'summary'       => 'Recorded ' . number_format($total, 2) . ' expense'
                    . ($vendorName !== '' ? ' — ' . $vendorName : '') . ' (' . $status . ')',
                'metadata'      => ['expense_id' => $expenseId, 'journal_id' => $journalId, 'total' => $total, 'status' => $status],
            ]);

            if ($ownTxn) {
                $pdo->commit();
            }
            return $expenseId;
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Pay an outstanding bill: DR Accounts Payable, CR the chosen bank/cash account.
     */
    public static function payExpense(int $companyId, int $expenseId, array $data, ?int $userId): void
    {
        $pdo = DB::pdo();
        $e = self::expense($companyId, $expenseId);
        if (!$e) {
            throw new RuntimeException('Expense not found.');
        }
        if ($e['status'] !== 'unpaid') {
            throw new RuntimeException('That bill is ' . $e['status'] . '.');
        }

        $paidFrom = (int)($data['paid_from_account_id'] ?? 0);
        $pfAcct = AccountingService::account($companyId, $paidFrom);
        if (!$pfAcct || $pfAcct['type'] !== 'asset') {
            throw new InvalidArgumentException('Choose the bank or cash account to pay from.');
        }
        $payDate = (string)($data['paid_on'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
            $payDate = date('Y-m-d');
        }
        $total = round((float)$e['total_amount'], 2);

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            $journalId = Ledger::post($companyId, [
                'date'    => $payDate,
                'memo'    => 'Bill payment' . ($e['vendor_name'] !== '' ? ' — ' . $e['vendor_name'] : ''),
                'source'  => 'expense_payment',
                'source_ref' => (string)$expenseId,
                'system'  => true,
                'user_id' => $userId,
                'lines'   => [
                    ['slot' => 'ap', 'debit' => $total, 'credit' => 0, 'vendor_id' => $e['vendor_id'] !== null ? (int)$e['vendor_id'] : null],
                    ['account_id' => $paidFrom, 'debit' => 0, 'credit' => $total],
                ],
            ]);

            $pdo->prepare(
                "UPDATE expenses SET status = 'paid', paid_from_account_id = :pf, payment_journal_id = :j WHERE id = :id"
            )->execute(['pf' => $paidFrom, 'j' => $journalId, 'id' => $expenseId]);

            Audit::log([
                'actor_user_id' => $userId, 'company_id' => $companyId,
                'event_type' => 'accounting.expense.paid',
                'summary' => 'Paid bill #' . $expenseId . ' (' . number_format($total, 2) . ')',
                'metadata' => ['expense_id' => $expenseId, 'journal_id' => $journalId],
            ]);

            if ($ownTxn) {
                $pdo->commit();
            }
        } catch (Throwable $e2) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e2;
        }
    }

    /**
     * Void an expense: reverse the bill journal (and the payment journal if it
     * was paid), then mark it void.
     */
    public static function voidExpense(int $companyId, int $expenseId, ?int $userId): void
    {
        $pdo = DB::pdo();
        $e = self::expense($companyId, $expenseId);
        if (!$e) {
            throw new RuntimeException('Expense not found.');
        }
        if ($e['status'] === 'void') {
            throw new RuntimeException('That expense is already void.');
        }

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            if (!empty($e['payment_journal_id'])) {
                Ledger::reverse($companyId, (int)$e['payment_journal_id'], date('Y-m-d'), 'Void bill payment', $userId);
            }
            if (!empty($e['journal_id'])) {
                Ledger::reverse($companyId, (int)$e['journal_id'], date('Y-m-d'), 'Void expense', $userId);
            }

            $pdo->prepare("UPDATE expenses SET status = 'void' WHERE id = :id")->execute(['id' => $expenseId]);

            Audit::log([
                'actor_user_id' => $userId, 'company_id' => $companyId,
                'event_type' => 'accounting.expense.voided',
                'summary' => 'Voided expense #' . $expenseId . ' (' . number_format((float)$e['total_amount'], 2) . ')',
                'metadata' => ['expense_id' => $expenseId],
            ]);

            if ($ownTxn) {
                $pdo->commit();
            }
        } catch (Throwable $ex) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
    }

    /** Totals for the expenses page header + the desk. */
    public static function summary(int $companyId): array
    {
        $fyStart = Ledger::fiscalYearStart($companyId, date('Y-m-d'));
        $r = DB::pdo()->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN status <> 'void' AND expense_date >= :fy THEN net_amount + tax_amount END), 0) AS ytd_total,
              COALESCE(SUM(CASE WHEN status = 'unpaid' THEN total_amount END), 0) AS unpaid_total,
              SUM(status = 'unpaid') AS unpaid_count
            FROM expenses WHERE company_id = :c
        ");
        $r->execute(['fy' => $fyStart, 'c' => $companyId]);
        $row = $r->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'ytd_total'     => round((float)($row['ytd_total'] ?? 0), 2),
            'unpaid_total'  => round((float)($row['unpaid_total'] ?? 0), 2),
            'unpaid_count'  => (int)($row['unpaid_count'] ?? 0),
        ];
    }
}
