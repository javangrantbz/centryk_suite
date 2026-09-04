<?php
require_once __DIR__ . '/FiscalEtdui.php';

/**
 * Belize BTS e-invoicing — canonical fiscal document -> raw (unsigned) UBL
 * 2.1 XML per the "Belize ETD Syntax Model" (BETDSM), manual Ch.4.
 *
 * BETDSM uses 4 UBL schemas (manual 4.4):
 *   - Tax Invoice, Tax Receipt, Debit Note -> UBL Invoice-2 (this is
 *     confirmed against BTS's own sample files, not assumed - all three of
 *     their sample documents for these types use an <Invoice> root, only
 *     the EFDRExtensions/ETDType differs)
 *   - Credit Note -> UBL CreditNote-2
 *   - Events (incl. cancellation) -> UBL ApplicationResponse-2 (built
 *     separately, see buildCancellation() - it isn't invoice-shaped)
 *
 * This class only builds the *unsigned* document (the ext:UBLExtensions
 * block for EFDRExtensions, but not the signature block - FiscalXadesSigner
 * adds that as a second, sibling ext:UBLExtension afterward, matching every
 * sample: two UBLExtension children, one for EFDRExtensions, one for the
 * signature).
 *
 * Field choices not pinned down by a fatal validation rule (e.g. the exact
 * UN/ECE InvoiceTypeCode, or AdditionalAccountID) mirror BTS's own sample
 * documents verbatim rather than a guess, since those samples pass their
 * own reference implementation.
 */
class FiscalUblBuilder
{
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
    private const NS_EFDR = 'ciat:org:efdr:CommonComponentes-2-4';

    private const NS_ROOT = [
        'invoice'      => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'tax_receipt'  => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'debit_note'   => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'credit_note'  => 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
    ];
    private const ROOT_ELEMENT = [
        'invoice'      => 'Invoice',
        'tax_receipt'  => 'Invoice',
        'debit_note'   => 'Invoice',
        'credit_note'  => 'CreditNote',
    ];
    private const LINE_ELEMENT = [
        'invoice'      => 'InvoiceLine',
        'tax_receipt'  => 'InvoiceLine',
        'debit_note'   => 'InvoiceLine',
        'credit_note'  => 'CreditNoteLine',
    ];
    private const TYPE_CODE_ELEMENT = [
        'invoice'      => 'InvoiceTypeCode',
        'tax_receipt'  => 'InvoiceTypeCode',
        'debit_note'   => 'InvoiceTypeCode',
        'credit_note'  => 'CreditNoteTypeCode',
    ];
    // Verbatim from BTS's own sample documents (all four types use "383" in
    // their Invoice-2 samples; CreditNote-2 samples use "381"). Both are
    // warning-level if wrong (rules 277-279), not fatal.
    private const TYPE_CODE_VALUE = [
        'invoice'      => '383',
        'tax_receipt'  => '383',
        'debit_note'   => '383',
        'credit_note'  => '381',
    ];
    private const TAX_CATEGORY_UBL = [
        'standard'    => ['id' => 'S', 'name' => 'Standard Rate'],
        'zero_rated'  => ['id' => 'Z', 'name' => 'Zero Rated'],
        'exempt'      => ['id' => 'E', 'name' => 'Exempt'],
    ];

    /**
     * Build the unsigned UBL XML for an invoice/tax_receipt/debit_note/credit_note.
     *
     * @param array $document fiscal_documents row - must already carry etdui,
     *              serial_number, series, security_code, environment, subtotal,
     *              tax_total, total, document_type, plus a 'sequence_previous' key
     *              (the company's last_sequence_hash, or null for the first ever).
     * @param array $lines fiscal_document_lines rows
     * @param array $taxes fiscal_document_taxes rows
     * @param array $seller decoded seller_snapshot_json: name, tin, address
     * @param array $buyer decoded buyer_snapshot_json: name, tin, address
     * @return array{xml: string, sequence_hash: string} the raw XML and the
     *              sequenceInfoThisETD value (the caller persists this as the
     *              company's new last_sequence_hash once the document is submitted).
     */
    public static function build(array $document, array $lines, array $taxes, array $seller, array $buyer): array
    {
        $type = (string)$document['document_type'];
        if (!isset(self::NS_ROOT[$type])) {
            throw new InvalidArgumentException('FiscalUblBuilder cannot build a "' . $type . '" document - use buildCancellation() for cancellations.');
        }
        if (trim((string)($buyer['name'] ?? '')) === '') {
            // Manual rule INV18/DBNT15/CDNT15 (code 283): AccountingCustomerParty
            // is mandatory - BTS rejects a document with no buyer party at all.
            throw new InvalidArgumentException('A buyer name is required - BTS rejects a document with no Accounting Customer Party.');
        }

        $doc = self::newDocument();

        $root = $doc->createElementNS(self::NS_ROOT[$type], self::ROOT_ELEMENT[$type]);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);
        $doc->appendChild($root);

        // ── ext:UBLExtensions > EFDRExtensions (signature extension added later) ──
        $extensions = $doc->createElementNS(self::NS_EXT, 'ext:UBLExtensions');
        $root->appendChild($extensions);
        $extension = $doc->createElementNS(self::NS_EXT, 'ext:UBLExtension');
        $extensions->appendChild($extension);
        $content = $doc->createElementNS(self::NS_EXT, 'ext:ExtensionContent');
        $extension->appendChild($content);
        $efdr = $doc->createElementNS(self::NS_EFDR, 'efdr:EFDRExtensions');
        $content->appendChild($efdr);

        $etduiParts = FiscalEtdui::parse($document['etdui']);
        $addElem = $doc->createElementNS(self::NS_EFDR, 'efdr:ETDUIAddElem');
        $efdr->appendChild($addElem);
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:ETDType', $etduiParts['etd_type']));
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:series', (string)$document['series']));
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:operMode', $etduiParts['oper_mode']));
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:receptionEnv', $etduiParts['reception_env']));
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:securityCode', $etduiParts['security_code']));
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:checkDigit', $etduiParts['check_digit']));

        // sequenceInfo (manual 4.3.4) - mandatory for the Invoice schema (rule
        // INV05/DBNT.., code 270 rejection if missing). Computed here since it
        // needs the final line count / tax / line-extension amounts.
        $lineCount = count($lines);
        $sequenceHash = FiscalEtdui::sequenceHash(
            $document['etdui'],
            $lineCount,
            number_format((float)$document['tax_total'], 2, '.', ''),
            number_format((float)$document['subtotal'], 2, '.', '')
        );
        $seqInfo = $doc->createElementNS(self::NS_EFDR, 'efdr:sequenceInfo');
        $efdr->appendChild($seqInfo);
        $seqInfo->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:sequenceInfoThisETD', $sequenceHash));
        $seqInfo->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:sequenceInfoPreviousETD', (string)($document['sequence_previous'] ?? '')));

        // ── General data ──────────────────────────────────────────────────
        self::text($doc, $root, 'cbc', 'UBLVersionID', '2.1');
        self::text($doc, $root, 'cbc', 'CustomizationID', 'urn:ciat:efdr:customization:1.0');
        self::text($doc, $root, 'cbc', 'ID', $etduiParts['serial_number']);
        self::text($doc, $root, 'cbc', 'UUID', $document['etdui']);
        self::text($doc, $root, 'cbc', 'IssueDate', $document['issue_date']);
        self::text($doc, $root, 'cbc', 'IssueTime', $document['issue_time']);

        $typeCodeEl = self::text($doc, $root, 'cbc', self::TYPE_CODE_ELEMENT[$type], self::TYPE_CODE_VALUE[$type]);
        $typeCodeEl->setAttribute('listID', 'UN/ECE 1001 Subset');
        $typeCodeEl->setAttribute('listAgencyID', 'UN/CEFACT');

        $currencyEl = self::text($doc, $root, 'cbc', 'DocumentCurrencyCode', 'BZD');
        $currencyEl->setAttribute('listID', 'D_Curr');

        self::text($doc, $root, 'cbc', 'LineCountNumeric', (string)$lineCount);

        // ── Parties ────────────────────────────────────────────────────────
        $supplier = $doc->createElementNS(self::NS_CAC, 'cac:AccountingSupplierParty');
        $root->appendChild($supplier);
        self::text($doc, $supplier, 'cbc', 'AdditionalAccountID', '1'); // constant per BTS samples
        $supplierParty = $doc->createElementNS(self::NS_CAC, 'cac:Party');
        $supplier->appendChild($supplierParty);
        self::appendAddress($doc, $supplierParty, $seller['address'] ?? null);
        $supplierTax = $doc->createElementNS(self::NS_CAC, 'cac:PartyTaxScheme');
        $supplierParty->appendChild($supplierTax);
        self::text($doc, $supplierTax, 'cbc', 'RegistrationName', (string)($seller['name'] ?? ''));
        self::text($doc, $supplierTax, 'cbc', 'CompanyID', self::digitsOnly((string)($seller['tin'] ?? '')));
        self::appendTaxScheme($doc, $supplierTax);

        $customer = $doc->createElementNS(self::NS_CAC, 'cac:AccountingCustomerParty');
        $root->appendChild($customer);
        $customerParty = $doc->createElementNS(self::NS_CAC, 'cac:Party');
        $customer->appendChild($customerParty);
        $partyName = $doc->createElementNS(self::NS_CAC, 'cac:PartyName');
        $customerParty->appendChild($partyName);
        self::text($doc, $partyName, 'cbc', 'Name', (string)$buyer['name']);
        self::appendAddress($doc, $customerParty, $buyer['address'] ?? null);
        if (!empty($buyer['tin'])) {
            $customerTax = $doc->createElementNS(self::NS_CAC, 'cac:PartyTaxScheme');
            $customerParty->appendChild($customerTax);
            self::text($doc, $customerTax, 'cbc', 'CompanyID', self::digitsOnly((string)$buyer['tin']));
            self::appendTaxScheme($doc, $customerTax);
        }

        // ── Tax totals ────────────────────────────────────────────────────
        $taxTotal = $doc->createElementNS(self::NS_CAC, 'cac:TaxTotal');
        $root->appendChild($taxTotal);
        self::money($doc, $taxTotal, 'TaxAmount', (float)$document['tax_total']);
        foreach ($taxes as $t) {
            $sub = $doc->createElementNS(self::NS_CAC, 'cac:TaxSubtotal');
            $taxTotal->appendChild($sub);
            self::money($doc, $sub, 'TaxableAmount', (float)$t['taxable_amount']);
            self::money($doc, $sub, 'TaxAmount', (float)$t['tax_amount']);
            self::text($doc, $sub, 'cbc', 'CalculationSequenceNumeric', '1');
            self::money($doc, $sub, 'TransactionCurrencyTaxAmount', (float)$t['tax_amount']);
            self::text($doc, $sub, 'cbc', 'Percent', self::trimZeros((string)$t['tax_rate']));
            $cat = self::TAX_CATEGORY_UBL[$t['tax_category']] ?? self::TAX_CATEGORY_UBL['standard'];
            $catEl = $doc->createElementNS(self::NS_CAC, 'cac:TaxCategory');
            $sub->appendChild($catEl);
            self::text($doc, $catEl, 'cbc', 'ID', $cat['id']);
            self::text($doc, $catEl, 'cbc', 'Name', $cat['name']);
            self::text($doc, $catEl, 'cbc', 'Percent', self::trimZeros((string)$t['tax_rate']));
            self::appendTaxScheme($doc, $catEl);
        }

        // ── Monetary total ────────────────────────────────────────────────
        $total = $doc->createElementNS(self::NS_CAC, 'cac:LegalMonetaryTotal');
        $root->appendChild($total);
        self::money($doc, $total, 'LineExtensionAmount', (float)$document['subtotal']);
        self::money($doc, $total, 'TaxExclusiveAmount', (float)$document['subtotal']);
        self::money($doc, $total, 'TaxInclusiveAmount', (float)$document['total']);
        self::money($doc, $total, 'AllowanceTotalAmount', 0.0);
        self::money($doc, $total, 'ChargeTotalAmount', 0.0);
        self::money($doc, $total, 'PrepaidAmount', 0.0);
        self::money($doc, $total, 'PayableAmount', (float)$document['total']);

        // ── Lines ─────────────────────────────────────────────────────────
        foreach ($lines as $line) {
            $lineEl = $doc->createElementNS(self::NS_CAC, 'cac:' . self::LINE_ELEMENT[$type]);
            $root->appendChild($lineEl);
            self::text($doc, $lineEl, 'cbc', 'ID', (string)$line['line_number']);
            self::text($doc, $lineEl, 'cbc', 'UUID', self::uuid4());
            self::money($doc, $lineEl, 'LineExtensionAmount', (float)$line['line_subtotal']);
            $item = $doc->createElementNS(self::NS_CAC, 'cac:Item');
            $lineEl->appendChild($item);
            self::text($doc, $item, 'cbc', 'Description', (string)$line['description']);
            self::text($doc, $item, 'cbc', 'CatalogueIndicator', 'false');
            self::text($doc, $item, 'cbc', 'Name', (string)$line['description']);
            if (!empty($line['item_code'])) {
                $buyersId = $doc->createElementNS(self::NS_CAC, 'cac:BuyersItemIdentification');
                $item->appendChild($buyersId);
                self::text($doc, $buyersId, 'cbc', 'ID', (string)$line['item_code']);
            }
        }

        return [
            'xml'           => self::finalize($doc),
            'sequence_hash' => $sequenceHash,
        ];
    }

    /**
     * The cancellation "event" (manual 4.10, Table 4-27 code 050: "ETD
     * Cancellation" - authored and signed by the issuer, referencing the
     * ETDUI of the document being cancelled). Root is ApplicationResponse-2,
     * not Invoice-2 - a structurally different, much smaller document.
     */
    public static function buildCancellation(array $document, array $originalDocument, array $issuer): array
    {
        $doc = self::newDocument();

        $ns = 'urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2';
        $root = $doc->createElementNS($ns, 'ApplicationResponse');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);
        $doc->appendChild($root);

        $extensions = $doc->createElementNS(self::NS_EXT, 'ext:UBLExtensions');
        $root->appendChild($extensions);
        $extension = $doc->createElementNS(self::NS_EXT, 'ext:UBLExtension');
        $extensions->appendChild($extension);
        $content = $doc->createElementNS(self::NS_EXT, 'ext:ExtensionContent');
        $extension->appendChild($content);
        $efdr = $doc->createElementNS(self::NS_EFDR, 'efdr:EFDRExtensions');
        $content->appendChild($efdr);

        $etduiParts = FiscalEtdui::parse($document['etdui']);
        $addElem = $doc->createElementNS(self::NS_EFDR, 'efdr:ETDUIAddElem');
        $efdr->appendChild($addElem);
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:ETDType', $etduiParts['etd_type'])); // 05
        // For events, "series" is the event code itself (manual 4.3.1), not a
        // running invoice series - here always EVENT_ETD_CANCELLATION (050).
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:series', $document['series']));
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:operMode', $etduiParts['oper_mode']));
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:receptionEnv', $etduiParts['reception_env']));
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:securityCode', $etduiParts['security_code']));
        $addElem->appendChild($doc->createElementNS(self::NS_EFDR, 'efdr:checkDigit', $etduiParts['check_digit']));
        // Events carry no sequenceInfo (that requirement is specific to the
        // Invoice schema per rule INV05 - Table 7-5).

        self::text($doc, $root, 'cbc', 'UBLVersionID', '2.1');
        self::text($doc, $root, 'cbc', 'CustomizationID', 'urn:ciat:efdr:customization:1.0');
        self::text($doc, $root, 'cbc', 'ID', $etduiParts['serial_number']);
        self::text($doc, $root, 'cbc', 'UUID', $document['etdui']);
        self::text($doc, $root, 'cbc', 'IssueDate', $document['issue_date']);
        self::text($doc, $root, 'cbc', 'IssueTime', $document['issue_time']);

        $sender = $doc->createElementNS(self::NS_CAC, 'cac:SenderParty');
        $root->appendChild($sender);
        $senderTax = $doc->createElementNS(self::NS_CAC, 'cac:PartyTaxScheme');
        $sender->appendChild($senderTax);
        self::text($doc, $senderTax, 'cbc', 'RegistrationName', (string)($issuer['name'] ?? ''));
        self::text($doc, $senderTax, 'cbc', 'CompanyID', self::digitsOnly((string)($issuer['tin'] ?? '')));
        $senderScheme = $doc->createElementNS(self::NS_CAC, 'cac:TaxScheme');
        $senderTax->appendChild($senderScheme);
        self::text($doc, $senderScheme, 'cbc', 'ID', 'GST');

        $receiver = $doc->createElementNS(self::NS_CAC, 'cac:ReceiverParty');
        $root->appendChild($receiver);
        $receiverTax = $doc->createElementNS(self::NS_CAC, 'cac:PartyTaxScheme');
        $receiver->appendChild($receiverTax);
        self::text($doc, $receiverTax, 'cbc', 'RegistrationName', (string)($issuer['name'] ?? ''));
        self::text($doc, $receiverTax, 'cbc', 'CompanyID', self::digitsOnly((string)($issuer['tin'] ?? '')));
        $receiverScheme = $doc->createElementNS(self::NS_CAC, 'cac:TaxScheme');
        $receiverTax->appendChild($receiverScheme);
        self::text($doc, $receiverScheme, 'cbc', 'ID', 'GST');

        $docResponse = $doc->createElementNS(self::NS_CAC, 'cac:DocumentResponse');
        $root->appendChild($docResponse);
        $response = $doc->createElementNS(self::NS_CAC, 'cac:Response');
        $docResponse->appendChild($response);
        self::text($doc, $response, 'cbc', 'ReferenceID', 'Whole document');
        self::text($doc, $response, 'cbc', 'ResponseCode', FiscalEtdui::EVENT_ETD_CANCELLATION);
        self::text($doc, $response, 'cbc', 'Description', 'ETD Cancellation');

        $docRef = $doc->createElementNS(self::NS_CAC, 'cac:DocumentReference');
        $docResponse->appendChild($docRef);
        self::text($doc, $docRef, 'cbc', 'UUID', (string)$originalDocument['etdui']);
        self::text($doc, $docRef, 'cbc', 'DocumentTypeCode', $etduiParts['etd_type']); // placeholder; caller may override to the original doc's own type
        self::text($doc, $docRef, 'cbc', 'DocumentType', (string)($originalDocument['document_type_label'] ?? 'Tax Invoice'));

        return ['xml' => self::finalize($doc), 'sequence_hash' => null];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Every one of BTS's own sample documents declares `encoding="utf-16"`
     * but is actually encoded as plain UTF-8/ASCII bytes (verified with a
     * hex dump - no UTF-16 BOM, single-byte "<?xml" markers) - almost
     * certainly a side effect of their .NET reference implementation
     * (strings are UTF-16 internally in .NET regardless of what a given
     * serialization path writes to the wire). One of these exact
     * mismatched-declaration samples has a real BTS authorization response
     * (ResponseCode 001) attached to it, so this is a demonstrated-working
     * pattern, not a guess - we replicate it rather than "correctly" emit
     * real UTF-16, which is unverified against BTS's actual parser.
     * Building as genuine UTF-8 keeps our own DOM/signing code simple and
     * correct; only the prolog's declared encoding is overwritten to match.
     */
    private static function newDocument(): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;
        return $doc;
    }

    private static function finalize(DOMDocument $doc): string
    {
        $xml = $doc->saveXML();
        return preg_replace('/^<\?xml version="1\.0" encoding="UTF-8"\?>/', '<?xml version="1.0" encoding="utf-16"?>', $xml, 1);
    }

    private static function text(DOMDocument $doc, DOMElement $parent, string $prefix, string $name, string $value): DOMElement
    {
        $ns = $prefix === 'cbc' ? self::NS_CBC : self::NS_CAC;
        $el = $doc->createElementNS($ns, $prefix . ':' . $name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        $parent->appendChild($el);
        return $el;
    }

    private static function money(DOMDocument $doc, DOMElement $parent, string $name, float $amount): DOMElement
    {
        $el = self::text($doc, $parent, 'cbc', $name, number_format($amount, 2, '.', ''));
        $el->setAttribute('currencyID', 'BZD');
        return $el;
    }

    private static function appendAddress(DOMDocument $doc, DOMElement $party, ?string $address): void
    {
        $postal = $doc->createElementNS(self::NS_CAC, 'cac:PostalAddress');
        $party->appendChild($postal);
        if ($address !== null && trim($address) !== '') {
            $addressLine = $doc->createElementNS(self::NS_CAC, 'cac:AddressLine');
            $postal->appendChild($addressLine);
            self::text($doc, $addressLine, 'cbc', 'Line', trim($address));
        }
        $country = $doc->createElementNS(self::NS_CAC, 'cac:Country');
        $postal->appendChild($country);
        self::text($doc, $country, 'cbc', 'IdentificationCode', 'BZE');
        self::text($doc, $country, 'cbc', 'Name', 'Belize');
    }

    private static function appendTaxScheme(DOMDocument $doc, DOMElement $parent): void
    {
        $scheme = $doc->createElementNS(self::NS_CAC, 'cac:TaxScheme');
        $parent->appendChild($scheme);
        self::text($doc, $scheme, 'cbc', 'ID', '1');
        self::text($doc, $scheme, 'cbc', 'Name', 'VAT');
    }

    private static function digitsOnly(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    private static function trimZeros(string $value): string
    {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
