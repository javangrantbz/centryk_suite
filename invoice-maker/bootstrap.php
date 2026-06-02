<?php
/**
 * Shared bootstrap for the Invoices app integrated into Centryk.
 * Authenticates via Centryk's session (no separate login) and scopes all data
 * to the active company. Entry files (index, api/*, pdf-*, download) require
 * this instead of doing their own session_start + config/db includes.
 */
$__cw = dirname(__DIR__); // centryk/
require_once $__cw . '/app/core/Auth.php';
require_once $__cw . '/app/core/DB.php';

require_once __DIR__ . '/app/config/app.php';      // APP_NAME, BASE_URL, CENTRYK_BASE
require_once __DIR__ . '/app/helpers/auth.php';
require_once __DIR__ . '/app/helpers/functions.php';
require_once __DIR__ . '/app/helpers/response.php';

Auth::start();
$__cu = Auth::user();
if (!$__cu) {
    header('Location: ' . CENTRYK_BASE . '/login.php');
    exit;
}

// Centryk DB connection used by all invoice queries.
$pdo = DB::pdo();

// Bridge to the invoice app's expectation of $_SESSION['user'].
$_SESSION['user'] = [
    'id'    => (int)$__cu['id'],
    'name'  => trim(($__cu['first_name'] ?? '') . ' ' . ($__cu['last_name'] ?? '')),
    'email' => $__cu['email'] ?? '',
];

// Resolve the active company — the scope for every invoice/customer/quote.
$GLOBALS['INV_COMPANY_ID'] = inv_resolve_company($pdo, (int)$__cu['id']);

// Currency for money() comes from the company's invoice settings.
$_SESSION['currency_symbol'] = '$';
if ($GLOBALS['INV_COMPANY_ID']) {
    $__st = $pdo->prepare("SELECT currency_symbol FROM invoice_settings WHERE company_id = ?");
    $__st->execute([$GLOBALS['INV_COMPANY_ID']]);
    $_SESSION['currency_symbol'] = $__st->fetchColumn() ?: '$';
}

function current_company_id(): int
{
    return (int)($GLOBALS['INV_COMPANY_ID'] ?? 0);
}

/**
 * Active company for the user, priority: ?company_id / ?company_uuid (e.g. when
 * launched from Centryk) > remembered session choice > first membership.
 * The chosen company is remembered in the session so navigation within the
 * invoice app keeps the same scope.
 */
function inv_resolve_company(PDO $pdo, int $uid): int
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.uuid
         FROM companies c
         JOIN company_members cm ON cm.company_id = c.id
         WHERE cm.user_id = :u AND cm.status = 'active' AND c.status = 'active'
         ORDER BY c.name"
    );
    $stmt->execute(['u' => $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { $_SESSION['inv_company_id'] = 0; return 0; }

    $cid  = isset($_GET['company_id'])   ? (int)$_GET['company_id'] : 0;
    $uuid = isset($_GET['company_uuid']) ? trim((string)$_GET['company_uuid']) : '';
    $sess = (int)($_SESSION['inv_company_id'] ?? 0);

    $chosen = (int)$rows[0]['id'];
    if ($cid)        { foreach ($rows as $r) if ((int)$r['id'] === $cid)  { $chosen = $cid; break; } }
    elseif ($uuid !== '') { foreach ($rows as $r) if ($r['uuid'] === $uuid) { $chosen = (int)$r['id']; break; } }
    elseif ($sess)   { foreach ($rows as $r) if ((int)$r['id'] === $sess) { $chosen = $sess; break; } }

    $_SESSION['inv_company_id'] = $chosen;
    return $chosen;
}
