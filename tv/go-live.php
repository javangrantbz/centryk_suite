<?php
/**
 * Browser-based "Go Live" - phase 1 of the WHIP build (see conversation
 * 2026-08-25). Captures camera/mic directly in the browser via WebRTC and
 * publishes to MediaMTX (docs/streaming-server.md's WHIP section), which
 * forwards natively into the SAME RTMP ingest OBS already uses - so
 * everything downstream (on_publish.php's key validation and capacity
 * guardrail, HLS, playback authorization, replay) applies identically
 * regardless of whether the broadcaster used OBS or just this page.
 *
 * No external WHIP client library - the protocol is simple enough
 * (POST an SDP offer, get an SDP answer back, DELETE the session URL to
 * stop) to hand-roll in the page's own script, which is more auditable
 * than pulling in a CDN dependency for it.
 *
 * Going live here creates a real tv_events row on demand (via the
 * existing api/events/create.php, same one the "Create Event" form uses) -
 * NOT the channel's own persistent stream key. Two reasons: replay
 * eligibility is entirely event-scoped (tv_events.is_replay_enabled - see
 * should_record_replay.php), and watch.php itself only knows how to show
 * events, not bare channels. A channel-key broadcast had no replay path
 * AND no viewer-facing watch link at all - going through a real event
 * fixes both at once, for free, using logic that already existed.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$user = tv_require_organization();
if (!tv_role_at_least('broadcaster')) {
    http_response_code(403);
    exit('Broadcaster access required.');
}
$organization = tv_active_organization();

$channelId = (int)($_GET['channel_id'] ?? 0);
$channel = null;
if ($channelId > 0) {
    $stmt = db()->prepare('SELECT id, name FROM tv_channels WHERE id = :id AND organization_id = :organization_id LIMIT 1');
    $stmt->execute(['id' => $channelId, 'organization_id' => (int)$organization['id']]);
    $channel = $stmt->fetch();
}

$channels = db()->prepare('SELECT id, name FROM tv_channels WHERE organization_id = :organization_id ORDER BY name ASC');
$channels->execute(['organization_id' => (int)$organization['id']]);
$channels = $channels->fetchAll();

$whipBase = (string)tv_config('stream_whip_url');
$turnCredentials = StreamingService::generateTurnCredentials('user' . (int)$user['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Go Live | <?= e((string)tv_config('app_name')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;900&display=swap'); body{font-family:'Plus Jakarta Sans',sans-serif;}</style>
</head>
<body class="bg-slate-950 text-white">
    <main class="mx-auto max-w-lg px-6 py-8">
        <a href="<?= e(tv_url('dashboard/channels')) ?>" class="text-xs font-bold uppercase tracking-[0.3em] text-teal-300">&larr; Channels</a>
        <h1 class="mt-4 text-2xl font-black tracking-tight">Go Live</h1>
        <p class="mt-1 text-sm text-slate-400">Stream straight from this browser - no software to install. Phase 1: works best on wifi; cellular reliability is a follow-up.</p>

        <?php if (!$channels): ?>
            <div class="mt-6 rounded-2xl border border-amber-400/30 bg-amber-400/10 p-4 text-sm text-amber-200">
                Create a channel first from the Channels page.
            </div>
        <?php else: ?>
            <form method="get" class="mt-6">
                <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Channel</label>
                <select name="channel_id" onchange="this.form.submit()"
                        class="mt-1.5 w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm outline-none focus:border-teal-400">
                    <option value="" class="text-slate-900">Select a channel&hellip;</option>
                    <?php foreach ($channels as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" class="text-slate-900" <?= $channelId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if ($channel && $whipBase === ''): ?>
                <div class="mt-6 rounded-2xl border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-200">
                    Browser streaming is not configured yet (missing STREAM_WHIP_URL).
                </div>
            <?php elseif ($channel): ?>
                <div class="mt-6 overflow-hidden rounded-[2rem] border border-white/10 bg-black">
                    <video id="preview" autoplay playsinline muted class="aspect-video w-full bg-black"></video>
                </div>

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Title (optional)</label>
                        <input type="text" id="broadcastTitle" maxlength="150" placeholder="<?= e('Live broadcast - ' . $channel['name']) ?>"
                               class="mt-1.5 w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-teal-400">
                    </div>
                    <label class="flex items-start gap-3 rounded-xl border border-white/10 bg-white/5 p-3.5">
                        <input type="checkbox" id="saveReplay" class="mt-0.5 h-4 w-4 rounded border-white/20 bg-transparent">
                        <span>
                            <span class="block text-sm font-bold text-white">Save a replay</span>
                            <span class="block text-xs leading-5 text-slate-400">Viewers can watch it back after you stop. Off by default.</span>
                        </span>
                    </label>
                </div>

                <div id="goLiveError" class="mt-4 hidden rounded-2xl border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-200"></div>
                <div id="watchLinkBox" class="mt-4 hidden rounded-2xl border border-teal-400/30 bg-teal-400/10 p-4 text-sm text-teal-200">
                    Share this with viewers: <a id="watchLinkAnchor" href="#" target="_blank" class="font-bold underline"></a>
                </div>

                <div class="mt-4 flex items-center justify-center gap-3">
                    <button type="button" id="btnSwitchCamera" class="hidden rounded-xl border border-white/15 px-4 py-3 text-xs font-black uppercase tracking-widest text-white/80 hover:bg-white/5">
                        Switch Camera
                    </button>
                    <button type="button" id="btnGoLive"
                            class="flex-1 rounded-2xl bg-teal-500 px-4 py-3.5 text-sm font-black uppercase tracking-widest text-slate-950 transition hover:bg-teal-400">
                        Go Live
                    </button>
                </div>
                <p id="statusLine" class="mt-3 text-center text-xs font-bold uppercase tracking-widest text-slate-500">Not connected</p>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <?php if ($channel && $whipBase !== ''): ?>
    <script>
        const WHIP_BASE = <?= json_encode($whipBase) ?>;
        const CHANNEL_ID = <?= json_encode((int)$channel['id']) ?>;
        const CHANNEL_NAME = <?= json_encode((string)$channel['name']) ?>;
        const CSRF_TOKEN = <?= json_encode(tv_csrf_token()) ?>;
        const WATCH_BASE = <?= json_encode(tv_url('watch')) ?>;
        // Ephemeral TURN credential (see StreamingService::generateTurnCredentials) -
        // expires in a few hours, so it being visible in page source here
        // isn't a standing risk to the relay. Falls back to STUN-only
        // (phase 1 behavior) if TURN isn't configured on this environment.
        const TURN_CREDENTIALS = <?= json_encode($turnCredentials) ?>;
        const video = document.getElementById('preview');
        const btnGoLive = document.getElementById('btnGoLive');
        const btnSwitch = document.getElementById('btnSwitchCamera');
        const statusLine = document.getElementById('statusLine');
        const errorBox = document.getElementById('goLiveError');
        const titleInput = document.getElementById('broadcastTitle');
        const replayCheckbox = document.getElementById('saveReplay');
        const watchLinkBox = document.getElementById('watchLinkBox');
        const watchLinkAnchor = document.getElementById('watchLinkAnchor');

        let pc = null;
        let localStream = null;
        let whipResourceUrl = null;
        let whipUrl = null; // set once per broadcast, when the event is created - not per reconnect
        let facingMode = 'environment'; // rear camera by default - this is filming an event, not a selfie

        // Creates the real tv_events row this broadcast will publish under
        // (see the file header comment for why) - reuses the same
        // api/events/create.php the "Create Event" dashboard form posts to.
        async function startEvent() {
            const now = new Date();
            const pad = (n) => String(n).padStart(2, '0');
            const startAt = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
            const title = titleInput.value.trim() || `Live broadcast - ${CHANNEL_NAME}`;

            const body = new URLSearchParams({
                title,
                channel_id: String(CHANNEL_ID),
                start_at: startAt,
                status: 'live',
                visibility: 'public',
                event_type: 'other',
                is_replay_enabled: replayCheckbox.checked ? '1' : '0',
            });

            const res = await fetch('api/events/create.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': CSRF_TOKEN,
                },
                body,
            });
            const payload = await res.json().catch(() => null);
            if (!res.ok || !payload || !payload.success) {
                throw new Error((payload && payload.message) || 'Could not start the broadcast.');
            }
            return payload.data;
        }

        function showError(message) {
            errorBox.textContent = message;
            errorBox.classList.remove('hidden');
        }
        function setStatus(text) {
            statusLine.textContent = text;
        }

        async function startPreview() {
            localStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode },
                audio: true
            });
            video.srcObject = localStream;
            btnSwitch.classList.remove('hidden');
        }

        async function goLive() {
            btnGoLive.disabled = true;
            btnGoLive.textContent = whipUrl ? 'Reconnecting...' : 'Starting...';
            setStatus(whipUrl ? 'Reconnecting' : 'Starting broadcast');
            errorBox.classList.add('hidden');

            try {
                // Only create the event once per broadcast - switchCamera()
                // calls stopLive()+goLive() internally to reconnect with a
                // new track, and must reuse the same event/stream key rather
                // than starting a second broadcast.
                if (!whipUrl) {
                    const event = await startEvent();
                    whipUrl = WHIP_BASE + '/' + encodeURIComponent(event.stream_key) + '/whip';
                    watchLinkAnchor.href = WATCH_BASE + '/' + event.slug;
                    watchLinkAnchor.textContent = WATCH_BASE + '/' + event.slug;
                    watchLinkBox.classList.remove('hidden');
                    titleInput.disabled = true;
                    replayCheckbox.disabled = true;
                }

                setStatus('Connecting');
                const iceServers = [{ urls: 'stun:stun.l.google.com:19302' }];
                if (TURN_CREDENTIALS) { iceServers.push(TURN_CREDENTIALS); }
                pc = new RTCPeerConnection({ iceServers });
                localStream.getTracks().forEach((track) => pc.addTrack(track, localStream));

                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);

                // WHIP: POST the SDP offer, get back an SDP answer plus a
                // Location header identifying this publish session (used to
                // DELETE it when stopping).
                const res = await fetch(whipUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/sdp' },
                    body: offer.sdp
                });
                if (!res.ok) {
                    throw new Error('Server rejected the connection (HTTP ' + res.status + ').');
                }
                const location = res.headers.get('Location');
                whipResourceUrl = location ? new URL(location, whipUrl).toString() : null;

                const answerSdp = await res.text();
                await pc.setRemoteDescription({ type: 'answer', sdp: answerSdp });

                setStatus('Live');
                btnGoLive.textContent = 'Stop Streaming';
                btnGoLive.classList.remove('bg-teal-500', 'hover:bg-teal-400');
                btnGoLive.classList.add('bg-rose-600', 'hover:bg-rose-500');
                btnGoLive.disabled = false;
                // Not `= stopLive` directly - onclick passes the click Event
                // as the first argument, which would be truthy and wrongly
                // satisfy stopLive's internalReconnect parameter.
                btnGoLive.onclick = () => stopLive(false);
            } catch (err) {
                showError(err.message || 'Could not go live.');
                setStatus('Not connected');
                btnGoLive.disabled = false;
                btnGoLive.textContent = 'Go Live';
                if (pc) { pc.close(); pc = null; }
            }
        }

        // internalReconnect=true is switchCamera() closing the connection to
        // reopen it with a new track - the broadcast/event itself is still
        // going, so whipUrl and the UI's "live" state (title/checkbox lock,
        // watch link) must survive. A real Stop Streaming click ends the
        // broadcast for good: the event is left to end server-side (the RTMP
        // disconnect alone triggers on_publish_done -> status 'ended', same
        // as OBS), and clearing whipUrl means a later Go Live starts a fresh
        // broadcast rather than resuming a now-ended event's stream key.
        async function stopLive(internalReconnect = false) {
            btnGoLive.disabled = true;
            btnGoLive.textContent = 'Stopping...';
            try {
                if (whipResourceUrl) {
                    await fetch(whipResourceUrl, { method: 'DELETE' });
                }
            } catch (err) {
                // Best-effort - the server will also time out the session on its own.
            }
            if (pc) { pc.close(); pc = null; }
            whipResourceUrl = null;
            if (!internalReconnect) {
                whipUrl = null;
                titleInput.disabled = false;
                replayCheckbox.disabled = false;
                watchLinkBox.classList.add('hidden');
            }
            setStatus('Not connected');
            btnGoLive.classList.remove('bg-rose-600', 'hover:bg-rose-500');
            btnGoLive.classList.add('bg-teal-500', 'hover:bg-teal-400');
            btnGoLive.disabled = false;
            btnGoLive.textContent = 'Go Live';
            btnGoLive.onclick = goLive;
        }

        async function switchCamera() {
            facingMode = facingMode === 'environment' ? 'user' : 'environment';
            const wasLive = whipResourceUrl !== null;
            if (wasLive) { await stopLive(true); }
            localStream.getTracks().forEach((t) => t.stop());
            await startPreview();
            if (wasLive) { await goLive(); }
        }

        // Using .onclick consistently (never addEventListener for this button)
        // matters here: goLive() and stopLive() reassign it to swap the
        // button's behavior once live. addEventListener would have stacked
        // an ADDITIONAL listener on top of that reassignment instead of
        // replacing it, so a "Stop" click fired stopLive() (which closes
        // pc and nulls it) AND the original goLive() handler at the same
        // time, racing each other - the exact cause of both the
        // "Cannot read properties of null" and "SDP does not match" errors.
        btnGoLive.onclick = goLive;
        btnSwitch.addEventListener('click', switchCamera);

        startPreview().catch((err) => showError('Camera/microphone access is required: ' + err.message));
    </script>
    <?php endif; ?>
</body>
</html>
