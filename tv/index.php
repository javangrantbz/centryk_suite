<?php
require_once __DIR__ . '/includes/bootstrap.php';

$liveNow = db()->query(
    'SELECT e.title, e.slug, e.thumbnail_path, e.start_at, o.name AS organization_name, o.slug AS organization_slug
     FROM tv_events e
     JOIN tv_organizations o ON o.id = e.organization_id
     WHERE e.status = "live" AND o.status = "active"
     ORDER BY e.start_at DESC
     LIMIT 6'
)->fetchAll();

$upcoming = db()->query(
    'SELECT e.title, e.slug, e.start_at, e.event_type, o.name AS organization_name, o.slug AS organization_slug
     FROM tv_events e
     JOIN tv_organizations o ON o.id = e.organization_id
     WHERE e.status = "scheduled" AND o.status = "active"
     ORDER BY e.start_at ASC
     LIMIT 6'
)->fetchAll();

$organizations = db()->query(
    'SELECT slug, name, description, logo_path
     FROM tv_organizations
     WHERE status = "active"
     ORDER BY updated_at DESC
     LIMIT 6'
)->fetchAll();

$replays = db()->query(
    'SELECT e.title, e.slug, e.replay_status, o.name AS organization_name
     FROM tv_events e
     JOIN tv_organizations o ON o.id = e.organization_id
     WHERE e.replay_status = "available" AND o.status = "active"
     ORDER BY e.updated_at DESC
     LIMIT 6'
)->fetchAll();

$viewer = tv_user();
$hasTvAccess = $viewer ? tv_has_app_access((int)$viewer['id']) : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e((string)tv_config('app_name')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] },
                    colors: {
                        ink: '#0f172a',
                        brand: '#0f766e'
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
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-[1400px] flex-wrap items-center justify-between gap-2.5 px-4 py-2.5 lg:px-6">
                <a href="<?= e(tv_url()) ?>" class="flex items-center gap-2.5">
                    <img src="<?= e(centryk_public_url() . '/assets/centryk_logo_c.png') ?>" alt="Centryk" class="h-8 w-8 rounded-lg object-contain ring-1 ring-slate-200">
                    <div class="leading-none">
                        <p class="text-[9px] font-black uppercase tracking-[0.26em] text-brand">Centryk TV</p>
                        <p class="mt-0.5 text-[11px] font-medium text-slate-400">Live Streaming &amp; Broadcasting</p>
                    </div>
                </a>
                <div class="flex flex-wrap items-center gap-1.5">
                    <a href="<?= e(tv_url('belize-basketball')) ?>" class="rounded-lg border border-brand/20 bg-brand/5 px-3 py-1.5 text-xs font-bold text-brand-700">Demo Org</a>
                    <?php if ($viewer): ?>
                        <a href="<?= e($hasTvAccess ? tv_url('dashboard') : centryk_public_url() . '/profile.php') ?>" class="rounded-lg bg-slate-950 px-3 py-1.5 text-xs font-bold text-white"><?= $hasTvAccess ? 'Dashboard' : 'Account' ?></a>
                    <?php else: ?>
                        <a href="<?= e(centryk_public_url() . '/login.php?redirect=' . urlencode('/centryk/tv/dashboard')) ?>" class="rounded-lg bg-slate-950 px-3 py-1.5 text-xs font-bold text-white">Login</a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-[1400px] space-y-3 px-4 py-3 lg:px-6 lg:py-4">

            <!-- Control strip: title + CTAs, no marketing filler -->
            <section class="flex flex-col gap-3 rounded-xl bg-slate-950 px-4 py-3.5 text-white sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="text-[9px] font-black uppercase tracking-[0.28em] text-brand-300">Broadcast control</p>
                    <h1 class="mt-1 text-lg font-black leading-tight tracking-tight sm:text-xl">Live events. Your audience. Your brand.</h1>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <?php foreach (['Sports', 'Schools', 'Business', 'Church', 'Government'] as $tag): ?>
                        <span class="rounded-md border border-white/10 bg-white/5 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.1em] text-white/70"><?= e($tag) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <a href="<?= e(tv_url('dashboard/events')) ?>" class="rounded-lg bg-brand px-3.5 py-2 text-xs font-bold text-white shadow-sm shadow-brand/30">Create Event</a>
                    <a href="<?= e(tv_url('belize-basketball')) ?>" class="rounded-lg border border-white/15 px-3.5 py-2 text-xs font-bold text-white">View Demo</a>
                </div>
            </section>

            <?php
            // Whichever has content first gets to be the default tab - live
            // beats upcoming beats replays beats orgs.
            $defaultTab = 'live';
            if ($liveNow === []) {
                $defaultTab = $upcoming !== [] ? 'upcoming' : ($replays !== [] ? 'replays' : 'orgs');
            }
            ?>
            <!-- One card, tabbed - live/upcoming/replays/orgs share the same shell instead of stacking as four separate sections -->
            <section class="rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center gap-1 border-b border-slate-100 px-2 pt-2">
                    <?php foreach ([
                        ['key' => 'live',     'label' => 'Live Now',     'count' => count($liveNow),      'dot' => 'bg-rose-500'],
                        ['key' => 'upcoming', 'label' => 'Upcoming',     'count' => count($upcoming),     'dot' => null],
                        ['key' => 'replays',  'label' => 'Replays',      'count' => count($replays),      'dot' => null],
                        ['key' => 'orgs',     'label' => 'Organizations','count' => count($organizations),'dot' => null],
                    ] as $t): $isActive = $t['key'] === $defaultTab; ?>
                    <button type="button" data-tab="<?= $t['key'] ?>"
                        class="tv-tab-btn flex items-center gap-1.5 rounded-t-lg px-3 py-2 text-xs font-black uppercase tracking-[0.1em] transition <?= $isActive ? 'tv-tab-active bg-slate-50 text-slate-900' : 'text-slate-400 hover:text-slate-600' ?>">
                        <?php if ($t['dot']): ?><span class="h-1.5 w-1.5 shrink-0 rounded-full <?= $t['dot'] ?>"></span><?php endif; ?>
                        <?= e($t['label']) ?>
                        <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] tabular-nums text-slate-500"><?= (int)$t['count'] ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <div class="p-3.5">
                    <div id="tvTab-live" class="tv-tab-panel <?= $defaultTab === 'live' ? '' : 'hidden' ?> grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <?php foreach ($liveNow as $event): ?>
                            <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="rounded-xl border border-slate-200 bg-white p-3 transition hover:border-brand/30 hover:shadow-sm">
                                <div class="flex items-center justify-between">
                                    <span class="rounded-md bg-rose-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.14em] text-rose-700">Live</span>
                                    <span class="text-[10px] font-semibold text-slate-400"><?= e(tv_format_datetime($event['start_at'], 'M j, g:i A')) ?></span>
                                </div>
                                <h3 class="mt-2.5 text-sm font-bold leading-snug text-slate-900"><?= e($event['title']) ?></h3>
                                <p class="mt-1 text-[10px] font-bold uppercase tracking-[0.1em] text-slate-400"><?= e($event['organization_name']) ?></p>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($liveNow === []): ?>
                            <div class="col-span-full rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-xs font-semibold text-slate-500">No live broadcasts are marked active right now.</div>
                        <?php endif; ?>
                    </div>

                    <div id="tvTab-upcoming" class="tv-tab-panel <?= $defaultTab === 'upcoming' ? '' : 'hidden' ?> space-y-2">
                        <?php foreach ($upcoming as $event): ?>
                            <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="flex items-center justify-between gap-3 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 transition hover:border-brand/20 hover:bg-white">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-brand-700"><?= e($event['event_type']) ?></p>
                                    <h3 class="truncate text-sm font-bold text-slate-900"><?= e($event['title']) ?></h3>
                                    <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-400"><?= e($event['organization_name']) ?></p>
                                </div>
                                <div class="shrink-0 text-right text-[10px] font-semibold text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></div>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($upcoming === []): ?>
                            <div class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-xs font-semibold text-slate-500">Nothing scheduled yet.</div>
                        <?php endif; ?>
                    </div>

                    <div id="tvTab-replays" class="tv-tab-panel <?= $defaultTab === 'replays' ? '' : 'hidden' ?> grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <?php foreach ($replays as $replay): ?>
                            <a href="<?= e(tv_url('watch/' . $replay['slug'])) ?>" class="rounded-lg border border-slate-100 bg-slate-50 p-2.5 transition hover:border-brand/20 hover:bg-white">
                                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-600"><?= e($replay['replay_status']) ?></span>
                                <h3 class="mt-2 truncate text-sm font-bold text-slate-900"><?= e($replay['title']) ?></h3>
                                <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-slate-400"><?= e($replay['organization_name']) ?></p>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($replays === []): ?>
                            <div class="col-span-full rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-xs font-semibold text-slate-500">No replays available yet.</div>
                        <?php endif; ?>
                    </div>

                    <div id="tvTab-orgs" class="tv-tab-panel <?= $defaultTab === 'orgs' ? '' : 'hidden' ?> grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <?php foreach ($organizations as $organization): ?>
                            <a href="<?= e(tv_url($organization['slug'])) ?>" class="rounded-lg border border-slate-100 bg-slate-50 p-3 transition hover:border-brand/20 hover:bg-white">
                                <h3 class="text-sm font-bold text-slate-900"><?= e($organization['name']) ?></h3>
                                <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500"><?= e((string)($organization['description'] ?: 'Organization-branded broadcasts and event replays.')) ?></p>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($organizations === []): ?>
                            <div class="col-span-full rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-xs font-semibold text-slate-500">No organizations published yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <style>
        .tv-tab-active { box-shadow: inset 0 -2px 0 0 #0f766e; }
    </style>
    <script>
        (function () {
            var buttons = document.querySelectorAll('.tv-tab-btn');
            var panels = document.querySelectorAll('.tv-tab-panel');
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var key = btn.getAttribute('data-tab');
                    buttons.forEach(function (b) {
                        b.classList.toggle('tv-tab-active', b === btn);
                        b.classList.toggle('bg-slate-50', b === btn);
                        b.classList.toggle('text-slate-900', b === btn);
                        b.classList.toggle('text-slate-400', b !== btn);
                    });
                    panels.forEach(function (p) {
                        p.classList.toggle('hidden', p.id !== 'tvTab-' + key);
                    });
                });
            });
        })();
    </script>
</body>
</html>
