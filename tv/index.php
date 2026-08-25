<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/page-shell.php';

tv_gate_coming_soon();

$liveNow = db()->query(
    'SELECT e.title, e.slug, e.start_at, o.name AS organization_name, o.slug AS organization_slug
     FROM tv_events e
     JOIN tv_organizations o ON o.id = e.organization_id
     JOIN tv_stream_keys sk ON sk.id = e.stream_key_id
     WHERE e.status = "live" AND sk.is_publishing = 1 AND o.status = "active"
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
    'SELECT slug, name, description
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
$studioOrganizations = ($viewer && $hasTvAccess) ? tv_user_organizations() : [];
$activeOrganization = $studioOrganizations !== [] ? tv_active_organization() : null;
$activeRole = $activeOrganization ? tv_active_role() : '';
$canBroadcast = $activeOrganization ? tv_role_at_least('broadcaster') : false;
$canAdminister = $activeOrganization ? tv_role_at_least('admin') : false;
$studioStats = ['live_now' => 0, 'upcoming_events' => 0, 'total_channels' => 0];
if ($activeOrganization) {
    $studioStatsStmt = db()->prepare(
        'SELECT
            (SELECT COUNT(*)
               FROM tv_events e
               JOIN tv_stream_keys sk ON sk.id = e.stream_key_id
              WHERE e.organization_id = :organization_id
                AND e.status = "live"
                AND sk.is_publishing = 1) AS live_now,
            (SELECT COUNT(*) FROM tv_events WHERE organization_id = :organization_id2 AND status = "scheduled") AS upcoming_events,
            (SELECT COUNT(*) FROM tv_channels WHERE organization_id = :organization_id3) AS total_channels'
    );
    $studioStatsStmt->execute([
        'organization_id' => (int)$activeOrganization['id'],
        'organization_id2' => (int)$activeOrganization['id'],
        'organization_id3' => (int)$activeOrganization['id'],
    ]);
    $studioStats = $studioStatsStmt->fetch() ?: $studioStats;
}

$featuredLive = $liveNow[0] ?? null;
$featuredUpcoming = $upcoming[0] ?? null;
$heroWatchUrl = $featuredLive
    ? tv_url('watch/' . $featuredLive['slug'])
    : ($featuredUpcoming ? tv_url('watch/' . $featuredUpcoming['slug']) : '');
$defaultTab = 'live';
if ($liveNow === []) {
    $defaultTab = $upcoming !== [] ? 'upcoming' : ($replays !== [] ? 'replays' : 'orgs');
}

$headerActions = [];
if ($canBroadcast) {
    $headerActions[] = ['href' => tv_url('go-live.php'), 'label' => 'Go Live'];
}
if ($activeOrganization) {
    $headerActions[] = ['href' => tv_url($activeOrganization['slug']), 'label' => 'Channel'];
}
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
                    colors: { brand: '#0f766e' }
                }
            }
        };
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .tv-tab-active { box-shadow: inset 0 -2px 0 0 #0f766e; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">
    <?php tv_render_page_header('Centryk TV', 'Live streaming and broadcasting', $headerActions); ?>

    <main class="mx-auto max-w-[1400px] space-y-3 px-4 py-3 lg:px-5">
        <section class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_320px]">
            <div class="rounded-xl border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center gap-1 border-b border-slate-100 px-2 pt-2">
                    <?php foreach ([
                        ['key' => 'live', 'label' => 'Live Now', 'count' => count($liveNow), 'dot' => 'bg-rose-500'],
                        ['key' => 'upcoming', 'label' => 'Upcoming', 'count' => count($upcoming), 'dot' => null],
                        ['key' => 'replays', 'label' => 'Replays', 'count' => count($replays), 'dot' => null],
                        ['key' => 'orgs', 'label' => 'Organizations', 'count' => count($organizations), 'dot' => null],
                    ] as $t): $isActive = $t['key'] === $defaultTab; ?>
                        <button type="button" data-tab="<?= $t['key'] ?>" class="tv-tab-btn flex items-center gap-1.5 rounded-t-lg px-3 py-2 text-xs font-black uppercase tracking-[0.1em] transition <?= $isActive ? 'tv-tab-active bg-slate-50 text-slate-900' : 'text-slate-400 hover:text-slate-600' ?>">
                            <?php if ($t['dot']): ?><span class="h-1.5 w-1.5 shrink-0 rounded-full <?= $t['dot'] ?>"></span><?php endif; ?>
                            <?= e($t['label']) ?>
                            <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] tabular-nums text-slate-500"><?= (int)$t['count'] ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="p-3.5">
                    <div id="tvTab-live" class="tv-tab-panel <?= $defaultTab === 'live' ? '' : 'hidden' ?> grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <?php foreach ($liveNow as $event): ?>
                            <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="rounded-lg border border-slate-200 bg-slate-50 p-3 transition hover:bg-white">
                                <div class="flex items-center justify-between">
                                    <span class="rounded-md bg-rose-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.12em] text-rose-700">Live</span>
                                    <span class="text-[10px] font-semibold text-slate-400"><?= e(tv_format_datetime($event['start_at'], 'M j, g:i A')) ?></span>
                                </div>
                                <h3 class="mt-2 text-sm font-bold text-slate-900"><?= e($event['title']) ?></h3>
                                <p class="mt-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-400"><?= e($event['organization_name']) ?></p>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($liveNow === []): ?><div class="col-span-full rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-xs font-semibold text-slate-500">No live broadcasts are active right now.</div><?php endif; ?>
                    </div>

                    <div id="tvTab-upcoming" class="tv-tab-panel <?= $defaultTab === 'upcoming' ? '' : 'hidden' ?> space-y-2">
                        <?php foreach ($upcoming as $event): ?>
                            <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:bg-white">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-brand"><?= e($event['event_type']) ?></p>
                                    <h3 class="truncate text-sm font-bold text-slate-900"><?= e($event['title']) ?></h3>
                                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400"><?= e($event['organization_name']) ?></p>
                                </div>
                                <div class="shrink-0 text-right text-[10px] font-semibold text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></div>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($upcoming === []): ?><div class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-xs font-semibold text-slate-500">Nothing scheduled yet.</div><?php endif; ?>
                    </div>

                    <div id="tvTab-replays" class="tv-tab-panel <?= $defaultTab === 'replays' ? '' : 'hidden' ?> grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <?php foreach ($replays as $replay): ?>
                            <a href="<?= e(tv_url('watch/' . $replay['slug'])) ?>" class="rounded-lg border border-slate-200 bg-slate-50 p-2.5 transition hover:bg-white">
                                <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.12em] text-slate-600"><?= e($replay['replay_status']) ?></span>
                                <h3 class="mt-2 text-sm font-bold text-slate-900"><?= e($replay['title']) ?></h3>
                                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400"><?= e($replay['organization_name']) ?></p>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($replays === []): ?><div class="col-span-full rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-xs font-semibold text-slate-500">No replays available yet.</div><?php endif; ?>
                    </div>

                    <div id="tvTab-orgs" class="tv-tab-panel <?= $defaultTab === 'orgs' ? '' : 'hidden' ?> grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <?php foreach ($organizations as $organization): ?>
                            <a href="<?= e(tv_url($organization['slug'])) ?>" class="rounded-lg border border-slate-200 bg-slate-50 p-3 transition hover:bg-white">
                                <h3 class="text-sm font-bold text-slate-900"><?= e($organization['name']) ?></h3>
                                <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500"><?= e((string)($organization['description'] ?: 'Organization-branded broadcasts and event replays.')) ?></p>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($organizations === []): ?><div class="col-span-full rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-xs font-semibold text-slate-500">No organizations published yet.</div><?php endif; ?>
                    </div>
                </div>
            </div>

            <aside class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-brand">Studio</p>
                        <h2 class="mt-1 text-base font-black text-slate-900"><?= $activeOrganization ? e((string)$activeOrganization['name']) : 'Your Studio' ?></h2>
                    </div>
                    <?php if ($activeOrganization): ?>
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-600"><?= e(str_replace('_', ' ', $activeRole)) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($activeOrganization): ?>
                    <div class="mt-3 grid grid-cols-3 gap-1.5">
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-2.5"><div class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Live</div><div class="mt-1 text-xl font-black text-slate-900"><?= (int)$studioStats['live_now'] ?></div></div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-2.5"><div class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Events</div><div class="mt-1 text-xl font-black text-slate-900"><?= (int)$studioStats['upcoming_events'] ?></div></div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-2.5"><div class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Channels</div><div class="mt-1 text-xl font-black text-slate-900"><?= (int)$studioStats['total_channels'] ?></div></div>
                    </div>
                    <div class="mt-3 space-y-2">
                        <a href="<?= e(tv_url('dashboard/events')) ?>" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:bg-slate-100"><span class="text-sm font-black text-slate-900">Manage Events</span><span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Open</span></a>
                        <a href="<?= e(tv_url('dashboard/channels')) ?>" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:bg-slate-100"><span class="text-sm font-black text-slate-900">Run Channels</span><span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Open</span></a>
                        <?php if ($canAdminister): ?><a href="<?= e(tv_url('dashboard/settings')) ?>" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:bg-slate-100"><span class="text-sm font-black text-slate-900">Settings</span><span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Open</span></a><?php endif; ?>
                    </div>
                <?php elseif ($viewer && !$hasTvAccess): ?>
                    <div class="mt-3 space-y-2">
                        <a href="<?= e(centryk_public_url() . '/profile.php') ?>" class="flex items-center justify-between rounded-lg bg-slate-900 px-3 py-2.5 text-white transition hover:bg-slate-800"><span class="text-sm font-black">Open Account</span><span class="text-[10px] font-black uppercase tracking-[0.12em]">Open</span></a>
                    </div>
                <?php else: ?>
                    <div class="mt-3 space-y-2">
                        <a href="<?= e(centryk_public_url() . '/login.php?redirect=' . urlencode('/centryk/tv')) ?>" class="flex items-center justify-between rounded-lg bg-slate-900 px-3 py-2.5 text-white transition hover:bg-slate-800"><span class="text-sm font-black">Open Studio</span><span class="text-[10px] font-black uppercase tracking-[0.12em]">Login</span></a>
                    </div>
                <?php endif; ?>
            </aside>
        </section>
    </main>

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
    <?php tv_render_page_footer(); ?>
</body>
</html>
