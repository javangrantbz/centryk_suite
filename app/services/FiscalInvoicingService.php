<?php
require_once __DIR__ . '/../core/DB.php';

/**
 * Belize BTS Electronic Invoicing — foundation layer.
 *
 * Owns the canonical fiscal-document model (company_fiscal_profiles,
 * fiscal_documents + lines/taxes/events) so any Centryk app can hand it a
 * normalized invoice and get back a structured tax document, independent of
 * BTS's actual wire format.
 *
 * What this class does NOT do yet, because BTS's Orientation Manual, XSD
 * schemas and sample XMLs haven't arrived (see
 * gitignore/bts integration information.txt): map a document to UBL 2.1 XML,
 * apply an XAdES signature, or submit anything to BTS. issue() takes a
 * document to status 'built' and stops there — that's the honest state of
 * "we have a well-formed internal fiscal document, ready to be mapped and
 * signed once we know the target schema." Nothing here should be read as
 * BTS-compliant until a builder/signer/transmitter are added on top.
 */
class FiscalInvoicingService
{
    public const DOCUMENT_TYPES = ['invoice', 'credit_note', 'debit_note', 'cancellation'];
    public const TAX_CATEGORIES = ['standard', 'zero_rated', 'exempt'];
    public const STANDARD_TAX_RATE = 12.50; // Belize GST

    // ── Company fiscal profile ──────────────────────────────────────────────

    /**
     * A company's fiscal profile. The first time this is called for a
     * company, the row is created and seeded from whatever's already on
     * file elsewhere in Centryk (invoice_settings' letterhead TIN/name/
     * address, falling back to the company's own tax_number/name) - so the
     * fiscal profile shows up already filled in rather than asking someone
     * to retype a TIN Centryk already has.
     */
    public static function getProfile(int $companyId): array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM company_fiscal_profiles WHERE company_id = :c LIMIT 1');
        $stmt->execute(['c' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        return self::seedProfile($companyId);
    }

    private static function seedProfile(int $companyId): array
    {
        $pdo = DB::pdo();

        $settingsStmt = $pdo->prepare('SELECT business_name, business_tax_number, business_address FROM invoice_settings WHERE company_id = :c LIMIT 1');
        $settingsStmt->execute(['c' => $companyId]);
        $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $companyStmt = $pdo->prepare('SELECT name, tax_number FROM companies WHERE id = :c LIMIT 1');
        $companyStmt->execute(['c' => $companyId]);
        $company = $companyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // invoice_settings is the more specific, invoice-facing record (it's
        // what actually prints on letterhead), so it wins when both exist.
        $tin = self::nullableString($settings['business_tax_number'] ?? null, 40)
            ?? self::nullableString($company['tax_number'] ?? null, 40);
        $legalName = self::nullableString($settings['business_name'] ?? null, 180)
            ?? self::nullableString($company['name'] ?? null, 180);
        $address = self::nullableString($settings['business_address'] ?? null, 2000);

        $pdo->prepare('
            INSERT INTO company_fiscal_profiles (company_id, legal_name, tin, address)
            VALUES (:company_id, :legal_name, :tin, :address)
        ')->execute([
            'company_id' => $companyId,
            'legal_name' => $legalName,
            'tin'        => $tin,
            'address'    => $address,
        ]);

        $stmt = $pdo->prepare('SELECT * FROM company_fiscal_profiles WHERE company_id = :c LIMIT 1');
        $stmt->execute(['c' => $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Update a company's fiscal profile (the BTS registration fields, plus
     * environment/enabled/status). getProfile() guarantees the row already
     * exists (seeded on first read), so this is always an update; $data
     * keys are the columns below, anything omitted keeps its current value.
     */
    public static function saveProfile(int $companyId, array $data): array
    {
        $existing = self::getProfile($companyId);

        $environment = (string)($data['environment'] ?? ($existing['environment'] ?? 'test'));
        if (!in_array($environment, ['test', 'production'], true)) {
            $environment = 'test';
        }
        $status = (string)($data['status'] ?? ($existing['status'] ?? 'not_started'));
        if (!in_array($status, ['not_started', 'info_sent', 'sandbox_access', 'live', 'suspended'], true)) {
            $status = 'not_started';
        }

        $fields = [
            'legal_name'             => self::nullableString($data['legal_name'] ?? $existing['legal_name'] ?? null, 180),
            'tin'                    => self::nullableString($data['tin'] ?? $existing['tin'] ?? null, 40),
            'address'                => self::nullableString($data['address'] ?? $existing['address'] ?? null, 2000),
            'economic_activity_code' => self::nullableString($data['economic_activity_code'] ?? $existing['economic_activity_code'] ?? null, 40),
            'establishment_code'     => self::nullableString($data['establishment_code'] ?? $existing['establishment_code'] ?? null, 40),
            'contact_name'           => self::nullableString($data['contact_name'] ?? $existing['contact_name'] ?? null, 150),
            'contact_position'       => self::nullableString($data['contact_position'] ?? $existing['contact_position'] ?? null, 100),
            'contact_email'          => self::nullableString($data['contact_email'] ?? $existing['contact_email'] ?? null, 150),
            'contact_phone'          => self::nullableString($data['contact_phone'] ?? $existing['contact_phone'] ?? null, 50),
            'tech_contact_name'      => self::nullableString($data['tech_contact_name'] ?? $existing['tech_contact_name'] ?? null, 150),
            'tech_contact_email'     => self::nullableString($data['tech_contact_email'] ?? $existing['tech_contact_email'] ?? null, 150),
            'environment'            => $environment,
            'status'                 => $status,
            'enabled'                => !empty($data['enabled']) ? 1 : 0,
            'effective_date'         => self::nullableDate($data['effective_date'] ?? $existing['effective_date'] ?? null),
            'notes'                  => self::nullableString($data['notes'] ?? $existing['notes'] ?? null, 4000),
        ];

        $sql = 'UPDATE company_fiscal_profiles SET ' . implode(', ', array_map(
            static fn($k) => "$k = :$k",
            array_keys($fields)
        )) . ' WHERE company_id = :company_id';
        $fields['company_id'] = $companyId;
        DB::pdo()->prepare($sql)->execute($fields);

        return self::getProfile($companyId);
    }

    // ── Fiscal documents ─────────────────────────────────────────────────────

    /**
     * Build a canonical fiscal document from a normalized array and leave it
     * at status 'built'. Shape of $doc:
     *   document_type: one of DOCUMENT_TYPES (default 'invoice')
     *   source_app, source_ref: where this came from (optional)
     *   reference_document_id: original doc for a credit/debit note or cancellation
     *   our_number: your own invoice/document number
     *   seller: ['name'=>, 'tin'=>, 'address'=>]
     *   buyer:  ['name'=>, 'tin'=>, 'address'=>]
     *   lines: list of ['description','quantity','unit_price','tax_category','tax_rate','item_code','unit_of_measure']
     * Line totals and the document's tax subtotals (by category) are computed
     * here, not trusted from the caller.
     */
    public static function issue(int $companyId, array $doc, ?int $userId = null): array
    {
        $type = (string)($doc['document_type'] ?? 'invoice');
        if (!in_array($type, self::DOCUMENT_TYPES, true)) {
            throw new InvalidArgumentException('Unknown document type.');
        }
        if (in_array($type, ['credit_note', 'debit_note', 'cancellation'], true) && empty($doc['reference_document_id'])) {
            throw new InvalidArgumentException('A ' . $type . ' needs the id of the document it refers to.');
        }

        $lines = is_array($doc['lines'] ?? null) ? $doc['lines'] : [];
        if (!$lines) {
            throw new InvalidArgumentException('A fiscal document needs at least one line.');
        }

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $subtotal = 0.0;
            $taxTotal = 0.0;
            $byCategory = []; // "category|rate" => ['category','rate','taxable','tax']
            $cleanLines = [];

            $lineNo = 0;
            foreach ($lines as $line) {
                $lineNo++;
                $qty = round((float)($line['quantity'] ?? 1), 4);
                $unitPrice = round((float)($line['unit_price'] ?? 0), 4);
                $lineSubtotal = round($qty * $unitPrice, 2);

                $category = (string)($line['tax_category'] ?? 'standard');
                if (!in_array($category, self::TAX_CATEGORIES, true)) {
                    $category = 'standard';
                }
                $rate = $category === 'standard'
                    ? round((float)($line['tax_rate'] ?? self::STANDARD_TAX_RATE), 2)
                    : 0.0;
                $lineTax = $category === 'standard' ? round($lineSubtotal * $rate / 100, 2) : 0.0;
                $lineTotal = round($lineSubtotal + $lineTax, 2);

                $subtotal += $lineSubtotal;
                $taxTotal += $lineTax;

                $key = $category . '|' . $rate;
                if (!isset($byCategory[$key])) {
                    $byCategory[$key] = ['category' => $category, 'rate' => $rate, 'taxable' => 0.0, 'tax' => 0.0];
                }
                $byCategory[$key]['taxable'] += $lineSubtotal;
                $byCategory[$key]['tax'] += $lineTax;

                $cleanLines[] = [
                    'line_number'     => $lineNo,
                    'item_code'       => self::nullableString($line['item_code'] ?? null, 80),
                    'description'     => trim((string)($line['description'] ?? '')) ?: 'Item',
                    'quantity'        => $qty,
                    'unit_of_measure' => self::nullableString($line['unit_of_measure'] ?? null, 20) ?? 'unit',
                    'unit_price'      => $unitPrice,
                    'line_subtotal'   => $lineSubtotal,
                    'tax_category'    => $category,
                    'tax_rate'        => $rate,
                    'tax_amount'      => $lineTax,
                    'line_total'      => $lineTotal,
                ];
            }

            $subtotal = round($subtotal, 2);
            $taxTotal = round($taxTotal, 2);
            $total = round($subtotal + $taxTotal, 2);

            $uuid = self::uuid4();

            $stmt = $pdo->prepare('
                INSERT INTO fiscal_documents
                    (company_id, document_uuid, document_type, status, source_app, source_ref,
                     reference_document_id, our_number, seller_snapshot_json, buyer_snapshot_json,
                     subtotal, tax_total, total, created_by)
                VALUES
                    (:company_id, :uuid, :type, \'built\', :source_app, :source_ref,
                     :reference_document_id, :our_number, :seller_json, :buyer_json,
                     :subtotal, :tax_total, :total, :created_by)
            ');
            $stmt->execute([
                'company_id'             => $companyId,
                'uuid'                   => $uuid,
                'type'                   => $type,
                'source_app'             => self::nullableString($doc['source_app'] ?? null, 20),
                'source_ref'             => self::nullableString($doc['source_ref'] ?? null, 64),
                'reference_document_id'  => !empty($doc['reference_document_id']) ? (int)$doc['reference_document_id'] : null,
                'our_number'             => self::nullableString($doc['our_number'] ?? null, 50),
                'seller_json'            => json_encode($doc['seller'] ?? [], JSON_UNESCAPED_UNICODE),
                'buyer_json'             => json_encode($doc['buyer'] ?? [], JSON_UNESCAPED_UNICODE),
                'subtotal'               => $subtotal,
                'tax_total'              => $taxTotal,
                'total'                  => $total,
                'created_by'             => $userId,
            ]);
            $documentId = (int)$pdo->lastInsertId();

            $lineStmt = $pdo->prepare('
                INSERT INTO fiscal_document_lines
                    (fiscal_document_id, line_number, item_code, description, quantity, unit_of_measure,
                     unit_price, line_subtotal, tax_category, tax_rate, tax_amount, line_total)
                VALUES
                    (:doc_id, :line_number, :item_code, :description, :quantity, :unit_of_measure,
                     :unit_price, :line_subtotal, :tax_category, :tax_rate, :tax_amount, :line_total)
            ');
            foreach ($cleanLines as $l) {
                $lineStmt->execute(array_merge(['doc_id' => $documentId], $l));
            }

            $taxStmt = $pdo->prepare('
                INSERT INTO fiscal_document_taxes
                    (fiscal_document_id, tax_category, tax_rate, taxable_amount, tax_amount)
                VALUES
                    (:doc_id, :tax_category, :tax_rate, :taxable_amount, :tax_amount)
            ');
            foreach ($byCategory as $t) {
                $taxStmt->execute([
                    'doc_id'         => $documentId,
                    'tax_category'   => $t['category'],
                    'tax_rate'       => $t['rate'],
                    'taxable_amount' => round($t['taxable'], 2),
                    'tax_amount'     => round($t['tax'], 2),
                ]);
            }

            self::logEvent($pdo, $documentId, 'built', 'Fiscal document built from ' .
                (self::nullableString($doc['source_app'] ?? null, 20) ?? 'manual entry') . '.', $userId);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::getDocument($companyId, $documentId);
    }

    /**
     * Build a fiscal document straight from an existing centryk_core invoice
     * (invoices + invoice_items + customers + invoice_settings) - proves the
     * model end to end against real data without needing BTS's schema.
     *
     * Known gap: invoices.tax is a single lump sum and invoice_items has no
     * per-line tax split, so every line here is treated as one 'standard'
     * category and the invoice's recorded tax is applied as one document-level
     * subtotal rather than true per-line tax. Good enough to prove the
     * pipeline; the real UBL mapping will want invoice-maker to carry
     * per-line tax once that's built.
     */
    public static function fromInvoice(int $companyId, int $invoiceId, ?int $userId = null): array
    {
        $pdo = DB::pdo();

        $inv = $pdo->prepare('SELECT * FROM invoices WHERE id = :id AND company_id = :c LIMIT 1');
        $inv->execute(['id' => $invoiceId, 'c' => $companyId]);
        $invoice = $inv->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new InvalidArgumentException('Invoice not found.');
        }
        if (in_array($invoice['status'], ['draft', 'cancelled'], true)) {
            throw new InvalidArgumentException('Only an issued invoice (not draft/cancelled) can become a fiscal document.');
        }

        $already = $pdo->prepare("SELECT id FROM fiscal_documents WHERE source_app = 'invoice-maker' AND source_ref = :ref AND company_id = :c AND status <> 'cancelled' LIMIT 1");
        $already->execute(['ref' => (string)$invoiceId, 'c' => $companyId]);
        if ($already->fetchColumn()) {
            throw new InvalidArgumentException('This invoice already has a fiscal document.');
        }

        $itemsStmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY id ASC');
        $itemsStmt->execute(['id' => $invoiceId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            throw new InvalidArgumentException('This invoice has no line items.');
        }

        $custStmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND company_id = :c LIMIT 1');
        $custStmt->execute(['id' => $invoice['customer_id'], 'c' => $companyId]);
        $customer = $custStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $settingsStmt = $pdo->prepare('SELECT * FROM invoice_settings WHERE company_id = :c LIMIT 1');
        $settingsStmt->execute(['c' => $companyId]);
        $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $companyStmt = $pdo->prepare('SELECT name FROM companies WHERE id = :c LIMIT 1');
        $companyStmt->execute(['c' => $companyId]);
        $companyName = (string)($companyStmt->fetchColumn() ?: '');

        // Distribute the invoice's single recorded tax amount across lines,
        // proportional to each line's share of the subtotal, so line totals
        // still add up to the invoice total. Documented limitation above.
        $itemsSubtotal = array_sum(array_map(static fn($i) => (float)$i['total'], $items));
        $invoiceTax = (float)($invoice['tax'] ?? 0);
        $impliedRate = $itemsSubtotal > 0 ? round($invoiceTax / $itemsSubtotal * 100, 2) : 0.0;

        $lines = [];
        foreach ($items as $item) {
            $qty = (float)$item['quantity'] ?: 1.0;
            $unitPrice = (float)$item['unit_price'];
            $lines[] = [
                'description'     => (string)$item['description'],
                'quantity'        => $qty,
                'unit_price'      => $unitPrice,
                'unit_of_measure' => 'unit',
                'tax_category'    => $impliedRate > 0 ? 'standard' : 'zero_rated',
                'tax_rate'        => $impliedRate > 0 ? $impliedRate : 0.0,
            ];
        }

        return self::issue($companyId, [
            'document_type' => 'invoice',
            'source_app'    => 'invoice-maker',
            'source_ref'    => (string)$invoiceId,
            'our_number'    => $invoice['invoice_number'],
            'seller' => [
                'name'    => $settings['business_name'] ?? $companyName,
                'tin'     => $settings['business_tax_number'] ?? null,
                'address' => $settings['business_address'] ?? null,
            ],
            'buyer' => [
                'name'    => $customer['name'] ?? null,
                'tin'     => $customer['tax_number'] ?? null,
                'address' => $customer['address'] ?? null,
            ],
            'lines' => $lines,
        ], $userId);
    }

    public static function listDocuments(int $companyId, array $filters = []): array
    {
        $sql = 'SELECT * FROM fiscal_documents WHERE company_id = :c';
        $params = ['c' => $companyId];
        if (!empty($filters['status']) && in_array($filters['status'], ['draft', 'built', 'signed', 'submitted', 'authorized', 'rejected', 'cancelled', 'error'], true)) {
            $sql .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 200';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getDocument(int $companyId, int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM fiscal_documents WHERE id = :id AND company_id = :c LIMIT 1');
        $stmt->execute(['id' => $id, 'c' => $companyId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            return null;
        }

        $lines = DB::pdo()->prepare('SELECT * FROM fiscal_document_lines WHERE fiscal_document_id = :id ORDER BY line_number ASC');
        $lines->execute(['id' => $id]);
        $doc['lines'] = $lines->fetchAll(PDO::FETCH_ASSOC);

        $taxes = DB::pdo()->prepare('SELECT * FROM fiscal_document_taxes WHERE fiscal_document_id = :id ORDER BY tax_category ASC');
        $taxes->execute(['id' => $id]);
        $doc['taxes'] = $taxes->fetchAll(PDO::FETCH_ASSOC);

        $events = DB::pdo()->prepare('SELECT * FROM fiscal_document_events WHERE fiscal_document_id = :id ORDER BY created_at ASC');
        $events->execute(['id' => $id]);
        $doc['events'] = $events->fetchAll(PDO::FETCH_ASSOC);

        return $doc;
    }

    /** Void a document that was never submitted to BTS - nothing to tell them yet. */
    public static function cancel(int $companyId, int $id, ?int $userId, string $reason = ''): array
    {
        $doc = self::getDocument($companyId, $id);
        if (!$doc) {
            throw new InvalidArgumentException('Document not found.');
        }
        if (!in_array($doc['status'], ['draft', 'built', 'error'], true)) {
            throw new InvalidArgumentException('Only a document that was never submitted can be cancelled here.');
        }

        $pdo = DB::pdo();
        $pdo->prepare("UPDATE fiscal_documents SET status = 'cancelled' WHERE id = :id AND company_id = :c")
            ->execute(['id' => $id, 'c' => $companyId]);
        self::logEvent($pdo, $id, 'cancelled', $reason !== '' ? $reason : null, $userId);

        return self::getDocument($companyId, $id);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function logEvent(PDO $pdo, int $documentId, string $eventType, ?string $detail, ?int $userId): void
    {
        $pdo->prepare('
            INSERT INTO fiscal_document_events (fiscal_document_id, event_type, detail, created_by)
            VALUES (:doc_id, :event_type, :detail, :created_by)
        ')->execute([
            'doc_id'     => $documentId,
            'event_type' => substr($eventType, 0, 40),
            'detail'     => $detail,
            'created_by' => $userId,
        ]);
    }

    private static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function nullableString($value, int $max): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $max);
    }

    private static function nullableDate($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : null;
    }
}
