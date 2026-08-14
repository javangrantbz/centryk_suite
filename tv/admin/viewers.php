<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-shell.php';

tv_require_organization();
$organization = tv_active_organization();

$liveEvents = db()->prepare('SELECT id, title, status FROM tv_events WHERE organization_id = :organization_id ORDER BY start_at DESC');
$liveEvents->execute(['organization_id' => (int)$organization['id']]);
$liveEvents = $liveEvents->fetchAll();

$sessions = TvMetricsService::viewerSessions((int)$organization['id']);

tv_render_admin_header('Viewers', 'viewers');
?>
<section class="rounded-[2rem] bg-white p-6 shadow-sm">
    <h3 class="text-xl font-black">Current Viewer Counts</h3>
    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($liveEvents as $event): ?>
            <div class="rounded-[1.5rem] border border-slate-200 p-5">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-400"><?= e($event['status']) ?></p>
                <h4 class="mt-2 text-lg font-bold"><?= e($event['title']) ?></h4>
                <p class="mt-3 text-3xl font-black"><?= TvMetricsService::currentViewerCount((int)$event['id']) ?></p>
                <p class="mt-1 text-sm text-slate-500">active viewers</p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="mt-8 rounded-[2rem] bg-white p-6 shadow-sm">
    <h3 class="text-xl font-black">Recent Viewer Sessions</h3>
    <div class="mt-6 overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="text-slate-500">
                <tr>
                    <th class="pb-3 pr-4">Event</th>
                    <th class="pb-3 pr-4">Viewer</th>
                    <th class="pb-3 pr-4">Started</th>
                    <th class="pb-3 pr-4">Last Seen</th>
                    <th class="pb-3 pr-4">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($sessions as $session): ?>
                    <tr>
                        <td class="py-3 pr-4 font-semibold text-slate-800"><?= e($session['title']) ?></td>
                        <td class="py-3 pr-4 text-slate-600"><?= e(trim((string)(($session['first_name'] ?? '') . ' ' . ($session['last_name'] ?? ''))) ?: ($session['email'] ?: 'Guest')) ?></td>
                        <td class="py-3 pr-4 text-slate-600"><?= e(tv_format_datetime($session['started_at'])) ?></td>
                        <td class="py-3 pr-4 text-slate-600"><?= e(tv_format_datetime($session['last_seen_at'])) ?></td>
                        <td class="py-3 pr-4 text-slate-600"><?= e((string)$session['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php tv_render_admin_footer(); ?>

