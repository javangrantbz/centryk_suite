<?php
/**
 * Sub-navigation for the Accounting pages. Expects $nav ('home'|'accounts'|
 * 'journal'|'reports') and $activeCompany.
 */
$_acid = (int)($activeCompany['id'] ?? 0);
$_items = [
    'home'     => ['Home',              'accounting.php'],
    'accounts' => ['Chart of accounts', 'gl_accounts.php'],
    'journal'  => ['Journal',           'gl_journal.php'],
    'expenses' => ['Expenses',          'expenses.php'],
    'reports'  => ['Financial statements', 'gl_reports.php'],
];
?>
<div class="biz-tabs mb-1">
    <?php foreach ($_items as $key => [$label, $href]): ?>
        <a class="biz-tab <?= ($nav ?? '') === $key ? 'is-active' : '' ?>"
           href="<?= htmlspecialchars($href) ?>?company_id=<?= $_acid ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
</div>
