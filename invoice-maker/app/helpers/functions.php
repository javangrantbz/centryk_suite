<?php

function money($amount)
{
    $symbol = $_SESSION['currency_symbol'] ?? '$';
    return $symbol . number_format((float)$amount, 2);
}

function e($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function active($page)
{
    return ($_GET['page'] ?? 'dashboard') === $page
        ? 'bg-slate-900 text-white'
        : 'text-slate-600 hover:bg-slate-100';
}

/**
 * The business profile shown on documents. Identity (name, email, phones,
 * address, TIN, logo, opening hours) is the single source of truth in the
 * Centryk `companies` table and is read-only here — edited in Centryk via
 * profile.php#companies. Document-styling defaults (logo position, currency,
 * invoice/quote terms) stay app-side in `invoice_settings`.
 */
function inv_business_profile(PDO $pdo, int $companyId): array
{
    $defaults = [
        'company_uuid' => '', 'business_name' => '', 'business_email' => '',
        'business_phone' => '', 'business_phone2' => '', 'business_phone3' => '',
        'business_address' => '', 'business_tax_number' => '', 'business_logo' => '',
        'opening_hours' => '', 'logo_position' => 'left', 'currency_symbol' => '$',
        'invoice_terms' => '', 'quote_terms' => '',
    ];
    if (!$companyId) return $defaults;

    $c = $pdo->prepare("SELECT uuid, name, email, phone, phone2, phone3, address, tax_number, logo, opening_hours
                        FROM companies WHERE id = ?");
    $c->execute([$companyId]);
    $co = $c->fetch(PDO::FETCH_ASSOC) ?: [];

    $s = $pdo->prepare("SELECT logo_position, currency_symbol, invoice_terms, quote_terms
                        FROM invoice_settings WHERE company_id = ?");
    $s->execute([$companyId]);
    $set = $s->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'company_uuid'        => $co['uuid'] ?? '',
        'business_name'       => $co['name'] ?? '',
        'business_email'      => $co['email'] ?? '',
        'business_phone'      => $co['phone'] ?? '',
        'business_phone2'     => $co['phone2'] ?? '',
        'business_phone3'     => $co['phone3'] ?? '',
        'business_address'    => $co['address'] ?? '',
        'business_tax_number' => $co['tax_number'] ?? '',
        'business_logo'       => $co['logo'] ?? '',
        'opening_hours'       => $co['opening_hours'] ?? '',
        'logo_position'       => ($set['logo_position'] ?? '') ?: 'left',
        'currency_symbol'     => ($set['currency_symbol'] ?? '') ?: '$',
        'invoice_terms'       => $set['invoice_terms'] ?? '',
        'quote_terms'         => $set['quote_terms'] ?? '',
    ] + $defaults;
}

/** Non-empty company phone numbers, in order, as a flat list. */
function inv_company_phones(array $business): array
{
    return array_values(array_filter(
        [$business['business_phone'] ?? '', $business['business_phone2'] ?? '', $business['business_phone3'] ?? ''],
        fn($p) => trim((string)$p) !== ''
    ));
}

/** Web URL for a company logo path stored relative to the Centryk public root. */
function inv_company_logo_url(?string $logo): string
{
    $logo = trim((string)$logo);
    if ($logo === '') return '';
    if (preg_match('#^https?://#i', $logo) || $logo[0] === '/') return $logo;
    return rtrim(CENTRYK_BASE, '/') . '/' . ltrim($logo, '/');
}

/** Filesystem path for a company logo (for PDF embedding); null if unreadable/remote. */
function inv_company_logo_path(?string $logo): ?string
{
    $logo = trim((string)$logo);
    if ($logo === '' || preg_match('#^https?://#i', $logo)) return null;
    $path = $logo[0] === '/'
        ? ($_SERVER['DOCUMENT_ROOT'] ?? '') . $logo
        : rtrim(CENTRYK_PUBLIC_DIR, '/\\') . '/' . $logo;
    return is_file($path) ? $path : null;
}
/**
 * True when an invoice is a mirror of a completed OnePay sale rather than a
 * document created here.
 *
 * These are financial records of money that actually changed hands at a POS
 * terminal. Deleting one from this app would not touch OnePay's own `sales`
 * row, so the two systems would silently disagree about what was sold — with
 * no trace left behind of the receipt that vanished. Corrections belong in
 * OnePay (void/refund the sale), which then flows back through the bridge.
 */
function inv_is_pos_receipt(array $invoice): bool
{
    return ($invoice['source_app'] ?? '') === 'onepay'
        && strpos((string)($invoice['source_ref'] ?? ''), 'sale:') === 0;
}

/** Guard message for a blocked POS-receipt deletion. */
function inv_pos_receipt_delete_message(): string
{
    return 'This is a POS receipt from a completed OnePay sale and cannot be deleted here. '
         . 'Void or refund the sale in OnePay instead.';
}
