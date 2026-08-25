<?php

function tv_render_admin_header(string $pageTitle, string $active): void
{
    $user = tv_user();
    $organization = tv_active_organization();
    $organizations = tv_user_organizations();
    $isPlatformAdmin = tv_is_platform_admin($user);
    $displayName = trim((string)(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    if ($displayName === '') {
        $displayName = (string)($user['email'] ?? 'User');
    }
    $initial = strtoupper(substr((string)($user['first_name'] ?? $displayName), 0, 1));
    $pageLabel = $pageTitle === 'Dashboard' ? 'Studio' : $pageTitle;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | <?= e((string)tv_config('app_name')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] },
                    colors: {
                        brand: {
                            50: '#f0fdfa',
                            100: '#ccfbf1',
                            500: '#14b8a6',
                            600: '#0d9488',
                            700: '#0f766e',
                            900: '#134e4a'
                        }
                    }
                }
            }
        };
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="min-h-screen">
    <div class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-[1400px] items-center gap-3 px-4 py-2.5 lg:px-5">
            <a href="<?= e(centryk_public_url() . '/') ?>" class="shrink-0 transition hover:opacity-80" title="Back to Centryk">
                <img src="<?= e(centryk_public_url() . '/assets/centryk_logo_c.png') ?>" alt="Centryk" class="h-9 w-9 rounded-lg object-contain ring-1 ring-slate-200">
            </a>
            <div class="h-5 w-px bg-slate-200"></div>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <p class="truncate text-sm font-bold text-slate-900"><?= e((string)($organization['company_name'] ?? $organization['name'] ?? 'Centryk TV')) ?></p>
                    <span class="rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.12em] text-brand-700"><?= e(tv_active_role()) ?></span>
                </div>
                <p class="truncate text-[11px] font-semibold text-slate-400"><?= e($pageLabel) ?></p>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <?php if (count($organizations) > 1): ?>
                    <form method="get" class="hidden sm:block">
                        <select name="organization_id" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs font-semibold text-slate-600 outline-none focus:border-brand-500">
                            <?php foreach ($organizations as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= (int)$item['id'] === (int)($organization['id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= e((string)$item['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
                <?php if (tv_role_at_least('broadcaster')): ?>
                    <a href="<?= e(tv_url('go-live.php')) ?>" class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-rose-500">
                        Go Live
                    </a>
                <?php endif; ?>
                <a href="<?= e(tv_url((string)$organization['slug'])) ?>" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100">
                    Channel
                </a>

                <div class="relative" id="tvUserMenuWrap">
                    <button id="tvUserMenuBtn" class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 transition hover:bg-slate-100">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-[12px] font-black text-slate-700"><?= e($initial) ?></div>
                        <div class="hidden text-left sm:block">
                            <p class="text-sm font-semibold leading-tight text-slate-800"><?= e($displayName) ?></p>
                            <p class="text-[10px] leading-tight text-slate-400"><?= e((string)($user['email'] ?? '')) ?></p>
                        </div>
                    </button>
                    <div id="tvUserMenu" class="absolute right-0 top-full z-50 mt-2 hidden w-56 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
                        <div class="border-b border-slate-100 px-3.5 py-3">
                            <p class="truncate text-sm font-bold text-slate-900"><?= e($displayName) ?></p>
                            <p class="mt-0.5 truncate text-[11px] text-slate-400"><?= e((string)($user['email'] ?? '')) ?></p>
                        </div>
                        <div class="p-1.5">
                            <a href="<?= e(centryk_public_url() . '/profile.php') ?>" class="flex items-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                                Account
                            </a>
                            <a href="<?= e(tv_url()) ?>" class="flex items-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                                TV Home
                            </a>
                            <?php if ($isPlatformAdmin): ?>
                                <a href="<?= e(tv_url('admin')) ?>" class="flex items-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                                    Platform Admin
                                </a>
                            <?php endif; ?>
                            <button id="tvLogoutBtn" class="flex w-full items-center rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-600 transition hover:bg-red-50 hover:text-red-600">
                                Sign out
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mx-auto grid max-w-[1400px] gap-3 px-4 py-3 lg:grid-cols-[220px_minmax(0,1fr)] lg:px-5">
        <aside class="rounded-xl border border-slate-200 bg-white p-2.5 shadow-sm">
            <nav class="space-y-1">
                <?php
                $links = [
                    'dashboard' => ['label' => 'Studio', 'href' => tv_url('dashboard')],
                    'channels' => ['label' => 'Channels', 'href' => tv_url('dashboard/channels')],
                    'events' => ['label' => 'Events', 'href' => tv_url('dashboard/events')],
                    'viewers' => ['label' => 'Viewers', 'href' => tv_url('dashboard/viewers')],
                    'analytics' => ['label' => 'Analytics', 'href' => tv_url('dashboard/analytics')],
                    'settings' => ['label' => 'Settings', 'href' => tv_url('dashboard/settings')],
                ];
                foreach ($links as $key => $item):
                    $isActive = $active === $key;
                ?>
                    <a href="<?= e($item['href']) ?>" class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-semibold transition <?= $isActive ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
                        <span><?= e($item['label']) ?></span>
                        <?php if ($isActive): ?><span class="text-[10px] font-black uppercase tracking-[0.12em] text-white/70">Open</span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div class="min-w-0">
            <main class="space-y-3">
                <?php foreach (tv_take_flashes() as $flash): ?>
                    <div class="rounded-lg border px-3.5 py-3 text-sm <?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>">
                        <?= e((string)$flash['message']) ?>
                    </div>
                <?php endforeach; ?>
    <?php
}

function tv_render_admin_footer(): void
{
    ?>
            </main>
        </div>
    </div>
</div>
<script>
(function () {
    const btn = document.getElementById('tvUserMenuBtn');
    const menu = document.getElementById('tvUserMenu');
    if (btn && menu) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            menu.classList.toggle('hidden');
        });
        document.addEventListener('click', function () {
            menu.classList.add('hidden');
        });
    }
    const logout = document.getElementById('tvLogoutBtn');
    if (logout) {
        logout.addEventListener('click', function () {
            fetch('<?= e(centryk_public_url()) ?>/api/auth/logout.php', { method: 'POST' })
                .finally(function () { window.location.href = '<?= e(centryk_public_url()) ?>/index.php'; });
        });
    }
}());
</script>
</body>
</html>
    <?php
}
