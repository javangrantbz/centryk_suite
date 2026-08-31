<?php
/**
 * Slim "free preview" strip shown at the top of every Business tool page while
 * the active company is on the promo. Expects $activeCompany. Requires
 * Entitlements (business_sidebar.php has already pulled it in).
 */
$_bpn = isset($activeCompany['id']) ? Entitlements::promoInfo((int)$activeCompany['id']) : null;
if ($_bpn):
    $_bpnEnds = date('j M Y', strtotime($_bpn['ends_on']));
    $_bpnPaid = date('F Y', strtotime($_bpn['paid_from']));
    $_bpnSoon = $_bpn['days_left'] <= 60;
?>
<div class="biz" style="
    display:flex; flex-wrap:wrap; align-items:center; gap:8px;
    border:1px solid <?= $_bpnSoon ? '#fcd9a5' : '#c7d2fe' ?>;
    background:<?= $_bpnSoon ? '#fffbeb' : '#eef2ff' ?>;
    color:<?= $_bpnSoon ? '#92400e' : '#3730a3' ?>;
    border-radius:5px; padding:6px 11px; margin:12px 12px 0; font-size:12px;">
    <span style="font-weight:800; text-transform:uppercase; letter-spacing:0.05em; font-size:10px;">Free preview</span>
    <span style="flex:1; min-width:200px;">
        Centryk Business is free until <strong><?= htmlspecialchars($_bpnEnds) ?></strong>.
        Paid plans begin <?= htmlspecialchars($_bpnPaid) ?>.
        <?php if ($_bpnSoon): ?><strong>· <?= (int)$_bpn['days_left'] ?> days left</strong><?php endif; ?>
    </span>
    <a href="business.php?company_id=<?= (int)$activeCompany['id'] ?>" style="font-weight:700; text-decoration:underline;">What's included</a>
</div>
<?php endif; ?>
