<?php
$currentCompany = null;
foreach ($companies as $company) {
    if ((int)($company['id'] ?? 0) === (int)($_SESSION['mobile_current_company_id'] ?? 0)) {
        $currentCompany = $company;
        break;
    }
}
?>
<div class="space-y-4 px-4 py-4">
  <section class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-950 text-white shadow-sm">
    <div class="bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.16),transparent_34%),linear-gradient(135deg,#0f172a_0%,#111827_62%,#1e293b_100%)] px-4 py-5">
      <p class="text-[11px] font-black uppercase tracking-[0.18em] text-white/55">Mobile Hub</p>
      <div class="mt-2 flex items-start justify-between gap-3">
        <div class="min-w-0">
          <h1 class="text-2xl font-black tracking-tight"><?= h($currentAppLabel) ?></h1>
          <p class="mt-1 text-sm font-medium text-white/70">
            <?= h($currentCompany['name'] ?? 'No company selected') ?>
          </p>
        </div>
        <span class="rounded-full px-3 py-1 text-[11px] font-black uppercase tracking-[0.14em]" style="background:<?= h($appColor) ?>33; color:#fff;">
          <?= count($enrolledApps) ?> Apps
        </span>
      </div>
      <div class="mt-4 grid grid-cols-2 gap-2">
        <a href="app.php?tab=notifications" class="rounded-2xl bg-white/10 px-3 py-3 backdrop-blur">
          <div class="text-[10px] font-black uppercase tracking-[0.14em] text-white/45">Inbox</div>
          <div class="mt-1 text-sm font-bold text-white">Notifications</div>
        </a>
        <a href="app.php?tab=account" class="rounded-2xl bg-white/10 px-3 py-3 backdrop-blur">
          <div class="text-[10px] font-black uppercase tracking-[0.14em] text-white/45">Profile</div>
          <div class="mt-1 text-sm font-bold text-white">Account</div>
        </a>
      </div>
    </div>
  </section>

  <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="mb-3 flex items-center justify-between gap-3">
      <div>
        <h2 class="text-sm font-black text-slate-900">Quick Actions</h2>
        <p class="text-xs font-semibold text-slate-500">Most-used pages for this app</p>
      </div>
    </div>
    <?php if ($pagesForApp): ?>
      <div class="space-y-2">
        <?php foreach ($pagesForApp as $page): ?>
          <a href="app.php?tab=home&view=<?= urlencode($page['key']) ?>" class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 px-4 py-3 hover:bg-slate-50">
            <div class="flex min-w-0 items-center gap-3">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl" style="background:<?= h($appColor) ?>1a; color:<?= h($appColor) ?>;">
                <i data-lucide="<?= h($page['icon']) ?>" class="h-4 w-4"></i>
              </span>
              <div class="min-w-0">
                <div class="truncate text-sm font-black text-slate-900"><?= h($page['label']) ?></div>
                <div class="text-xs font-semibold text-slate-500">Open <?= h($currentAppLabel) ?> on mobile</div>
              </div>
            </div>
            <i data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-slate-300"></i>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center">
        <p class="text-sm font-black text-slate-800">This app is not wired up yet.</p>
        <p class="mt-1 text-xs font-semibold text-slate-500">Use the app switcher above to jump into a mobile-ready area.</p>
      </div>
    <?php endif; ?>
  </section>

  <section class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="mb-3">
      <h2 class="text-sm font-black text-slate-900">Your Apps</h2>
      <p class="text-xs font-semibold text-slate-500">Switch the mobile shell between tools you can access</p>
    </div>
    <div class="space-y-2">
      <?php foreach ($enrolledApps as $app): $isCurrent = ($app['key'] ?? '') === $currentApp; ?>
        <a href="app.php?switch_app=<?= urlencode($app['key']) ?>"
           class="flex items-center justify-between gap-3 rounded-2xl border px-4 py-3 <?= $isCurrent ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-900 hover:bg-slate-50' ?>">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background:<?= h($app['color'] ?: '#64748b') ?>;"></span>
              <div class="truncate text-sm font-black"><?= h($app['label']) ?></div>
            </div>
            <div class="mt-1 truncate text-xs font-semibold <?= $isCurrent ? 'text-white/60' : 'text-slate-500' ?>">
              <?= h($app['description'] ?? 'Mobile access through Centryk.') ?>
            </div>
          </div>
          <?php if ($isCurrent): ?>
            <i data-lucide="check-circle-2" class="h-5 w-5 shrink-0 text-white"></i>
          <?php else: ?>
            <i data-lucide="arrow-up-right" class="h-4 w-4 shrink-0 text-slate-300"></i>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
</div>
