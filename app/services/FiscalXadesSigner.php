<?php

/**
 * Belize BTS e-invoicing — XAdES-EPES signing of a raw UBL document.
 *
 * Structure and algorithm transcribed from BTS's own C# reference
 * (gitignore/bts docs/supplementary documents/resources/UBLXadesSigner.cs)
 * and the integration slide deck (steps 8-9): an enveloped XMLDSig signature
 * (RSA-SHA256, non-exclusive C14N) over the whole document, plus a second
 * signed Reference over an XAdES SignedProperties block (signing time,
 * signing-certificate digest, and a *fixed* signature policy URL + SHA-256
 * hash - the same on every document BTS's own samples produce).
 *
 * Where the C# reference does 3 passes of SignedXml.ComputeSignature() to
 * work around a quirk of .NET's SignedXml (Reference digests must be
 * computed before the Signature element exists in the target document, then
 * the fully-formed Signature is spliced in afterward), this implementation
 * gets the same result more directly using DOMNode::C14N(), which correctly
 * accounts for ancestor namespace context when canonicalizing a node already
 * in its final tree position:
 *
 *   1. Build the *complete* Signature element (SignedInfo with both
 *      References but empty DigestValues, KeyInfo, and the Object/
 *      QualifyingProperties/SignedProperties already fully populated) and
 *      insert it into the document at its final position.
 *   2. Reference 0 (URI=""): clone the whole document, remove the Signature
 *      element from the clone (the enveloped-signature transform), C14N the
 *      clone, SHA-256 it.
 *   3. Reference 1 (#signedprops): C14N the real, in-tree SignedProperties
 *      node directly (not a standalone copy - ancestor xmlns declarations
 *      must be in scope, which is exactly why non-exclusive C14N is used
 *      for an enveloped signature).
 *   4. Fill in both DigestValue elements (now known) in the real tree.
 *   5. C14N the real, in-tree SignedInfo node (digests now correct) and
 *      RSA-SHA256-sign those canonical bytes with the certificate's private
 *      key -> SignatureValue.
 *
 * Self-consistency is checked by verify() (recomputes both digests and the
 * RSA signature independently) - exercised in FiscalXadesSignerTest against
 * a locally-generated test certificate, since no company has a real BTS
 * certificate yet to test against the live service.
 */
class FiscalXadesSigner
{
    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';
    private const NS_SIG = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';
    private const NS_SAC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';
    private const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private const C14N_ALGORITHM = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private const SIG_METHOD = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    private const DIGEST_METHOD = 'http://www.w3.org/2001/04/xmlenc#sha256';
    private const ENVELOPED_TRANSFORM = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    // Fixed for every document (manual/samples/PPTX all agree): BTS's own
    // subscription policy document and its SHA-256 hash.
    private const SIG_POLICY_URL = 'https://efdrshare.blob.core.windows.net/documentation/bz/sigPolicyv1.pdf';
    private const SIG_POLICY_SHA256_HEX = '9395410178e7fe09ec72df1da1c55456decd8fd43f87df22216206b152ef1a38';

    /**
     * @param string $xml Raw UBL XML from FiscalUblBuilder (declares
     *              encoding="utf-16" but is actually UTF-8 bytes - see
     *              FiscalUblBuilder::finalize()).
     * @param string $certPem PEM-encoded X.509 certificate (the public part).
     * @param OpenSSLAsymmetricKey|resource $privateKey As returned by openssl_pkey_get_private().
     */
    public static function sign(string $xml, string $certPem, $privateKey): string
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        $loaded = $doc->loadXML(self::forParsing($xml));
        if (!$loaded) {
            throw new RuntimeException('FiscalXadesSigner: input XML did not parse.');
        }

        $guid = self::guidv4();
        $signatureId = 'xmldsig-' . $guid . '-sigvalue';
        $referenceId = 'xmldsig-' . $guid;
        $signedPropsId = 'xmldsig-' . $guid . '-signedprops';
        $keyInfoId = 'xmldsig-' . $guid . '-keyinfo';
        $signingTime = gmdate('Y-m-d\TH:i:s\Z');

        $certInfo = self::certInfo($certPem);

        // ── Locate the (already EFDRExtensions-only) UBLExtensions and add a
        // second, sibling UBLExtension for the signature - matching every
        // BTS sample: two UBLExtension children of UBLExtensions. ──────────
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ext', self::NS_EXT);
        $extensionsNode = $xpath->query('//ext:UBLExtensions')->item(0);
        if (!$extensionsNode) {
            throw new RuntimeException('FiscalXadesSigner: document has no ext:UBLExtensions to attach the signature to.');
        }

        $sigExtension = $doc->createElementNS(self::NS_EXT, 'ext:UBLExtension');
        $extensionsNode->appendChild($sigExtension);
        $sigContent = $doc->createElementNS(self::NS_EXT, 'ext:ExtensionContent');
        $sigExtension->appendChild($sigContent);
        $ublDocSignatures = $doc->createElementNS(self::NS_SIG, 'sig:UBLDocumentSignatures');
        $sigContent->appendChild($ublDocSignatures);
        $signatureInformation = $doc->createElementNS(self::NS_SAC, 'sac:SignatureInformation');
        $ublDocSignatures->appendChild($signatureInformation);

        // ── Signature / SignedInfo (digests filled in after step 2-3) ────
        $signature = $doc->createElementNS(self::NS_DS, 'Signature');
        $signature->setAttribute('Id', $signatureId);
        $signatureInformation->appendChild($signature);

        $signedInfo = $doc->createElementNS(self::NS_DS, 'SignedInfo');
        $signature->appendChild($signedInfo);
        $c14n = $doc->createElementNS(self::NS_DS, 'CanonicalizationMethod');
        $c14n->setAttribute('Algorithm', self::C14N_ALGORITHM);
        $signedInfo->appendChild($c14n);
        $sigMethod = $doc->createElementNS(self::NS_DS, 'SignatureMethod');
        $sigMethod->setAttribute('Algorithm', self::SIG_METHOD);
        $signedInfo->appendChild($sigMethod);

        $ref0 = $doc->createElementNS(self::NS_DS, 'Reference');
        $ref0->setAttribute('Id', $referenceId);
        $ref0->setAttribute('URI', '');
        $signedInfo->appendChild($ref0);
        $transforms = $doc->createElementNS(self::NS_DS, 'Transforms');
        $ref0->appendChild($transforms);
        $t1 = $doc->createElementNS(self::NS_DS, 'Transform');
        $t1->setAttribute('Algorithm', self::ENVELOPED_TRANSFORM);
        $transforms->appendChild($t1);
        $t2 = $doc->createElementNS(self::NS_DS, 'Transform');
        $t2->setAttribute('Algorithm', self::C14N_ALGORITHM);
        $transforms->appendChild($t2);
        $dm0 = $doc->createElementNS(self::NS_DS, 'DigestMethod');
        $dm0->setAttribute('Algorithm', self::DIGEST_METHOD);
        $ref0->appendChild($dm0);
        $dv0 = $doc->createElementNS(self::NS_DS, 'DigestValue');
        $ref0->appendChild($dv0);

        $ref1 = $doc->createElementNS(self::NS_DS, 'Reference');
        $ref1->setAttribute('URI', '#' . $signedPropsId);
        $ref1->setAttribute('Type', 'http://uri.etsi.org/01903#SignedProperties');
        $signedInfo->appendChild($ref1);
        $dm1 = $doc->createElementNS(self::NS_DS, 'DigestMethod');
        $dm1->setAttribute('Algorithm', self::DIGEST_METHOD);
        $ref1->appendChild($dm1);
        $dv1 = $doc->createElementNS(self::NS_DS, 'DigestValue');
        $ref1->appendChild($dv1);

        $sigValueEl = $doc->createElementNS(self::NS_DS, 'SignatureValue');
        $signature->appendChild($sigValueEl);

        // ── KeyInfo ───────────────────────────────────────────────────────
        $keyInfo = $doc->createElementNS(self::NS_DS, 'KeyInfo');
        $keyInfo->setAttribute('Id', $keyInfoId);
        $signature->appendChild($keyInfo);
        $x509Data = $doc->createElementNS(self::NS_DS, 'X509Data');
        $keyInfo->appendChild($x509Data);
        $issuerSerial = $doc->createElementNS(self::NS_DS, 'X509IssuerSerial');
        $x509Data->appendChild($issuerSerial);
        $issuerSerial->appendChild($doc->createElementNS(self::NS_DS, 'X509IssuerName', htmlspecialchars($certInfo['issuer_dn'], ENT_XML1)));
        $issuerSerial->appendChild($doc->createElementNS(self::NS_DS, 'X509SerialNumber', $certInfo['serial_decimal']));
        $x509Data->appendChild($doc->createElementNS(self::NS_DS, 'X509SubjectName', htmlspecialchars($certInfo['subject_dn'], ENT_XML1)));
        $x509Data->appendChild($doc->createElementNS(self::NS_DS, 'X509Certificate', $certInfo['der_base64']));

        // ── Object / XAdES QualifyingProperties / SignedProperties ───────
        $object = $doc->createElementNS(self::NS_DS, 'Object');
        $signature->appendChild($object);
        $qualifyingProps = $doc->createElementNS(self::NS_XADES, 'xades:QualifyingProperties');
        $qualifyingProps->setAttribute('Target', '#' . $signatureId);
        $object->appendChild($qualifyingProps);
        $signedProps = $doc->createElementNS(self::NS_XADES, 'xades:SignedProperties');
        $signedProps->setAttribute('Id', $signedPropsId);
        $qualifyingProps->appendChild($signedProps);
        $signedSigProps = $doc->createElementNS(self::NS_XADES, 'xades:SignedSignatureProperties');
        $signedProps->appendChild($signedSigProps);

        $signedSigProps->appendChild($doc->createElementNS(self::NS_XADES, 'xades:SigningTime', $signingTime));

        $signingCert = $doc->createElementNS(self::NS_XADES, 'xades:SigningCertificate');
        $signedSigProps->appendChild($signingCert);
        $cert = $doc->createElementNS(self::NS_XADES, 'xades:Cert');
        $signingCert->appendChild($cert);
        $certDigest = $doc->createElementNS(self::NS_XADES, 'xades:CertDigest');
        $cert->appendChild($certDigest);
        $certDigestMethod = $doc->createElementNS(self::NS_DS, 'ds:DigestMethod');
        $certDigestMethod->setAttribute('Algorithm', self::DIGEST_METHOD);
        $certDigest->appendChild($certDigestMethod);
        $certDigest->appendChild($doc->createElementNS(self::NS_DS, 'ds:DigestValue', base64_encode(hash('sha256', $certInfo['der'], true))));
        $certIssuerSerial = $doc->createElementNS(self::NS_XADES, 'xades:IssuerSerial');
        $cert->appendChild($certIssuerSerial);
        $certIssuerSerial->appendChild($doc->createElementNS(self::NS_DS, 'ds:X509IssuerName', htmlspecialchars($certInfo['issuer_dn'], ENT_XML1)));
        $certIssuerSerial->appendChild($doc->createElementNS(self::NS_DS, 'ds:X509SerialNumber', $certInfo['serial_decimal']));

        $policyIdentifier = $doc->createElementNS(self::NS_XADES, 'xades:SignaturePolicyIdentifier');
        $signedSigProps->appendChild($policyIdentifier);
        $policyId = $doc->createElementNS(self::NS_XADES, 'xades:SignaturePolicyId');
        $policyIdentifier->appendChild($policyId);
        $sigPolicyId = $doc->createElementNS(self::NS_XADES, 'xades:SigPolicyId');
        $policyId->appendChild($sigPolicyId);
        $sigPolicyId->appendChild($doc->createElementNS(self::NS_XADES, 'xades:Identifier', self::SIG_POLICY_URL));
        $sigPolicyHash = $doc->createElementNS(self::NS_XADES, 'xades:SigPolicyHash');
        $policyId->appendChild($sigPolicyHash);
        $policyDigestMethod = $doc->createElementNS(self::NS_DS, 'ds:DigestMethod');
        $policyDigestMethod->setAttribute('Algorithm', self::DIGEST_METHOD);
        $sigPolicyHash->appendChild($policyDigestMethod);
        $sigPolicyHash->appendChild($doc->createElementNS(self::NS_DS, 'ds:DigestValue', base64_encode(hex2bin(self::SIG_POLICY_SHA256_HEX))));

        // ── Step 2: Reference[0] digest (enveloped - strip Signature from a clone) ──
        $clone = $doc->cloneNode(true);
        $cloneXpath = new DOMXPath($clone);
        $cloneXpath->registerNamespace('ds', self::NS_DS);
        foreach (iterator_to_array($cloneXpath->query('//ds:Signature')) as $sigNode) {
            $sigNode->parentNode->removeChild($sigNode);
        }
        $wholeDocCanonical = $clone->C14N(false, false);
        $dv0->nodeValue = base64_encode(hash('sha256', $wholeDocCanonical, true));

        // ── Step 3: Reference[1] digest (the real, in-tree SignedProperties node) ──
        $signedPropsCanonical = $signedProps->C14N(false, false);
        $dv1->nodeValue = base64_encode(hash('sha256', $signedPropsCanonical, true));

        // ── Step 5: sign the real, in-tree SignedInfo (digests now correct) ──
        $signedInfoCanonical = $signedInfo->C14N(false, false);
        $ok = openssl_sign($signedInfoCanonical, $signatureBytes, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('FiscalXadesSigner: openssl_sign failed - ' . (openssl_error_string() ?: 'unknown error'));
        }
        $sigValueEl->nodeValue = base64_encode($signatureBytes);

        return self::forStorage($doc->saveXML());
    }

    /**
     * Independently re-verifies a signature this class produced: recomputes
     * both Reference digests and checks the RSA signature against the
     * certificate embedded in the document - the same checks BTS's own
     * server performs, minus certificate-chain/revocation checking (that
     * needs BTS's CA, which we don't have outside their real environment).
     */
    public static function verify(string $signedXml): bool
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        if (!$doc->loadXML(self::forParsing($signedXml))) {
            return false;
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ds', self::NS_DS);
        $xpath->registerNamespace('xades', self::NS_XADES);

        $signatureNode = $xpath->query('//ds:Signature')->item(0);
        $signedInfoNode = $xpath->query('.//ds:SignedInfo', $signatureNode)->item(0);
        $signedPropsNode = $xpath->query('.//xades:SignedProperties', $signatureNode)->item(0);
        $certB64 = $xpath->query('.//ds:X509Certificate', $signatureNode)->item(0)?->nodeValue;
        $sigValueB64 = $xpath->query('.//ds:SignatureValue', $signatureNode)->item(0)?->nodeValue;
        $refs = $xpath->query('.//ds:Reference', $signedInfoNode);
        if (!$signatureNode || !$signedInfoNode || !$signedPropsNode || !$certB64 || !$sigValueB64 || $refs->length !== 2) {
            return false;
        }

        // Reference[0]: whole document, signature stripped.
        $clone = $doc->cloneNode(true);
        $cloneXpath = new DOMXPath($clone);
        $cloneXpath->registerNamespace('ds', self::NS_DS);
        foreach (iterator_to_array($cloneXpath->query('//ds:Signature')) as $sigNode) {
            $sigNode->parentNode->removeChild($sigNode);
        }
        $expectedDigest0 = base64_encode(hash('sha256', $clone->C14N(false, false), true));
        $actualDigest0 = $xpath->query('.//ds:DigestValue', $refs->item(0))->item(0)->nodeValue;
        if (!hash_equals($expectedDigest0, $actualDigest0)) {
            return false;
        }

        // Reference[1]: SignedProperties, in its real tree position.
        $expectedDigest1 = base64_encode(hash('sha256', $signedPropsNode->C14N(false, false), true));
        $actualDigest1 = $xpath->query('.//ds:DigestValue', $refs->item(1))->item(0)->nodeValue;
        if (!hash_equals($expectedDigest1, $actualDigest1)) {
            return false;
        }

        // SignatureValue: RSA-SHA256 over the canonicalized SignedInfo.
        $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($certB64, 64) . "-----END CERTIFICATE-----\n";
        $publicKey = openssl_pkey_get_public($cert);
        if ($publicKey === false) {
            return false;
        }
        $result = openssl_verify($signedInfoNode->C14N(false, false), base64_decode($sigValueB64), $publicKey, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /** Certificate details needed for KeyInfo/XAdES, from a PEM certificate. */
    private static function certInfo(string $certPem): array
    {
        $parsed = openssl_x509_parse($certPem);
        if ($parsed === false) {
            throw new InvalidArgumentException('Could not parse the signing certificate.');
        }
        // DER = the base64 body of the PEM, decoded to raw bytes.
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certPem);
        $der = base64_decode($body);

        return [
            'der'            => $der,
            'der_base64'     => base64_encode($der),
            'issuer_dn'      => self::dnString($parsed['issuer'] ?? []),
            'subject_dn'     => self::dnString($parsed['subject'] ?? []),
            'serial_decimal' => (string)($parsed['serialNumber'] ?? '0'),
        ];
    }

    /** A simple "CN=...,O=...,C=..." rendering - not integrity-protected, informational only (see class doc). */
    private static function dnString(array $parts): string
    {
        $order = ['CN', 'O', 'OU', 'C', 'ST', 'L'];
        $bits = [];
        foreach ($order as $key) {
            if (isset($parts[$key]) && $parts[$key] !== '') {
                $bits[] = $key . '=' . $parts[$key];
            }
        }
        return implode(', ', $bits);
    }

    /** loadXML() needs an encoding it actually understands; see FiscalUblBuilder::finalize(). */
    private static function forParsing(string $xml): string
    {
        return preg_replace('/encoding="utf-16"/', 'encoding="UTF-8"', $xml, 1);
    }

    /** Restore the utf-16 declaration BTS's own samples use before storing/transmitting. */
    private static function forStorage(string $xml): string
    {
        return preg_replace('/encoding="UTF-8"/', 'encoding="utf-16"', $xml, 1);
    }

    private static function guidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
