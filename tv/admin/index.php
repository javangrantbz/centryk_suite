<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-shell.php';

tv_require_organization();
$organization = tv_active_organization();
$stats = TvMetricsService::dashboardStats((int)$organization['id']);

$liveEventsStmt = db()->prepare(
    'SELECT title, slug, start_at
     FROM tv_events
     WHERE organization_id = :organization_id AND status = "live"
     ORDER BY start_at DESC
     LIMIT 5'
);
$liveEventsStmt->execute(['organization_id' => (int)$organization['id']]);
$liveEvents = $liveEventsStmt->fetchAll();

$upcomingEventsStmt = db()->prepare(
    'SELECT title, slug, start_at, event_type
     FROM tv_events
     WHERE organization_id = :organization_id AND status = "scheduled"
     ORDER BY start_at ASC
     LIMIT 5'
);
$upcomingEventsStmt->execute(['organization_id' => (int)$organization['id']]);
$upcomingEvents = $upcomingEventsStmt->fetchAll();

$channelsStmt = db()->prepare(
    'SELECT name, slug, visibility, status, is_live
     FROM tv_channels
     WHERE organization_id = :organization_id
     ORDER BY is_live DESC, updated_at DESC, created_at DESC
     LIMIT 4'
);
$channelsStmt->execute(['organization_id' => (int)$organization['id']]);
$channels = $channelsStmt->fetchAll();

$activity = TvMetricsService::recentActivity((int)$organization['id']);
$featuredLive = $liveEvents[0] ?? null;
$featuredUpcoming = $upcomingEvents[0] ?? null;
$canBroadcast = tv_role_at_least('broadcaster');
$canAdminister = tv_role_at_least('admin');

tv_render_admin_header('Dashboard', 'dashboard');
?>

<section class="grid gap-3 xl:grid-cols-[minmax(0,1.55fr)_300px]">
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-brand-700">Studio Overview</p>
                <h1 class="mt-1 text-lg font-black tracking-tight text-slate-900">
                    <?= $featuredLive ? e($featuredLive['title']) : ($featuredUpcoming ? e($featuredUpcoming['title']) : 'Ready for your next broadcast') ?>
                </h1>
                <p class="mt-1 text-xs font-semibold text-slate-500">
                    <?php if ($featuredLive): ?>
                        Live now · <?= e(tv_format_datetime($featuredLive['start_at'], 'M j, g:i A')) ?>
                    <?php elseif ($featuredUpcoming): ?>
                        Up next · <?= e(ucfirst((string)$featuredUpcoming['event_type'])) ?> · <?= e(tv_format_datetime($featuredUpcoming['start_at'], 'M j, g:i A')) ?>
                    <?php else: ?>
                        Create a channel, schedule an event, or go live.
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <?php if ($canBroadcast): ?>
                    <a href="<?= e(tv_url('go-live.php')) ?>" class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-rose-500">
                        Go Live
                    </a>
                <?php endif; ?>
                <a href="<?= e(tv_url((string)$organization['slug'])) ?>" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-700 transition hover:bg-slate-100">
                    View Channel
                </a>
            </div>
        </div>

        <div class="mt-3 grid gap-2.5 lg:grid-cols-[minmax(0,1fr)_220px]">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <?php if ($featuredLive): ?>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-rose-700">
                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                            Live
                        </span>
                        <span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400"><?= (int)$stats['live_now'] ?> active</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <a href="<?= e(tv_url('watch/' . $featuredLive['slug'])) ?>" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">
                            Watch Feed
                        </a>
                        <a href="<?= e(tv_url('dashboard/events')) ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-700 transition hover:bg-slate-100">
                            Manage Event
                        </a>
                    </div>
                <?php elseif ($featuredUpcoming): ?>
                    <span class="inline-flex rounded-full bg-brand-100 px-2 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-brand-700">Up Next</span>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <a href="<?= e(tv_url('watch/' . $featuredUpcoming['slug'])) ?>" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">
                            Open Event
                        </a>
                        <?php if ($canBroadcast): ?>
                            <a href="<?= e(tv_url('go-live.php')) ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-700 transition hover:bg-slate-100">
                                Start Broadcast
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <span class="inline-flex rounded-full bg-slate-200 px-2 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">Studio Ready</span>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <a href="<?= e(tv_url('dashboard/channels')) ?>" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">
                            Create Channel
                        </a>
                        <a href="<?= e(tv_url('dashboard/events')) ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-700 transition hover:bg-slate-100">
                            Create Event
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-2 gap-1.5">
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2.5">
                    <div class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Live</div>
                    <div class="mt-1 text-xl font-black text-slate-900"><?= (int)$stats['live_now'] ?></div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2.5">
                    <div class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Upcoming</div>
                    <div class="mt-1 text-xl font-black text-slate-900"><?= (int)$stats['upcoming_events'] ?></div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2.5">
                    <div class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Viewers</div>
                    <div class="mt-1 text-xl font-black text-slate-900"><?= (int)$stats['total_viewers'] ?></div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2.5">
                    <div class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Channels</div>
                    <div class="mt-1 text-xl font-black text-slate-900"><?= (int)$stats['total_channels'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <aside class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-brand-700">Quick Routes</p>
        <h2 class="mt-1 text-base font-black tracking-tight text-slate-900">Studio actions</h2>
        <div class="mt-3 space-y-2">
            <?php if ($canBroadcast): ?>
                <a href="<?= e(tv_url('go-live.php')) ?>" class="flex items-center justify-between rounded-lg bg-rose-600 px-3 py-2.5 text-white transition hover:bg-rose-500">
                    <span class="text-sm font-black">Go Live</span>
                    <span class="text-[10px] font-black uppercase tracking-[0.12em]">Open</span>
                </a>
            <?php endif; ?>
            <a href="<?= e(tv_url('dashboard/events')) ?>" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:bg-slate-100">
                <span class="text-sm font-black text-slate-900">Manage Events</span>
                <span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Open</span>
            </a>
            <a href="<?= e(tv_url('dashboard/channels')) ?>" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:bg-slate-100">
                <span class="text-sm font-black text-slate-900">Run Channels</span>
                <span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Open</span>
            </a>
            <a href="<?= e(tv_url('dashboard/viewers')) ?>" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:bg-slate-100">
                <span class="text-sm font-black text-slate-900">Viewer Activity</span>
                <span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Open</span>
            </a>
            <?php if ($canAdminister): ?>
                <a href="<?= e(tv_url('dashboard/settings')) ?>" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:bg-slate-100">
                    <span class="text-sm font-black text-slate-900">Organization Settings</span>
                    <span class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Open</span>
                </a>
            <?php endif; ?>
        </div>
    </aside>
</section>

<section class="grid gap-3 xl:grid-cols-2">
    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Live Desk</p>
                <h2 class="mt-1 text-base font-black tracking-tight text-slate-900">Current live events</h2>
            </div>
            <a href="<?= e(tv_url('dashboard/events')) ?>" class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Events</a>
        </div>
        <div class="mt-3 space-y-2">
            <?php foreach ($liveEvents as $event): ?>
                <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:bg-slate-100">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-black text-slate-900"><?= e($event['title']) ?></p>
                        <p class="mt-0.5 text-xs font-semibold text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                    </div>
                    <span class="shrink-0 rounded-full bg-rose-100 px-2 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-rose-700">Live</span>
                </a>
            <?php endforeach; ?>
            <?php if ($liveEvents === []): ?>
                <div class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-sm font-semibold text-slate-500">
                    No events are live right now.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Programming</p>
                <h2 class="mt-1 text-base font-black tracking-tight text-slate-900">Upcoming lineup</h2>
            </div>
            <a href="<?= e(tv_url('dashboard/events')) ?>" class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Schedule</a>
        </div>
        <div class="mt-3 space-y-2">
            <?php foreach ($upcomingEvents as $event): ?>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5">
                    <p class="text-[10px] font-black uppercase tracking-[0.12em] text-brand-700"><?= e((string)$event['event_type']) ?></p>
                    <p class="mt-1 text-sm font-black text-slate-900"><?= e($event['title']) ?></p>
                    <p class="mt-0.5 text-xs font-semibold text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                </div>
            <?php endforeach; ?>
            <?php if ($upcomingEvents === []): ?>
                <div class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-sm font-semibold text-slate-500">
                    Nothing is scheduled yet.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Channels</p>
                <h2 class="mt-1 text-base font-black tracking-tight text-slate-900">Channel status</h2>
            </div>
            <a href="<?= e(tv_url('dashboard/channels')) ?>" class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Channels</a>
        </div>
        <div class="mt-3 space-y-2">
            <?php foreach ($channels as $channel): ?>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5">
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-black text-slate-900"><?= e($channel['name']) ?></p>
                            <p class="mt-0.5 text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">
                                <?= e((string)$channel['visibility']) ?> · <?= e((string)$channel['status']) ?>
                            </p>
                        </div>
                        <?php if (!empty($channel['is_live'])): ?>
                            <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-emerald-700">On Air</span>
                        <?php else: ?>
                            <span class="shrink-0 rounded-full bg-slate-200 px-2 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">Standby</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($channels === []): ?>
                <div class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-sm font-semibold text-slate-500">
                    No channels created yet.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-3">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Audit Trail</p>
                <h2 class="mt-1 text-base font-black tracking-tight text-slate-900">Recent activity</h2>
            </div>
            <a href="<?= e(tv_url('dashboard/analytics')) ?>" class="text-xs font-black uppercase tracking-[0.12em] text-brand-700">Analytics</a>
        </div>
        <div class="mt-3 space-y-2">
            <?php foreach ($activity as $item): ?>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5">
                    <p class="text-sm font-semibold text-slate-900">
                        <?= e(trim((string)(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''))) ?: 'System') ?>
                        <?= e(str_replace('_', ' ', (string)$item['action'])) ?>
                    </p>
                    <p class="mt-0.5 text-xs font-semibold text-slate-500"><?= e(tv_format_datetime($item['created_at'])) ?></p>
                </div>
            <?php endforeach; ?>
            <?php if ($activity === []): ?>
                <div class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-sm font-semibold text-slate-500">
                    No activity logged yet.
                </div>
            <?php endif; ?>
        </div>
    </section>
</section>

<?php tv_render_admin_footer(); ?>
