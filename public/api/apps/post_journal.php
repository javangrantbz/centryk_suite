<?php
/**
 * Server-to-server endpoint: post a journal entry into a Centryk company's
 * general ledger. Called by spoke apps — MyPay (payroll journals) and OnePay
 * (daily sales journals) — so their activity lands on the books the same way
 * the hub's own subledgers do.
 *
 * Requires the shared PROVISION_SECRET (same secret as provision_user.php etc.).
 *
 * Expected request:
 *   POST /api/apps/post_journal.php
 *   Body: {
 *     "provision_secret": "<shared secret>",
 *     "company_uuid":     "<company uuid>",
 *     "date":             "2026-08-31",
 *     "memo":             "Payroll — August 2026",
 *     "source":           "payroll" | "pos",
 *     "source_ref":       "run:482",                 // idempotency key within source
 *     "lines": [
 *       { "account_code": "6000", "debit": 12500.00, "credit": 0, "memo": "Gross wages" },
 *       { "slot": "paye_payable", "debit": 0, "credit": 1875.00 },
 *       ...
 *     ]
 *   }
 *
 * Behaviour:
 *   - accounting not set up for the company        -> { success:true, posted:false, reason:"accounting_disabled" }
 *   - a journal for this source+source_ref exists   -> { success:true, posted:false, reason:"already_posted", journal_id }
 *   - otherwise posts and returns                   -> { success:true, posted:true, journal_id }
 *
 * The entry must balance to the cent; unbalanced or unresolvable requests get a 422.
 */
require_once __DIR__ . '/../../../app/core/DB.php';
require_once __DIR__ . '/../../../app/core/Response.php';
require_once __DIR__ . '/../../../app/core/Ledger.php';
require_once __DIR__ . '/../../../app/core/Audit.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::error('Method not allowed.', 405);
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$secret = trim((string)($body['provision_secret'] ?? ''));

$expected = $_ENV['PROVISION_SECRET'] ?? '';
if ($expected === '' || !hash_equals($expected, $secret)) {
    Response::error('Unauthorized.', 401);
}

$companyUuid = trim((string)($body['company_uuid'] ?? ''));
$date        = trim((string)($body['date'] ?? ''));
$memo        = trim((string)($body['memo'] ?? ''));
$source      = trim((string)($body['source'] ?? ''));
$sourceRef   = trim((string)($body['source_ref'] ?? ''));
$lines       = $body['lines'] ?? [];

if ($companyUuid === '') {
    Response::error('company_uuid is required.', 422);
}
if (!in_array($source, ['payroll', 'pos'], true)) {
    Response::error('source must be "payroll" or "pos".', 422);
}
if ($sourceRef === '') {
    Response::error('source_ref is required (used as the idempotency key).', 422);
}
if (!is_array($lines) || count($lines) < 2) {
    Response::error('lines must hold at least two entries.', 422);
}

$pdo = DB::pdo();

$cs = $pdo->prepare('SELECT id, name FROM companies WHERE uuid = :u AND status = "active" LIMIT 1');
$cs->execute(['u' => $companyUuid]);
$company = $cs->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    Response::error('Unknown company.', 404);
}
$companyId = (int)$company['id'];

if (!Ledger::isActivated($companyId)) {
    Response::json(['success' => true, 'posted' => false, 'reason' => 'accounting_disabled']);
}

// Idempotency.
$dup = $pdo->prepare(
    "SELECT id FROM gl_journals WHERE company_id = :c AND source = :s AND source_ref = :r AND status <> 'void' LIMIT 1"
);
$dup->execute(['c' => $companyId, 's' => $source, 'r' => $sourceRef]);
if ($existing = (int)($dup->fetchColumn() ?: 0)) {
    Response::json(['success' => true, 'posted' => false, 'reason' => 'already_posted', 'journal_id' => $existing]);
}

// Resolve account_code -> account_id up front so we can report every miss at once.
$codes = [];
foreach ($lines as $l) {
    if (!empty($l['account_code'])) {
        $codes[(string)$l['account_code']] = true;
    }
}
$codeToId = [];
if ($codes) {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $q = $pdo->prepare("SELECT id, code FROM gl_accounts WHERE company_id = ? AND code IN ($in)");
    $q->execute(array_merge([$companyId], array_keys($codes)));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $codeToId[(string)$r['code']] = (int)$r['id'];
    }
    $missing = array_values(array_diff(array_keys($codes), array_keys($codeToId)));
    if ($missing) {
        Response::error('These account codes are not in the company chart: ' . implode(', ', $missing), 422);
    }
}

$postLines = [];
foreach ($lines as $i => $l) {
    $line = [
        'debit'  => (float)($l['debit'] ?? 0),
        'credit' => (float)($l['credit'] ?? 0),
        'memo'   => (string)($l['memo'] ?? ''),
    ];
    if (!empty($l['slot'])) {
        $line['slot'] = (string)$l['slot'];
    } elseif (!empty($l['account_code'])) {
        $line['account_id'] = $codeToId[(string)$l['account_code']];
    } else {
        Response::error('Line ' . ($i + 1) . ' needs an account_code or a slot.', 422);
    }
    $postLines[] = $line;
}

try {
    $journalId = Ledger::post($companyId, [
        'date'       => $date,
        'memo'       => $memo !== '' ? $memo : ucfirst($source) . ' journal',
        'source'     => $source,
        'source_ref' => $sourceRef,
        'system'     => true,
        'lines'      => $postLines,
    ]);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 422);
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::error('Could not post the journal.', 500);
}

Audit::log([
    'actor_user_id' => null,
    'company_id'    => $companyId,
    'event_type'    => 'accounting.journal.posted_s2s',
    'summary'       => ucfirst($source) . ' journal #' . $journalId . ' posted from a spoke (' . $sourceRef . ')',
    'metadata'      => ['journal_id' => $journalId, 'source' => $source, 'source_ref' => $sourceRef],
]);

Response::json(['success' => true, 'posted' => true, 'journal_id' => $journalId]);
