<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/FiscalEtdui.php';
require_once __DIR__ . '/FiscalUblBuilder.php';
require_once __DIR__ . '/FiscalXadesSigner.php';
require_once __DIR__ . '/FiscalBtsClient.php';

/**
 * Belize BTS Electronic Invoicing.
 *
 * Owns the canonical fiscal-document model (company_fiscal_profiles,
 * fiscal_documents + lines/taxes/events) so any Centryk app can hand it a
 * normalized invoice and get back a real, BTS-authorized tax document.
 *
 * issue()/fromInvoice() build a document to status 'built' - a well-formed
 * internal fiscal document, not yet mapped or signed. submitToBts() takes it
 * the rest of the way: assigns the ETDUI (serial number consumed only here,
 * not at issue() time - see add_fiscal_invoicing_submission.sql), maps it to
 * UBL via FiscalUblBuilder, signs it via FiscalXadesSigner, and posts it to
 * BTS via FiscalBtsClient - built strictly to BTS's Orientation Manual
 * v1.30 and cross-verified against their own sample documents (see
 * FiscalEtdui/FiscalUblBuilder/FiscalXadesSigner class docs for exactly
 * what was verified and how). UNTESTED against the live BTS test
 * environment: no company has a real BTS-issued certificate yet.
 */
class FiscalInvoicingService
{
    public const DOCUMENT_TYPES = ['invoice', 'tax_receipt', 'credit_note', 'debit_note', 'cancellation'];
    public const TAX_CATEGORIES = ['standard', 'zero_rated', 'exempt'];
    public const STANDARD_TAX_RATE = 12.50; // Belize GST

    /** document_type -> FiscalEtdui::TYPE_* (manual Table 10-2). */
    private const ETD_TYPE_MAP = [
        'invoice'      => FiscalEtdui::TYPE_INVOICE,
        'tax_receipt'  => FiscalEtdui::TYPE_TAX_RECEIPT,
        'debit_note'   => FiscalEtdui::TYPE_DEBIT_NOTE,
        'credit_note'  => FiscalEtdui::TYPE_CREDIT_NOTE,
        'cancellation' => FiscalEtdui::TYPE_APPLICATION_RESPONSE,
    ];

    /** document_type -> the label BTS's own DocumentType domain uses (manual 7.4 rule INV11-14). */
    private const TYPE_LABEL = [
        'invoice'     => 'Tax Invoice',
        'tax_receipt' => 'Tax Receipt',
        'debit_note'  => 'Debit Note',
        'credit_note' => 'Credit Note',
    ];

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
        $userId = $userId ?: null; // 0 / '' -> null, so created_by never breaks the FK
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
     * Per-line tax: invoice_items now carries tax_category / tax_rate
     * (add_invoice_line_tax.sql), so a mixed standard / zero-rated / exempt
     * invoice maps to the correct per-category TaxSubtotal. Invoices issued
     * before that migration fall back to blending invoices.tax into one
     * implied 'standard' rate.
     *
     * Document type: a POS sale (invoices.source_ref = 'sale:<id>') to a
     * customer with no TIN is a Tax Receipt (ETDType 02) - a final-consumer
     * sale at the till. A POS sale to a business that gave its TIN, and any
     * manually-raised invoice-maker invoice, stays a Tax Invoice (ETDType 01).
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

        // Per-line tax if invoice-maker recorded it (add_invoice_line_tax.sql);
        // otherwise fall back to the old behaviour - blend the invoice's
        // single recorded tax amount into one implied 'standard' rate across
        // every line. Once invoice-maker always carries per-line tax this
        // fallback only matters for invoices issued before that migration.
        $hasLineTax = $items && array_key_exists('tax_category', $items[0]);
        $itemsSubtotal = array_sum(array_map(static fn($i) => (float)$i['total'], $items));
        $invoiceTax = (float)($invoice['tax'] ?? 0);
        $impliedRate = $itemsSubtotal > 0 ? round($invoiceTax / $itemsSubtotal * 100, 2) : 0.0;

        $lines = [];
        foreach ($items as $item) {
            $qty = (float)$item['quantity'] ?: 1.0;
            $unitPrice = (float)$item['unit_price'];

            if ($hasLineTax) {
                $category = in_array($item['tax_category'], self::TAX_CATEGORIES, true) ? $item['tax_category'] : 'standard';
                $rate = $category === 'standard' ? (float)$item['tax_rate'] : 0.0;
            } else {
                $category = $impliedRate > 0 ? 'standard' : 'zero_rated';
                $rate = $impliedRate > 0 ? $impliedRate : 0.0;
            }

            $lines[] = [
                'description'     => (string)$item['description'],
                'quantity'        => $qty,
                'unit_price'      => $unitPrice,
                'unit_of_measure' => 'unit',
                'tax_category'    => $category,
                'tax_rate'        => $rate,
            ];
        }

        $buyerTin  = trim((string)($customer['tax_number'] ?? ''));
        $isPosSale = str_starts_with((string)($invoice['source_ref'] ?? ''), 'sale:');
        $documentType = ($isPosSale && $buyerTin === '') ? 'tax_receipt' : 'invoice';

        return self::issue($companyId, [
            'document_type' => $documentType,
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

    /**
     * Platform-wide view for the Centryk admin monitoring page (not scoped
     * to one company) - every company that has touched e-invoicing at all,
     * with its profile status and a quick document tally.
     */
    public static function platformProfiles(): array
    {
        $stmt = DB::pdo()->query('
            SELECT p.*, c.name AS company_name,
                   COALESCE(d.total, 0) AS document_count,
                   COALESCE(d.authorized, 0) AS authorized_count,
                   COALESCE(d.errored, 0) AS errored_count
            FROM company_fiscal_profiles p
            JOIN companies c ON c.id = p.company_id
            LEFT JOIN (
                SELECT company_id, COUNT(*) AS total,
                       SUM(status = \'authorized\') AS authorized,
                       SUM(status IN (\'rejected\', \'error\')) AS errored
                FROM fiscal_documents
                GROUP BY company_id
            ) d ON d.company_id = p.company_id
            ORDER BY p.enabled DESC, c.name ASC
        ');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['has_certificate'] = !empty($row['certificate_path']);
            unset($row['certificate_path']);
        }
        unset($row);
        return $rows;
    }

    /** Every fiscal document across every company, newest first - the admin document log. */
    public static function platformDocuments(array $filters = []): array
    {
        $sql = '
            SELECT f.*, c.name AS company_name
            FROM fiscal_documents f
            JOIN companies c ON c.id = f.company_id
            WHERE 1=1
        ';
        $params = [];
        if (!empty($filters['status']) && in_array($filters['status'], ['draft', 'built', 'signed', 'submitted', 'authorized', 'rejected', 'cancelled', 'error'], true)) {
            $sql .= ' AND f.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['company_id'])) {
            $sql .= ' AND f.company_id = :company_id';
            $params['company_id'] = (int)$filters['company_id'];
        }
        $sql .= ' ORDER BY f.created_at DESC LIMIT 300';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getDocument(int $companyId, int $id): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM fiscal_documents WHERE id = :id AND company_id = :c LIMIT 1');
        $stmt->execute(['id' => $id, 'c' => $companyId]);
        return self::hydrateDocument($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /** Same as getDocument(), without the company scope - platform-admin use only. */
    public static function adminGetDocument(int $id): ?array
    {
        $stmt = DB::pdo()->prepare('
            SELECT f.*, c.name AS company_name FROM fiscal_documents f
            JOIN companies c ON c.id = f.company_id
            WHERE f.id = :id LIMIT 1
        ');
        $stmt->execute(['id' => $id]);
        return self::hydrateDocument($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed>|false $doc */
    private static function hydrateDocument($doc): ?array
    {
        if (!$doc) {
            return null;
        }
        $id = (int)$doc['id'];

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

    /**
     * Create the fiscal document for cancelling an already-authorized ETD -
     * an event (manual Table 4-27, code 050 "ETD Cancellation", authored and
     * signed by the issuer) referencing the original document's ETDUI. Has
     * no lines/taxes of its own. Call submitToBts() on the returned document
     * to actually sign and send it.
     */
    public static function issueCancellation(int $companyId, int $originalDocumentId, ?int $userId = null, string $reason = ''): array
    {
        $original = self::getDocument($companyId, $originalDocumentId);
        if (!$original) {
            throw new InvalidArgumentException('The document to cancel was not found.');
        }
        if ($original['status'] !== 'authorized') {
            throw new InvalidArgumentException('Only a BTS-authorized document can be cancelled through BTS. A document that was never submitted can just be voided (cancel()).');
        }
        if (in_array($original['document_type'], ['cancellation'], true)) {
            throw new InvalidArgumentException('A cancellation event cannot itself be cancelled.');
        }

        $pdo = DB::pdo();
        $uuid = self::uuid4();
        $stmt = $pdo->prepare('
            INSERT INTO fiscal_documents
                (company_id, document_uuid, document_type, status, reference_document_id, our_number,
                 seller_snapshot_json, buyer_snapshot_json, subtotal, tax_total, total, created_by)
            VALUES
                (:company_id, :uuid, \'cancellation\', \'built\', :ref_id, :our_number,
                 :seller_json, :buyer_json, 0, 0, 0, :created_by)
        ');
        $stmt->execute([
            'company_id'  => $companyId,
            'uuid'        => $uuid,
            'ref_id'      => $originalDocumentId,
            'our_number'  => 'CANCEL-' . ($original['our_number'] ?: $originalDocumentId),
            'seller_json' => $original['seller_snapshot_json'],
            'buyer_json'  => $original['buyer_snapshot_json'],
            'created_by'  => $userId ?: null,
        ]);
        $documentId = (int)$pdo->lastInsertId();
        self::logEvent($pdo, $documentId, 'built', $reason !== '' ? $reason : 'Cancellation prepared for submission.', $userId);

        return self::getDocument($companyId, $documentId);
    }

    /**
     * Create a credit note against a BTS-authorized invoice / tax receipt /
     * debit note - an invoice-shaped document (ETDType 04, UBL CreditNote-2)
     * that reverses value on the original.
     *
     * $lineSelections is a list of ['line_number' => n, 'quantity' => q]
     * choosing which of the original's lines to credit and how much of each.
     * A quantity is capped at the original line's quantity (this flow only
     * ever reverses value, it never adds any) and a line left out - or given
     * quantity 0 - is not credited. Pass an empty array to credit the whole
     * document. Unit price, tax category and tax rate carry over from the
     * original line unchanged.
     *
     * Returns a 'built' credit_note; call submitToBts() on it to sign and send.
     *
     * v1 limitations:
     *  - it does not track the running credited total across several credit
     *    notes against the same invoice, so nothing here stops you crediting
     *    the same line twice. The per-line cap is against the original
     *    quantity only.
     *  - the UBL carries no cac:BillingReference back to the original ETDUI.
     *    BTS's own CreditNote/DebitNote samples don't either, so we match
     *    that; the link to the original is kept in our own DB
     *    (reference_document_id). If BTS's live validation turns out to
     *    require an ETD reference, that's a targeted add to FiscalUblBuilder.
     */
    public static function issueCreditNote(int $companyId, int $originalDocumentId, array $lineSelections = [], ?int $userId = null, string $reason = ''): array
    {
        $original = self::getDocument($companyId, $originalDocumentId);
        if (!$original) {
            throw new InvalidArgumentException('The document to credit was not found.');
        }
        if ($original['status'] !== 'authorized') {
            throw new InvalidArgumentException('Only a BTS-authorized document can be credited. This one is "' . $original['status'] . '".');
        }
        if (!in_array($original['document_type'], ['invoice', 'tax_receipt', 'debit_note'], true)) {
            throw new InvalidArgumentException('A ' . self::TYPE_LABEL[$original['document_type']] . ' cannot be credited.');
        }
        if (empty($original['lines'])) {
            throw new InvalidArgumentException('The original document has no lines to credit.');
        }

        $wanted = [];
        foreach ($lineSelections as $sel) {
            $ln = (int)($sel['line_number'] ?? 0);
            if ($ln > 0) {
                $wanted[$ln] = round((float)($sel['quantity'] ?? 0), 4);
            }
        }
        $creditWholeDocument = $wanted === [];

        $creditLines = [];
        foreach ($original['lines'] as $ol) {
            $lineNo = (int)$ol['line_number'];
            $origQty = (float)$ol['quantity'];
            $qty = $creditWholeDocument ? $origQty : ($wanted[$lineNo] ?? 0.0);
            if ($qty <= 0) {
                continue;
            }
            if ($qty > $origQty) {
                $qty = $origQty; // never credit more than was invoiced on that line
            }
            $creditLines[] = [
                'item_code'       => $ol['item_code'],
                'description'     => $ol['description'],
                'quantity'        => $qty,
                'unit_of_measure' => $ol['unit_of_measure'],
                'unit_price'      => (float)$ol['unit_price'],
                'tax_category'    => $ol['tax_category'],
                'tax_rate'        => (float)$ol['tax_rate'],
            ];
        }
        if (!$creditLines) {
            throw new InvalidArgumentException('Choose at least one line, with a quantity, to credit.');
        }

        $document = self::issue($companyId, [
            'document_type'         => 'credit_note',
            'reference_document_id' => $originalDocumentId,
            'source_app'            => $original['source_app'],
            'source_ref'            => $original['source_ref'],
            'our_number'            => 'CN-' . ($original['our_number'] ?: $originalDocumentId),
            'seller'                => json_decode((string)$original['seller_snapshot_json'], true) ?: [],
            'buyer'                 => json_decode((string)$original['buyer_snapshot_json'], true) ?: [],
            'lines'                 => $creditLines,
        ], $userId);

        if ($reason !== '') {
            self::logEvent(DB::pdo(), (int)$document['id'], 'note', 'Reason: ' . $reason, $userId);
        }

        return $document;
    }

    /**
     * Create a debit note against a BTS-authorized invoice / tax receipt - an
     * invoice-shaped document (ETDType 03, UBL Invoice-2) that adds charges
     * to the original: a freight charge billed after the fact, an
     * undercharge correction, a late fee, etc.
     *
     * $lines is a list of brand-new charge lines - NOT selections from the
     * original: ['description', 'quantity', 'unit_price', 'tax_category',
     * 'tax_rate']. At least one with a description, a positive quantity and a
     * positive unit price is required.
     *
     * Returns a 'built' debit_note; call submitToBts() on it to sign and send.
     *
     * v1 limitation: like issueCreditNote(), the UBL carries no
     * cac:BillingReference back to the original ETDUI - the link is kept in
     * our own DB (reference_document_id).
     */
    public static function issueDebitNote(int $companyId, int $originalDocumentId, array $lines, ?int $userId = null, string $reason = ''): array
    {
        $original = self::getDocument($companyId, $originalDocumentId);
        if (!$original) {
            throw new InvalidArgumentException('The document to add a debit note to was not found.');
        }
        if ($original['status'] !== 'authorized') {
            throw new InvalidArgumentException('Only a BTS-authorized document can have a debit note. This one is "' . $original['status'] . '".');
        }
        if (!in_array($original['document_type'], ['invoice', 'tax_receipt'], true)) {
            throw new InvalidArgumentException('A debit note can only be raised against a tax invoice or tax receipt.');
        }

        $chargeLines = [];
        foreach ($lines as $l) {
            $desc  = trim((string)($l['description'] ?? ''));
            $qty   = round((float)($l['quantity'] ?? 0), 4);
            $price = round((float)($l['unit_price'] ?? 0), 4);
            if ($desc === '' || $qty <= 0 || $price <= 0) {
                continue;
            }
            $chargeLines[] = [
                'description'  => $desc,
                'quantity'     => $qty,
                'unit_price'   => $price,
                'tax_category' => (string)($l['tax_category'] ?? 'standard'),
                'tax_rate'     => (float)($l['tax_rate'] ?? self::STANDARD_TAX_RATE),
            ];
        }
        if (!$chargeLines) {
            throw new InvalidArgumentException('Add at least one charge line - each needs a description, a quantity and a unit price.');
        }

        $document = self::issue($companyId, [
            'document_type'         => 'debit_note',
            'reference_document_id' => $originalDocumentId,
            'source_app'            => $original['source_app'],
            'source_ref'            => $original['source_ref'],
            'our_number'            => 'DN-' . ($original['our_number'] ?: $originalDocumentId),
            'seller'                => json_decode((string)$original['seller_snapshot_json'], true) ?: [],
            'buyer'                 => json_decode((string)$original['buyer_snapshot_json'], true) ?: [],
            'lines'                 => $chargeLines,
        ], $userId);

        if ($reason !== '') {
            self::logEvent(DB::pdo(), (int)$document['id'], 'note', 'Reason: ' . $reason, $userId);
        }

        return $document;
    }

    /**
     * Map, sign and submit a 'built' (or previously failed) document to BTS.
     * Consumes the next serial number from the company's counter - see the
     * class doc and add_fiscal_invoicing_submission.sql for why that
     * happens here and not at issue() time. A rejected submission does NOT
     * advance the counter (manual 3.3.2: "the corrected ETD candidate file
     * can use the same series and serial number"), so a retry after fixing
     * the underlying problem reuses the same number.
     */
    public static function submitToBts(int $companyId, int $documentId, ?int $userId = null): array
    {
        $profile = self::getProfile($companyId);
        if (empty($profile['enabled'])) {
            throw new InvalidArgumentException('E-invoicing is not enabled for this company yet - turn it on in the fiscal profile.');
        }
        if (empty($profile['tin'])) {
            throw new InvalidArgumentException("This company's TIN is not set on the fiscal profile.");
        }
        if (empty($profile['certificate_path']) || !is_file($profile['certificate_path'])) {
            throw new InvalidArgumentException("No certificate on file. Generate one via BTS's EFDR Portal and upload it in the fiscal profile.");
        }

        $document = self::getDocument($companyId, $documentId);
        if (!$document) {
            throw new InvalidArgumentException('Document not found.');
        }
        if (!in_array($document['status'], ['built', 'error', 'rejected'], true)) {
            throw new InvalidArgumentException('Only a document with status "built" (or a previously failed attempt) can be submitted. This one is "' . $document['status'] . '".');
        }

        [$certPem, $privateKeyPem] = self::loadCertificate($profile);

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            // Lock the profile row so two concurrent submissions for this
            // company can never consume the same serial number.
            $lockStmt = $pdo->prepare('SELECT * FROM company_fiscal_profiles WHERE company_id = :c FOR UPDATE');
            $lockStmt->execute(['c' => $companyId]);
            $lockedProfile = $lockStmt->fetch(PDO::FETCH_ASSOC);

            $isCancellation = $document['document_type'] === 'cancellation';
            $etdType = self::ETD_TYPE_MAP[$document['document_type']];
            $environment = $lockedProfile['environment'] === 'production' ? 'production' : 'test';
            $receptionEnv = $environment === 'production' ? FiscalEtdui::ENV_PRODUCTION : FiscalEtdui::ENV_TEST;
            $series = $isCancellation ? FiscalEtdui::EVENT_ETD_CANCELLATION : ((string)$lockedProfile['default_series'] ?: '001');
            $nextSerial = (int)$lockedProfile['last_serial_number'] + 1;

            try {
                $issuedAt = new DateTime('now', new DateTimeZone('America/Belize'));
            } catch (Throwable $e) {
                $issuedAt = new DateTime('now');
            }

            $etduiResult = FiscalEtdui::build(
                $etdType, (string)$lockedProfile['tin'], $nextSerial, $series,
                $issuedAt, FiscalEtdui::OPER_MODE_NORMAL, $receptionEnv
            );

            $pdo->prepare('
                UPDATE fiscal_documents
                SET etdui = :etdui, serial_number = :serial, series = :series, security_code = :sec,
                    environment = :env, issue_date = :issue_date, issue_time = :issue_time
                WHERE id = :id
            ')->execute([
                'etdui'      => $etduiResult['etdui'],
                'serial'     => $nextSerial,
                'series'     => $series,
                'sec'        => $etduiResult['security_code'],
                'env'        => $environment,
                'issue_date' => $issuedAt->format('Y-m-d'),
                'issue_time' => $issuedAt->format('H:i:sP'),
                'id'         => $documentId,
            ]);

            $document['etdui'] = $etduiResult['etdui'];
            $document['series'] = $series;
            $document['environment'] = $environment;
            $document['issue_date'] = $issuedAt->format('Y-m-d');
            $document['issue_time'] = $issuedAt->format('H:i:sP');
            $document['sequence_previous'] = $lockedProfile['last_sequence_hash'];

            if ($isCancellation) {
                $original = self::getDocument($companyId, (int)$document['reference_document_id']);
                if (!$original || empty($original['etdui'])) {
                    throw new InvalidArgumentException('The document being cancelled has no ETDUI on file.');
                }
                $original['document_type_label'] = self::TYPE_LABEL[$original['document_type']] ?? 'Tax Invoice';
                $issuer = ['name' => $lockedProfile['legal_name'], 'tin' => $lockedProfile['tin']];
                $built = FiscalUblBuilder::buildCancellation($document, $original, $issuer);
            } else {
                $seller = [
                    'name'    => $lockedProfile['legal_name'],
                    'tin'     => $lockedProfile['tin'],
                    'address' => $lockedProfile['address'],
                ];
                $buyer = json_decode((string)$document['buyer_snapshot_json'], true) ?: [];
                $built = FiscalUblBuilder::build($document, $document['lines'], $document['taxes'], $seller, $buyer);
            }

            $privateKey = openssl_pkey_get_private($privateKeyPem);
            if ($privateKey === false) {
                throw new RuntimeException('Could not load the private key from the certificate.');
            }
            $signedXml = FiscalXadesSigner::sign($built['xml'], $certPem, $privateKey);

            // Persisted before the network call, so a signed document is
            // never lost even if the HTTP submission itself fails.
            $xmlPath = self::signedXmlPath($companyId, $documentId);
            self::ensureDir(dirname($xmlPath));
            file_put_contents($xmlPath, $signedXml);

            $pdo->prepare("UPDATE fiscal_documents SET status = 'signed', signed_xml_path = :path WHERE id = :id")
                ->execute(['path' => $xmlPath, 'id' => $documentId]);
            self::logEvent($pdo, $documentId, 'signed', null, $userId);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // The network call happens outside the DB transaction (it can take
        // real wall-clock time and shouldn't hold a row lock while it does).
        $btsDocType = $isCancellation ? 'cancellation' : $document['document_type'];
        $response = FiscalBtsClient::submit($btsDocType, $environment, $signedXml, $certPem, $privateKeyPem);

        $newStatus = $response['authorized'] ? 'authorized' : ($response['http_status'] === 0 ? 'error' : 'rejected');

        $pdo->beginTransaction();
        try {
            $pdo->prepare('
                UPDATE fiscal_documents
                SET status = :status, authorization_code = :auth, authorized_at = :authorized_at,
                    bts_response_json = :resp, error_message = :err, submitted_at = NOW(),
                    retry_count = retry_count + 1
                WHERE id = :id
            ')->execute([
                'status'        => $newStatus,
                'auth'          => $response['authorized'] ? $document['etdui'] : null,
                'authorized_at' => $response['authorized'] ? date('Y-m-d H:i:s') : null,
                'resp'          => json_encode($response, JSON_UNESCAPED_UNICODE),
                'err'           => $response['error'],
                'id'            => $documentId,
            ]);
            self::logEvent($pdo, $documentId, $newStatus, $response['error'] ?? ('HTTP ' . $response['http_status'] . ($response['description'] ? ' - ' . $response['description'] : '')), $userId);

            // Only a confirmed authorization advances the numbering chain -
            // a rejected/failed attempt keeps the same serial/series
            // available for a corrected retry (manual 3.3.2).
            if ($response['authorized']) {
                $pdo->prepare('UPDATE company_fiscal_profiles SET last_serial_number = :serial, last_sequence_hash = :hash WHERE company_id = :c')
                    ->execute(['serial' => $nextSerial, 'hash' => $built['sequence_hash'], 'c' => $companyId]);

                if ($isCancellation) {
                    $pdo->prepare("UPDATE fiscal_documents SET status = 'cancelled' WHERE id = :id")
                        ->execute(['id' => (int)$document['reference_document_id']]);
                    self::logEvent($pdo, (int)$document['reference_document_id'], 'cancelled', 'Cancelled via BTS event ' . $document['etdui'], $userId);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::getDocument($companyId, $documentId);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Extract the certificate + private key from the company's PFX/P12 file
     * (password = the company's own TIN, per BTS's EFDR Portal convention -
     * see project memory / the integration slide deck, section 3).
     *
     * @return array{0: string, 1: string} [certPem, privateKeyPem]
     */
    private static function loadCertificate(array $profile): array
    {
        $pfx = file_get_contents($profile['certificate_path']);
        if ($pfx === false) {
            throw new RuntimeException('Could not read the certificate file on disk.');
        }
        $certs = [];
        if (!openssl_pkcs12_read($pfx, $certs, (string)$profile['tin'])) {
            throw new RuntimeException('Could not open the certificate (PFX/P12) - the password should be this company\'s TIN.');
        }
        return [$certs['cert'], $certs['pkey']];
    }

    private static function signedXmlPath(int $companyId, int $documentId): string
    {
        return self::storageRoot() . '/documents/' . $companyId . '/' . $documentId . '.xml';
    }

    /** Where an uploaded certificate for a company should be written to. */
    public static function certificatePath(int $companyId): string
    {
        return self::storageRoot() . '/certs/' . $companyId . '.pfx';
    }

    /**
     * Outside the web root on purpose (certificates and signed tax documents
     * are not public files). Known gap: stored as delivered, no
     * encryption-at-rest yet - that needs a real key-management decision
     * (where would the encryption key itself live?) before this handles a
     * real company's live certificate; filesystem permissions are the only
     * protection today.
     */
    private static function storageRoot(): string
    {
        return dirname(__DIR__, 2) . '/storage/fiscal';
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    private static function logEvent(PDO $pdo, int $documentId, string $eventType, ?string $detail, ?int $userId): void
    {
        $pdo->prepare('
            INSERT INTO fiscal_document_events (fiscal_document_id, event_type, detail, created_by)
            VALUES (:doc_id, :event_type, :detail, :created_by)
        ')->execute([
            'doc_id'     => $documentId,
            'event_type' => substr($eventType, 0, 40),
            'detail'     => $detail,
            'created_by' => $userId ?: null,
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
