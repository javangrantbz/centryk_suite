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

/* ── letterhead + a Belize GST TIN so the GST summary has one ─────────── */
foreach ([1 => '024531-GST', 3 => '031887-GST'] as $cid => $tin) {
    $pdo->prepare("
        INSERT INTO invoice_settings (company_id, business_tax_number, currency_symbol)
        VALUES (:c, :tin, 'BZD ')
        ON DUPLICATE KEY UPDATE business_tax_number = VALUES(business_tax_number)
    ")->execute(['c' => $cid, 'tin' => $tin]);
}

/* ── sample customers + invoices for each target company ──────────────── */
// Emails are +aliases on the owner's own inbox so "Email statement / reminder"
// actually lands somewhere you can read while testing on localhost (and never
// spams a real third party).
$OWNER_INBOX = 'javangrantbz@gmail.com';
$demoEmail = static function (string $slug) use ($OWNER_INBOX): string {
    [$u, $d] = explode('@', $OWNER_INBOX, 2);
    return $u . '+' . $slug . '@' . $d;
};
$CUSTOMERS = [
    ['Corozal Cash & Carry',     $demoEmail('corozal'),  75000, 30, 0],
    ['San Pedro Provisions',     $demoEmail('sanpedro'), 12000,  7, 0],
    ['Belmopan Wholesale Ltd',   $demoEmail('belmopan'), 120000, 30, 0],
    ['Orange Walk Distributors', $demoEmail('owd'),      90000, 30, 0],
    ['Dangriga Trading Post',    $demoEmail('dangriga'), 15000, 15, 0],
    ['Placencia Mini Mart',      $demoEmail('placencia'), 8000,  7, 1],  // on hold
];

// Repoint any earlier-seeded demo customers that still have the old fake .bz
// addresses (the marker guard below skips re-seeding otherwise).
foreach ($TARGETS as $cid => $t) {
    foreach ($CUSTOMERS as [$name, $email]) {
        $pdo->prepare("UPDATE customers SET email = :e WHERE company_id = :c AND name = :n AND email LIKE '%.bz'")
            ->execute(['e' => $email, 'c' => $cid, 'n' => $name]);
    }
}

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

    // Cheques: one held uncleared, one post-dated, one that bounced.
    ReceivablesService::recordPayment($cid, $custIds['Belmopan Wholesale Ltd'], [
        'amount' => 3000, 'method' => 'cheque', 'cheque_number' => '004512', 'cheque_bank' => 'Atlantic Bank',
        'cheque_date' => (new DateTime('-2 days'))->format('Y-m-d'), 'received_on' => (new DateTime('-2 days'))->format('Y-m-d'),
    ], $t['actor']);
    ReceivablesService::recordPayment($cid, $custIds['San Pedro Provisions'], [
        'amount' => 1500, 'method' => 'cheque', 'cheque_number' => '221190', 'cheque_bank' => 'Heritage Bank',
        'cheque_date' => (new DateTime('+12 days'))->format('Y-m-d'), 'received_on' => (new DateTime('-1 day'))->format('Y-m-d'),
    ], $t['actor']);
    $bad = ReceivablesService::recordPayment($cid, $custIds['Orange Walk Distributors'], [
        'amount' => 2400, 'method' => 'cheque', 'cheque_number' => '888014', 'cheque_bank' => 'Belize Bank',
        'cheque_date' => (new DateTime('-9 days'))->format('Y-m-d'), 'received_on' => (new DateTime('-9 days'))->format('Y-m-d'),
    ], $t['actor']);
    ReceivablesService::bounceCheque($cid, (int) $bad['payment_id'], 'insufficient funds', $t['actor']);

    // Write-offs: one approved (a small overdue invoice gone bad) + one pending
    // (damaged goods on a Dangriga invoice, waiting for an admin).
    $openInv = $pdo->prepare("
        SELECT id, (total - amount_paid) AS outstanding FROM invoices
        WHERE company_id = :c AND customer_id = :cust AND status IN ('sent','overdue')
        ORDER BY (total - amount_paid) ASC LIMIT 1
    ");
    $openInv->execute(['c' => $cid, 'cust' => $custIds['Dangriga Trading Post']]);
    if ($small = $openInv->fetch(PDO::FETCH_ASSOC)) {
        $w = ReceivablesService::proposeWriteoff($cid, [
            'invoice_id' => (int)$small['id'], 'amount' => round($small['outstanding'], 2),
            'kind' => 'bad_debt', 'reason' => 'customer ceased trading',
        ], $t['actor']);
        ReceivablesService::decideWriteoff($cid, $w, 'approve', ['note' => 'confirmed with the rep'], $t['actor']);
    }
    $openInv->execute(['c' => $cid, 'cust' => $custIds['Belmopan Wholesale Ltd']]);
    if ($big = $openInv->fetch(PDO::FETCH_ASSOC)) {
        ReceivablesService::proposeWriteoff($cid, [
            'invoice_id' => (int)$big['id'], 'amount' => 1500,
            'kind' => 'damaged_goods', 'reason' => 'two pallets water-damaged in transit',
        ], $t['actor']);
    }

    say("#{$cid} {$t['label']}: seeded 6 customers, 14 invoices, 2 receipts, 1 write-off + 1 pending");
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
    RoutesService::submitSettlement($cid, $tripA, 4300.00, 'Short BZD 50 — customer paid partial', $t['actor']); // expected 4350, variance -50; left awaiting approval

    // An older trip, fully settled — gives the driver-performance report some history
    $tripC = RoutesService::createTrip($cid, $routeId, (new DateTime('-8 days'))->format('Y-m-d'), 'Marlon Cruz', $t['actor']);
    foreach ([['Belmopan Wholesale Ltd', 3100.00], ['Corozal Cash & Carry', 2450.00]] as [$name, $amt]) {
        if (!isset($cust[$name])) { continue; }
        $sid = RoutesService::addStop($cid, $tripC, $cust[$name], $t['actor']);
        RoutesService::recordStop($cid, $sid, ['status' => 'paid', 'amount_collected' => $amt, 'method' => 'cash'], $t['actor']);
    }
    RoutesService::setTripStatus($cid, $tripC, 'out', $t['actor']);
    RoutesService::submitSettlement($cid, $tripC, 5550.00, '', $t['actor']); // exact
    RoutesService::approveSettlement($cid, $tripC, $t['actor']);

    // Trip D — settled, mixed payment methods so commission on electronic shows.
    $tripD = RoutesService::createTrip($cid, $routeId, (new DateTime('-5 days'))->format('Y-m-d'), 'Marlon Cruz', $t['actor']);
    foreach ([['Orange Walk Distributors', 2600.00, 'bank_transfer'], ['San Pedro Provisions', 1400.00, 'card'], ['Dangriga Trading Post', 900.00, 'cash']] as [$name, $amt, $mth]) {
        if (!isset($cust[$name])) { continue; }
        $sid = RoutesService::addStop($cid, $tripD, $cust[$name], $t['actor']);
        RoutesService::recordStop($cid, $sid, ['status' => 'paid', 'amount_collected' => $amt, 'method' => $mth], $t['actor']);
    }
    RoutesService::setTripStatus($cid, $tripD, 'out', $t['actor']);
    RoutesService::submitSettlement($cid, $tripD, 900.00, '', $t['actor']);
    RoutesService::approveSettlement($cid, $tripD, $t['actor']);

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

    // Assign javangrantbz (user 9) as the driver of the out trip on Miss Bella
    // Shop so they can test the phone-first field view (routes_field.php).
    if ($cid === 3 && $u9) {
        RoutesService::assignDriver($cid, $tripB, (int)$u9, $t['actor']);
    }

    // Commission: a 2% company default + a route rule that pays 5% on
    // electronic collections (the incentive to move drivers off cash).
    if (!$pdo->query("SELECT id FROM route_commission_rules WHERE company_id = " . (int)$cid . " LIMIT 1")->fetch()) {
        RoutesService::saveCommissionRule($cid, [
            'scope' => 'company', 'basis' => 'collections_total', 'rate' => 2, 'note' => 'standard route commission',
        ], $t['actor']);
        RoutesService::saveCommissionRule($cid, [
            'scope' => 'route', 'route_id' => $routeId, 'basis' => 'collections_electronic', 'rate' => 5,
            'note' => 'higher rate for card / transfer collections',
        ], $t['actor']);
    }

    say("#{$cid} {$t['label']}: route 'Northern Distribution', 1 trip awaiting approval, 1 out with cash in transit, commission rules");
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
        . (new DateTime('-1 day'))->format('Y-m-d')  . ",CHEQUE 004512,CHQ4512,980.00\n"
        . (new DateTime('-3 days'))->format('Y-m-d') . ",MONTHLY SERVICE FEE,SVC-0825,-45.00\n"
        . (new DateTime('-7 days'))->format('Y-m-d') . ",BANK CHARGE - STATEMENT,SVC-0725,-12.50\n";

    // An auto-ignore rule for recurring bank fees — it runs on import, so the
    // two fee lines above never reach the matching queue.
    ReconciliationService::saveRule($cid, [
        'description_like' => 'FEE', 'direction' => 'debit', 'note' => 'bank fees — not customer payments',
    ], $t['actor']);
    ReconciliationService::saveRule($cid, [
        'description_like' => 'BANK CHARGE', 'direction' => 'debit', 'note' => 'bank charges',
    ], $t['actor']);

    $r = ReconciliationService::import($cid, $csv, [], $t['actor'], 'demo-statement.csv');
    say("#{$cid} {$t['label']}: imported {$r['imported']} bank line(s), {$r['auto_ignored']} auto-ignored");

    // A OnePay card batch: 4 small paid sales on -4d + the settlement deposit
    // on -2d, so the "match as settlement" flow has something to work.
    $onepayCust = $custIds['San Pedro Provisions'] ?? array_values($custIds)[0];
    $sales = [42.50, 118.00, 9.99, 76.25];
    foreach ($sales as $i => $amt) {
        $ref = 'S' . date('Ymd', strtotime('-4 days')) . sprintf('%03d', 700 + $i);
        $pdo->prepare("
            INSERT INTO invoices (company_id, customer_id, invoice_number, status, issue_date,
                                  subtotal, total, amount_paid, source_app, source_ref)
            VALUES (:c, :cust, :num, 'paid', :d, :sub, :tot, :paid, 'onepay', :ref)
        ")->execute([
            'c' => $cid, 'cust' => $onepayCust, 'num' => 'OP-' . $ref,
            'd' => (new DateTime('-4 days'))->format('Y-m-d'),
            'sub' => $amt, 'tot' => $amt, 'paid' => $amt, 'ref' => $ref,
        ]);
    }
    ReceivablesService::syncOnepayReceipts($cid, $t['actor']);
    $batch = array_sum($sales);
    $depDate = (new DateTime('-2 days'))->format('Y-m-d');
    $pdo->prepare("
        INSERT INTO bank_transactions (company_id, txn_date, description, reference, amount, direction, dedupe_hash, status)
        VALUES (:c, :d, 'CARD MERCHANT SETTLEMENT', 'STLB0824', :a, 'credit', :h, 'unmatched')
    ")->execute([
        'c' => $cid, 'd' => $depDate, 'a' => $batch,
        'h' => sha1($cid . '|' . $depDate . '|onepay-settlement|' . $batch),
    ]);
    say("#{$cid} {$t['label']}: 4 OnePay sales + a {$batch} settlement deposit to reconcile");
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

/* ── subscription billing — a couple of months, mostly paid ───────────── */
require_once __DIR__ . '/../app/services/BillingService.php';
$madeCharges = (int)$pdo->query("
    SELECT COUNT(*) FROM company_subscription_charges WHERE company_id IN (1,3)
")->fetchColumn();
if ($madeCharges === 0) {
    BillingService::runCycle(date('Y-m-01', strtotime('-1 month')), 1);
    BillingService::runCycle(date('Y-m-01'), 1);
    // Settle everything except the newest charge for each company, so the
    // billing console has a small "due" list to work and dunning has a target.
    $rows = $pdo->query("
        SELECT id, company_id FROM company_subscription_charges
        WHERE company_id IN (1,3) ORDER BY company_id, period_start DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $keptOpen = [];
    foreach ($rows as $r) {
        $c = (int)$r['company_id'];
        if (($keptOpen[$c] ?? 0) < 1) { $keptOpen[$c] = 1; continue; }   // leave one open
        BillingService::updateCharge((int)$r['id'], 'paid', ['method' => 'bank transfer', 'paid_on' => date('Y-m-d', strtotime('-10 days'))], 1);
    }
    say("seeded billing — 2 monthly cycles, all but the latest charge per company marked paid");
} else {
    say("billing charges already exist — skipping");
}

say("\nDone. Log in as webdevelopment@bhilimited.com → pick 'J Bells Grocery' → all four modules are live.");
say("groups.php will show 'BHI Group' with both companies rolled up.");
