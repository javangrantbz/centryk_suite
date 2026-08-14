<?php
require_once __DIR__ . '/includes/bootstrap.php';

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
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            300: '#5eead4'
                        }
                    }
                }
            }
        };
    </script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap'); body{font-family:'Plus Jakarta Sans',sans-serif;}</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-3 px-4 py-3 lg:px-6">
            <img src="<?= e(centryk_public_url() . '/assets/centryk_logo_c.png') ?>" alt="Centryk" class="h-11 w-11 rounded-2xl object-contain ring-1 ring-slate-200">
            <a href="<?= e(tv_url()) ?>" class="rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-bold text-slate-700">All organizations</a>
        </div>
    </div>

    <main class="mx-auto max-w-[1600px] px-4 py-4 lg:px-6 lg:py-5">
        <section class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
            <div class="rounded-[1.75rem] bg-slate-950 p-5 text-white shadow-xl">
                <p class="text-[10px] font-black uppercase tracking-[0.34em] text-brand-300"><?= e($organization['company_name']) ?></p>
                <h1 class="mt-3 text-3xl font-black tracking-tight lg:text-4xl"><?= e($organization['name']) ?></h1>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-300"><?= e((string)($organization['description'] ?: 'Organization-owned broadcasts, livestreams, and replay experiences.')) ?></p>
                <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold uppercase tracking-[0.16em] text-slate-200">
                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">Live</span>
                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">Upcoming</span>
                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">Replays</span>
                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">Channels</span>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-[1.5rem] border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-[0.28em] text-slate-400">Website</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900 break-all"><?= e((string)($organization['website'] ?: 'Not set')) ?></p>
                </div>
                <div class="rounded-[1.5rem] border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-[0.28em] text-slate-400">Email</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900 break-all"><?= e((string)($organization['email'] ?: 'Not set')) ?></p>
                </div>
                <div class="rounded-[1.5rem] border border-slate-200 bg-white p-4 shadow-sm sm:col-span-2">
                    <p class="text-[10px] font-black uppercase tracking-[0.28em] text-slate-400">Access</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Public events are open to anyone. Authenticated events are available to signed-in Centryk users. Private events require explicit viewer access.</p>
                </div>
            </div>
        </section>

        <section>
            <h2 class="text-xl font-black tracking-tight">Live Events</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($liveEvents as $event): ?>
                    <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:shadow-md">
                        <span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] text-rose-700">Live</span>
                        <h3 class="mt-4 text-lg font-black"><?= e($event['title']) ?></h3>
                        <p class="mt-1.5 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                    </a>
                <?php endforeach; ?>
                <?php if ($liveEvents === []): ?><div class="rounded-2xl border border-dashed border-slate-300 bg-white p-5 text-sm text-slate-500">No live events right now.</div><?php endif; ?>
            </div>
        </section>

        <section class="mt-5 grid gap-4 lg:grid-cols-2">
            <div>
                <h2 class="text-xl font-black tracking-tight">Upcoming Events</h2>
                <div class="mt-4 space-y-3">
                    <?php foreach ($upcoming as $event): ?>
                        <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="block rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm transition hover:shadow-md">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-sky-700"><?= e($event['visibility']) ?></p>
                            <h3 class="mt-2 text-base font-bold"><?= e($event['title']) ?></h3>
                            <p class="mt-1 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <h2 class="text-xl font-black tracking-tight">Recent Replays</h2>
                <div class="mt-4 space-y-3">
                    <?php foreach ($replays as $replay): ?>
                        <a href="<?= e(tv_url('watch/' . $replay['slug'])) ?>" class="block rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm transition hover:shadow-md">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500"><?= e($replay['replay_status']) ?></p>
                            <h3 class="mt-2 text-base font-bold"><?= e($replay['title']) ?></h3>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="mt-5">
            <h2 class="text-xl font-black tracking-tight">Channels</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($channels as $channel): ?>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-700"><?= e($channel['visibility']) ?></p>
                        <h3 class="mt-2 text-base font-bold"><?= e($channel['name']) ?></h3>
                        <p class="mt-1.5 text-sm leading-6 text-slate-500"><?= e((string)($channel['description'] ?: 'Broadcast channel.')) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
