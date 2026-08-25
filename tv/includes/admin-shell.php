<?php

function tv_render_admin_header(string $pageTitle, string $active): void
{
    $user = tv_user();
    $organization = tv_active_organization();
    $organizations = tv_user_organizations();
    $isPlatformAdmin = tv_is_platform_admin($user);
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
                            50: '#ecfeff',
                            100: '#cffafe',
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
<body class="bg-slate-100 text-slate-900">
<div class="min-h-screen">
    <div class="h-1 bg-gradient-to-r from-brand-700 via-sky-500 to-slate-900"></div>
    <div class="grid min-h-screen lg:grid-cols-[248px_1fr]">
        <aside class="bg-slate-950 px-4 py-4 text-white lg:px-5">
            <a href="<?= e(tv_url('dashboard')) ?>" class="inline-flex items-center">
                <img src="<?= e(centryk_public_url() . '/assets/centryk_logo_c.png') ?>" alt="Centryk" class="h-11 w-11 rounded-2xl object-contain ring-1 ring-white/10">
            </a>

            <div class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-slate-400">Organization</p>
                <div class="mt-3">
                    <p class="text-base font-bold leading-tight"><?= e((string)($organization['name'] ?? 'No organization')) ?></p>
                    <p class="mt-1 text-[10px] font-bold uppercase tracking-[0.24em] text-brand-300"><?= e(tv_active_role()) ?></p>
                </div>
                <?php if (count($organizations) > 1): ?>
                    <form method="get" class="mt-4">
                        <label class="text-[10px] font-bold uppercase tracking-[0.24em] text-slate-300">Switch scope</label>
                        <select name="organization_id" onchange="this.form.submit()" class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-900 px-3 py-2 text-sm text-white outline-none">
                            <?php foreach ($organizations as $item): ?>
                                <option value="<?= (int)$item['id'] ?>" <?= (int)$item['id'] === (int)($organization['id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= e((string)$item['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
            </div>

            <div class="mt-4 rounded-3xl border border-white/10 bg-white/5 p-4 text-sm text-slate-300">
                <p class="text-[10px] font-bold uppercase tracking-[0.28em] text-slate-400">Access scope</p>
                <p class="mt-2 text-sm font-semibold text-white">Organization-scoped management</p>
                <p class="mt-1 text-xs leading-5 text-slate-300">Everything in this dashboard applies to the selected organization. Switch organizations here to manage a different scope.</p>
            </div>

            <nav class="mt-5 space-y-1.5">
                <?php
                $links = [
                    'dashboard' => ['label' => 'Dashboard', 'href' => tv_url('dashboard')],
                    'channels' => ['label' => 'Channels', 'href' => tv_url('dashboard/channels')],
                    'events' => ['label' => 'Events', 'href' => tv_url('dashboard/events')],
                    'viewers' => ['label' => 'Viewers', 'href' => tv_url('dashboard/viewers')],
                    'analytics' => ['label' => 'Analytics', 'href' => tv_url('dashboard/analytics')],
                    'settings' => ['label' => 'Settings', 'href' => tv_url('dashboard/settings')],
                ];
                foreach ($links as $key => $item):
                    $isActive = $active === $key;
                ?>
                    <a href="<?= e($item['href']) ?>" class="flex items-center justify-between rounded-2xl px-3.5 py-2.5 text-sm font-semibold transition <?= $isActive ? 'bg-brand-600 text-white shadow-lg shadow-brand-950/30' : 'text-slate-200 hover:bg-white/10 hover:text-white' ?>">
                        <span><?= e($item['label']) ?></span>
                        <?php if ($isActive): ?><span class="text-xs uppercase tracking-[0.2em] text-brand-100">Open</span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($isPlatformAdmin): ?>
                    <a href="<?= e(tv_url('admin')) ?>" class="flex items-center justify-between rounded-2xl px-3.5 py-2.5 text-sm font-semibold transition <?= $active === 'platform' ? 'bg-sky-600 text-white shadow-lg shadow-sky-950/30' : 'text-slate-200 hover:bg-white/10 hover:text-white' ?>">
                        <span>Platform Admin</span>
                        <span class="text-xs uppercase tracking-[0.2em] text-sky-100">Admin</span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="mt-5 rounded-3xl border border-white/10 bg-white/5 p-4 text-sm text-slate-300">
                <p class="font-semibold text-white">Streaming Driver</p>
                <p class="mt-1 uppercase tracking-[0.2em] text-brand-300"><?= e((string)tv_config('stream_driver')) ?></p>
                <p class="mt-3 text-xs leading-5 text-slate-400">Current MVP uses a mock-capable abstraction so the app can run before the streaming origin is deployed.</p>
            </div>
        </aside>

        <div class="min-w-0">
            <header class="border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur lg:px-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.3em] text-brand-700"><?= e((string)tv_config('app_name')) ?></p>
                        <h2 class="mt-1 text-xl font-black tracking-tight lg:text-2xl"><?= e($pageTitle) ?></h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if (tv_role_at_least('broadcaster')): ?>
                            <a href="<?= e(tv_url('go-live.php')) ?>" class="flex items-center gap-1.5 rounded-2xl bg-rose-600 px-3 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-lg shadow-rose-950/20 transition hover:bg-rose-500">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-white"></span>
                                Go Live
                            </a>
                        <?php endif; ?>
                        <a href="<?= e(tv_url()) ?>" class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-100">Public site</a>
                        <a href="<?= e(centryk_public_url() . '/profile.php') ?>" class="rounded-2xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white"><?= e((string)($user['display_name'] ?? 'Profile')) ?></a>
                    </div>
                </div>
            </header>
            <main class="px-4 py-4 lg:px-6 lg:py-5">
                <?php foreach (tv_take_flashes() as $flash): ?>
                    <div class="mb-3 rounded-2xl border px-4 py-3 text-sm <?= $flash['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>">
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
</body>
</html>
    <?php
}
