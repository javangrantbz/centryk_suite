<?php
/**
 * Left rail for the Centryk Business tools. Include right after
 * account_header.php; close the layout with business_sidebar_end.php before
 * </body>.
 *
 * Expects:
 *   $bizNav        — current section key: insights|accounting|receivables|
 *                    reconciliation|routes|groups|tax  (accounting sub-pages
 *                    all use 'accounting')
 *   $activeCompany — ['id'=>int,'name'=>string] for the page's current company
 *
 * The rail only lists modules the active company actually holds (its own grant
 * or one inherited from its group). The company <select> switches the page for
 * every Business company the user administers.
 */
require_once __DIR__ . '/../../app/core/DB.php';
require_once __DIR__ . '/../../app/core/Auth.php';
require_once __DIR__ . '/../../app/core/Entitlements.php';

$_bsUser = Auth::user() ?? [];
$_bsNav  = $bizNav ?? '';
$_bsCid  = isset($activeCompany['id']) ? (int)$activeCompany['id'] : 0;

// Every Business company this user can manage (for the switcher).
$_bsCompanies = [];
if (!empty($_bsUser['id'])) {
    $stmt = DB::pdo()->prepare("
        SELECT DISTINCT c.id, c.name
        FROM company_members cm
        JOIN companies c ON c.id = cm.company_id
        JOIN company_entitlements e ON e.company_id = c.id AND e.state <> 'revoked'
        WHERE cm.user_id = :uid AND cm.status = 'active'
          AND cm.role IN ('admin','manager') AND c.status = 'active'
        ORDER BY c.name ASC
    ");
    $stmt->execute(['uid' => (int)$_bsUser['id']]);
    $_bsCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$_bsHeld = $_bsCid ? Entitlements::forCompany($_bsCid) : [];   // package_key => level
$_bsHasAny = $_bsHeld !== [];
$_bsPromo  = $_bsCid ? Entitlements::promoInfo($_bsCid) : null;

// key => [label, href, icon, requires-package (null = show whenever any package is held)]
$_bsModules = [
    ['insights',       'Insights',             'business_insights.php', 'bar-chart-3', null],
    ['accounting',     'Accounting',           'accounting.php',        'book-open',   'accounting'],
    ['receivables',    'Receivables',          'receivables.php',       'wallet',      'receivables'],
    ['reconciliation', 'Reconciliation',       'reconciliation.php',    'scale',       'reconciliation'],
    ['routes',         'Field Sales & Routes', 'routes.php',            'truck',       'routes'],
    ['groups',         'Company Groups',       'groups.php',            'building-2',  'enterprise'],
];
$_bsExtras = [
    ['tax',      'GST summary',   'business_tax.php', 'receipt',   'receivables'],
    ['explore',  'Explore more',  'business.php',     'plus',      false],   // false = always
];

$_bsLink = static function (string $href) use ($_bsCid): string {
    if ($href === 'business.php' && $_bsCid) {
        return 'business.php?company_id=' . $_bsCid;
    }
    return $_bsCid ? ($href . '?company_id=' . $_bsCid) : $href;
};
?>
<div class="biz biz-layout">
  <aside class="biz-side">
    <?php if (count($_bsCompanies) > 1): ?>
    <div class="biz-side-co">
      <select onchange="if(this.value)location.href=this.value">
        <?php foreach ($_bsCompanies as $c):
            $sel = (int)$c['id'] === $_bsCid ? ' selected' : '';
            // keep the current page, swap the company
            $page = basename($_SERVER['PHP_SELF']);
        ?>
        <option value="<?= htmlspecialchars($page) ?>?company_id=<?= (int)$c['id'] ?>"<?= $sel ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php elseif ($_bsCid && !empty($activeCompany['name'])): ?>
    <div class="biz-side-co"><div class="biz-nav-label" style="padding-top:2px"><?= htmlspecialchars($activeCompany['name']) ?></div></div>
    <?php endif; ?>

    <?php if ($_bsHasAny): ?>
    <div class="biz-side-co" style="margin-top:0">
      <span class="biz-chip biz-c-accent" style="width:100%; text-align:center; padding:3px 0;">Centryk Business</span>
    </div>
    <?php endif; ?>

    <?php if ($_bsPromo): ?>
    <div class="biz-side-co" style="margin-top:0">
      <span class="biz-chip biz-c-blue" style="width:100%; text-align:center; padding:3px 0;">Free preview</span>
    </div>
    <?php endif; ?>

    <div class="biz-nav-label">Modules</div>
    <?php foreach ($_bsModules as [$key, $label, $href, $icon, $needs]):
        $show = $needs === null ? $_bsHasAny : isset($_bsHeld[$needs]);
        if (!$show) { continue; }
        $active = $_bsNav === $key ? ' is-active' : '';
        $paused = $needs !== null && ($_bsHeld[$needs] ?? '') === 'read';
    ?>
    <a class="biz-nav-item<?= $active ?>" href="<?= htmlspecialchars($_bsLink($href)) ?>">
      <i data-lucide="<?= $icon ?>"></i><span><?= htmlspecialchars($label) ?></span>
      <?php if ($paused): ?><span style="margin-left:auto" class="biz-chip biz-c-amber">paused</span><?php endif; ?>
    </a>
    <?php endforeach; ?>

    <div class="biz-nav-sep"></div>
    <?php foreach ($_bsExtras as [$key, $label, $href, $icon, $needs]):
        $show = $needs === false ? true : isset($_bsHeld[$needs]);
        if (!$show) { continue; }
        $active = $_bsNav === $key ? ' is-active' : '';
    ?>
    <a class="biz-nav-item<?= $active ?>" href="<?= htmlspecialchars($_bsLink($href)) ?>">
      <i data-lucide="<?= $icon ?>"></i><span><?= htmlspecialchars($label) ?></span>
    </a>
    <?php endforeach; ?>
  </aside>
  <div class="biz-layout-main">
<?php include __DIR__ . '/business_promo_notice.php'; ?>
<?php /* page content follows; closed by business_sidebar_end.php */ ?>
