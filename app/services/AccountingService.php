<?php
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Audit.php';
require_once __DIR__ . '/../core/Ledger.php';
require_once __DIR__ . '/GlSync.php';
require_once __DIR__ . '/ExpensesService.php';

/**
 * Centryk Business — Accounting: chart of accounts, setup, periods and the
 * shaped financial statements (trial balance, P&L, balance sheet, GL detail).
 *
 * The posting engine is Ledger. This service owns everything around it that the
 * accounting desk touches directly. Every method is company-scoped; callers
 * must already have checked membership and the 'accounting' entitlement.
 */
class AccountingService
{
    /** Account types → the side their balance normally sits on. */
    private const NORMAL_BY_TYPE = [
        'asset'     => 'debit',
        'expense'   => 'debit',
        'cogs'      => 'debit',
        'liability' => 'credit',
        'equity'    => 'credit',
        'income'    => 'credit',
    ];

    /**
     * The Belize small/medium-business starter chart. Rows:
     *   [code, name, type, subtype, flags]
     * flags: 'system' (seeded, undeletable), 'control' (subledger-owned),
     *        'normal:credit' / 'normal:debit' to override the type default
     *        (contra accounts), 'slot:<name>' to bind a gl_account_map slot.
     */
    public const TEMPLATE = [
        // ── Assets ────────────────────────────────────────────────────────
        ['1000', 'Cash on Hand',                 'asset', 'current_asset', []],
        ['1010', 'Bank — Chequing',              'asset', 'current_asset', ['slot:bank_default']],
        ['1020', 'Undeposited Funds',            'asset', 'current_asset', ['slot:undeposited_funds', 'slot:pos_clearing']],
        ['1100', 'Accounts Receivable',          'asset', 'current_asset', ['system', 'control', 'slot:ar']],
        ['1200', 'Inventory',                    'asset', 'current_asset', []],
        ['1300', 'GST Input Tax Credit',         'asset', 'current_asset', ['system', 'slot:gst_input']],
        ['1400', 'Prepaid Expenses',             'asset', 'current_asset', []],
        ['1500', 'Property, Plant & Equipment',  'asset', 'fixed_asset',   []],
        ['1510', 'Accumulated Depreciation',     'asset', 'fixed_asset',   ['normal:credit']],
        // ── Liabilities ──────────────────────────────────────────────────
        ['2000', 'Accounts Payable',             'liability', 'current_liability',   ['system', 'control', 'slot:ap']],
        ['2100', 'GST Output Tax Payable',       'liability', 'current_liability',   ['system', 'slot:gst_output']],
        ['2200', 'PAYE Payable',                 'liability', 'current_liability',   ['slot:paye_payable']],
        ['2210', 'Social Security Payable',      'liability', 'current_liability',   ['slot:ssb_payable']],
        ['2250', 'Net Wages Payable',            'liability', 'current_liability',   ['slot:payroll_clearing']],
        ['2300', 'Accrued Liabilities',          'liability', 'current_liability',   []],
        ['2400', 'Business Tax Payable',         'liability', 'current_liability',   []],
        ['2500', 'Loans Payable',                'liability', 'long_term_liability', []],
        // ── Equity ───────────────────────────────────────────────────────
        ['3000', "Owner's Capital",              'equity', '', []],
        ['3100', "Owner's Drawings",             'equity', '', ['normal:debit']],
        ['3200', 'Retained Earnings',            'equity', '', ['system', 'slot:retained_earnings']],
        ['3900', 'Opening Balance Equity',       'equity', '', ['system', 'slot:opening_balance_equity']],
        // ── Income ───────────────────────────────────────────────────────
        ['4000', 'Sales Revenue',                'income', '', ['slot:sales_default']],
        ['4100', 'Service Revenue',              'income', '', []],
        ['4200', 'Other Income',                 'income', '', []],
        ['4900', 'Sales Returns & Allowances',   'income', '', ['normal:debit', 'slot:sales_returns']],
        // ── Cost of sales ────────────────────────────────────────────────
        ['5000', 'Cost of Goods Sold',           'cogs', '', ['slot:cogs_default']],
        ['5100', 'Freight & Duty',               'cogs', '', []],
        ['5200', 'Purchase Discounts',           'cogs', '', ['normal:credit']],
        // ── Operating expenses ───────────────────────────────────────────
        ['6000', 'Salaries & Wages',             'expense', '', ['slot:payroll_wages_expense']],
        ['6010', 'Employer Social Security',     'expense', '', ['slot:payroll_employer_ss_expense']],
        ['6100', 'Rent',                         'expense', '', []],
        ['6150', 'Utilities',                    'expense', '', []],
        ['6200', 'Telephone & Internet',         'expense', '', []],
        ['6250', 'Office Supplies',              'expense', '', []],
        ['6300', 'Repairs & Maintenance',        'expense', '', []],
        ['6350', 'Motor Vehicle & Fuel',         'expense', '', []],
        ['6400', 'Insurance',                    'expense', '', []],
        ['6450', 'Professional Fees',            'expense', '', []],
        ['6500', 'Bank Charges & Merchant Fees', 'expense', '', ['slot:bank_charges']],
        ['6550', 'Advertising & Marketing',      'expense', '', []],
        ['6600', 'Travel & Entertainment',       'expense', '', []],
        ['6650', 'Bad Debt Expense',             'expense', '', ['system', 'slot:bad_debt']],
        ['6700', 'Depreciation Expense',         'expense', '', []],
        ['6900', 'Miscellaneous Expense',        'expense', '', []],
    ];

    private static function normalFor(string $type, array $flags): string
    {
        foreach ($flags as $f) {
            if ($f === 'normal:credit') {
                return 'credit';
            }
            if ($f === 'normal:debit') {
                return 'debit';
            }
        }
        return self::NORMAL_BY_TYPE[$type] ?? 'debit';
    }

    // ── Setup ─────────────────────────────────────────────────────────────

    /**
     * The starter chart, shaped for the setup screen preview.
     * @return array<int,array{code:string,name:string,type:string,subtype:string,normal_balance:string,system:bool,control:bool,slots:array<string>}>
     */
    public static function templatePreview(): array
    {
        $out = [];
        foreach (self::TEMPLATE as [$code, $name, $type, $subtype, $flags]) {
            $slots = [];
            foreach ($flags as $f) {
                if (str_starts_with($f, 'slot:')) {
                    $slots[] = substr($f, 5);
                }
            }
            $out[] = [
                'code'           => $code,
                'name'           => $name,
                'type'           => $type,
                'subtype'        => $subtype,
                'normal_balance' => self::normalFor($type, $flags),
                'system'         => in_array('system', $flags, true),
                'control'        => in_array('control', $flags, true),
                'slots'          => $slots,
            ];
        }
        return $out;
    }

    /**
     * Turn on the books for a company: create the config row, seed the chart
     * (starter template unless $customAccounts is given), bind the control-account
     * slots, and lay down the current fiscal year's periods.
     *
     * @param array $opts  ['fiscal_year_start_month' => 1..12, 'base_currency' => 'BZD',
     *                       'use_template' => bool, 'accounts' => [...]]
     * @throws RuntimeException if the books are already activated
     */
    public static function activate(int $companyId, array $opts, ?int $userId): void
    {
        $pdo = DB::pdo();

        if (Ledger::isActivated($companyId)) {
            throw new RuntimeException('Accounting is already set up for this company.');
        }

        $startMonth = (int)($opts['fiscal_year_start_month'] ?? 1);
        if ($startMonth < 1 || $startMonth > 12) {
            $startMonth = 1;
        }
        $currency = strtoupper(mb_substr(trim((string)($opts['base_currency'] ?? 'BZD')), 0, 3)) ?: 'BZD';
        $useTemplate = !array_key_exists('use_template', $opts) || !empty($opts['use_template']);

        $ownTxn = !$pdo->inTransaction();
        try {
            if ($ownTxn) {
                $pdo->beginTransaction();
            }

            $pdo->prepare(
                'INSERT INTO company_accounting (company_id, base_currency, fiscal_year_start_month, activated_at, activated_by)
                 VALUES (:c, :cur, :m, NOW(), :by)
                 ON DUPLICATE KEY UPDATE base_currency = VALUES(base_currency),
                                         fiscal_year_start_month = VALUES(fiscal_year_start_month),
                                         activated_at = COALESCE(activated_at, NOW()),
                                         activated_by = VALUES(activated_by)'
            )->execute(['c' => $companyId, 'cur' => $currency, 'm' => $startMonth, 'by' => $userId]);

            Ledger::flushCache();

            if ($useTemplate) {
                self::seedTemplate($companyId);
            } elseif (!empty($opts['accounts']) && is_array($opts['accounts'])) {
                foreach ($opts['accounts'] as $a) {
                    self::saveAccount($companyId, $a, $userId, true);
                }
            }

            Ledger::ensureFiscalYear($companyId, date('Y-m-d'));

            if ($ownTxn) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Ledger::flushCache();
        Audit::log([
            'actor_user_id' => $userId,
            'company_id'    => $companyId,
            'event_type'    => 'accounting.activated',
            'summary'       => 'Set up the general ledger' . ($useTemplate ? ' from the Belize starter chart' : ''),
            'metadata'      => ['fiscal_year_start_month' => $startMonth, 'currency' => $currency],
        ]);
    }

    /** Insert the starter template's accounts and slot bindings. Idempotent by code. */
    private static function seedTemplate(int $companyId): void
    {
        foreach (self::TEMPLATE as [$code, $name, $type, $subtype, $flags]) {
            $accountId = self::upsertAccount($companyId, [
                'code'           => $code,
                'name'           => $name,
                'type'           => $type,
                'subtype'        => $subtype,
                'normal_balance' => self::normalFor($type, $flags),
                'is_system'      => in_array('system', $flags, true),
                'is_control'     => in_array('control', $flags, true),
            ]);
            foreach ($flags as $f) {
                if (str_starts_with($f, 'slot:')) {
                    self::setMap($companyId, substr($f, 5), $accountId, null, true);
                }
            }
        }
    }

    // ── Chart of accounts ────────────────────────────────────────────────

    /**
     * @param array $opts ['active_only' => bool, 'postable_only' => bool (excludes control)]
     * @return array<int,array>
     */
    public static function accounts(int $companyId, array $opts = []): array
    {
        $sql = 'SELECT a.*,
                       p.code AS parent_code, p.name AS parent_name
                  FROM gl_accounts a
                  LEFT JOIN gl_accounts p ON p.id = a.parent_id
                 WHERE a.company_id = :c';
        if (!empty($opts['active_only'])) {
            $sql .= ' AND a.is_active = 1';
        }
        if (!empty($opts['postable_only'])) {
            $sql .= ' AND a.is_control = 0';
        }
        $sql .= " ORDER BY FIELD(a.type,'asset','liability','equity','income','cogs','expense'), a.code";

        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute(['c' => $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Attach any slot bindings.
        $map = self::accountMap($companyId);
        $bySlotAccount = [];
        foreach ($map as $slot => $accountId) {
            $bySlotAccount[$accountId][] = $slot;
        }
        foreach ($rows as &$r) {
            $r['id']         = (int)$r['id'];
            $r['is_active']  = (bool)$r['is_active'];
            $r['is_system']  = (bool)$r['is_system'];
            $r['is_control'] = (bool)$r['is_control'];
            $r['slots']      = $bySlotAccount[$r['id']] ?? [];
        }
        return $rows;
    }

    public static function account(int $companyId, int $accountId): ?array
    {
        $stmt = DB::pdo()->prepare('SELECT * FROM gl_accounts WHERE id = :id AND company_id = :c LIMIT 1');
        $stmt->execute(['id' => $accountId, 'c' => $companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create or update a chart-of-accounts row. Returns the account id.
     * System accounts: name and parent are editable, code and type are not.
     */
    public static function saveAccount(int $companyId, array $data, ?int $userId, bool $silent = false): int
    {
        $id      = (int)($data['id'] ?? 0);
        $code    = mb_substr(trim((string)($data['code'] ?? '')), 0, 20);
        $name    = mb_substr(trim((string)($data['name'] ?? '')), 0, 120);
        $type    = (string)($data['type'] ?? '');
        $subtype = mb_substr(trim((string)($data['subtype'] ?? '')), 0, 40);
        $parent  = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;

        if ($name === '') {
            throw new InvalidArgumentException('An account name is required.');
        }

        if ($id > 0) {
            $existing = self::account($companyId, $id);
            if (!$existing) {
                throw new RuntimeException('Account not found.');
            }
            $isSystem = (bool)$existing['is_system'];
            $newCode  = $isSystem ? $existing['code'] : ($code !== '' ? $code : $existing['code']);
            $newType  = $isSystem ? $existing['type'] : ($type !== '' ? $type : $existing['type']);
            if (!isset(self::NORMAL_BY_TYPE[$newType])) {
                throw new InvalidArgumentException('Unknown account type.');
            }
            $normal = $data['normal_balance'] ?? $existing['normal_balance'];
            if (!in_array($normal, ['debit', 'credit'], true)) {
                $normal = self::NORMAL_BY_TYPE[$newType];
            }
            self::assertUniqueCode($companyId, $newCode, $id);

            DB::pdo()->prepare(
                'UPDATE gl_accounts SET code = :code, name = :name, type = :type, subtype = :subtype,
                        parent_id = :parent, normal_balance = :normal
                  WHERE id = :id AND company_id = :c'
            )->execute([
                'code' => $newCode, 'name' => $name, 'type' => $newType, 'subtype' => $subtype,
                'parent' => $parent, 'normal' => $normal, 'id' => $id, 'c' => $companyId,
            ]);
        } else {
            if ($code === '') {
                throw new InvalidArgumentException('An account code is required.');
            }
            if (!isset(self::NORMAL_BY_TYPE[$type])) {
                throw new InvalidArgumentException('Unknown account type.');
            }
            $normal = $data['normal_balance'] ?? self::NORMAL_BY_TYPE[$type];
            if (!in_array($normal, ['debit', 'credit'], true)) {
                $normal = self::NORMAL_BY_TYPE[$type];
            }
            $id = self::upsertAccount($companyId, [
                'code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype,
                'normal_balance' => $normal, 'parent_id' => $parent,
                'is_system' => false, 'is_control' => false,
            ]);
        }

        if (!$silent) {
            Audit::log([
                'actor_user_id' => $userId,
                'company_id'    => $companyId,
                'event_type'    => 'accounting.account.saved',
                'summary'       => 'Saved GL account ' . $code . ' ' . $name,
                'metadata'      => ['account_id' => $id],
            ]);
        }
        return $id;
    }

    /**
     * Archive (soft-delete) an account. Refused for system accounts, accounts
     * with posted activity, or accounts still bound to a slot.
     */
    public static function archiveAccount(int $companyId, int $accountId, ?int $userId): void
    {
        $a = self::account($companyId, $accountId);
        if (!$a) {
            throw new RuntimeException('Account not found.');
        }
        if ($a['is_system']) {
            throw new RuntimeException('A system account cannot be removed.');
        }

        $used = DB::pdo()->prepare('SELECT COUNT(*) FROM gl_journal_lines WHERE account_id = :id');
        $used->execute(['id' => $accountId]);
        if ((int)$used->fetchColumn() > 0) {
            throw new RuntimeException('That account has journal activity — it can only be made inactive, not removed.');
        }

        $mapped = DB::pdo()->prepare('SELECT COUNT(*) FROM gl_account_map WHERE company_id = :c AND account_id = :id');
        $mapped->execute(['c' => $companyId, 'id' => $accountId]);
        if ((int)$mapped->fetchColumn() > 0) {
            throw new RuntimeException('That account is bound to a control slot — rebind it first.');
        }

        DB::pdo()->prepare('UPDATE gl_accounts SET is_active = 0 WHERE id = :id AND company_id = :c')
            ->execute(['id' => $accountId, 'c' => $companyId]);

        Audit::log([
            'actor_user_id' => $userId,
            'company_id'    => $companyId,
            'event_type'    => 'accounting.account.archived',
            'summary'       => 'Made GL account ' . $a['code'] . ' inactive',
            'metadata'      => ['account_id' => $accountId],
        ]);
    }

    private static function assertUniqueCode(int $companyId, string $code, int $exceptId = 0): void
    {
        $stmt = DB::pdo()->prepare(
            'SELECT id FROM gl_accounts WHERE company_id = :c AND code = :code AND id <> :ex LIMIT 1'
        );
        $stmt->execute(['c' => $companyId, 'code' => $code, 'ex' => $exceptId]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException('Account code ' . $code . ' is already in use.');
        }
    }

    /** Insert-or-update by (company_id, code). Returns the account id. */
    private static function upsertAccount(int $companyId, array $a): int
    {
        $pdo = DB::pdo();
        $stmt = $pdo->prepare('SELECT id FROM gl_accounts WHERE company_id = :c AND code = :code LIMIT 1');
        $stmt->execute(['c' => $companyId, 'code' => $a['code']]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $pdo->prepare(
                'UPDATE gl_accounts SET name = :name, type = :type, subtype = :subtype,
                        normal_balance = :normal, parent_id = :parent,
                        is_system = GREATEST(is_system, :sys), is_control = GREATEST(is_control, :ctl),
                        is_active = 1
                  WHERE id = :id'
            )->execute([
                'name' => $a['name'], 'type' => $a['type'], 'subtype' => $a['subtype'] ?? '',
                'normal' => $a['normal_balance'], 'parent' => $a['parent_id'] ?? null,
                'sys' => !empty($a['is_system']) ? 1 : 0, 'ctl' => !empty($a['is_control']) ? 1 : 0,
                'id' => $existingId,
            ]);
            return $existingId;
        }

        $pdo->prepare(
            'INSERT INTO gl_accounts (company_id, code, name, type, subtype, normal_balance, parent_id, is_system, is_control)
             VALUES (:c, :code, :name, :type, :subtype, :normal, :parent, :sys, :ctl)'
        )->execute([
            'c' => $companyId, 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'],
            'subtype' => $a['subtype'] ?? '', 'normal' => $a['normal_balance'], 'parent' => $a['parent_id'] ?? null,
            'sys' => !empty($a['is_system']) ? 1 : 0, 'ctl' => !empty($a['is_control']) ? 1 : 0,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Bulk-load a chart of accounts from CSV — for a company bringing its codes
     * across from another system. Header row, case-insensitive, order-free:
     *   code (required), name (required), type (required: asset|liability|equity|income|cogs|expense),
     *   subtype, parent_code, normal_balance.
     * Upsert by code; nothing is deleted.
     *
     * @return array{created:int, updated:int, skipped:int, errors:array<string>}
     */
    public static function importAccountsCsv(int $companyId, string $csv, ?int $userId): array
    {
        $csv = trim(str_replace(["\r\n", "\r"], "\n", $csv));
        $lines = array_values(array_filter(explode("\n", $csv), static fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw new InvalidArgumentException('Need a header row and at least one account.');
        }

        $header = array_map(static fn ($h) => strtolower(trim($h, " \t\"'")), str_getcsv(array_shift($lines)));
        $alias = [
            'code'           => ['code', 'account code', 'number', 'account number', 'acct', 'no', 'gl code'],
            'name'           => ['name', 'account name', 'account', 'title', 'description'],
            'type'           => ['type', 'account type', 'category', 'class'],
            'subtype'        => ['subtype', 'sub type', 'sub-type', 'detail type', 'group'],
            'parent_code'    => ['parent_code', 'parent', 'parent code', 'rollup', 'header'],
            'normal_balance' => ['normal_balance', 'normal balance', 'normal side', 'balance'],
        ];
        $col = [];
        foreach ($alias as $key => $names) {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false) {
                    $col[$key] = $i;
                    break;
                }
            }
        }
        if (!isset($col['code'], $col['name'])) {
            throw new InvalidArgumentException('The header needs at least "code" and "name" columns.');
        }

        $typeAlias = [
            'asset' => 'asset', 'assets' => 'asset', 'bank' => 'asset', 'current asset' => 'asset',
            'fixed asset' => 'asset', 'accounts receivable' => 'asset', 'other current asset' => 'asset',
            'liability' => 'liability', 'liabilities' => 'liability', 'current liability' => 'liability',
            'accounts payable' => 'liability', 'credit card' => 'liability', 'long term liability' => 'liability',
            'equity' => 'equity', 'capital' => 'equity',
            'income' => 'income', 'revenue' => 'income', 'sales' => 'income', 'other income' => 'income',
            'cogs' => 'cogs', 'cost of goods sold' => 'cogs', 'cost of sales' => 'cogs',
            'expense' => 'expense', 'expenses' => 'expense', 'operating expense' => 'expense', 'other expense' => 'expense',
        ];

        $pdo = DB::pdo();
        $existing = [];
        $ex = $pdo->prepare('SELECT code FROM gl_accounts WHERE company_id = :c');
        $ex->execute(['c' => $companyId]);
        foreach ($ex->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $existing[strtolower((string)$c)] = true;
        }

        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $rowNo = 1;
        $parents = []; // code => parent_code, resolved in a second pass

        foreach ($lines as $line) {
            $rowNo++;
            $cells = str_getcsv($line);
            $code = mb_substr(trim((string)($cells[$col['code']] ?? '')), 0, 20);
            $name = mb_substr(trim((string)($cells[$col['name']] ?? '')), 0, 120);
            if ($code === '' || $name === '') {
                $out['skipped']++;
                $out['errors'][] = "Row {$rowNo}: missing code or name";
                continue;
            }

            $rawType = strtolower(trim((string)($cells[$col['type'] ?? -1] ?? '')));
            $type = $typeAlias[$rawType] ?? ($rawType !== '' && isset(self::NORMAL_BY_TYPE[$rawType]) ? $rawType : '');
            if ($type === '') {
                $out['skipped']++;
                $out['errors'][] = "Row {$rowNo} ({$code}): unrecognised type '" . $rawType . "'";
                continue;
            }

            $subtype = mb_substr(trim((string)($cells[$col['subtype'] ?? -1] ?? '')), 0, 40);
            $normal  = strtolower(trim((string)($cells[$col['normal_balance'] ?? -1] ?? '')));
            if (!in_array($normal, ['debit', 'credit'], true)) {
                $normal = self::NORMAL_BY_TYPE[$type];
            }
            $parentCode = mb_substr(trim((string)($cells[$col['parent_code'] ?? -1] ?? '')), 0, 20);
            if ($parentCode !== '') {
                $parents[$code] = $parentCode;
            }

            $wasThere = isset($existing[strtolower($code)]);
            try {
                self::upsertAccount($companyId, [
                    'code' => $code, 'name' => $name, 'type' => $type,
                    'subtype' => $subtype, 'normal_balance' => $normal,
                ]);
                $wasThere ? $out['updated']++ : $out['created']++;
                $existing[strtolower($code)] = true;
            } catch (Throwable $e) {
                $out['skipped']++;
                $out['errors'][] = "Row {$rowNo} ({$code}): " . $e->getMessage();
            }
        }

        // Second pass: wire parents now that every code exists.
        if ($parents) {
            $idByCode = [];
            $q = $pdo->prepare('SELECT id, code FROM gl_accounts WHERE company_id = :c');
            $q->execute(['c' => $companyId]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $idByCode[strtolower($r['code'])] = (int)$r['id'];
            }
            $upd = $pdo->prepare('UPDATE gl_accounts SET parent_id = :p WHERE company_id = :c AND id = :id');
            foreach ($parents as $childCode => $parentCode) {
                $childId  = $idByCode[strtolower($childCode)] ?? 0;
                $parentId = $idByCode[strtolower($parentCode)] ?? 0;
                if ($childId && $parentId && $childId !== $parentId) {
                    $upd->execute(['p' => $parentId, 'c' => $companyId, 'id' => $childId]);
                }
            }
        }

        Audit::log([
            'actor_user_id' => $userId,
            'company_id'    => $companyId,
            'event_type'    => 'accounting.accounts.imported',
            'summary'       => "Chart import: {$out['created']} created, {$out['updated']} updated, {$out['skipped']} skipped",
            'metadata'      => ['created' => $out['created'], 'updated' => $out['updated'], 'skipped' => $out['skipped']],
        ]);

        $out['errors'] = array_slice($out['errors'], 0, 25);
        return $out;
    }

    // ── Control-account map ──────────────────────────────────────────────

    /** All slot bindings for a company, slot => account_id. */
    public static function accountMap(int $companyId): array
    {
        $stmt = DB::pdo()->prepare('SELECT slot, account_id FROM gl_account_map WHERE company_id = :c');
        $stmt->execute(['c' => $companyId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['slot']] = (int)$r['account_id'];
        }
        return $out;
    }

    /** Slots the engine can use, with a human label and whether they're required. */
    public const SLOTS = [
        'ar'                          => ['Accounts Receivable control', true],
        'ap'                          => ['Accounts Payable control', false],
        'bank_default'                => ['Default bank account', true],
        'undeposited_funds'           => ['Undeposited funds', true],
        'pos_clearing'                => ['POS takings clearing', false],
        'gst_output'                  => ['GST output tax payable', true],
        'gst_input'                   => ['GST input tax credit', true],
        'sales_default'               => ['Default sales revenue', true],
        'cogs_default'                => ['Default cost of goods sold', false],
        'sales_returns'               => ['Sales returns & allowances', false],
        'bad_debt'                    => ['Bad debt expense', true],
        'retained_earnings'           => ['Retained earnings', true],
        'opening_balance_equity'      => ['Opening balance equity', true],
        'bank_charges'                => ['Bank & merchant charges', false],
        'payroll_clearing'            => ['Net wages payable', false],
        'paye_payable'                => ['PAYE payable', false],
        'ssb_payable'                 => ['Social security payable', false],
        'payroll_wages_expense'       => ['Salaries & wages expense', false],
        'payroll_employer_ss_expense' => ['Employer social security expense', false],
    ];

    public static function setMap(int $companyId, string $slot, int $accountId, ?int $userId, bool $silent = false): void
    {
        if (!array_key_exists($slot, self::SLOTS)) {
            throw new InvalidArgumentException('Unknown control slot: ' . $slot);
        }
        $a = self::account($companyId, $accountId);
        if (!$a) {
            throw new RuntimeException('Account not found.');
        }

        DB::pdo()->prepare(
            'INSERT INTO gl_account_map (company_id, slot, account_id) VALUES (:c, :s, :a)
             ON DUPLICATE KEY UPDATE account_id = VALUES(account_id)'
        )->execute(['c' => $companyId, 's' => $slot, 'a' => $accountId]);

        Ledger::flushCache();
        if (!$silent) {
            Audit::log([
                'actor_user_id' => $userId,
                'company_id'    => $companyId,
                'event_type'    => 'accounting.map.set',
                'summary'       => "Bound '{$slot}' to account {$a['code']} {$a['name']}",
                'metadata'      => ['slot' => $slot, 'account_id' => $accountId],
            ]);
        }
    }

    /** Slots that are required but not yet bound — blocks clean auto-posting. */
    public static function unmappedRequiredSlots(int $companyId): array
    {
        $have = self::accountMap($companyId);
        $missing = [];
        foreach (self::SLOTS as $slot => [$label, $required]) {
            if ($required && !isset($have[$slot])) {
                $missing[$slot] = $label;
            }
        }
        return $missing;
    }

    // ── Periods ─────────────────────────────────────────────────────────

    public static function periods(int $companyId, ?int $fiscalYear = null): array
    {
        $sql = 'SELECT * FROM gl_periods WHERE company_id = :c';
        $params = ['c' => $companyId];
        if ($fiscalYear !== null) {
            $sql .= ' AND fiscal_year = :fy';
            $params['fy'] = $fiscalYear;
        }
        $sql .= ' ORDER BY fiscal_year DESC, period_no ASC';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function setPeriodStatus(int $companyId, int $periodId, string $status, ?int $userId): void
    {
        if (!in_array($status, ['open', 'closed', 'locked'], true)) {
            throw new InvalidArgumentException('Invalid period status.');
        }
        $p = DB::pdo()->prepare('SELECT * FROM gl_periods WHERE id = :id AND company_id = :c LIMIT 1');
        $p->execute(['id' => $periodId, 'c' => $companyId]);
        $period = $p->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            throw new RuntimeException('Period not found.');
        }
        if ($period['status'] === 'locked' && $status !== 'locked') {
            throw new RuntimeException('A locked period cannot be reopened here — lift the lock in settings.');
        }

        $sql = 'UPDATE gl_periods SET status = :s';
        $sql .= $status === 'open' ? ', closed_at = NULL, closed_by = NULL' : ', closed_at = NOW(), closed_by = :by';
        $sql .= ' WHERE id = :id AND company_id = :c';
        $params = ['s' => $status, 'id' => $periodId, 'c' => $companyId];
        if ($status !== 'open') {
            $params['by'] = $userId;
        }
        DB::pdo()->prepare($sql)->execute($params);

        Audit::log([
            'actor_user_id' => $userId,
            'company_id'    => $companyId,
            'event_type'    => 'accounting.period.' . $status,
            'summary'       => ucfirst($status) . " period {$period['fiscal_year']}-" . str_pad((string)$period['period_no'], 2, '0', STR_PAD_LEFT),
            'metadata'      => ['period_id' => $periodId, 'status' => $status],
        ]);
    }

    // ── Journals (read) ─────────────────────────────────────────────────

    /**
     * @param array $filters ['from','to','source','account_id','q','limit','offset','include_drafts']
     */
    public static function journals(int $companyId, array $filters = []): array
    {
        $where = ['j.company_id = :c'];
        $params = ['c' => $companyId];

        if (empty($filters['include_drafts'])) {
            $where[] = "j.status <> 'draft'";
        }
        if (!empty($filters['from'])) {
            $where[] = 'j.entry_date >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'j.entry_date <= :to';
            $params['to'] = $filters['to'];
        }
        if (!empty($filters['source'])) {
            $where[] = 'j.source = :src';
            $params['src'] = $filters['source'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(j.memo LIKE :q OR j.journal_no = :qn)';
            $params['q'] = '%' . $filters['q'] . '%';
            $params['qn'] = (int)$filters['q'];
        }
        if (!empty($filters['account_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM gl_journal_lines l WHERE l.journal_id = j.id AND l.account_id = :acct)';
            $params['acct'] = (int)$filters['account_id'];
        }

        $limit  = min(200, max(1, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $sql = 'SELECT j.*, TRIM(CONCAT(COALESCE(u.first_name,\'\'),\' \',COALESCE(u.last_name,\'\'))) AS created_by_name
                  FROM gl_journals j
                  LEFT JOIN users u ON u.id = j.created_by
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY j.entry_date DESC, j.journal_no DESC
                 LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['journal_no'] = (int)$r['journal_no'];
            $r['total_debit'] = (float)$r['total_debit'];
            $r['total_credit'] = (float)$r['total_credit'];
        }
        return $rows;
    }

    public static function journal(int $companyId, int $journalId): ?array
    {
        $j = DB::pdo()->prepare('SELECT * FROM gl_journals WHERE id = :id AND company_id = :c LIMIT 1');
        $j->execute(['id' => $journalId, 'c' => $companyId]);
        $journal = $j->fetch(PDO::FETCH_ASSOC);
        if (!$journal) {
            return null;
        }
        $l = DB::pdo()->prepare(
            'SELECT l.*, a.code AS account_code, a.name AS account_name, a.type AS account_type
               FROM gl_journal_lines l
               JOIN gl_accounts a ON a.id = l.account_id
              WHERE l.journal_id = :j ORDER BY l.line_no'
        );
        $l->execute(['j' => $journalId]);
        $journal['lines'] = $l->fetchAll(PDO::FETCH_ASSOC);
        return $journal;
    }

    // ── Financial statements ────────────────────────────────────────────

    /**
     * Trial balance as at $asOf: every account with a non-zero balance (plus
     * every active account), one side each, and the grand totals (which match).
     */
    public static function trialBalance(int $companyId, string $asOf): array
    {
        $balances = Ledger::balancesAsOf($companyId, $asOf);
        $accounts = self::accounts($companyId);

        $rows = [];
        $totalDr = 0.0;
        $totalCr = 0.0;
        foreach ($accounts as $a) {
            $net = $balances[$a['id']]['net'] ?? 0.0; // debit-positive
            if (abs($net) < Ledger::EPSILON && !$a['is_active']) {
                continue;
            }
            if (abs($net) < Ledger::EPSILON) {
                continue;
            }
            $dr = $net > 0 ? round($net, 2) : 0.0;
            $cr = $net < 0 ? round(-$net, 2) : 0.0;
            $totalDr += $dr;
            $totalCr += $cr;
            $rows[] = [
                'account_id' => $a['id'],
                'code'       => $a['code'],
                'name'       => $a['name'],
                'type'       => $a['type'],
                'debit'      => $dr,
                'credit'     => $cr,
            ];
        }

        return [
            'as_of'        => $asOf,
            'rows'         => $rows,
            'total_debit'  => round($totalDr, 2),
            'total_credit' => round($totalCr, 2),
            'balanced'     => abs($totalDr - $totalCr) < 0.01,
        ];
    }

    /**
     * Profit & loss for a date range. Income & contra-income net within Income;
     * COGS group yields gross profit; expenses yield net profit.
     */
    public static function profitAndLoss(int $companyId, string $from, string $to): array
    {
        $activity = Ledger::activity($companyId, $from, $to);
        $accounts = self::accounts($companyId);

        $groups = ['income' => [], 'cogs' => [], 'expense' => []];
        $totals = ['income' => 0.0, 'cogs' => 0.0, 'expense' => 0.0];

        foreach ($accounts as $a) {
            if (!in_array($a['type'], ['income', 'cogs', 'expense'], true)) {
                continue;
            }
            $act = $activity[$a['id']] ?? null;
            if ($act === null) {
                continue;
            }
            // Income: credit-positive. COGS / expense: debit-positive.
            $amount = $a['type'] === 'income'
                ? round($act['credit'] - $act['debit'], 2)
                : round($act['debit'] - $act['credit'], 2);
            if (abs($amount) < Ledger::EPSILON) {
                continue;
            }
            $groups[$a['type']][] = [
                'account_id' => $a['id'],
                'code'       => $a['code'],
                'name'       => $a['name'],
                'amount'     => $amount,
            ];
            $totals[$a['type']] += $amount;
        }

        $income = round($totals['income'], 2);
        $cogs   = round($totals['cogs'], 2);
        $expense = round($totals['expense'], 2);
        $grossProfit = round($income - $cogs, 2);
        $netProfit   = round($grossProfit - $expense, 2);

        return [
            'from'         => $from,
            'to'           => $to,
            'income'       => $groups['income'],
            'cogs'         => $groups['cogs'],
            'expense'      => $groups['expense'],
            'total_income' => $income,
            'total_cogs'   => $cogs,
            'gross_profit' => $grossProfit,
            'total_expense' => $expense,
            'net_profit'   => $netProfit,
        ];
    }

    /**
     * Balance sheet as at $asOf. Equity includes the accounts plus current-year
     * earnings (this fiscal year's P&L, not yet closed to retained earnings).
     */
    public static function balanceSheet(int $companyId, string $asOf): array
    {
        $balances = Ledger::balancesAsOf($companyId, $asOf);
        $accounts = self::accounts($companyId);

        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $totals   = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];

        foreach ($accounts as $a) {
            if (!in_array($a['type'], ['asset', 'liability', 'equity'], true)) {
                continue;
            }
            $net = $balances[$a['id']]['net'] ?? 0.0; // debit-positive
            $amount = $a['type'] === 'asset' ? round($net, 2) : round(-$net, 2);
            if (abs($amount) < Ledger::EPSILON) {
                continue;
            }
            $sections[$a['type']][] = [
                'account_id' => $a['id'],
                'code'       => $a['code'],
                'name'       => $a['name'],
                'amount'     => $amount,
            ];
            $totals[$a['type']] += $amount;
        }

        $fyStart = Ledger::fiscalYearStart($companyId, $asOf);
        $pl = self::profitAndLoss($companyId, $fyStart, $asOf);
        $currentEarnings = $pl['net_profit'];

        $totalAssets      = round($totals['asset'], 2);
        $totalLiabilities = round($totals['liability'], 2);
        $totalEquity      = round($totals['equity'] + $currentEarnings, 2);

        return [
            'as_of'                  => $asOf,
            'assets'                 => $sections['asset'],
            'liabilities'            => $sections['liability'],
            'equity'                 => $sections['equity'],
            'current_year_earnings'  => $currentEarnings,
            'total_assets'           => $totalAssets,
            'total_liabilities'      => $totalLiabilities,
            'total_equity'           => $totalEquity,
            'total_liabilities_equity' => round($totalLiabilities + $totalEquity, 2),
            'balanced'               => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
        ];
    }

    /**
     * General ledger detail for one account: opening balance, every posted line
     * in the range with a running balance, closing balance.
     */
    public static function generalLedger(int $companyId, int $accountId, string $from, string $to): array
    {
        $account = self::account($companyId, $accountId);
        if (!$account) {
            throw new RuntimeException('Account not found.');
        }
        $signDebit = $account['normal_balance'] === 'debit' ? 1 : -1;

        $dayBefore = date('Y-m-d', strtotime($from . ' -1 day'));
        $opening = Ledger::balancesAsOf($companyId, $dayBefore)[$accountId]['net'] ?? 0.0; // debit-positive
        $openingSigned = round($opening * $signDebit, 2);

        $stmt = DB::pdo()->prepare(
            'SELECT l.id, l.debit, l.credit, l.memo, l.entry_date, l.customer_id, l.vendor_id,
                    j.id AS journal_id, j.journal_no, j.memo AS journal_memo, j.source, j.source_ref
               FROM gl_journal_lines l
               JOIN gl_journals j ON j.id = l.journal_id
              WHERE l.company_id = :c AND l.account_id = :a AND j.status = "posted"
                AND l.entry_date BETWEEN :from AND :to
              ORDER BY l.entry_date, j.journal_no, l.line_no'
        );
        $stmt->execute(['c' => $companyId, 'a' => $accountId, 'from' => $from, 'to' => $to]);

        $running = $openingSigned;
        $rows = [];
        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dr = (float)$r['debit'];
            $cr = (float)$r['credit'];
            $running = round($running + ($dr - $cr) * $signDebit, 2);
            $sumDr += $dr;
            $sumCr += $cr;
            $rows[] = [
                'date'         => $r['entry_date'],
                'journal_id'   => (int)$r['journal_id'],
                'journal_no'   => (int)$r['journal_no'],
                'memo'         => $r['memo'] !== '' ? $r['memo'] : $r['journal_memo'],
                'source'       => $r['source'],
                'debit'        => round($dr, 2),
                'credit'       => round($cr, 2),
                'balance'      => $running,
            ];
        }

        return [
            'account' => [
                'id'             => (int)$account['id'],
                'code'           => $account['code'],
                'name'           => $account['name'],
                'type'           => $account['type'],
                'normal_balance' => $account['normal_balance'],
            ],
            'from'            => $from,
            'to'              => $to,
            'opening_balance' => $openingSigned,
            'rows'            => $rows,
            'total_debit'    => round($sumDr, 2),
            'total_credit'   => round($sumCr, 2),
            'closing_balance' => $running,
        ];
    }

    /**
     * Year-end close: move every income / COGS / expense balance for the fiscal
     * year into Retained Earnings with one closing journal dated the last day of
     * the year, then mark the year's periods 'closed'.
     */
    public static function yearEndClose(int $companyId, int $fiscalYear, ?int $userId): int
    {
        $periods = self::periods($companyId, $fiscalYear);
        if (!$periods) {
            throw new RuntimeException('That fiscal year has no periods.');
        }
        $lastDay = $periods[0]['end_date']; // periods() is period_no ASC within a year? it's DESC by year then ASC by period
        foreach ($periods as $p) {
            if ($p['end_date'] > $lastDay) {
                $lastDay = $p['end_date'];
            }
        }
        $firstDay = $periods[0]['start_date'];
        foreach ($periods as $p) {
            if ($p['start_date'] < $firstDay) {
                $firstDay = $p['start_date'];
            }
        }

        $pl = self::profitAndLoss($companyId, $firstDay, $lastDay);
        $retained = Ledger::slotAccountId($companyId, 'retained_earnings');

        $lines = [];
        foreach (['income', 'cogs', 'expense'] as $grp) {
            foreach ($pl[$grp] as $row) {
                // Close the account: post the opposite of its P&L balance.
                if ($grp === 'income') {
                    // income sits credit; to zero it, debit it
                    $lines[] = ['account_id' => $row['account_id'], 'debit' => abs($row['amount']), 'credit' => 0];
                } else {
                    $lines[] = ['account_id' => $row['account_id'], 'debit' => 0, 'credit' => abs($row['amount'])];
                }
            }
        }
        if (!$lines) {
            throw new RuntimeException('Nothing to close for ' . $fiscalYear . '.');
        }
        // Balancing line to Retained Earnings.
        $net = $pl['net_profit'];
        $lines[] = $net >= 0
            ? ['account_id' => $retained, 'debit' => 0, 'credit' => round($net, 2)]
            : ['account_id' => $retained, 'debit' => round(-$net, 2), 'credit' => 0];

        $journalId = Ledger::post($companyId, [
            'date'    => $lastDay,
            'memo'    => 'Year-end close ' . $fiscalYear,
            'source'  => 'closing',
            'status'  => 'posted',
            'system'  => true,
            'user_id' => $userId,
            'lines'   => $lines,
        ]);

        DB::pdo()->prepare(
            "UPDATE gl_periods SET status = 'closed', closed_at = NOW(), closed_by = :by
              WHERE company_id = :c AND fiscal_year = :fy AND status = 'open'"
        )->execute(['by' => $userId, 'c' => $companyId, 'fy' => $fiscalYear]);

        Audit::log([
            'actor_user_id' => $userId,
            'company_id'    => $companyId,
            'event_type'    => 'accounting.year_end_close',
            'summary'       => "Closed fiscal year {$fiscalYear} — net " . number_format($net, 2) . " to Retained Earnings",
            'metadata'      => ['fiscal_year' => $fiscalYear, 'journal_id' => $journalId, 'net_profit' => $net],
        ]);
        return $journalId;
    }

    // ── Desk summary ────────────────────────────────────────────────────

    /** The accounting home page: setup state, this period, quick P&L, drafts. */
    public static function deskSummary(int $companyId): array
    {
        $cfg = Ledger::config($companyId);
        if ($cfg === null || empty($cfg['activated_at'])) {
            return ['activated' => false];
        }

        $today = date('Y-m-d');
        $period = Ledger::periodForDate($companyId, $today);
        $fyStart = Ledger::fiscalYearStart($companyId, $today);
        $pl = self::profitAndLoss($companyId, $fyStart, $today);

        $drafts = DB::pdo()->prepare(
            "SELECT COUNT(*) FROM gl_journals WHERE company_id = :c AND status = 'draft'"
        );
        $drafts->execute(['c' => $companyId]);

        $lastJournal = DB::pdo()->prepare(
            "SELECT entry_date, journal_no FROM gl_journals
              WHERE company_id = :c AND status = 'posted'
              ORDER BY id DESC LIMIT 1"
        );
        $lastJournal->execute(['c' => $companyId]);

        return [
            'activated'        => true,
            'base_currency'    => $cfg['base_currency'],
            'fiscal_year_start_month' => (int)$cfg['fiscal_year_start_month'],
            'lock_before'      => $cfg['lock_before'],
            'current_period'   => $period,
            'fy_start'         => $fyStart,
            'ytd'              => [
                'income'       => $pl['total_income'],
                'gross_profit' => $pl['gross_profit'],
                'net_profit'   => $pl['net_profit'],
            ],
            'draft_journals'   => (int)$drafts->fetchColumn(),
            'last_journal'     => $lastJournal->fetch(PDO::FETCH_ASSOC) ?: null,
            'unmapped_slots'   => self::unmappedRequiredSlots($companyId),
            'ar' => [
                'started_on' => GlSync::arStartedOn($companyId),
                'pending'    => GlSync::arEnabled($companyId) ? GlSync::pendingCount($companyId) : 0,
            ],
            'expenses' => ExpensesService::summary($companyId),
        ];
    }

    public static function updateSettings(int $companyId, array $data, ?int $userId): void
    {
        $fields = [];
        $params = ['c' => $companyId];
        if (isset($data['fiscal_year_start_month'])) {
            $m = (int)$data['fiscal_year_start_month'];
            if ($m >= 1 && $m <= 12) {
                $fields[] = 'fiscal_year_start_month = :m';
                $params['m'] = $m;
            }
        }
        if (array_key_exists('lock_before', $data)) {
            $lb = trim((string)$data['lock_before']);
            $fields[] = 'lock_before = :lb';
            $params['lb'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $lb) ? $lb : null;
        }
        if (isset($data['base_currency'])) {
            $fields[] = 'base_currency = :cur';
            $params['cur'] = strtoupper(mb_substr(trim((string)$data['base_currency']), 0, 3)) ?: 'BZD';
        }
        if (!$fields) {
            return;
        }
        DB::pdo()->prepare('UPDATE company_accounting SET ' . implode(', ', $fields) . ' WHERE company_id = :c')
            ->execute($params);
        Ledger::flushCache();

        Audit::log([
            'actor_user_id' => $userId,
            'company_id'    => $companyId,
            'event_type'    => 'accounting.settings.updated',
            'summary'       => 'Updated accounting settings',
            'metadata'      => array_diff_key($params, ['c' => 1]),
        ]);
    }
}
