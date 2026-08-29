<?php
/**
 * One-off LOCAL setup: give a couple of companies full Centryk Business access
 * plus enough sample data (customers, invoices, receipts, a company group) to
 * exercise every module when logging in on localhost.
 *
 *   php scripts/setup_business_demo.php
 *
 * Idempotent — re-running only fills gaps. Do NOT run against production.
 */
require __DIR__ . '/../app/core/Env.php';
require __DIR__ . '/../app/core/DB.php';
require __DIR__ . '/../app/core/Entitlements.php';
require __DIR__ . '/../app/services/ReceivablesService.php';
require __DIR__ . '/../app/services/GroupsService.php';

Env::load(__DIR__ . '/../.env');
if (Env::isProduction()) {
    fwrite(STDERR, "Refusing to run in production — this seeds demo customers.\n");
    exit(1);
}

$pdo = DB::pdo();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName !== 'centryk_core') {
    fwrite(STDERR, "Refusing to run: expected centryk_core, got {$dbName}\n");
    exit(1);
}

$PACKAGES = ['receivables', 'reconciliation', 'routes', 'enterprise'];

/* ── who gets what ─────────────────────────────────────────────────────── */
// company_id => [ admin user id to use as the "actor", label ]
$TARGETS = [
    1 => ['actor' => 1, 'label' => 'J Bells Grocery'],   // webdevelopment@bhilimited.com
    3 => ['actor' => 3, 'label' => 'Miss Bella Shop'],    // javangrant.ai@gmail.com
];

function say(string $s): void { echo $s . "\n"; }

/* ── grant the packages (real subscription + entitlement, like the console) ── */
$prices = [];
foreach ($pdo->query("SELECT `key`, monthly_price, currency FROM business_packages")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $prices[$r['key']] = ['price' => (float)$r['monthly_price'], 'currency' => $r['currency'] ?: 'BZD'];
}

foreach ($TARGETS as $cid => $t) {
    $co = $pdo->prepare('SELECT id, name FROM companies WHERE id = :id LIMIT 1');
    $co->execute(['id' => $cid]);
    if (!$co->fetch()) { say("skip company #{$cid} (not found)"); continue; }

    foreach ($PACKAGES as $pkg) {
        // an open subscription already? then it's set up
        $has = $pdo->prepare("SELECT id FROM company_subscriptions WHERE company_id=:c AND package_key=:k AND status IN ('trialing','active','past_due','paused') LIMIT 1");
        $has->execute(['c' => $cid, 'k' => $pkg]);
        if ($has->fetch()) { continue; }

        $pr = $prices[$pkg] ?? ['price' => 0, 'currency' => 'BZD'];
        $pdo->prepare("
            INSERT INTO company_subscriptions
                (company_id, package_key, status, price, currency, billing_interval, current_period_start, current_period_end, contract_ref, created_by)
            VALUES (:c, :k, 'active', :price, :cur, 'monthly', :ps, :pe, 'DEMO', :by)
        ")->execute([
            'c' => $cid, 'k' => $pkg, 'price' => $pr['price'], 'cur' => $pr['currency'],
            'ps' => date('Y-m-01'), 'pe' => date('Y-m-t'), 'by' => $t['actor'],
        ]);
        $subId = (int)$pdo->lastInsertId();
        Entitlements::grant($cid, $pkg, $subId, $t['actor'], 'admin_grant', null, 'demo setup');
    }
    say("granted all 4 packages to #{$cid} {$t['label']} (with subscriptions)");
}

/* ── make javangrantbz@gmail.com (user 9) a manager of Miss Bella Shop ── */
$u9 = $pdo->query("SELECT id FROM users WHERE email = 'javangrantbz@gmail.com' LIMIT 1")->fetchColumn();
if ($u9) {
    $pdo->prepare("
        INSERT INTO company_members (company_id, user_id, role, status) VALUES (3, :u, 'manager', 'active')
        ON DUPLICATE KEY UPDATE role = 'manager', status = 'active'
    ")->execute(['u' => $u9]);
    say("user #{$u9} (javangrantbz@gmail.com) is now a manager of Miss Bella Shop");
}

/* ── sample customers + invoices for each target company ──────────────── */
$CUSTOMERS = [
    ['Corozal Cash & Carry',    'accounts@corozalcc.bz',   75000, 30, 0],
    ['San Pedro Provisions',    'ap@sanpedroprov.bz',      12000,  7, 0],
    ['Belmopan Wholesale Ltd',  'finance@belmopanws.bz',  120000, 30, 0],
    ['Orange Walk Distributors', 'owd.accounts@mail.bz',   90000, 30, 0],
    ['Dangriga Trading Post',   'dtp@mail.bz',             15000, 15, 0],
    ['Placencia Mini Mart',     'placenciamm@mail.bz',      8000,  7, 1],  // on hold
];

foreach ($TARGETS as $cid => $t) {
    // marker: skip seeding if this company already has a demo customer
    $mk = $pdo->prepare("SELECT id FROM customers WHERE company_id = :c AND name = 'Corozal Cash & Carry' LIMIT 1");
    $mk->execute(['c' => $cid]);
    if ($mk->fetch()) { say("#{$cid} already seeded — skipping sample data"); continue; }

    $custIds = [];
    foreach ($CUSTOMERS as [$name, $email, $limit, $terms, $hold]) {
        $pdo->prepare("
            INSERT INTO customers (company_id, name, email, credit_limit, payment_terms_days, on_hold, opening_balance, created_by)
            VALUES (:c, :n, :e, :l, :t, :h, 0, :by)
        ")->execute(['c' => $cid, 'n' => $name, 'e' => $email, 'l' => $limit, 't' => $terms, 'h' => $hold, 'by' => $t['actor']]);
        $custIds[$name] = (int)$pdo->lastInsertId();
    }

    // invoices: id => [customer, days-ago issued, total, paid?]
    $INVOICES = [
        ['Corozal Cash & Carry',    88, 14200.00, true],
        ['Corozal Cash & Carry',    41,  9800.00, false],
        ['Corozal Cash & Carry',    12,  6450.00, false],
        ['San Pedro Provisions',    30,  2100.00, false],   // overdue (net 7)
        ['San Pedro Provisions',     4,  1180.00, false],
        ['Belmopan Wholesale Ltd',  73, 22400.00, true],
        ['Belmopan Wholesale Ltd',  35, 18220.00, false],
        ['Belmopan Wholesale Ltd',   9,  7500.00, false],
        ['Orange Walk Distributors', 66, 12000.00, 'part'], // partially paid
        ['Orange Walk Distributors', 20,  5240.00, false],
        ['Dangriga Trading Post',   52,  3400.00, false],   // overdue (net 15)
        ['Dangriga Trading Post',    6,   980.00, false],
        ['Placencia Mini Mart',     44,  2600.00, false],   // overdue + on hold
        ['Placencia Mini Mart',     18,  1810.00, false],
    ];

    $n = 0;
    foreach ($INVOICES as [$cust, $daysAgo, $total, $paid]) {
        $n++;
        $issue = (new DateTime("-{$daysAgo} days"))->format('Y-m-d');
        $terms = array_values(array_filter($CUSTOMERS, fn($x) => $x[0] === $cust))[0][3];
        $due   = (new DateTime("-{$daysAgo} days"))->modify("+{$terms} days")->format('Y-m-d');
        $num   = sprintf('INV-%d-%04d', $cid, 2400 + $n);

        $amountPaid = 0.0;
        $status = 'sent';
        if ($paid === true)  { $amountPaid = $total; $status = 'paid'; }
        if ($paid === 'part') { $amountPaid = round($total * 0.45, 2); }

        $pdo->prepare("
            INSERT INTO invoices (company_id, customer_id, invoice_number, status, issue_date, due_date, subtotal, tax, discount, total, amount_paid, created_by)
            VALUES (:c, :cust, :num, :st, :iss, :due, :sub, 0, 0, :tot, :ap, :by)
        ")->execute([
            'c' => $cid, 'cust' => $custIds[$cust], 'num' => $num, 'st' => $status,
            'iss' => $issue, 'due' => $due, 'sub' => $total, 'tot' => $total, 'ap' => $amountPaid, 'by' => $t['actor'],
        ]);
    }

    // a couple of account-level receipts (routed through the ledger)
    ReceivablesService::recordPayment($cid, $custIds['Corozal Cash & Carry'], [
        'amount' => 5000, 'method' => 'bank_transfer', 'received_on' => (new DateTime('-3 days'))->format('Y-m-d'),
        'reference' => 'TRF-0091', 'notes' => 'demo',
    ], $t['actor']);
    ReceivablesService::recordPayment($cid, $custIds['Dangriga Trading Post'], [
        'amount' => 1000, 'method' => 'cash', 'received_on' => (new DateTime('-1 day'))->format('Y-m-d'),
    ], $t['actor']);

    say("#{$cid} {$t['label']}: seeded 6 customers, 14 invoices, 2 receipts");
}

/* ── delivery routes + a settlement awaiting approval ─────────────────── */
require_once __DIR__ . '/../app/services/RoutesService.php';
foreach ($TARGETS as $cid => $t) {
    $mk = $pdo->prepare("SELECT id FROM routes WHERE company_id = :c AND name = 'Northern Distribution' LIMIT 1");
    $mk->execute(['c' => $cid]);
    if ($mk->fetch()) { say("#{$cid} already has routes — skipping"); continue; }

    // customer ids by name for this company
    $cust = [];
    $cs = $pdo->prepare("SELECT id, name FROM customers WHERE company_id = :c");
    $cs->execute(['c' => $cid]);
    foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $r) { $cust[$r['name']] = (int)$r['id']; }

    $routeId = RoutesService::saveRoute($cid, ['name' => 'Northern Distribution', 'default_driver_name' => 'Marlon Cruz'], $t['actor']);

    // Trip A — ran yesterday, cash declared, now awaiting an admin's approval
    $tripA = RoutesService::createTrip($cid, $routeId, (new DateTime('-1 day'))->format('Y-m-d'), 'Marlon Cruz', $t['actor']);
    foreach ([['Corozal Cash & Carry', 1800.00], ['San Pedro Provisions', 950.00], ['Orange Walk Distributors', 1600.00]] as [$name, $amt]) {
        if (!isset($cust[$name])) { continue; }
        $sid = RoutesService::addStop($cid, $tripA, $cust[$name], $t['actor']);
        RoutesService::recordStop($cid, $sid, ['status' => 'paid', 'amount_collected' => $amt, 'method' => 'cash'], $t['actor']);
    }
    RoutesService::setTripStatus($cid, $tripA, 'out', $t['actor']);
    RoutesService::submitSettlement($cid, $tripA, 4300.00, 'Short BZD 50 — customer paid partial', $t['actor']); // expected 4350, variance -50

    // Trip B — out on the road today, cash still in transit
    $tripB = RoutesService::createTrip($cid, $routeId, date('Y-m-d'), 'Marlon Cruz', $t['actor']);
    foreach ([['Belmopan Wholesale Ltd', 2200.00], ['Dangriga Trading Post', 780.00]] as [$name, $amt]) {
        if (!isset($cust[$name])) { continue; }
        $sid = RoutesService::addStop($cid, $tripB, $cust[$name], $t['actor']);
        RoutesService::recordStop($cid, $sid, ['status' => 'paid', 'amount_collected' => $amt, 'method' => 'cash'], $t['actor']);
    }
    if (isset($cust['Placencia Mini Mart'])) {
        RoutesService::addStop($cid, $tripB, $cust['Placencia Mini Mart'], $t['actor']); // pending
    }
    RoutesService::setTripStatus($cid, $tripB, 'out', $t['actor']);

    say("#{$cid} {$t['label']}: route 'Northern Distribution', 1 trip awaiting approval, 1 out with cash in transit");
}

/* ── unmatched bank deposits for the reconciliation workbench ─────────── */
require_once __DIR__ . '/../app/services/ReconciliationService.php';
foreach ($TARGETS as $cid => $t) {
    $have = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE company_id = " . (int)$cid)->fetchColumn();
    if ($have > 0) { say("#{$cid} already has bank lines — skipping"); continue; }

    $csv = "date,description,reference,amount\n"
        . (new DateTime('-6 days'))->format('Y-m-d') . ",TRANSFER FROM BELMOPAN WHOLESALE,FT2261A,7500.00\n"
        . (new DateTime('-5 days'))->format('Y-m-d') . ",MOBILE DEPOSIT,DEP0092,9800.00\n"
        . (new DateTime('-4 days'))->format('Y-m-d') . ",ONLINE PMT SAN PEDRO PROV,OLP7741,1180.00\n"
        . (new DateTime('-3 days'))->format('Y-m-d') . ",CASH DEPOSIT BRANCH 04,CD0041,3250.00\n"
        . (new DateTime('-2 days'))->format('Y-m-d') . ",TRANSFER ORANGE WALK DIST,FT2288B,5240.00\n"
        . (new DateTime('-1 day'))->format('Y-m-d')  . ",CHEQUE 004512,CHQ4512,980.00\n";
    $r = ReconciliationService::import($cid, $csv, [], $t['actor'], 'demo-statement.csv');
    say("#{$cid} {$t['label']}: imported {$r['imported']} bank line(s) — all unmatched");
}

/* ── company group for the Enterprise module ──────────────────────────── */
$grp = $pdo->query("SELECT id FROM company_groups WHERE name = 'BHI Group' LIMIT 1")->fetchColumn();
if (!$grp) {
    $grp = GroupsService::saveGroup(1, ['name' => 'BHI Group'], true);   // user 1 becomes owner + group_admin
    say("created company group 'BHI Group' (#{$grp}), user #1 is group_admin");
}
$grp = (int)$grp;
foreach ([1, 3] as $cid) {
    try { GroupsService::attachCompany($grp, $cid, 1, true); say("attached company #{$cid} to BHI Group"); }
    catch (Throwable $e) { /* already attached */ }
}
if (Entitlements::groupLevel($grp, 'enterprise') !== Entitlements::FULL) {
    Entitlements::grantGroup($grp, 'enterprise', 1, 'demo setup');
    say("granted 'enterprise' to BHI Group");
}
// also give javangrantbz a seat on the group so their login sees it
if ($u9) {
    GroupsService::setMember($grp, (int)$u9, 'group_viewer', 1, true);
    say("user #{$u9} added to BHI Group as viewer");
}

say("\nDone. Log in as webdevelopment@bhilimited.com → pick 'J Bells Grocery' → all four modules are live.");
say("groups.php will show 'BHI Group' with both companies rolled up.");
