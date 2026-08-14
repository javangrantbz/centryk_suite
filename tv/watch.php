<?php
require_once __DIR__ . '/includes/bootstrap.php';

tv_gate_coming_soon();

$slug = trim((string)($_GET['event'] ?? ''));
$event = $slug !== '' ? tv_find_event_by_slug($slug) : null;
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

$user = tv_user();
if (!tv_can_watch_event($event, $user)) {
    if (!$user) {
        tv_redirect(centryk_public_url() . '/login.php?redirect=' . urlencode(tv_current_path()));
    }

    http_response_code(403);
    exit('You do not have access to this event.');
}

$playbackUrl = StreamingService::getPlaybackUrl($event);
$viewerCount = TvMetricsService::currentViewerCount((int)$event['id']);
$related = db()->prepare(
    'SELECT title, slug, status
     FROM tv_events
     WHERE organization_id = :organization_id AND id <> :id
     ORDER BY start_at DESC
     LIMIT 4'
);
$related->execute([
    'organization_id' => (int)$event['organization_id'],
    'id' => (int)$event['id'],
]);
$related = $related->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($event['title']) ?> | <?= e((string)tv_config('app_name')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            200: '#99f6e4',
                            300: '#5eead4'
                        }
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap'); body{font-family:'Plus Jakarta Sans',sans-serif;}</style>
</head>
<body class="bg-slate-950 text-white">
    <main class="mx-auto max-w-7xl px-6 py-8">
        <a href="<?= e(tv_url($event['organization_slug'])) ?>" class="text-xs font-bold uppercase tracking-[0.3em] text-brand-300">Back to <?= e($event['organization_name']) ?></a>
        <div class="mt-5 grid gap-8 xl:grid-cols-[1.4fr_0.6fr]">
            <section>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.28em] text-brand-300"><?= e($event['organization_name']) ?></p>
                        <h1 class="mt-2 text-4xl font-black tracking-tight"><?= e($event['title']) ?></h1>
                        <p class="mt-3 text-sm text-slate-400"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] <?= e(tv_status_badge_class((string)$event['status'])) ?>"><?= e($event['status']) ?></span>
                        <span class="rounded-full border border-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] text-slate-200"><?= e($event['visibility']) ?></span>
                    </div>
                </div>

                <div class="mt-6 overflow-hidden rounded-[2rem] border border-white/10 bg-slate-900 shadow-2xl shadow-black/25">
                    <div class="aspect-video bg-black">
                        <?php if ($playbackUrl): ?>
                            <video id="tvPlayer" controls playsinline class="h-full w-full bg-black"></video>
                        <?php else: ?>
                            <div class="flex h-full items-center justify-center p-8 text-center">
                                <div>
                                    <p class="text-sm font-bold uppercase tracking-[0.3em] text-amber-300">Playback Unavailable</p>
                                    <h2 class="mt-3 text-2xl font-black">Streaming origin not connected</h2>
                                    <p class="mt-3 max-w-xl text-sm leading-7 text-slate-400">This event is ready in Centryk TV, but no playback base URL is configured yet. The watch page is still recording access and viewer heartbeats.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-4 border-t border-white/10 px-5 py-4 text-sm text-slate-300">
                        <div class="flex items-center gap-4">
                            <span id="viewerCount" class="font-semibold"><?= (int)$viewerCount ?> watching now</span>
                            <?php if (!empty($event['sport']) && !empty($event['home_team']) && !empty($event['away_team'])): ?>
                                <span class="rounded-full border border-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] text-brand-200">
                                    <?= e($event['home_team']) ?> <?= (int)$event['home_score'] ?> - <?= (int)$event['away_score'] ?> <?= e($event['away_team']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <span><?= e($event['channel_name']) ?></span>
                    </div>
                </div>

                <div class="mt-8 rounded-[2rem] border border-white/10 bg-white/5 p-6">
                    <h2 class="text-xl font-black">Event Information</h2>
                    <p class="mt-4 text-sm leading-7 text-slate-300"><?= nl2br(e((string)($event['description'] ?: 'No description added yet.'))) ?></p>
                    <?php if (!empty($event['sport'])): ?>
                        <div class="mt-6 grid gap-4 md:grid-cols-2">
                            <div class="rounded-2xl bg-black/20 p-4">
                                <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-300">Competition</p>
                                <p class="mt-2 text-lg font-bold"><?= e((string)($event['competition'] ?: 'Not set')) ?></p>
                            </div>
                            <div class="rounded-2xl bg-black/20 p-4">
                                <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-300">Venue</p>
                                <p class="mt-2 text-lg font-bold"><?= e((string)($event['venue'] ?: 'Not set')) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="space-y-6">
                <div class="rounded-[2rem] border border-white/10 bg-white/5 p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-brand-300">Organization</p>
                    <h2 class="mt-3 text-2xl font-black"><?= e($event['organization_name']) ?></h2>
                    <a href="<?= e(tv_url($event['organization_slug'])) ?>" class="mt-4 inline-flex rounded-full border border-white/10 px-4 py-2 text-sm font-semibold text-white">Visit organization page</a>
                </div>
                <div class="rounded-[2rem] border border-white/10 bg-white/5 p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.28em] text-brand-300">Related Events</p>
                    <div class="mt-4 space-y-4">
                        <?php foreach ($related as $item): ?>
                            <a href="<?= e(tv_url('watch/' . $item['slug'])) ?>" class="block rounded-2xl bg-black/20 px-4 py-4 transition hover:bg-black/30">
                                <h3 class="font-bold"><?= e($item['title']) ?></h3>
                                <p class="mt-1 text-xs uppercase tracking-[0.2em] text-slate-400"><?= e($item['status']) ?></p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>
        </div>
    </main>

    <script>
        const playbackUrl = <?= json_encode($playbackUrl) ?>;
        const eventId = <?= (int)$event['id'] ?>;
        const player = document.getElementById('tvPlayer');
        const viewerCount = document.getElementById('viewerCount');

        function ensureSessionToken() {
            let token = localStorage.getItem('tv_viewer_session');
            if (!token) {
                token = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
                localStorage.setItem('tv_viewer_session', token);
            }
            return token;
        }

        async function sendHeartbeat() {
            const response = await fetch('<?= e(tv_url('api/viewer/heartbeat.php')) ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    event_id: eventId,
                    session_token: ensureSessionToken()
                })
            });
            const payload = await response.json();
            if (payload.success && viewerCount) {
                viewerCount.textContent = `${payload.data.viewer_count} watching now`;
            }
        }

        if (playbackUrl && player) {
            if (player.canPlayType('application/vnd.apple.mpegurl')) {
                player.src = playbackUrl;
            } else if (window.Hls && window.Hls.isSupported()) {
                const hls = new Hls();
                hls.loadSource(playbackUrl);
                hls.attachMedia(player);
                hls.on(Hls.Events.ERROR, function (_, data) {
                    if (data && data.fatal) {
                        console.error('HLS fatal error', data);
                    }
                });
            }
        }

        sendHeartbeat().catch(console.error);
        setInterval(() => sendHeartbeat().catch(console.error), 30000);
    </script>
</body>
</html>
