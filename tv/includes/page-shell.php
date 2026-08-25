<?php

function tv_render_page_header(string $title, string $subtitle = '', array $actions = [], bool $showOrgSwitcher = false): void
{
    $user = tv_user();
    $displayName = '';
    $initial = 'U';
    if ($user) {
        $displayName = trim((string)(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
        if ($displayName === '') {
            $displayName = (string)($user['email'] ?? 'User');
        }
        $initial = strtoupper(substr((string)($user['first_name'] ?? $displayName), 0, 1));
    }
    // Only meaningful on pages about the CURRENT user's own management
    // context (Go Live picking a channel to stream to) - not on pages like
    // watch.php that show someone else's event/organization, where this
    // viewer's own org membership (if any) is irrelevant to what's on screen.
    $organizations = $showOrgSwitcher ? tv_user_organizations() : [];
    $activeOrganization = $showOrgSwitcher ? tv_active_organization() : null;
    ?>
    <div class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-[1400px] items-center gap-3 px-4 py-2.5 lg:px-5">
            <a href="<?= e(centryk_public_url() . '/') ?>" class="shrink-0 transition hover:opacity-80" title="Back to Centryk">
                <img src="<?= e(centryk_public_url() . '/assets/centryk_logo_c.png') ?>" alt="Centryk" class="h-9 w-9 rounded-lg object-contain ring-1 ring-slate-200">
            </a>
            <div class="h-5 w-px bg-slate-200"></div>
            <div class="min-w-0">
                <p class="truncate text-sm font-bold text-slate-900"><?= e($title) ?></p>
                <?php if ($subtitle !== ''): ?>
                    <p class="truncate text-[11px] font-semibold text-slate-400"><?= e($subtitle) ?></p>
                <?php endif; ?>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <?php if (count($organizations) > 1): ?>
                    <select onchange="if (this.value) { window.location.href = this.value; }" class="hidden rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs font-semibold text-slate-600 outline-none focus:border-brand-500 sm:block">
                        <?php foreach ($organizations as $item): ?>
                            <option value="<?= e(tv_current_url_with_organization((int)$item['id'])) ?>" <?= (int)$item['id'] === (int)($activeOrganization['id'] ?? 0) ? 'selected' : '' ?>>
                                <?= e((string)$item['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php foreach ($actions as $action): ?>
                    <?php
                    $href = (string)($action['href'] ?? '#');
                    $label = (string)($action['label'] ?? '');
                    $kind = (string)($action['kind'] ?? 'secondary');
                    $classes = $kind === 'primary'
                        ? 'rounded-lg bg-slate-900 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800'
                        : ($kind === 'danger'
                            ? 'rounded-lg bg-rose-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-rose-500'
                            : 'rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100');
                    ?>
                    <a href="<?= e($href) ?>" class="<?= $classes ?>"><?= e($label) ?></a>
                <?php endforeach; ?>

                <?php if ($user): ?>
                    <div class="relative" id="tvPageUserMenuWrap">
                        <button id="tvPageUserMenuBtn" class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 transition hover:bg-slate-100">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-[12px] font-black text-slate-700"><?= e($initial) ?></div>
                            <div class="hidden text-left sm:block">
                                <p class="text-sm font-semibold leading-tight text-slate-800"><?= e($displayName) ?></p>
                                <p class="text-[10px] leading-tight text-slate-400"><?= e((string)($user['email'] ?? '')) ?></p>
                            </div>
                        </button>
                        <div id="tvPageUserMenu" class="absolute right-0 top-full z-50 mt-2 hidden w-56 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
                            <div class="border-b border-slate-100 px-3.5 py-3">
                                <p class="truncate text-sm font-bold text-slate-900"><?= e($displayName) ?></p>
                                <p class="mt-0.5 truncate text-[11px] text-slate-400"><?= e((string)($user['email'] ?? '')) ?></p>
                            </div>
                            <div class="p-1.5">
                                <a href="<?= e(centryk_public_url() . '/profile.php') ?>" class="flex items-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">Account</a>
                                <?php if (tv_has_app_access((int)$user['id'])): ?>
                                    <a href="<?= e(tv_url('dashboard')) ?>" class="flex items-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">Studio</a>
                                <?php endif; ?>
                                <button id="tvPageLogoutBtn" class="flex w-full items-center rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-600 transition hover:bg-red-50 hover:text-red-600">Sign out</button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= e(centryk_public_url() . '/login.php?redirect=' . urlencode(tv_current_path())) ?>" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($showOrgSwitcher && tv_organization_choice_pending()): ?>
    <!-- Forces an explicit pick when the org was only resolved by falling
         back to whichever sorts first, mirroring the core Centryk
         dashboard's "Choose a company" modal. -->
    <div id="tvOrgChooseModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/60 p-4">
        <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-brand-700">Centryk TV</p>
            <h2 class="mt-1 text-lg font-black text-slate-900">Choose a workspace</h2>
            <p class="mt-1 text-sm text-slate-500">You're part of more than one organization here - pick which one to manage.</p>
            <div class="mt-4 space-y-1.5">
                <?php foreach ($organizations as $item): ?>
                    <a href="<?= e(tv_current_url_with_organization((int)$item['id'])) ?>" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm font-semibold text-slate-800 transition hover:border-brand-500 hover:bg-brand-50">
                        <span class="truncate"><?= e((string)$item['name']) ?></span>
                        <span class="shrink-0 text-[10px] font-black uppercase tracking-[0.12em] text-slate-400"><?= e((string)($item['user_role'] ?? '')) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php
}

function tv_render_page_footer(): void
{
    ?>
    <script>
    (function () {
        const btn = document.getElementById('tvPageUserMenuBtn');
        const menu = document.getElementById('tvPageUserMenu');
        if (btn && menu) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                menu.classList.toggle('hidden');
            });
            document.addEventListener('click', function () {
                menu.classList.add('hidden');
            });
        }
        const logout = document.getElementById('tvPageLogoutBtn');
        if (logout) {
            logout.addEventListener('click', function () {
                fetch('<?= e(centryk_public_url()) ?>/api/auth/logout.php', { method: 'POST' })
                    .finally(function () { window.location.href = '<?= e(centryk_public_url()) ?>/index.php'; });
            });
        }
    }());
    </script>
    <?php
}
