<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-shell.php';

$user = tv_require_app_access();
if (!tv_is_platform_admin($user)) {
    http_response_code(403);
    exit('Platform administrator access required.');
}

$orgCount = (int)db()->query('SELECT COUNT(*) FROM tv_organizations')->fetchColumn();
$activeOrgCount = (int)db()->query('SELECT COUNT(*) FROM tv_organizations WHERE status = "active"')->fetchColumn();
$liveCount = (int)db()->query('SELECT COUNT(*) FROM tv_events WHERE status = "live"')->fetchColumn();
$userCount = (int)db()->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn();
$todayCount = (int)db()->query('SELECT COUNT(*) FROM tv_events WHERE DATE(start_at) = CURDATE()')->fetchColumn();
$currentViewers = (int)db()->query('SELECT COUNT(*) FROM tv_viewer_sessions WHERE ended_at IS NULL AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 90 SECOND)')->fetchColumn();

$organizations = db()->query(
    'SELECT o.name, o.slug, o.status, c.name AS company_name
     FROM tv_organizations o
     JOIN companies c ON c.id = o.company_id
     ORDER BY o.updated_at DESC'
)->fetchAll();

tv_render_admin_header('Platform Admin', 'platform');
?>
<div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Organizations</p><p class="mt-3 text-4xl font-black"><?= $orgCount ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Active Organizations</p><p class="mt-3 text-4xl font-black"><?= $activeOrgCount ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Live Streams</p><p class="mt-3 text-4xl font-black"><?= $liveCount ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Total Users</p><p class="mt-3 text-4xl font-black"><?= $userCount ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Current Viewers</p><p class="mt-3 text-4xl font-black"><?= $currentViewers ?></p></div>
    <div class="rounded-[1.75rem] bg-white p-5 shadow-sm"><p class="text-xs font-bold uppercase tracking-[0.25em] text-slate-400">Events Today</p><p class="mt-3 text-4xl font-black"><?= $todayCount ?></p></div>
</div>

<section class="mt-8 rounded-[2rem] bg-white p-6 shadow-sm">
    <h3 class="text-xl font-black">Organizations</h3>
    <div class="mt-6 overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead class="text-slate-500">
                <tr>
                    <th class="pb-3 pr-4">Organization</th>
                    <th class="pb-3 pr-4">Company</th>
                    <th class="pb-3 pr-4">Status</th>
                    <th class="pb-3 pr-4">Public URL</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($organizations as $organization): ?>
                    <tr>
                        <td class="py-3 pr-4 font-semibold text-slate-800"><?= e($organization['name']) ?></td>
                        <td class="py-3 pr-4 text-slate-600"><?= e($organization['company_name']) ?></td>
                        <td class="py-3 pr-4 text-slate-600"><?= e($organization['status']) ?></td>
                        <td class="py-3 pr-4 text-brand-700"><a href="<?= e(tv_url($organization['slug'])) ?>"><?= e(tv_url($organization['slug'])) ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php tv_render_admin_footer(); ?>

