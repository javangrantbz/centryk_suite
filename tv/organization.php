<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/page-shell.php';

tv_gate_coming_soon();

$slug = trim((string)($_GET['organization'] ?? ''));
$organization = $slug !== '' ? tv_find_public_organization($slug) : null;
if (!$organization) {
    http_response_code(404);
    exit('Organization not found.');
}

$liveEvents = db()->prepare('SELECT title, slug, start_at FROM tv_events WHERE organization_id = :organization_id AND status = "live" ORDER BY start_at DESC LIMIT 6');
$liveEvents->execute(['organization_id' => (int)$organization['id']]);
$liveEvents = $liveEvents->fetchAll();

$upcoming = db()->prepare('SELECT title, slug, start_at, visibility FROM tv_events WHERE organization_id = :organization_id AND status = "scheduled" ORDER BY start_at ASC LIMIT 6');
$upcoming->execute(['organization_id' => (int)$organization['id']]);
$upcoming = $upcoming->fetchAll();

$replays = db()->prepare('SELECT title, slug, replay_status FROM tv_events WHERE organization_id = :organization_id AND replay_status = "available" ORDER BY updated_at DESC LIMIT 6');
$replays->execute(['organization_id' => (int)$organization['id']]);
$replays = $replays->fetchAll();

$channels = db()->prepare('SELECT name, slug, description, visibility FROM tv_channels WHERE organization_id = :organization_id ORDER BY created_at DESC');
$channels->execute(['organization_id' => (int)$organization['id']]);
$channels = $channels->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($organization['name']) ?> | <?= e((string)tv_config('app_name')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap'); body{font-family:'Plus Jakarta Sans',sans-serif;}</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <?php tv_render_page_header((string)$organization['name'], (string)($organization['company_name'] ?? 'Organization'), [['href' => tv_url(), 'label' => 'All Orgs']]); ?>

    <main class="mx-auto max-w-[1400px] space-y-4 px-4 py-3 lg:px-5">
        <section class="grid gap-3 xl:grid-cols-[1.15fr_0.85fr]">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-brand-700"><?= e((string)$organization['company_name']) ?></p>
                <h1 class="mt-1 text-lg font-black tracking-tight text-slate-900"><?= e($organization['name']) ?></h1>
                <p class="mt-2 text-sm leading-6 text-slate-500"><?= e((string)($organization['description'] ?: 'Organization-owned broadcasts, livestreams, and replay experiences.')) ?></p>
                <div class="mt-3 flex flex-wrap gap-1.5 text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">
                    <span class="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1">Live</span>
                    <span class="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1">Upcoming</span>
                    <span class="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1">Replays</span>
                    <span class="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1">Channels</span>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Website</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900 break-all"><?= e((string)($organization['website'] ?: 'Not set')) ?></p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Email</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900 break-all"><?= e((string)($organization['email'] ?: 'Not set')) ?></p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:col-span-2">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Access</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Public events are open to anyone. Authenticated events are available to signed-in Centryk users. Private events require explicit viewer access.</p>
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-base font-black tracking-tight text-slate-900">Live Events</h2>
            <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($liveEvents as $event): ?>
                    <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:bg-slate-50">
                        <span class="rounded-full bg-rose-100 px-2 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-rose-700">Live</span>
                        <h3 class="mt-3 text-base font-black text-slate-900"><?= e($event['title']) ?></h3>
                        <p class="mt-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                    </a>
                <?php endforeach; ?>
                <?php if ($liveEvents === []): ?><div class="rounded-lg border border-dashed border-slate-300 bg-white p-5 text-sm text-slate-500">No live events right now.</div><?php endif; ?>
            </div>
        </section>

        <section class="grid gap-3 lg:grid-cols-2">
            <div>
                <h2 class="text-base font-black tracking-tight text-slate-900">Upcoming Events</h2>
                <div class="mt-3 space-y-2">
                    <?php foreach ($upcoming as $event): ?>
                        <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="block rounded-lg border border-slate-200 bg-white px-3 py-2.5 shadow-sm transition hover:bg-slate-50">
                            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-sky-700"><?= e($event['visibility']) ?></p>
                            <h3 class="mt-1 text-sm font-bold text-slate-900"><?= e($event['title']) ?></h3>
                            <p class="mt-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <h2 class="text-base font-black tracking-tight text-slate-900">Recent Replays</h2>
                <div class="mt-3 space-y-2">
                    <?php foreach ($replays as $replay): ?>
                        <a href="<?= e(tv_url('watch/' . $replay['slug'])) ?>" class="block rounded-lg border border-slate-200 bg-white px-3 py-2.5 shadow-sm transition hover:bg-slate-50">
                            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500"><?= e($replay['replay_status']) ?></p>
                            <h3 class="mt-1 text-sm font-bold text-slate-900"><?= e($replay['title']) ?></h3>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-base font-black tracking-tight text-slate-900">Channels</h2>
            <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($channels as $channel): ?>
                    <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.12em] text-brand-700"><?= e($channel['visibility']) ?></p>
                        <h3 class="mt-1 text-sm font-bold text-slate-900"><?= e($channel['name']) ?></h3>
                        <p class="mt-1.5 text-sm leading-6 text-slate-500"><?= e((string)($channel['description'] ?: 'Broadcast channel.')) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <?php tv_render_page_footer(); ?>
</body>
</html>
