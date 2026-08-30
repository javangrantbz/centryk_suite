<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';
require_once __DIR__ . '/../core/Ledger.php';

/**
 * Centryk Business — posts the Receivables subledger into the general ledger.
 *
 * Model: "opening balance only". When a company turns on AR posting we take a
 * single opening-balance journal for what customers owe as at the go-live date
 * (company_accounting.ar_started_on); from that date forward every invoice,
 * receipt and write-off posts its own journal to the control accounts:
 *
 *   invoice   DR Accounts Receivable       CR Sales, CR GST Output
 *   receipt   DR Bank / Undeposited Funds  CR Accounts Receivable
 *   write-off DR Bad Debt / Sales Returns  CR Accounts Receivable
 *
 * Every poster is idempotent (keyed on gl_journals.source + source_ref) so the
 * best-effort hooks in ReceivablesService and the periodic sweep, sync(), can
 * both run without double-posting. The hooks never let a ledger error break an
 * AR action — sync() is the backstop.
 */
class GlSync
{
    /** @return ?string  the go-live date, or null when AR posting is off */
    public static function arStartedOn(int $companyId): ?string
    {
        $cfg = Ledger::config($companyId);
        return $cfg && !empty($cfg['ar_started_on']) ? (string)$cfg['ar_started_on'] : null;
    }

    public static function arEnabled(int $companyId): bool
    {
        return Ledger::isActivated($companyId) && self::arStartedOn($companyId) !== null;
    }

    // ── Turn on / off ──────────────────────────────────────────────────────

    /**
     * Switch on AR posting: set the go-live date and post the opening-balance
     * journal (per-customer DR Accounts Receivable, CR Opening Balance Equity)
     * for balances as at that date. Refused if already on.
     *
     * @return array{journal_id:?int, opening_total:float, customers:int}
     */
    public static function enableAr(int $companyId, string $openingDate, ?int $userId): array
    {
        if (!Ledger::isActivated($companyId)) {
            throw new RuntimeException('Set up accounting for this company first.');
        }
        if (self::arStartedOn($companyId) !== null) {
            throw new RuntimeException('AR posting is already switched on.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $openingDate)) {
            $openingDate = date('Y-m-d');
        }

        $arAcct  = Ledger::slotAccountId($companyId, 'ar');
        $obeAcct = Ledger::slotAccountId($companyId, 'opening_balance_equity');

        $balances = self::arBalancesBefore($companyId, $openingDate);

        $pdo = DB::pdo();
        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            $pdo->prepare('UPDATE company_accounting SET ar_started_on = :d WHERE company_id = :c')
                ->execute(['d' => $openingDate, 'c' => $companyId]);
            Ledger::flushCache();

            $journalId = null;
            $total = 0.0;
            foreach ($balances as $b) {
                $total += $b['balance'];
            }
            $total = round($total, 2);

            if (abs($total) > Ledger::EPSILON) {
                $lines = [];
                foreach ($balances as $b) {
                    if (abs($b['balance']) < Ledger::EPSILON) {
                        continue;
                    }
                    $lines[] = [
                        'account_id'  => $arAcct,
                        'debit'       => $b['balance'] > 0 ? round($b['balance'], 2) : 0,
                        'credit'      => $b['balance'] < 0 ? round(-$b['balance'], 2) : 0,
                        'memo'        => 'Opening balance — ' . $b['name'],
                        'customer_id' => $b['customer_id'],
                    ];
                }
                $lines[] = [
                    'account_id' => $obeAcct,
                    'debit'      => $total < 0 ? round(-$total, 2) : 0,
                    'credit'     => $total > 0 ? round($total, 2) : 0,
                ];
                $journalId = Ledger::post($companyId, [
                    'date'       => $openingDate,
                    'memo'       => 'Accounts receivable opening balance',
                    'source'     => 'opening',
                    'source_ref' => 'ar',
                    'system'     => true,
                    'user_id'    => $userId,
                    'lines'      => $lines,
                ]);
            }

            Audit::log([
                'actor_user_id' => $userId,
                'company_id'    => $companyId,
                'event_type'    => 'accounting.ar.enabled',
                'summary'       => 'Switched on AR ledger posting from ' . $openingDate
                    . ' — opening balance ' . number_format($total, 2),
                'metadata'      => ['ar_started_on' => $openingDate, 'opening_total' => $total, 'journal_id' => $journalId],
            ]);

            if ($ownTxn) {
                $pdo->commit();
            }
            return ['journal_id' => $journalId, 'opening_total' => $total, 'customers' => count($balances)];
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Stop new AR auto-posting. Posted journals are left untouched. */
    public static function disableAr(int $companyId, ?int $userId): void
    {
        DB::pdo()->prepare('UPDATE company_accounting SET ar_started_on = NULL WHERE company_id = :c')
            ->execute(['c' => $companyId]);
        Ledger::flushCache();
        Audit::log([
            'actor_user_id' => $userId,
            'company_id'    => $companyId,
            'event_type'    => 'accounting.ar.disabled',
            'summary'       => 'Switched off AR ledger posting',
            'metadata'      => [],
        ]);
    }

    /**
     * Per-customer AR balance as at $date (invoices issued before $date, less
     * payments received before $date; plus the customer's opening balance).
     *
     * @return array<int,array{customer_id:int, name:string, balance:float}>
     */
    private static function arBalancesBefore(int $companyId, string $date): array
    {
        $pdo = DB::pdo();

        // Current outstanding on invoices raised before the go-live date. Uses
        // invoices.amount_paid directly (like the Receivables portfolio) so it
        // agrees with the subledger regardless of how a payment was recorded.
        // A payment made ON or AFTER go-live still credits AR through its own
        // auto-posted receipt journal, so this is not double-counted.
        $inv = $pdo->prepare("
            SELECT i.customer_id, SUM(i.total - i.amount_paid) AS outstanding
            FROM invoices i
            WHERE i.company_id = :c AND i.issue_date < :d AND i.status IN ('sent','overdue')
            GROUP BY i.customer_id
        ");
        $inv->execute(['c' => $companyId, 'd' => $date]);
        $out = [];
        foreach ($inv->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['customer_id']] = (float)$r['outstanding'];
        }

        // On-account credit from receipts before the go-live date.
        $cr = $pdo->prepare("
            SELECT p.customer_id, SUM(p.amount) - COALESCE(SUM(a.alloc), 0) AS credit
            FROM ar_payments p
            LEFT JOIN (SELECT ar_payment_id, SUM(amount) AS alloc FROM ar_payment_allocations GROUP BY ar_payment_id) a
                   ON a.ar_payment_id = p.id
            WHERE p.company_id = :c AND p.received_on < :d AND p.clearance_status <> 'bounced'
            GROUP BY p.customer_id
        ");
        $cr->execute(['c' => $companyId, 'd' => $date]);
        foreach ($cr->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['customer_id']] = ($out[(int)$r['customer_id']] ?? 0) - (float)$r['credit'];
        }

        // The customer's stated opening balance.
        $ob = $pdo->prepare("SELECT id, name, opening_balance FROM customers WHERE company_id = :c AND ar_status = 'active'");
        $ob->execute(['c' => $companyId]);
        $names = [];
        foreach ($ob->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $names[(int)$r['id']] = $r['name'];
            if (abs((float)$r['opening_balance']) > Ledger::EPSILON) {
                $out[(int)$r['id']] = ($out[(int)$r['id']] ?? 0) + (float)$r['opening_balance'];
            }
        }

        $rows = [];
        foreach ($out as $customerId => $bal) {
            if (abs($bal) < Ledger::EPSILON) {
                continue;
            }
            $rows[] = [
                'customer_id' => $customerId,
                'name'        => $names[$customerId] ?? ('Customer #' . $customerId),
                'balance'     => round($bal, 2),
            ];
        }
        return $rows;
    }

    // ── Per-item posters (idempotent) ─────────────────────────────────────

    private static function alreadyPosted(int $companyId, string $source, string $ref): bool
    {
        $stmt = DB::pdo()->prepare(
            "SELECT 1 FROM gl_journals
              WHERE company_id = :c AND source = :s AND source_ref = :r AND status <> 'void' LIMIT 1"
        );
        $stmt->execute(['c' => $companyId, 's' => $source, 'r' => $ref]);
        return (bool)$stmt->fetchColumn();
    }

    private static function bankSlotFor(string $method): string
    {
        return in_array($method, ['bank_transfer', 'xfer'], true) ? 'bank_default' : 'undeposited_funds';
    }

    /** Post an invoice: DR AR, CR Sales (net), CR GST Output (tax). */
    public static function postInvoice(int $companyId, int $invoiceId): bool
    {
        $start = self::arStartedOn($companyId);
        if ($start === null) {
            return false;
        }
        if (self::alreadyPosted($companyId, 'ar_invoice', (string)$invoiceId)) {
            return false;
        }

        $stmt = DB::pdo()->prepare("
            SELECT id, customer_id, invoice_number, issue_date, total, tax, status
            FROM invoices
            WHERE id = :id AND company_id = :c
              AND status IN ('sent','overdue','paid','written_off') AND issue_date >= :start
            LIMIT 1
        ");
        $stmt->execute(['id' => $invoiceId, 'c' => $companyId, 'start' => $start]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return false;
        }

        $total = round((float)$inv['total'], 2);
        $tax   = round((float)$inv['tax'], 2);
        $net   = round($total - $tax, 2);
        if ($total <= Ledger::EPSILON) {
            return false;
        }

        $lines = [
            ['slot' => 'ar', 'debit' => $total, 'credit' => 0, 'customer_id' => (int)$inv['customer_id']],
            ['slot' => 'sales_default', 'debit' => 0, 'credit' => $net],
        ];
        if ($tax > Ledger::EPSILON) {
            $lines[] = ['slot' => 'gst_output', 'debit' => 0, 'credit' => $tax];
        }

        Ledger::post($companyId, [
            'date'       => $inv['issue_date'],
            'memo'       => 'Invoice ' . $inv['invoice_number'],
            'source'     => 'ar_invoice',
            'source_ref' => (string)$invoiceId,
            'system'     => true,
            'lines'      => $lines,
        ]);
        return true;
    }

    /** Post a receipt: DR Bank / Undeposited Funds, CR AR. */
    public static function postReceipt(int $companyId, int $paymentId): bool
    {
        $start = self::arStartedOn($companyId);
        if ($start === null) {
            return false;
        }
        if (self::alreadyPosted($companyId, 'ar_receipt', (string)$paymentId)) {
            return false;
        }

        $stmt = DB::pdo()->prepare("
            SELECT id, customer_id, received_on, amount, method, reference, clearance_status
            FROM ar_payments
            WHERE id = :id AND company_id = :c AND received_on >= :start
            LIMIT 1
        ");
        $stmt->execute(['id' => $paymentId, 'c' => $companyId, 'start' => $start]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p || $p['clearance_status'] === 'bounced') {
            return false;
        }
        $amount = round((float)$p['amount'], 2);
        if ($amount <= Ledger::EPSILON) {
            return false;
        }

        Ledger::post($companyId, [
            'date'       => $p['received_on'],
            'memo'       => 'Receipt ' . ($p['reference'] !== '' ? $p['reference'] : '#' . $paymentId),
            'source'     => 'ar_receipt',
            'source_ref' => (string)$paymentId,
            'system'     => true,
            'lines'      => [
                ['slot' => self::bankSlotFor((string)$p['method']), 'debit' => $amount, 'credit' => 0],
                ['slot' => 'ar', 'debit' => 0, 'credit' => $amount, 'customer_id' => (int)$p['customer_id']],
            ],
        ]);
        return true;
    }

    /** Post an approved write-off: DR Bad Debt / Sales Returns, CR AR. */
    public static function postWriteoff(int $companyId, int $writeoffId): bool
    {
        $start = self::arStartedOn($companyId);
        if ($start === null) {
            return false;
        }
        if (self::alreadyPosted($companyId, 'ar_writeoff', (string)$writeoffId)) {
            return false;
        }

        $stmt = DB::pdo()->prepare("
            SELECT w.id, w.customer_id, w.invoice_id, w.amount, w.kind, w.reason, w.status,
                   w.decided_at, i.invoice_number
            FROM ar_writeoffs w
            JOIN invoices i ON i.id = w.invoice_id
            WHERE w.id = :id AND w.company_id = :c AND w.status = 'approved'
            LIMIT 1
        ");
        $stmt->execute(['id' => $writeoffId, 'c' => $companyId]);
        $w = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$w) {
            return false;
        }
        $amount = round((float)$w['amount'], 2);
        if ($amount <= Ledger::EPSILON) {
            return false;
        }
        $date = $w['decided_at'] ? substr((string)$w['decided_at'], 0, 10) : date('Y-m-d');
        // Write-offs decided before go-live are already reflected in the invoice's
        // amount_paid, hence in the opening balance — don't post them again.
        if ($date < $start) {
            return false;
        }

        $expenseSlot = $w['kind'] === 'bad_debt' ? 'bad_debt'
            : (Ledger::slotAccountIdOrNull($companyId, 'sales_returns') ? 'sales_returns' : 'bad_debt');

        Ledger::post($companyId, [
            'date'       => $date,
            'memo'       => 'Write-off ' . $w['invoice_number'] . ($w['reason'] !== '' ? ' — ' . $w['reason'] : ''),
            'source'     => 'ar_writeoff',
            'source_ref' => (string)$writeoffId,
            'system'     => true,
            'lines'      => [
                ['slot' => $expenseSlot, 'debit' => $amount, 'credit' => 0],
                ['slot' => 'ar', 'debit' => 0, 'credit' => $amount, 'customer_id' => (int)$w['customer_id']],
            ],
        ]);
        return true;
    }

    /** A cheque bounced — reverse the receipt journal that had cleared it. */
    public static function reverseForBouncedCheque(int $companyId, int $paymentId): bool
    {
        if (self::arStartedOn($companyId) === null) {
            return false;
        }
        $stmt = DB::pdo()->prepare(
            "SELECT id, reversed_by_journal_id FROM gl_journals
              WHERE company_id = :c AND source = 'ar_receipt' AND source_ref = :r AND status = 'posted' LIMIT 1"
        );
        $stmt->execute(['c' => $companyId, 'r' => (string)$paymentId]);
        $j = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$j || !empty($j['reversed_by_journal_id'])) {
            return false;
        }
        Ledger::reverse($companyId, (int)$j['id'], date('Y-m-d'), 'Cheque returned', null);
        return true;
    }

    // ── Best-effort hook (called from ReceivablesService) ─────────────────

    /**
     * Fire a poster after an AR action, swallowing any ledger error — the AR
     * write must never fail because of the GL. sync() cleans up anything missed.
     */
    public static function tryPost(int $companyId, string $what, int $id): void
    {
        try {
            if (!self::arEnabled($companyId)) {
                return;
            }
            match ($what) {
                'invoice'        => self::postInvoice($companyId, $id),
                'receipt'        => self::postReceipt($companyId, $id),
                'writeoff'       => self::postWriteoff($companyId, $id),
                'cheque_bounce'  => self::reverseForBouncedCheque($companyId, $id),
                default          => null,
            };
        } catch (Throwable $e) {
            error_log('GlSync::tryPost ' . $what . ' #' . $id . ' failed: ' . $e->getMessage());
        }
    }

    // ── Sweep ────────────────────────────────────────────────────────────

    /** How many AR items are not yet in the ledger (for the desk indicator). */
    public static function pendingCount(int $companyId): int
    {
        $start = self::arStartedOn($companyId);
        if ($start === null) {
            return 0;
        }
        $pdo = DB::pdo();

        $q = $pdo->prepare("
            SELECT
              (SELECT COUNT(*) FROM invoices i
                WHERE i.company_id = :c1 AND i.issue_date >= :s1
                  AND i.status IN ('sent','overdue','paid','written_off')
                  AND NOT EXISTS (SELECT 1 FROM gl_journals j WHERE j.company_id = i.company_id AND j.source = 'ar_invoice' AND j.source_ref = i.id)) AS inv,
              (SELECT COUNT(*) FROM ar_payments p
                WHERE p.company_id = :c2 AND p.received_on >= :s2 AND p.clearance_status <> 'bounced'
                  AND NOT EXISTS (SELECT 1 FROM gl_journals j WHERE j.company_id = p.company_id AND j.source = 'ar_receipt' AND j.source_ref = p.id)) AS rcpt,
              (SELECT COUNT(*) FROM ar_writeoffs w
                WHERE w.company_id = :c3 AND w.status = 'approved' AND w.decided_at >= :s3
                  AND NOT EXISTS (SELECT 1 FROM gl_journals j WHERE j.company_id = w.company_id AND j.source = 'ar_writeoff' AND j.source_ref = w.id)) AS wo
        ");
        $q->execute([
            'c1' => $companyId, 's1' => $start,
            'c2' => $companyId, 's2' => $start,
            'c3' => $companyId, 's3' => $start,
        ]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return (int)($r['inv'] ?? 0) + (int)($r['rcpt'] ?? 0) + (int)($r['wo'] ?? 0);
    }

    /**
     * Post everything in the Receivables subledger that isn't in the ledger yet.
     * Idempotent; safe to run on a schedule.
     *
     * @return array{invoices:int, receipts:int, writeoffs:int, bounced:int, errors:array<string>}
     */
    public static function sync(int $companyId, ?int $userId): array
    {
        $start = self::arStartedOn($companyId);
        if ($start === null) {
            throw new RuntimeException('AR posting is not switched on for this company.');
        }
        $pdo = DB::pdo();
        $out = ['invoices' => 0, 'receipts' => 0, 'writeoffs' => 0, 'bounced' => 0, 'errors' => []];

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            $invIds = $pdo->prepare("
                SELECT i.id FROM invoices i
                WHERE i.company_id = :c AND i.issue_date >= :s
                  AND i.status IN ('sent','overdue','paid','written_off')
                ORDER BY i.issue_date, i.id
            ");
            $invIds->execute(['c' => $companyId, 's' => $start]);
            foreach ($invIds->fetchAll(PDO::FETCH_COLUMN) as $id) {
                try {
                    if (self::postInvoice($companyId, (int)$id)) {
                        $out['invoices']++;
                    }
                } catch (Throwable $e) {
                    $out['errors'][] = 'Invoice #' . $id . ': ' . $e->getMessage();
                }
            }

            $payIds = $pdo->prepare("
                SELECT id FROM ar_payments
                WHERE company_id = :c AND received_on >= :s AND clearance_status <> 'bounced'
                ORDER BY received_on, id
            ");
            $payIds->execute(['c' => $companyId, 's' => $start]);
            foreach ($payIds->fetchAll(PDO::FETCH_COLUMN) as $id) {
                try {
                    if (self::postReceipt($companyId, (int)$id)) {
                        $out['receipts']++;
                    }
                } catch (Throwable $e) {
                    $out['errors'][] = 'Receipt #' . $id . ': ' . $e->getMessage();
                }
            }

            $woIds = $pdo->prepare("SELECT id FROM ar_writeoffs WHERE company_id = :c AND status = 'approved' ORDER BY id");
            $woIds->execute(['c' => $companyId]);
            foreach ($woIds->fetchAll(PDO::FETCH_COLUMN) as $id) {
                try {
                    if (self::postWriteoff($companyId, (int)$id)) {
                        $out['writeoffs']++;
                    }
                } catch (Throwable $e) {
                    $out['errors'][] = 'Write-off #' . $id . ': ' . $e->getMessage();
                }
            }

            $bounced = $pdo->prepare("SELECT id FROM ar_payments WHERE company_id = :c AND method = 'cheque' AND clearance_status = 'bounced'");
            $bounced->execute(['c' => $companyId]);
            foreach ($bounced->fetchAll(PDO::FETCH_COLUMN) as $id) {
                try {
                    if (self::reverseForBouncedCheque($companyId, (int)$id)) {
                        $out['bounced']++;
                    }
                } catch (Throwable $e) {
                    $out['errors'][] = 'Bounced cheque #' . $id . ': ' . $e->getMessage();
                }
            }

            $done = $out['invoices'] + $out['receipts'] + $out['writeoffs'] + $out['bounced'];
            if ($done > 0) {
                Audit::log([
                    'actor_user_id' => $userId,
                    'company_id'    => $companyId,
                    'event_type'    => 'accounting.ar.synced',
                    'summary'       => "Posted {$out['invoices']} invoice(s), {$out['receipts']} receipt(s), "
                        . "{$out['writeoffs']} write-off(s) to the ledger",
                    'metadata'      => $out,
                ]);
            }

            if ($ownTxn) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $out['errors'] = array_slice($out['errors'], 0, 20);
        return $out;
    }
}
