<?php

/**
 * Belize BTS e-invoicing — the mTLS transmitter.
 *
 * Endpoints, methods and headers are transcribed verbatim from the
 * Orientation Manual (sections 7.4-7.10) and cross-checked against the
 * integration slide deck: every Reception/Event API is `POST`,
 * `Content-Type: application/xml`, body = the signed UBL XML, at
 * `https://{prod|test}.bz.efdrciat.org/api/{service}/v1`. All APIs require
 * mutual TLS - both sides present a certificate - so every call here needs
 * the company's own certificate (not BTS's signing certificate, which only
 * signs the *document*; this is the *connection's* client certificate, and
 * per the EFDR Portal they happen to be the same certificate/keypair).
 *
 * UNTESTED against the live BTS test environment: no company has a real
 * BTS-issued certificate yet (see company_fiscal_profiles.certificate_path -
 * still empty for every company as of this writing). Built strictly to the
 * manual's spec and ready to exercise the moment a real certificate exists;
 * treat the first real submission as the actual integration test.
 */
class FiscalBtsClient
{
    private const BASE_URL = [
        'production' => 'https://prod.bz.efdrciat.org/',
        'test'       => 'https://test.bz.efdrciat.org/',
    ];

    /** Reception + Event endpoints (manual 7.4-7.8) - XML in, XML out. */
    private const ENDPOINTS = [
        'invoice'      => 'api/invoice/v1',
        'tax_receipt'  => 'api/taxreceipt/v1',
        'debit_note'   => 'api/debitnote/v1',
        'credit_note'  => 'api/creditnote/v1',
        'cancellation' => 'api/event/v1',
    ];

    /**
     * POST a signed document to its BTS reception/event service.
     *
     * @param string $documentType One of the ENDPOINTS keys.
     * @param string $environment 'test' or 'production'.
     * @param string $signedXml From FiscalXadesSigner::sign().
     * @param string $certPem PEM certificate (the company's).
     * @param string $privateKeyPem PEM private key, unencrypted (already
     *        decrypted from the PFX by the caller).
     * @return array{
     *   http_status: int, response_code: ?string, description: ?string,
     *   authorized: bool, raw_response: string, error: ?string
     * }
     */
    public static function submit(
        string $documentType,
        string $environment,
        string $signedXml,
        string $certPem,
        string $privateKeyPem
    ): array {
        if (!isset(self::ENDPOINTS[$documentType])) {
            throw new InvalidArgumentException('Unknown BTS document type "' . $documentType . '".');
        }
        if (!isset(self::BASE_URL[$environment])) {
            throw new InvalidArgumentException('Unknown environment "' . $environment . '" - must be "test" or "production".');
        }

        $url = self::BASE_URL[$environment] . self::ENDPOINTS[$documentType];

        // cURL needs the client cert/key as files. PEM works across TLS
        // backends (unlike PKCS#12, which schannel-based curl builds don't
        // always accept), so we always hand it PEM, written to the OS temp
        // dir just for this call and removed immediately after - regardless
        // of outcome - so the private key never lingers on disk.
        $certFile = tempnam(sys_get_temp_dir(), 'fiscal_cert_');
        $keyFile  = tempnam(sys_get_temp_dir(), 'fiscal_key_');
        file_put_contents($certFile, $certPem);
        file_put_contents($keyFile, $privateKeyPem);
        @chmod($certFile, 0600);
        @chmod($keyFile, 0600);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $signedXml,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/xml'],
                CURLOPT_SSLCERT        => $certFile,
                CURLOPT_SSLCERTTYPE    => 'PEM',
                CURLOPT_SSLKEY         => $keyFile,
                CURLOPT_SSLKEYTYPE     => 'PEM',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }

        if ($body === false) {
            return [
                'http_status' => 0, 'response_code' => null, 'description' => null,
                'authorized' => false, 'raw_response' => '', 'error' => 'Connection failed: ' . $curlError,
            ];
        }

        $parsed = self::parseApplicationResponse((string)$body);

        return [
            'http_status'   => $httpStatus,
            'response_code' => $parsed['response_code'],
            'description'   => $parsed['description'],
            'authorized'    => $httpStatus === 201 && in_array($parsed['response_code'], ['001', '002'], true),
            'raw_response'  => (string)$body,
            'error'         => $httpStatus >= 400 ? self::describeError($httpStatus, $parsed['description']) : null,
        ];
    }

    /** System Status service (manual 7.10) - JSON, still mTLS. */
    public static function checkStatus(string $environment, string $certPem, string $privateKeyPem): array
    {
        $url = self::BASE_URL[$environment] . 'api/status/v1';
        $certFile = tempnam(sys_get_temp_dir(), 'fiscal_cert_');
        $keyFile  = tempnam(sys_get_temp_dir(), 'fiscal_key_');
        file_put_contents($certFile, $certPem);
        file_put_contents($keyFile, $privateKeyPem);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET        => true,
                CURLOPT_SSLCERT        => $certFile,
                CURLOPT_SSLCERTTYPE    => 'PEM',
                CURLOPT_SSLKEY         => $keyFile,
                CURLOPT_SSLKEYTYPE     => 'PEM',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
        } finally {
            @unlink($certFile);
            @unlink($keyFile);
        }

        return ['http_status' => $status, 'body' => $body, 'error' => $error ?: null];
    }

    /** Pull ResponseCode/Description out of BTS's ApplicationResponse XML (or a plain-text error body). */
    private static function parseApplicationResponse(string $body): array
    {
        if (trim($body) === '') {
            return ['response_code' => null, 'description' => null];
        }
        try {
            $doc = new DOMDocument();
            $normalized = preg_replace('/encoding="utf-16"/i', 'encoding="UTF-8"', $body, 1);
            if (!@$doc->loadXML($normalized)) {
                return ['response_code' => null, 'description' => trim($body)];
            }
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $code = $xpath->evaluate('string(//cac:DocumentResponse/cac:Response/cbc:ResponseCode)');
            $desc = $xpath->evaluate('string(//cac:DocumentResponse/cac:Response/cbc:Description)');
            return ['response_code' => $code ?: null, 'description' => $desc ?: null];
        } catch (Throwable $e) {
            return ['response_code' => null, 'description' => trim($body)];
        }
    }

    private static function describeError(int $httpStatus, ?string $description): string
    {
        $label = match (true) {
            $httpStatus === 400 => 'Rejected (validation rules)',
            $httpStatus === 401 => 'Permission error (invalid certificate or issuer)',
            $httpStatus === 500 => 'BTS internal server error',
            default             => 'Unexpected HTTP status ' . $httpStatus,
        };
        return $description ? ($label . ': ' . $description) : $label;
    }
}
