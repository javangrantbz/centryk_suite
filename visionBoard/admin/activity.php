<?php
$active = 'activity';
$pageTitle = 'Activity Log';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$pdo = db();
$logsStmt = $pdo->prepare(
    'SELECT * FROM vb_activity_logs WHERE company_id = ? ORDER BY created_at DESC, id DESC LIMIT 300'
);
$logsStmt->execute([vb_cid()]);
$logs = $logsStmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="flex items-center justify-between mb-4">
  <div>
    <h1 class="text-xl font-black tracking-tight text-slate-900">Activity Log</h1>
    <p class="text-sm text-slate-500">Recent admin and editor changes.</p>
  </div>
</div>

<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50 text-slate-500">
        <tr>
          <th class="text-left font-semibold px-4 py-3">Time</th>
          <th class="text-left font-semibold px-4 py-3">User</th>
          <th class="text-left font-semibold px-4 py-3">Action</th>
          <th class="text-left font-semibold px-4 py-3">Item</th>
          <th class="text-left font-semibold px-4 py-3">Details</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php foreach ($logs as $log): ?>
          <tr>
            <td class="px-4 py-3 whitespace-nowrap text-slate-500"><?= e(date('M j, g:i A', strtotime($log['created_at']))) ?></td>
            <td class="px-4 py-3 whitespace-nowrap"><?= e($log['username'] ?: 'System') ?></td>
            <td class="px-4 py-3 whitespace-nowrap">
              <span class="rounded-full bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700"><?= e($log['action']) ?></span>
            </td>
            <td class="px-4 py-3 whitespace-nowrap text-slate-600">
              <?= e($log['entity_type'] ?: '-') ?><?= $log['entity_id'] ? ' #' . (int) $log['entity_id'] : '' ?>
            </td>
            <td class="px-4 py-3 text-slate-600 max-w-xl"><?= e($log['details'] ?: '') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?>
          <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No activity recorded yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
