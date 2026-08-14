<?php $currentCompanyId = $_SESSION['mobile_current_company_id'] ?? null; ?>
<div class="px-4 py-4">
  <div class="mb-5 flex items-center gap-3">
    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xl font-black text-white">
      <?= h(strtoupper(substr($user['first_name'] ?? '?', 0, 1))) ?>
    </div>
    <div class="min-w-0">
      <div class="truncate text-base font-black text-slate-900"><?= h(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></div>
      <div class="truncate text-xs font-semibold text-slate-500"><?= h($user['email'] ?? '') ?></div>
    </div>
  </div>

  <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-100 px-4 py-3">
      <h2 class="text-sm font-black text-slate-900">Company</h2>
      <p class="text-xs font-semibold text-slate-400">Switch which company's data you're viewing</p>
    </div>
    <div class="divide-y divide-slate-100">
      <?php if (!$companies): ?>
        <p class="px-4 py-4 text-sm font-medium text-slate-400">No companies on your account yet.</p>
      <?php else: foreach ($companies as $company): $isActive = (int)($company['id'] ?? 0) === $currentCompanyId; ?>
        <a href="app.php?switch_company=<?= (int)$company['id'] ?>"
           class="flex items-center gap-3 px-4 py-3 <?= $isActive ? 'bg-blue-50/60' : 'hover:bg-slate-50' ?>">
          <?php if (!empty($company['logo'])): ?>
            <img src="<?= h('../' . ltrim($company['logo'], '/')) ?>" alt="" class="h-9 w-9 shrink-0 rounded-lg border border-slate-200 object-cover bg-white">
          <?php else: ?>
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs font-black text-slate-400"><?= h(strtoupper(substr($company['name'] ?? '?', 0, 1))) ?></div>
          <?php endif; ?>
          <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-bold text-slate-900"><?= h($company['name'] ?? '') ?></div>
            <div class="text-[10px] font-bold uppercase tracking-wide text-slate-400"><?= h($company['role'] ?? '') ?></div>
          </div>
          <?php if ($isActive): ?>
            <i data-lucide="check-circle-2" class="h-5 w-5 shrink-0 text-blue-600"></i>
          <?php endif; ?>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <a href="../logout.php" class="mt-5 flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-rose-200 py-3.5 text-sm font-black uppercase tracking-widest text-rose-600 hover:bg-rose-50">
    <i data-lucide="log-out" class="h-4 w-4"></i>
    Sign Out
  </a>

  <a href="../desktop.php" class="mt-3 block text-center text-xs font-semibold text-slate-400 hover:text-slate-600">
    Continue to desktop site
  </a>
</div>
