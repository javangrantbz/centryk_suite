<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';
require_once __DIR__ . '/ReceivablesService.php';

/**
 * Reconciliation (Centryk Business) — import bank statement lines and match the
 * deposits to open invoices. Company-scoped; callers must have checked
 * membership and the 'reconciliation' entitlement.
 *
 * Matching a credit line to an invoice posts a receipt through
 * ReceivablesService (so it lands on the customer account and ages correctly);
 * un-matching voids that receipt.
 */
class ReconciliationService
{
    /** SQL expression for an invoice's effective due date (mirrors ReceivablesService). */
    private const DUE_EXPR =
        "COALESCE(i.due_date, DATE_ADD(i.issue_date, INTERVAL COALESCE(c.payment_terms_days, 0) DAY))";

    /** Header aliases for CSV auto-mapping (lower-cased, trimmed). */
    private const ALIASES = [
        'date'        => ['date', 'transaction date', 'txn date', 'value date', 'posting date', 'posted', 'date posted'],
        'description' => ['description', 'details', 'narrative', 'memo', 'payee', 'particulars', 'transaction details'],
        'amount'      => ['amount', 'value'],
        'credit'      => ['credit', 'money in', 'deposit', 'deposits', 'cr', 'paid in'],
        'debit'       => ['debit', 'money out', 'withdrawal', 'withdrawals', 'dr', 'paid out'],
        'reference'   => ['reference', 'ref', 'cheque', 'cheque no', 'transaction id'],
    ];

    public static function summary(int $companyId): array
    {
        $pdo = DB::pdo();
        $row = $pdo->prepare("
            SELECT
                SUM(status = 'unmatched' AND direction = 'credit')                    AS unmatched_credits,
                SUM(CASE WHEN status = 'unmatched' AND direction = 'credit' THEN amount ELSE 0 END) AS unmatched_value,
                SUM(status = 'matched')                                               AS matched_count,
                SUM(CASE WHEN status = 'matched' THEN ABS(amount) ELSE 0 END)         AS matched_value,
                SUM(status = 'ignored')                                               AS ignored_count,
                COUNT(*)                                                              AS total
            FROM bank_transactions WHERE company_id = :cid
        ");
        $row->execute(['cid' => $companyId]);
        $r = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'unmatched_credits' => (int)($r['unmatched_credits'] ?? 0),
            'unmatched_value'   => round((float)($r['unmatched_value'] ?? 0), 2),
            'matched_count'     => (int)($r['matched_count'] ?? 0),
            'matched_value'     => round((float)($r['matched_value'] ?? 0), 2),
            'ignored_count'     => (int)($r['ignored_count'] ?? 0),
            'total'             => (int)($r['total'] ?? 0),
        ];
    }

    public static function imports(int $companyId): array
    {
        $stmt = DB::pdo()->prepare("
            SELECT i.id, i.filename, i.row_count, i.skipped, i.imported_at,
                   TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS imported_by_name
            FROM bank_statement_imports i
            LEFT JOIN users u ON u.id = i.imported_by
            WHERE i.company_id = :cid
            ORDER BY i.id DESC LIMIT 20
        ");
        $stmt->execute(['cid' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function transactions(int $companyId, array $filters = []): array
    {
        $where = ['company_id = :cid'];
        $args  = ['cid' => $companyId];

        $status = $filters['status'] ?? 'unmatched';
        if (in_array($status, ['unmatched', 'matched', 'ignored'], true)) {
            $where[] = 'status = :status';
            $args['status'] = $status;
        }
        if (($filters['direction'] ?? '') === 'credit' || ($filters['direction'] ?? '') === 'debit') {
            $where[] = 'direction = :dir';
            $args['dir'] = $filters['direction'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(description LIKE :q OR reference LIKE :q)';
            $args['q'] = '%' . $filters['q'] . '%';
        }

        $stmt = DB::pdo()->prepare("
            SELECT id, txn_date, description, reference, amount, direction, status,
                   match_type, match_id, ar_payment_id, note, matched_at
            FROM bank_transactions
            WHERE " . implode(' AND ', $where) . "
            ORDER BY txn_date DESC, id DESC
            LIMIT 300
        ");
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Import a bank statement. Auto-detects CSV, OFX/QFX or MT940. For CSV,
     * $mapping may pin columns by header name; anything missing is auto-detected.
     *
     * @return array{import_id:int, imported:int, skipped:int, format:string, errors:array<string>}
     */
    public static function import(int $companyId, string $content, array $mapping, ?int $actorId, string $filename = ''): array
    {
        $content = trim(str_replace(["\r\n", "\r"], "\n", $content));
        if ($content === '') {
            throw new InvalidArgumentException('The file is empty.');
        }

        $format = self::detectFormat($content);
        [$rows, $errors] = match ($format) {
            'ofx'   => self::parseOfxRows($content),
            'mt940' => self::parseMt940Rows($content),
            default => self::parseCsvRows($content, $mapping),
        };

        $pdo = DB::pdo();
        $imported = 0;
        $skipped = 0;

        try {
            $pdo->beginTransaction();

            $pdo->prepare("INSERT INTO bank_statement_imports (company_id, filename, imported_by) VALUES (:cid, :fn, :by)")
                ->execute(['cid' => $companyId, 'fn' => mb_substr($filename ?: strtoupper($format) . ' import', 0, 190), 'by' => $actorId]);
            $importId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("
                INSERT IGNORE INTO bank_transactions
                    (company_id, import_id, txn_date, description, reference, amount, direction, dedupe_hash)
                VALUES (:cid, :imp, :d, :desc, :ref, :amt, :dir, :hash)
            ");

            foreach ($rows as $row) {
                $amount = round((float)$row['amount'], 2);
                if ($amount === 0.0) { $skipped++; continue; }
                $desc = (string)$row['description'];
                $ref  = (string)($row['reference'] ?? '');
                $direction = $amount >= 0 ? 'credit' : 'debit';
                $hash = sha1($companyId . '|' . $row['date'] . '|' . number_format($amount, 2, '.', '')
                    . '|' . mb_strtolower($desc) . '|' . mb_strtolower($ref));

                $ins->execute([
                    'cid' => $companyId, 'imp' => $importId, 'd' => $row['date'],
                    'desc' => mb_substr($desc, 0, 255), 'ref' => mb_substr($ref, 0, 190),
                    'amt' => $amount, 'dir' => $direction, 'hash' => $hash,
                ]);
                $ins->rowCount() > 0 ? $imported++ : $skipped++;
            }

            $pdo->prepare("UPDATE bank_statement_imports SET row_count = :rc, skipped = :sk WHERE id = :id")
                ->execute(['rc' => $imported, 'sk' => $skipped, 'id' => $importId]);

            Audit::log([
                'actor_user_id' => $actorId,
                'company_id'    => $companyId,
                'event_type'    => 'reconciliation.import',
                'summary'       => 'Imported ' . $imported . ' bank line(s) (' . strtoupper($format) . ')' . ($skipped ? ", skipped {$skipped}" : ''),
                'metadata'      => ['import_id' => $importId, 'format' => $format, 'imported' => $imported, 'skipped' => $skipped],
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'import_id' => $importId, 'imported' => $imported, 'skipped' => $skipped,
            'format' => $format, 'errors' => array_slice($errors, 0, 20),
        ];
    }

    // ── statement format parsers ───────────────────────────────────────────

    private static function detectFormat(string $content): string
    {
        $head = mb_strtoupper(mb_substr(ltrim($content), 0, 400));
        if (str_contains($head, '<OFX>') || str_contains($head, 'OFXHEADER') || str_contains($head, '<STMTTRN>')) {
            return 'ofx';
        }
        if (preg_match('/^:2[05]:/m', $content) || preg_match('/^:61:/m', $content)) {
            return 'mt940';
        }
        return 'csv';
    }

    /** @return array{0:array<array>,1:array<string>} */
    private static function parseCsvRows(string $csv, array $mapping): array
    {
        $lines = array_values(array_filter(explode("\n", $csv), static fn($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw new InvalidArgumentException('Need a header row and at least one transaction.');
        }
        $header = array_map(static fn($h) => strtolower(trim($h, " \t\"'")), str_getcsv(array_shift($lines)));
        $col = self::resolveColumns($header, $mapping);
        if ($col['date'] === null || $col['description'] === null || ($col['amount'] === null && $col['credit'] === null && $col['debit'] === null)) {
            throw new InvalidArgumentException('Could not find date, description and amount columns. Check the file or set the mapping.');
        }

        $rows = [];
        $errors = [];
        foreach ($lines as $n => $line) {
            $cells = str_getcsv($line);
            $date = self::parseDate(trim((string)($cells[$col['date']] ?? '')));
            if ($date === null) {
                $errors[] = 'Row ' . ($n + 2) . ': unrecognised date';
                continue;
            }
            if ($col['amount'] !== null) {
                $amount = self::parseAmount((string)($cells[$col['amount']] ?? ''));
            } else {
                $cr = $col['credit'] !== null ? self::parseAmount((string)($cells[$col['credit']] ?? '')) : 0.0;
                $dr = $col['debit']  !== null ? self::parseAmount((string)($cells[$col['debit']] ?? '')) : 0.0;
                $amount = round(abs($cr) - abs($dr), 2);
            }
            $rows[] = [
                'date'        => $date,
                'description' => trim((string)($cells[$col['description']] ?? '')),
                'reference'   => $col['reference'] !== null ? trim((string)($cells[$col['reference']] ?? '')) : '',
                'amount'      => $amount,
            ];
        }
        return [$rows, $errors];
    }

    /** @return array{0:array<array>,1:array<string>} */
    private static function parseOfxRows(string $ofx): array
    {
        if (!preg_match_all('#<STMTTRN>(.*?)</STMTTRN>#is', $ofx, $blocks)) {
            throw new InvalidArgumentException('No transactions found in the OFX file.');
        }
        $tag = static function (string $block, string $name): string {
            // OFX 1.x omits closing tags; value runs to the next '<' or newline.
            if (preg_match('#<' . $name . '>([^<\r\n]*)#i', $block, $m)) {
                return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
            }
            return '';
        };

        $rows = [];
        $errors = [];
        foreach ($blocks[1] as $i => $block) {
            $raw = $tag($block, 'DTPOSTED') ?: $tag($block, 'DTUSER');
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $raw, $dm)) {
                $errors[] = 'Transaction ' . ($i + 1) . ': bad date';
                continue;
            }
            $amt = self::parseAmount($tag($block, 'TRNAMT'));
            $name = $tag($block, 'NAME');
            $memo = $tag($block, 'MEMO');
            $desc = trim($name . ($memo && $memo !== $name ? ' — ' . $memo : ''));
            $rows[] = [
                'date'        => "$dm[1]-$dm[2]-$dm[3]",
                'description' => $desc !== '' ? $desc : ($tag($block, 'TRNTYPE') ?: 'Transaction'),
                'reference'   => $tag($block, 'CHECKNUM') ?: $tag($block, 'REFNUM') ?: $tag($block, 'FITID'),
                'amount'      => $amt,
            ];
        }
        return [$rows, $errors];
    }

    /** @return array{0:array<array>,1:array<string>} */
    private static function parseMt940Rows(string $mt): array
    {
        // Join continuation lines, then split on the :NN: field tags.
        $lines = explode("\n", $mt);
        $fields = [];
        $cur = null;
        foreach ($lines as $ln) {
            if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $ln, $m)) {
                if ($cur !== null) { $fields[] = $cur; }
                $cur = ['tag' => $m[1], 'val' => $m[2]];
            } elseif ($cur !== null) {
                $cur['val'] .= "\n" . $ln;
            }
        }
        if ($cur !== null) { $fields[] = $cur; }

        $rows = [];
        $errors = [];
        for ($i = 0, $n = count($fields); $i < $n; $i++) {
            if ($fields[$i]['tag'] !== '61') { continue; }
            $v = $fields[$i]['val'];
            // :61: YYMMDD [MMDD] (R?)(C|D) [funds] amount(N|F|S)type ...
            if (!preg_match('/^(\d{6})(\d{4})?(R?[CD])([A-Za-z])?([0-9,]+)/', $v, $m)) {
                $errors[] = 'Statement line ' . ($i + 1) . ': unparseable :61:';
                continue;
            }
            $yy = (int)substr($m[1], 0, 2);
            $date = sprintf('%04d-%s-%s', $yy + ($yy > 70 ? 1900 : 2000), substr($m[1], 2, 2), substr($m[1], 4, 2));
            $sign = str_contains($m[3], 'D') ? -1 : 1;
            if (str_starts_with($m[3], 'R')) { $sign *= -1; } // reversal
            $amount = round($sign * (float)str_replace(',', '.', rtrim($m[5], ',')), 2);

            // The following :86: field carries the description.
            $desc = '';
            if (isset($fields[$i + 1]) && $fields[$i + 1]['tag'] === '86') {
                $desc = trim(preg_replace('/\s*\n\s*/', ' ', $fields[$i + 1]['val']));
                $desc = preg_replace('/\?\d{2}/', ' ', $desc); // strip ?20 ?21 structured markers
                $desc = trim(preg_replace('/\s{2,}/', ' ', $desc));
            }
            $ref = '';
            if (preg_match('#//(\S+)#', $v, $rm)) { $ref = $rm[1]; }
            elseif (preg_match('/N[A-Z]{3}(\S+)/', $v, $rm)) { $ref = $rm[1]; }

            $rows[] = [
                'date'        => $date,
                'description' => $desc !== '' ? $desc : 'Bank transaction',
                'reference'   => $ref,
                'amount'      => $amount,
            ];
        }
        if (!$rows && !$errors) {
            throw new InvalidArgumentException('No :61: statement lines found in the MT940 file.');
        }
        return [$rows, $errors];
    }

    /**
     * A short, stable reference a customer can put on a bank transfer so the
     * deposit self-identifies. Reversible: refsIn() decodes it back.
     * e.g. invoice #742 -> "PAY-KE"
     */
    public static function paymentRef(int $invoiceId): string
    {
        return 'PAY-' . strtoupper(base_convert((string)$invoiceId, 10, 36));
    }

    /** Invoice ids referenced anywhere in a free-text string (0..n). */
    private static function refsIn(string $text): array
    {
        if (!preg_match_all('/PAY-([0-9A-Z]{1,8})/i', $text, $mm)) {
            return [];
        }
        $ids = [];
        foreach ($mm[1] as $code) {
            $id = (int)base_convert(strtoupper($code), 36, 10);
            if ($id > 0) { $ids[$id] = true; }
        }
        return array_keys($ids);
    }

    /** Open invoices with their payment reference, for finance to share with customers. */
    public static function paymentRefs(int $companyId): array
    {
        $stmt = DB::pdo()->prepare("
            SELECT i.id, i.invoice_number, (i.total - i.amount_paid) AS outstanding,
                   " . self::DUE_EXPR . " AS effective_due, c.name AS customer_name
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            WHERE i.company_id = :cid AND i.status IN ('sent','overdue') AND (i.total - i.amount_paid) > 0
            ORDER BY effective_due ASC, i.id ASC
            LIMIT 200
        ");
        $stmt->execute(['cid' => $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['outstanding'] = round((float)$r['outstanding'], 2);
            $r['payment_ref'] = self::paymentRef($r['id']);
        }
        return $rows;
    }

    public static function suggestions(int $companyId, int $txnId): array
    {
        $pdo = DB::pdo();
        $t = $pdo->prepare("SELECT * FROM bank_transactions WHERE id = :id AND company_id = :cid LIMIT 1");
        $t->execute(['id' => $txnId, 'cid' => $companyId]);
        $txn = $t->fetch(PDO::FETCH_ASSOC);
        if (!$txn) {
            return ['transaction' => null, 'invoices' => []];
        }

        $amount = round((float)$txn['amount'], 2);
        $haystack = mb_strtolower($txn['description'] . ' ' . $txn['reference']);
        $refIds = array_flip(self::refsIn($txn['description'] . ' ' . $txn['reference']));

        $inv = $pdo->prepare("
            SELECT i.id, i.invoice_number, i.total, i.amount_paid, (i.total - i.amount_paid) AS outstanding,
                   i.issue_date, i.due_date, c.id AS customer_id, c.name AS customer_name
            FROM invoices i
            JOIN customers c ON c.id = i.customer_id
            WHERE i.company_id = :cid AND i.status IN ('sent','overdue') AND (i.total - i.amount_paid) > 0
        ");
        $inv->execute(['cid' => $companyId]);

        $out = [];
        foreach ($inv->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $outstanding = round((float)$row['outstanding'], 2);
            $reasons = [];
            $score = 0;

            if (isset($refIds[(int)$row['id']])) { $score += 80; $reasons[] = 'payment reference matches'; }

            if (abs($outstanding - $amount) < 0.01) { $score += 60; $reasons[] = 'exact amount'; }
            elseif ($amount > 0 && abs($outstanding - $amount) / max($outstanding, $amount) <= 0.02) { $score += 30; $reasons[] = 'amount within 2%'; }
            elseif ($amount >= $outstanding - 0.01) { $score += 10; $reasons[] = 'covers the balance'; }

            $num = mb_strtolower(trim($row['invoice_number']));
            if ($num !== '' && str_contains($haystack, $num)) { $score += 40; $reasons[] = 'invoice number in the description'; }

            $name = mb_strtolower(trim($row['customer_name']));
            if ($name !== '' && str_contains($haystack, $name)) { $score += 25; $reasons[] = 'customer name in the description'; }
            else {
                $first = strtok($name, ' ');
                if ($first && mb_strlen($first) >= 4 && str_contains($haystack, $first)) { $score += 12; $reasons[] = 'customer name (partial)'; }
            }

            if ($score <= 0) {
                continue;
            }
            $out[] = [
                'invoice_id'     => (int)$row['id'],
                'invoice_number' => $row['invoice_number'],
                'payment_ref'    => self::paymentRef((int)$row['id']),
                'customer_id'    => (int)$row['customer_id'],
                'customer_name'  => $row['customer_name'],
                'outstanding'    => $outstanding,
                'issue_date'     => $row['issue_date'],
                'score'          => $score,
                'reasons'        => $reasons,
            ];
        }

        usort($out, static fn($a, $b) => $b['score'] <=> $a['score']);

        return ['transaction' => $txn, 'invoices' => array_slice($out, 0, 8)];
    }

    /**
     * Link a bank line to an invoice (posts a receipt) or to an existing receipt.
     * $type: 'invoice' | 'ar_payment'.
     */
    public static function match(int $companyId, int $txnId, string $type, int $targetId, ?int $actorId): array
    {
        $pdo = DB::pdo();
        $t = $pdo->prepare("SELECT * FROM bank_transactions WHERE id = :id AND company_id = :cid LIMIT 1");
        $t->execute(['id' => $txnId, 'cid' => $companyId]);
        $txn = $t->fetch(PDO::FETCH_ASSOC);
        if (!$txn) {
            throw new RuntimeException('Bank line not found.');
        }
        if ($txn['status'] === 'matched') {
            throw new RuntimeException('That line is already matched.');
        }
        if ($txn['direction'] !== 'credit') {
            throw new InvalidArgumentException('Only deposits can be matched to a customer invoice.');
        }

        $arPaymentId = null;

        if ($type === 'invoice') {
            $iv = $pdo->prepare("
                SELECT i.id, i.invoice_number, i.customer_id, c.name AS customer_name
                FROM invoices i JOIN customers c ON c.id = i.customer_id
                WHERE i.id = :id AND i.company_id = :cid LIMIT 1
            ");
            $iv->execute(['id' => $targetId, 'cid' => $companyId]);
            $invoice = $iv->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new RuntimeException('Invoice not found.');
            }

            $receipt = ReceivablesService::recordPayment($companyId, (int)$invoice['customer_id'], [
                'amount'            => round((float)$txn['amount'], 2),
                'method'            => 'bank_transfer',
                'received_on'       => $txn['txn_date'],
                'reference'         => $txn['reference'] !== '' ? $txn['reference'] : ('Bank ' . $txn['txn_date']),
                'notes'             => 'Matched from bank statement',
                'target_invoice_id' => (int)$invoice['id'],
            ], $actorId);
            $arPaymentId = $receipt['payment_id'];
            $summaryTail = 'invoice ' . $invoice['invoice_number'] . ' (' . $invoice['customer_name'] . ')';
        } elseif ($type === 'ar_payment') {
            $ap = $pdo->prepare("SELECT id FROM ar_payments WHERE id = :id AND company_id = :cid LIMIT 1");
            $ap->execute(['id' => $targetId, 'cid' => $companyId]);
            if (!$ap->fetch()) {
                throw new RuntimeException('Receipt not found.');
            }
            $arPaymentId = $targetId;
            $summaryTail = 'existing receipt #' . $targetId;
        } else {
            throw new InvalidArgumentException('Unknown match type.');
        }

        $pdo->prepare("
            UPDATE bank_transactions
            SET status = 'matched', match_type = :mt, match_id = :mid, ar_payment_id = :apid,
                matched_by = :by, matched_at = NOW()
            WHERE id = :id
        ")->execute([
            'mt' => $type, 'mid' => $targetId, 'apid' => $arPaymentId, 'by' => $actorId, 'id' => $txnId,
        ]);

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'reconciliation.matched',
            'summary'       => 'Matched bank line ' . $txn['txn_date'] . ' ' . number_format((float)$txn['amount'], 2) . ' to ' . $summaryTail,
            'metadata'      => ['txn_id' => $txnId, 'match_type' => $type, 'match_id' => $targetId, 'ar_payment_id' => $arPaymentId],
        ]);

        return ['status' => 'matched', 'ar_payment_id' => $arPaymentId];
    }

    /** Undo a match. Voids the receipt it created (if any). */
    public static function unmatch(int $companyId, int $txnId, ?int $actorId): void
    {
        $pdo = DB::pdo();
        $t = $pdo->prepare("SELECT * FROM bank_transactions WHERE id = :id AND company_id = :cid LIMIT 1");
        $t->execute(['id' => $txnId, 'cid' => $companyId]);
        $txn = $t->fetch(PDO::FETCH_ASSOC);
        if (!$txn) {
            throw new RuntimeException('Bank line not found.');
        }
        if ($txn['status'] !== 'matched') {
            throw new RuntimeException('That line is not matched.');
        }

        // Only void the receipt if this line created it (invoice match).
        if ($txn['match_type'] === 'invoice' && $txn['ar_payment_id']) {
            ReceivablesService::voidPayment($companyId, (int)$txn['ar_payment_id'], $actorId);
        }

        $pdo->prepare("
            UPDATE bank_transactions
            SET status = 'unmatched', match_type = NULL, match_id = NULL, ar_payment_id = NULL,
                matched_by = NULL, matched_at = NULL
            WHERE id = :id
        ")->execute(['id' => $txnId]);

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => 'reconciliation.unmatched',
            'summary'       => 'Unmatched bank line ' . $txn['txn_date'] . ' ' . number_format((float)$txn['amount'], 2),
            'metadata'      => ['txn_id' => $txnId],
        ]);
    }

    public static function setIgnored(int $companyId, int $txnId, bool $ignored, ?int $actorId): void
    {
        $pdo = DB::pdo();
        $t = $pdo->prepare("SELECT status FROM bank_transactions WHERE id = :id AND company_id = :cid LIMIT 1");
        $t->execute(['id' => $txnId, 'cid' => $companyId]);
        $row = $t->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Bank line not found.');
        }
        if ($row['status'] === 'matched') {
            throw new RuntimeException('Unmatch the line before ignoring it.');
        }

        $pdo->prepare("UPDATE bank_transactions SET status = :st WHERE id = :id")
            ->execute(['st' => $ignored ? 'ignored' : 'unmatched', 'id' => $txnId]);

        Audit::log([
            'actor_user_id' => $actorId,
            'company_id'    => $companyId,
            'event_type'    => $ignored ? 'reconciliation.ignored' : 'reconciliation.unignored',
            'summary'       => ($ignored ? 'Ignored' : 'Restored') . ' bank line #' . $txnId,
            'metadata'      => ['txn_id' => $txnId],
        ]);
    }

    // ── CSV helpers ─────────────────────────────────────────────────────────

    private static function resolveColumns(array $header, array $mapping): array
    {
        $index = static function (string $name) use ($header): ?int {
            $i = array_search(strtolower(trim($name)), $header, true);
            return $i === false ? null : (int)$i;
        };

        $col = ['date' => null, 'description' => null, 'amount' => null, 'credit' => null, 'debit' => null, 'reference' => null];
        foreach ($col as $key => $_) {
            if (!empty($mapping[$key])) {
                $col[$key] = $index((string)$mapping[$key]);
                continue;
            }
            foreach (self::ALIASES[$key] as $alias) {
                $hit = $index($alias);
                if ($hit !== null) { $col[$key] = $hit; break; }
            }
        }
        return $col;
    }

    private static function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // ISO first
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }
        // d/m/y or m/d/y with - . or /
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $raw, $m)) {
            $a = (int)$m[1]; $b = (int)$m[2]; $y = (int)$m[3];
            if ($y < 100) { $y += 2000; }
            // prefer d/m/y (Belize convention); swap to m/d/y if that doesn't validate
            [$d, $mo] = [$a, $b];
            if (!checkdate($mo, $d, $y)) {
                [$d, $mo] = [$b, $a];
            }
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        }
        $ts = strtotime($raw);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    private static function parseAmount(string $raw): float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0.0;
        }
        $neg = str_contains($raw, '(') || str_starts_with($raw, '-');
        $raw = preg_replace('/[^0-9.]/', '', str_replace(',', '', $raw));
        if ($raw === '' || $raw === '.') {
            return 0.0;
        }
        $val = round((float)$raw, 2);
        return $neg ? -$val : $val;
    }
}
