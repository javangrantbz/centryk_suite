<?php

/**
 * Belize BTS e-invoicing — ETDUI (Electronic Tax Document Unique Identifier)
 * and the sequenceInfo transmission-tracing hash.
 *
 * Both algorithms below are transcribed from BTS's own "Orientation Manual
 * for Issuers of Electronic Tax Documents" v1.30 (2026-05-20), sections 4.3.2
 * (ETDUI), 4.3.3 (check digit) and 4.3.4 (sequenceInfo) - not guessed. The
 * ETDUI composition was verified digit-for-digit against a real sample
 * document BTS provided (Invoice.xml in gitignore/bts docs/supplementary
 * documents); the check digit algorithm matches BTS's own reference C# code
 * (CalcMod11.cs / ETDUIUtil.cs) exactly.
 *
 * ETDUI = 44 numeric digits:
 *   [type:2][reserved:1="0"][issuerTIN:6][serial:9][series:3][YYMMDD:6]
 *   [HHMM:4][operMode:1][receptionEnv:2][securityCode:9][checkDigit:1]
 */
class FiscalEtdui
{
    /** ETDType codes (manual Table 10-2). */
    public const TYPE_INVOICE      = '01';
    public const TYPE_TAX_RECEIPT  = '02';
    public const TYPE_DEBIT_NOTE   = '03';
    public const TYPE_CREDIT_NOTE  = '04';
    public const TYPE_APPLICATION_RESPONSE = '05';

    /** operMode (manual Table 10-6). Only NORMAL is implemented - see class doc on FiscalBtsClient. */
    public const OPER_MODE_NORMAL = '1';

    /** receptionEnv (manual Ch.7). */
    public const ENV_PRODUCTION = '01';
    public const ENV_TEST       = '02';

    /** Event codes for ApplicationResponse "series" (manual Table 4-27). */
    public const EVENT_AUTHORIZED        = '001';
    public const EVENT_AUTHORIZED_ALERT  = '002';
    public const EVENT_REJECTED          = '003';
    public const EVENT_ETD_CANCELLATION  = '050'; // issuer cancelling their own ETD

    /**
     * Modulo-11 check digit over a numeric string (weights 2,3,4,5,6,7,8,9
     * cycling, applied right-to-left). Returns a single digit 0-9 (a
     * would-be "10" collapses to "0", per BTS's own reference code).
     */
    public static function checkDigit(string $digits): string
    {
        if (!preg_match('/^\d+$/', $digits)) {
            throw new InvalidArgumentException('checkDigit() input must be all numeric digits.');
        }

        $weights = [2, 3, 4, 5, 6, 7, 8, 9];
        $sum = 0;
        $weightIndex = 0;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int)$digits[$i];
            $weight = $weights[$weightIndex % count($weights)];
            $sum += $digit * $weight;
            $weightIndex++;
        }
        $checkDigit = 11 - ($sum % 11);
        return $checkDigit >= 10 ? '0' : (string)$checkDigit;
    }

    /**
     * Build a 44-digit ETDUI. $issuedAt is the document's own issue moment
     * (used for the YYMMDD/HHMM fields - must match <cbc:IssueDate>/<cbc:IssueTime>
     * exactly or BTS rejects it per rule ETDUI05).
     *
     * @param string $etdType One of the TYPE_* constants.
     * @param string $issuerTin Up to 6 digits.
     * @param int $serialNumber Up to 9 digits - the issuer's own running number.
     * @param string $series 3 digits: an ordinary numbering series (e.g. "001")
     *                       for Invoice/TaxReceipt/DebitNote/CreditNote, or the
     *                       EVENT_* code for an ApplicationResponse (manual 4.3.1:
     *                       "the series in this case must be the code of the event").
     * @param string $operMode One of the OPER_MODE_* constants.
     * @param string $receptionEnv One of the ENV_* constants.
     * @param string|null $securityCode 9 digits; a fresh random one is generated if omitted.
     */
    public static function build(
        string $etdType,
        string $issuerTin,
        int $serialNumber,
        string $series,
        DateTimeInterface $issuedAt,
        string $operMode,
        string $receptionEnv,
        ?string $securityCode = null
    ): array {
        $securityCode = $securityCode ?? str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

        $body =
            str_pad($etdType, 2, '0', STR_PAD_LEFT) .
            '0' .
            str_pad($issuerTin, 6, '0', STR_PAD_LEFT) .
            str_pad((string)$serialNumber, 9, '0', STR_PAD_LEFT) .
            str_pad($series, 3, '0', STR_PAD_LEFT) .
            $issuedAt->format('ymd') .
            $issuedAt->format('Hi') .
            $operMode .
            str_pad($receptionEnv, 2, '0', STR_PAD_LEFT) .
            $securityCode;

        if (strlen($body) !== 43) {
            throw new InvalidArgumentException('ETDUI body must be 43 digits before the check digit; got ' . strlen($body) . '.');
        }

        $check = self::checkDigit($body);

        return [
            'etdui'         => $body . $check,
            'security_code' => $securityCode,
            'check_digit'   => $check,
        ];
    }

    /** Split a 44-digit ETDUI back into its named fields, for display/debugging. */
    public static function parse(string $etdui): array
    {
        if (!preg_match('/^\d{44}$/', $etdui)) {
            throw new InvalidArgumentException('ETDUI must be exactly 44 numeric digits.');
        }
        return [
            'etd_type'      => substr($etdui, 0, 2),
            'reserved'      => substr($etdui, 2, 1),
            'issuer_tin'    => substr($etdui, 3, 6),
            'serial_number' => substr($etdui, 9, 9),
            'series'        => substr($etdui, 18, 3),
            'issue_date'    => substr($etdui, 21, 6), // YYMMDD
            'issue_time'    => substr($etdui, 27, 4), // HHMM
            'oper_mode'     => substr($etdui, 31, 1),
            'reception_env' => substr($etdui, 32, 2),
            'security_code' => substr($etdui, 34, 9),
            'check_digit'   => substr($etdui, 43, 1),
        ];
    }

    /**
     * sequenceInfoThisETD (manual 4.3.4): SHA1, base64-encoded, of the
     * concatenation of the document's UUID (the ETDUI itself once inserted
     * into <cbc:UUID>), LineCountNumeric, TaxAmount and LineExtensionAmount -
     * in that order, as plain concatenated text (no separators).
     */
    public static function sequenceHash(string $etdui, int $lineCount, string $taxAmount, string $lineExtensionAmount): string
    {
        $concatenated = $etdui . $lineCount . $taxAmount . $lineExtensionAmount;
        return base64_encode(sha1($concatenated, true));
    }
}
