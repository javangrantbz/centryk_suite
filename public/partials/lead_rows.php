<?php
/**
 * Server-rendered lead rows for leads.php's initial load. Expects $leads,
 * $statusChip, $companyId, $isManager in scope. Mirrors renderLeads() in
 * leads.php's inline script, which takes over for filtered reloads.
 */
if (!$leads) {
    echo '<div class="biz-panel-empty" style="padding:24px">No customers match this filter.</div>';
    return;
}
foreach ($leads as $l):
    $agent = trim(($l['agent_first'] ?? '') . ' ' . ($l['agent_last'] ?? ''));
    $due = $l['next_follow_up_date'] && $l['next_follow_up_date'] <= date('Y-m-d') && $l['status'] !== 'inactive';
?>
<button class="biz-row" style="align-items:flex-start;width:100%;text-align:left" onclick="openDetail(<?= (int)$l['id'] ?>)">
    <div class="min-w-0 flex-1">
        <span class="block font-bold" style="color:var(--bz-fg)"><?= htmlspecialchars($l['business_name']) ?></span>
        <div class="biz-muted mt-0.5" style="font-size:11px">
            <span class="biz-chip <?= $statusChip($l['status']) ?>"><?= htmlspecialchars($l['status']) ?></span>
            <?php if ($due): ?><span class="biz-chip biz-c-red">follow up due</span><?php endif; ?>
            <?php if ($l['contact_name']): ?>· <?= htmlspecialchars($l['contact_name']) ?><?php endif; ?>
            <?php if ($l['phone']): ?>· <?= htmlspecialchars($l['phone']) ?><?php endif; ?>
            <?php if ($isManager && $agent !== ''): ?>· added by <?= htmlspecialchars($agent) ?><?php endif; ?>
        </div>
        <?php if ($l['order_details']): ?>
        <div class="biz-muted mt-0.5" style="font-size:11px"><?= htmlspecialchars($l['order_details']) ?><?= $l['order_value'] ? ' · BZ$' . number_format((float)$l['order_value'], 2) : '' ?></div>
        <?php endif; ?>
    </div>
</button>
<?php endforeach; ?>
