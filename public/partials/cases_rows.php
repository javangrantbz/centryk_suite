<?php
/**
 * Server-rendered case rows for cases.php's initial load. Expects $cases,
 * $statusChip, $priorityChip in scope. Mirrors renderCases() in cases.php's
 * inline script, which takes over for filtered reloads.
 */
if (!$cases) {
    echo '<div class="biz-panel-empty" style="padding:24px">No cases match this filter.</div>';
    return;
}
foreach ($cases as $c):
    $asg = trim(($c['asg_first'] ?? '') . ' ' . ($c['asg_last'] ?? ''));
?>
<div class="biz-row" style="align-items:flex-start">
    <div class="min-w-0 flex-1">
        <a href="case.php?id=<?= (int)$c['id'] ?>&company_id=<?= $companyId ?>" class="block font-bold" style="text-decoration:none;color:var(--bz-accent-d)">
            <?= htmlspecialchars($c['case_number']) ?> — <?= htmlspecialchars($c['subject']) ?>
        </a>
        <div class="biz-muted mt-0.5" style="font-size:11px">
            <span class="biz-chip <?= $statusChip($c['status']) ?>"><?= htmlspecialchars(str_replace('_', ' ', $c['status'])) ?></span>
            <span class="biz-chip <?= $priorityChip($c['priority']) ?>"><?= htmlspecialchars($c['priority']) ?></span>
            <?php if ($c['service_name']): ?>· <?= htmlspecialchars($c['service_name']) ?><?php endif; ?>
            · requested by <?= htmlspecialchars($c['req_first'] . ' ' . $c['req_last']) ?>
            <?= $asg !== '' ? '· assigned to ' . htmlspecialchars($asg) : '· unassigned' ?>
            <?php if ($c['due_date']): ?>· due <?= htmlspecialchars($c['due_date']) ?><?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
