<?php
require_once __DIR__ . '/DB.php';

/**
 * Centryk Business — the double-entry posting engine.
 *
 * Everything that changes a company's books goes through Ledger::post(): manual
 * journals from the accounting desk, and auto-posts from the subledgers (AR
 * invoices/receipts/write-offs, expenses, payroll, POS). A journal is written
 * only if it balances to the cent and its period is open.
 *
 * Accounts can be named by numeric `account_id` or by `slot` (a stable binding
 * in gl_account_map — 'ar', 'gst_output', 'sales_default', …) so a company can
 * renumber its chart without breaking auto-posting.
 *
 * Reads here are raw (balances / activity by account). Shaped statements
 * (trial balance, P&L, balance sheet, GL detail) live in AccountingService.
 */
class Ledger
{
    /** Tolerance for the balanced-entry check and zero comparisons. */
    public const EPSILON = 0.005;

    /** @var array<int,array|null> per-request memo of company_accounting rows */
    private static array $configMemo = [];

    /** @var array<string,int> per-request memo of resolved slots, "cid:slot" => account_id */
    private static array $slotMemo = [];

    // ── Configuration ───────────────────────────────────────────────────────

    /**
     * The company_accounting row, or null when the books have not been set up.
     */
    public static function config(int $companyId): ?array
    {
        if (array_key_exists($companyId, self::$configMemo)) {
            return self::$configMemo[$companyId];
        }
        $stmt = DB::pdo()->prepare('SELECT * FROM company_accounting WHERE company_id = :c LIMIT 1');
        $stmt->execute(['c' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        self::$configMemo[$companyId] = $row;
        return $row;
    }

    public static function isActivated(int $companyId): bool
    {
        $cfg = self::config($companyId);
        return $cfg !== null && !empty($cfg['activated_at']);
    }

    /** Clear the per-request caches (after COA / mapping / settings changes). */
    public static function flushCache(): void
    {
        self::$configMemo = [];
        self::$slotMemo = [];
    }

    /**
     * Resolve a control-account slot to its account id.
     * @throws RuntimeException when the slot is not mapped
     */
    public static function slotAccountId(int $companyId, string $slot): int
    {
        $key = $companyId . ':' . $slot;
        if (isset(self::$slotMemo[$key])) {
            return self::$slotMemo[$key];
        }
        $stmt = DB::pdo()->prepare(
            'SELECT account_id FROM gl_account_map WHERE company_id = :c AND slot = :s LIMIT 1'
        );
        $stmt->execute(['c' => $companyId, 's' => $slot]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id <= 0) {
            throw new RuntimeException("No account is mapped to '{$slot}'. Set it under Accounting settings.");
        }
        self::$slotMemo[$key] = $id;
        return $id;
    }

    public static function slotAccountIdOrNull(int $companyId, string $slot): ?int
    {
        try {
            return self::slotAccountId($companyId, $slot);
        } catch (RuntimeException $e) {
            return null;
        }
    }

    // ── Periods ────────────────────────────────────────────────────────────

    /**
     * Make sure the 12 periods of the fiscal year containing $date exist.
     * Idempotent. Safe to call before every post.
     */
    public static function ensureFiscalYear(int $companyId, string $date): void
    {
        $cfg = self::config($companyId);
        $startMonth = $cfg ? (int)$cfg['fiscal_year_start_month'] : 1;
        if ($startMonth < 1 || $startMonth > 12) {
            $startMonth = 1;
        }

        $ts = strtotime($date);
        $y  = (int)date('Y', $ts);
        $m  = (int)date('n', $ts);
        // Fiscal year label = the calendar year the FY starts in.
        $fyStartYear = $m >= $startMonth ? $y : $y - 1;

        $exists = DB::pdo()->prepare(
            'SELECT COUNT(*) FROM gl_periods WHERE company_id = :c AND fiscal_year = :fy'
        );
        $exists->execute(['c' => $companyId, 'fy' => $fyStartYear]);
        if ((int)$exists->fetchColumn() >= 12) {
            return;
        }

        $ins = DB::pdo()->prepare(
            'INSERT IGNORE INTO gl_periods (company_id, fiscal_year, period_no, start_date, end_date, status)
             VALUES (:c, :fy, :pn, :sd, :ed, "open")'
        );
        for ($i = 0; $i < 12; $i++) {
            $periodStart = mktime(0, 0, 0, $startMonth + $i, 1, $fyStartYear);
            $sd = date('Y-m-01', $periodStart);
            $ed = date('Y-m-t', $periodStart);
            $ins->execute([
                'c'  => $companyId,
                'fy' => $fyStartYear,
                'pn' => $i + 1,
                'sd' => $sd,
                'ed' => $ed,
            ]);
        }
    }

    /**
     * The period row covering $date, or null if none exists yet.
     */
    public static function periodForDate(int $companyId, string $date): ?array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT * FROM gl_periods
              WHERE company_id = :c AND :d BETWEEN start_date AND end_date
              LIMIT 1'
        );
        $stmt->execute(['c' => $companyId, 'd' => $date]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Posting ───────────────────────────────────────────────────────────

    /**
     * Post (or save as draft) a journal entry.
     *
     * $entry = [
     *   'date'       => 'Y-m-d',
     *   'memo'       => string,
     *   'source'     => 'manual' | 'ar_invoice' | …           (default 'manual')
     *   'source_ref' => string,
     *   'status'     => 'posted' | 'draft'                    (default 'posted')
     *   'system'     => bool     — internal caller, may touch control accounts
     *   'user_id'    => ?int
     *   'lines'      => [
     *       ['account_id'|'slot' => …, 'debit' => 0, 'credit' => 0,
     *        'memo' => '', 'customer_id' => null, 'vendor_id' => null],
     *       …
     *   ],
     * ]
     *
     * @return int  the new gl_journals.id
     * @throws InvalidArgumentException on a malformed / unbalanced entry
     * @throws RuntimeException         on a closed period or hard lock
     */
    public static function post(int $companyId, array $entry): int
    {
        $pdo = DB::pdo();

        $date   = (string)($entry['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            throw new InvalidArgumentException('A valid entry date is required.');
        }
        $status = ($entry['status'] ?? 'posted') === 'draft' ? 'draft' : 'posted';
        $source = (string)($entry['source'] ?? 'manual');
        $system = !empty($entry['system']);
        $memo   = mb_substr(trim((string)($entry['memo'] ?? '')), 0, 255);
        $srcRef = mb_substr(trim((string)($entry['source_ref'] ?? '')), 0, 64);
        $userId = isset($entry['user_id']) ? (int)$entry['user_id'] : null;

        $rawLines = $entry['lines'] ?? [];
        if (!is_array($rawLines) || count($rawLines) < 2) {
            throw new InvalidArgumentException('A journal entry needs at least two lines.');
        }

        // ── Resolve + validate lines ──────────────────────────────────────
        $accountCache = [];
        $lines = [];
        $sumDr = 0.0;
        $sumCr = 0.0;

        foreach ($rawLines as $idx => $l) {
            $accountId = isset($l['slot']) && $l['slot'] !== ''
                ? self::slotAccountId($companyId, (string)$l['slot'])
                : (int)($l['account_id'] ?? 0);
            if ($accountId <= 0) {
                throw new InvalidArgumentException('Every line needs an account.');
            }

            if (!isset($accountCache[$accountId])) {
                $a = $pdo->prepare(
                    'SELECT id, is_active, is_control FROM gl_accounts
                      WHERE id = :id AND company_id = :c LIMIT 1'
                );
                $a->execute(['id' => $accountId, 'c' => $companyId]);
                $accountCache[$accountId] = $a->fetch(PDO::FETCH_ASSOC) ?: false;
            }
            $acct = $accountCache[$accountId];
            if ($acct === false) {
                throw new InvalidArgumentException('An account on the entry does not belong to this company.');
            }
            if (!$acct['is_active']) {
                throw new InvalidArgumentException('An account on the entry is archived.');
            }
            if ($acct['is_control'] && !$system) {
                throw new InvalidArgumentException(
                    'A control account (Accounts Receivable, GST, …) cannot be posted to by hand — '
                    . 'it is maintained by its subledger.'
                );
            }

            $debit  = round(max(0.0, (float)($l['debit'] ?? 0)), 2);
            $credit = round(max(0.0, (float)($l['credit'] ?? 0)), 2);
            if ($debit > 0 && $credit > 0) {
                throw new InvalidArgumentException('A line cannot have both a debit and a credit.');
            }
            if ($debit <= 0 && $credit <= 0) {
                continue; // drop empty lines silently
            }

            $lines[] = [
                'account_id'  => $accountId,
                'debit'       => $debit,
                'credit'      => $credit,
                'memo'        => mb_substr(trim((string)($l['memo'] ?? '')), 0, 255),
                'customer_id' => !empty($l['customer_id']) ? (int)$l['customer_id'] : null,
                'vendor_id'   => !empty($l['vendor_id']) ? (int)$l['vendor_id'] : null,
            ];
            $sumDr += $debit;
            $sumCr += $credit;
        }

        if (count($lines) < 2) {
            throw new InvalidArgumentException('A journal entry needs at least two non-zero lines.');
        }
        if (abs($sumDr - $sumCr) > self::EPSILON) {
            throw new InvalidArgumentException(sprintf(
                'The entry does not balance: debits %.2f, credits %.2f.',
                $sumDr,
                $sumCr
            ));
        }

        // ── Period / lock checks (posted entries only) ────────────────────
        $periodId = null;
        if ($status === 'posted') {
            $cfg = self::config($companyId);
            if ($cfg && !empty($cfg['lock_before']) && $date <= $cfg['lock_before']) {
                throw new RuntimeException('That date is in a locked prior period (' . $cfg['lock_before'] . ' and earlier).');
            }
            self::ensureFiscalYear($companyId, $date);
            $period = self::periodForDate($companyId, $date);
            if ($period === null) {
                throw new RuntimeException('No accounting period covers ' . $date . '.');
            }
            if ($period['status'] !== 'open') {
                throw new RuntimeException('The period containing ' . $date . ' is ' . $period['status'] . '.');
            }
            $periodId = (int)$period['id'];
        } else {
            $period = self::periodForDate($companyId, $date);
            $periodId = $period ? (int)$period['id'] : null;
        }

        // ── Write ────────────────────────────────────────────────────────
        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            $journalNo = self::nextCounter($companyId, 'journal');

            $pdo->prepare(
                'INSERT INTO gl_journals
                     (company_id, journal_no, entry_date, period_id, memo, source, source_ref,
                      status, total_debit, total_credit, created_by, posted_at)
                 VALUES
                     (:c, :no, :d, :pid, :memo, :src, :ref, :st, :dr, :cr, :by, :pat)'
            )->execute([
                'c'    => $companyId,
                'no'   => $journalNo,
                'd'    => $date,
                'pid'  => $periodId,
                'memo' => $memo,
                'src'  => $source,
                'ref'  => $srcRef,
                'st'   => $status,
                'dr'   => round($sumDr, 2),
                'cr'   => round($sumCr, 2),
                'by'   => $userId,
                'pat'  => $status === 'posted' ? date('Y-m-d H:i:s') : null,
            ]);
            $journalId = (int)$pdo->lastInsertId();

            $li = $pdo->prepare(
                'INSERT INTO gl_journal_lines
                     (journal_id, company_id, line_no, account_id, debit, credit, memo, customer_id, vendor_id, entry_date)
                 VALUES
                     (:j, :c, :ln, :acct, :dr, :cr, :memo, :cust, :vend, :d)'
            );
            $lineNo = 0;
            foreach ($lines as $l) {
                $lineNo++;
                $li->execute([
                    'j'    => $journalId,
                    'c'    => $companyId,
                    'ln'   => $lineNo,
                    'acct' => $l['account_id'],
                    'dr'   => $l['debit'],
                    'cr'   => $l['credit'],
                    'memo' => $l['memo'],
                    'cust' => $l['customer_id'],
                    'vend' => $l['vendor_id'],
                    'd'    => $date,
                ]);
            }

            if ($ownTxn) {
                $pdo->commit();
            }
            return $journalId;
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Post the accounting-correct reversal of a journal: a mirror entry dated
     * $date (default today) with debits and credits swapped. The original is
     * left in place and cross-linked.
     *
     * @return int  the reversing journal's id
     */
    public static function reverse(int $companyId, int $journalId, ?string $date, ?string $memo, ?int $userId): int
    {
        $pdo = DB::pdo();

        $j = $pdo->prepare('SELECT * FROM gl_journals WHERE id = :id AND company_id = :c LIMIT 1');
        $j->execute(['id' => $journalId, 'c' => $companyId]);
        $journal = $j->fetch(PDO::FETCH_ASSOC);
        if (!$journal) {
            throw new RuntimeException('Journal not found.');
        }
        if ($journal['status'] !== 'posted') {
            throw new RuntimeException('Only a posted journal can be reversed.');
        }
        if (!empty($journal['reversed_by_journal_id'])) {
            throw new RuntimeException('That journal has already been reversed.');
        }

        $ls = $pdo->prepare('SELECT * FROM gl_journal_lines WHERE journal_id = :j ORDER BY line_no');
        $ls->execute(['j' => $journalId]);
        $srcLines = $ls->fetchAll(PDO::FETCH_ASSOC);
        if (!$srcLines) {
            throw new RuntimeException('That journal has no lines.');
        }

        $revDate = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
        $revMemo = $memo !== null && trim($memo) !== ''
            ? trim($memo)
            : ('Reversal of J' . $journal['journal_no'] . ($journal['memo'] !== '' ? ' — ' . $journal['memo'] : ''));

        $lines = [];
        foreach ($srcLines as $l) {
            $lines[] = [
                'account_id'  => (int)$l['account_id'],
                'debit'       => (float)$l['credit'],
                'credit'      => (float)$l['debit'],
                'memo'        => $l['memo'],
                'customer_id' => $l['customer_id'] !== null ? (int)$l['customer_id'] : null,
                'vendor_id'   => $l['vendor_id'] !== null ? (int)$l['vendor_id'] : null,
            ];
        }

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            $revId = self::post($companyId, [
                'date'       => $revDate,
                'memo'       => $revMemo,
                'source'     => $journal['source'],
                'source_ref' => $journal['source_ref'],
                'status'     => 'posted',
                'system'     => true, // a reversal may legitimately touch control accounts
                'user_id'    => $userId,
                'lines'      => $lines,
            ]);

            $pdo->prepare('UPDATE gl_journals SET is_reversal = 1, reverses_journal_id = :orig WHERE id = :rev')
                ->execute(['orig' => $journalId, 'rev' => $revId]);
            $pdo->prepare('UPDATE gl_journals SET reversed_by_journal_id = :rev WHERE id = :orig')
                ->execute(['rev' => $revId, 'orig' => $journalId]);

            if ($ownTxn) {
                $pdo->commit();
            }
            return $revId;
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Allocate the next value of a per-company counter. Must be called inside a
     * transaction — the row is locked FOR UPDATE.
     */
    private static function nextCounter(int $companyId, string $name): int
    {
        $pdo = DB::pdo();
        $sel = $pdo->prepare(
            'SELECT next_val FROM gl_counters WHERE company_id = :c AND name = :n FOR UPDATE'
        );
        $sel->execute(['c' => $companyId, 'n' => $name]);
        $cur = $sel->fetchColumn();

        if ($cur === false) {
            $pdo->prepare('INSERT INTO gl_counters (company_id, name, next_val) VALUES (:c, :n, 2)')
                ->execute(['c' => $companyId, 'n' => $name]);
            return 1;
        }
        $cur = (int)$cur;
        $pdo->prepare('UPDATE gl_counters SET next_val = :v WHERE company_id = :c AND name = :n')
            ->execute(['v' => $cur + 1, 'c' => $companyId, 'n' => $name]);
        return $cur;
    }

    // ── Raw reads ─────────────────────────────────────────────────────────

    /**
     * Net movement per account between two dates (inclusive), posted lines only.
     * $from = null means "from the beginning of time".
     *
     * @return array<int,array{debit:float,credit:float,net:float}>  keyed by account_id
     *         net is signed debit-positive (debit − credit).
     */
    public static function activity(int $companyId, ?string $from, string $to): array
    {
        $sql = 'SELECT l.account_id,
                       SUM(l.debit)  AS d,
                       SUM(l.credit) AS c
                  FROM gl_journal_lines l
                  JOIN gl_journals j ON j.id = l.journal_id
                 WHERE l.company_id = :c AND j.status = "posted" AND l.entry_date <= :to';
        $params = ['c' => $companyId, 'to' => $to];
        if ($from !== null) {
            $sql .= ' AND l.entry_date >= :from';
            $params['from'] = $from;
        }
        $sql .= ' GROUP BY l.account_id';

        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = (float)$r['d'];
            $c = (float)$r['c'];
            $out[(int)$r['account_id']] = [
                'debit'  => round($d, 2),
                'credit' => round($c, 2),
                'net'    => round($d - $c, 2),
            ];
        }
        return $out;
    }

    /**
     * Cumulative balance per account as at $asOf (posted lines only).
     * Same shape as activity(); net is debit − credit.
     */
    public static function balancesAsOf(int $companyId, string $asOf): array
    {
        return self::activity($companyId, null, $asOf);
    }

    /**
     * First day of the fiscal year that contains $date, as 'Y-m-d'.
     */
    public static function fiscalYearStart(int $companyId, string $date): string
    {
        $cfg = self::config($companyId);
        $startMonth = $cfg ? (int)$cfg['fiscal_year_start_month'] : 1;
        if ($startMonth < 1 || $startMonth > 12) {
            $startMonth = 1;
        }
        $ts = strtotime($date);
        $y  = (int)date('Y', $ts);
        $m  = (int)date('n', $ts);
        $fyStartYear = $m >= $startMonth ? $y : $y - 1;
        return sprintf('%04d-%02d-01', $fyStartYear, $startMonth);
    }
}
