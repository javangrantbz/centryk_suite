<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-shell.php';

tv_require_organization();
$organization = tv_active_organization();
$stats = TvMetricsService::dashboardStats((int)$organization['id']);

$liveEvents = db()->prepare('SELECT title, slug, start_at FROM tv_events WHERE organization_id = :organization_id AND status = "live" ORDER BY start_at DESC LIMIT 5');
$liveEvents->execute(['organization_id' => (int)$organization['id']]);
$liveEvents = $liveEvents->fetchAll();

$upcomingEvents = db()->prepare('SELECT title, slug, start_at FROM tv_events WHERE organization_id = :organization_id AND status = "scheduled" ORDER BY start_at ASC LIMIT 5');
$upcomingEvents->execute(['organization_id' => (int)$organization['id']]);
$upcomingEvents = $upcomingEvents->fetchAll();

$activity = TvMetricsService::recentActivity((int)$organization['id']);

tv_render_admin_header('Dashboard', 'dashboard');
?>
<div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-[1.75rem] bg-slate-950 p-5 text-white shadow-xl"><p class="text-xs font-bold uppercase tracking-[0.25em] text-brand-300">Live Now</p><p class="mt-3 text-4xl font-black"><?= (int)$stats['live_now'] ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Upcoming Events</p><p class="mt-3 text-4xl font-black"><?= (int)$stats['upcoming_events'] ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Total Viewers</p><p class="mt-3 text-4xl font-black"><?= (int)$stats['total_viewers'] ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Total Channels</p><p class="mt-3 text-4xl font-black"><?= (int)$stats['total_channels'] ?></p></div>
</div>

<div class="mt-8 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
    <section class="rounded-[2rem] bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <h3 class="text-xl font-black">Current Live Events</h3>
            <a href="<?= e(tv_url('dashboard/events')) ?>" class="text-sm font-bold text-brand-700">Manage events</a>
        </div>
        <div class="mt-5 space-y-4">
            <?php foreach ($liveEvents as $event): ?>
                <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-4 transition hover:bg-slate-100">
                    <div>
                        <p class="text-sm font-bold"><?= e($event['title']) ?></p>
                        <p class="mt-1 text-xs text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                    </div>
                    <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] text-emerald-700">Live</span>
                </a>
            <?php endforeach; ?>
            <?php if ($liveEvents === []): ?><div class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500">No events are marked live right now.</div><?php endif; ?>
        </div>
    </section>

    <section class="rounded-[2rem] bg-white p-6 shadow-sm">
        <h3 class="text-xl font-black">Upcoming Events</h3>
        <div class="mt-5 space-y-4">
            <?php foreach ($upcomingEvents as $event): ?>
                <div class="rounded-2xl bg-slate-50 px-4 py-4">
                    <p class="text-sm font-bold"><?= e($event['title']) ?></p>
                    <p class="mt-1 text-xs text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="mt-8 rounded-[2rem] bg-white p-6 shadow-sm">
    <h3 class="text-xl font-black">Recent Activity</h3>
    <div class="mt-5 space-y-4">
        <?php foreach ($activity as $item): ?>
            <div class="rounded-2xl bg-slate-50 px-4 py-4">
                <p class="text-sm font-semibold text-slate-900"><?= e(trim((string)(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''))) ?: 'System') ?> <?= e(str_replace('_', ' ', (string)$item['action'])) ?></p>
                <p class="mt-1 text-xs text-slate-500"><?= e(tv_format_datetime($item['created_at'])) ?></p>
            </div>
        <?php endforeach; ?>
        <?php if ($activity === []): ?><div class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500">No activity logged yet.</div><?php endif; ?>
    </div>
</section>
<?php tv_render_admin_footer(); ?>

