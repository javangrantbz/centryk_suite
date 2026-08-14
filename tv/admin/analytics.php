<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-shell.php';

tv_require_organization();
if (!tv_role_at_least('admin')) {
    http_response_code(403);
    exit('Admin access required.');
}

$organization = tv_active_organization();
$stats = TvMetricsService::dashboardStats((int)$organization['id']);
$mostWatched = TvMetricsService::mostWatchedEvents((int)$organization['id']);

tv_render_admin_header('Analytics', 'analytics');
?>
<div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Total Events</p><p class="mt-3 text-4xl font-black"><?= (int)$stats['total_events'] ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Unique Viewers</p><p class="mt-3 text-4xl font-black"><?= (int)$stats['unique_viewers'] ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Watch Sessions</p><p class="mt-3 text-4xl font-black"><?= (int)$stats['total_watch_sessions'] ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Avg Watch Minutes</p><p class="mt-3 text-4xl font-black"><?= e((string)$stats['average_watch_minutes']) ?></p></div>
</div>

<section class="mt-8 rounded-[2rem] bg-white p-6 shadow-sm">
    <h3 class="text-xl font-black">Most Watched Events</h3>
    <div class="mt-6 overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="text-slate-500">
                <tr>
                    <th class="pb-3 pr-4">Event</th>
                    <th class="pb-3 pr-4">Sessions</th>
                    <th class="pb-3 pr-4">Unique Viewers</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($mostWatched as $row): ?>
                    <tr>
                        <td class="py-3 pr-4 font-semibold text-slate-800"><?= e($row['title']) ?></td>
                        <td class="py-3 pr-4 text-slate-600"><?= (int)$row['sessions'] ?></td>
                        <td class="py-3 pr-4 text-slate-600"><?= (int)$row['unique_viewers'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php tv_render_admin_footer(); ?>

