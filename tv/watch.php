<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/page-shell.php';

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
    if ((string)($event['channel_visibility'] ?? '') === 'paid' && (float)($event['price_amount'] ?? 0) > 0) {
        tv_redirect(tv_url('paywall.php?event=' . $event['slug']));
    }
    http_response_code(403);
    exit('You do not have access to this event.');
}

$isReplay = (string)($event['status'] ?? '') === 'ended' && (string)($event['replay_status'] ?? '') === 'available';
$playbackUrl = $isReplay ? StreamingService::getReplayUrl($event) : StreamingService::getPlaybackUrl($event);
$liveStatus = StreamingService::getStreamStatus($event);
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
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap'); body{font-family:'Plus Jakarta Sans',sans-serif;}</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <?php tv_render_page_header((string)$event['title'], (string)$event['organization_name'], [['href' => tv_url($event['organization_slug']), 'label' => 'Back']]); ?>
    <main class="mx-auto max-w-[1400px] px-4 py-3 lg:px-5">
        <div class="grid gap-4 xl:grid-cols-[1.45fr_0.55fr]">
            <section>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-brand-700"><?= e($event['organization_name']) ?></p>
                        <h1 class="mt-1 text-xl font-black tracking-tight"><?= e($event['title']) ?></h1>
                        <p class="mt-1 text-sm text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-[0.12em] <?= e(tv_status_badge_class($liveStatus)) ?>"><?= e($liveStatus) ?></span>
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.12em] text-slate-600"><?= e($event['visibility']) ?></span>
                    </div>
                </div>

                <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div class="aspect-video bg-black">
                        <?php if ($playbackUrl): ?>
                            <video id="tvPlayer" controls playsinline class="h-full w-full bg-black"></video>
                        <?php elseif ((string)($event['status'] ?? '') === 'ended' && (string)($event['replay_status'] ?? '') === 'processing'): ?>
                            <div class="flex h-full items-center justify-center p-8 text-center"><div><p class="text-sm font-bold uppercase tracking-[0.18em] text-amber-500">Replay Processing</p><h2 class="mt-2 text-xl font-black text-white">This event just ended</h2><p class="mt-2 max-w-xl text-sm leading-6 text-slate-400">The replay is being prepared and will appear here shortly.</p></div></div>
                        <?php elseif ((string)($event['replay_status'] ?? '') === 'failed'): ?>
                            <div class="flex h-full items-center justify-center p-8 text-center"><div><p class="text-sm font-bold uppercase tracking-[0.18em] text-rose-400">Replay Unavailable</p><h2 class="mt-2 text-xl font-black text-white">This replay could not be prepared</h2><p class="mt-2 max-w-xl text-sm leading-6 text-slate-400">Something went wrong while processing this recording.</p></div></div>
                        <?php else: ?>
                            <div class="flex h-full items-center justify-center p-8 text-center"><div><p class="text-sm font-bold uppercase tracking-[0.18em] text-amber-500">Playback Unavailable</p><h2 class="mt-2 text-xl font-black text-white">Streaming origin not connected</h2><p class="mt-2 max-w-xl text-sm leading-6 text-slate-400">This event is ready in Centryk TV, but no playback base URL is configured yet.</p></div></div>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 px-4 py-3 text-sm text-slate-600">
                        <div class="flex items-center gap-4">
                            <span id="viewerCount" class="font-semibold"><?= (int)$viewerCount ?> watching now</span>
                            <?php if (!empty($event['sport']) && !empty($event['home_team']) && !empty($event['away_team'])): ?>
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.12em] text-brand-700"><?= e($event['home_team']) ?> <?= (int)$event['home_score'] ?> - <?= (int)$event['away_score'] ?> <?= e($event['away_team']) ?></span>
                            <?php endif; ?>
                        </div>
                        <span><?= e($event['channel_name']) ?></span>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-base font-black">Event Information</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600"><?= nl2br(e((string)($event['description'] ?: 'No description added yet.'))) ?></p>
                    <?php if (!empty($event['sport'])): ?>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><p class="text-[10px] font-black uppercase tracking-[0.12em] text-brand-700">Competition</p><p class="mt-1 text-base font-bold text-slate-900"><?= e((string)($event['competition'] ?: 'Not set')) ?></p></div>
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3"><p class="text-[10px] font-black uppercase tracking-[0.12em] text-brand-700">Venue</p><p class="mt-1 text-base font-bold text-slate-900"><?= e((string)($event['venue'] ?: 'Not set')) ?></p></div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="space-y-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-brand-700">Organization</p>
                    <h2 class="mt-2 text-lg font-black"><?= e($event['organization_name']) ?></h2>
                    <a href="<?= e(tv_url($event['organization_slug'])) ?>" class="mt-3 inline-flex rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">Visit organization page</a>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-[0.18em] text-brand-700">Related Events</p>
                    <div class="mt-3 space-y-2">
                        <?php foreach ($related as $item): ?>
                            <a href="<?= e(tv_url('watch/' . $item['slug'])) ?>" class="block rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 transition hover:bg-slate-100">
                                <h3 class="font-bold text-slate-900"><?= e($item['title']) ?></h3>
                                <p class="mt-1 text-[10px] uppercase tracking-[0.12em] text-slate-400"><?= e($item['status']) ?></p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>
        </div>
    </main>

    <script>
        const playbackUrl = <?= json_encode($playbackUrl) ?>;
        const isReplay = <?= $isReplay ? 'true' : 'false' ?>;
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
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ event_id: eventId, session_token: ensureSessionToken() })
            });
            const payload = await response.json();
            if (payload.success && viewerCount) {
                viewerCount.textContent = `${payload.data.viewer_count} watching now`;
            }
        }

        if (playbackUrl && player) {
            if (isReplay) {
                player.src = playbackUrl;
            } else if (player.canPlayType('application/vnd.apple.mpegurl')) {
                player.src = playbackUrl;
            } else if (window.Hls && window.Hls.isSupported()) {
                const hls = new Hls();
                hls.loadSource(playbackUrl);
                hls.attachMedia(player);
            }
        }

        sendHeartbeat().catch(console.error);
        setInterval(() => sendHeartbeat().catch(console.error), 30000);
    </script>
    <?php tv_render_page_footer(); ?>
</body>
</html>
