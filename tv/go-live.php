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
    $stmt = db()->prepare(
        'SELECT c.*,
                (
                    SELECT sk.stream_key_encrypted
                    FROM tv_stream_keys sk
                    WHERE sk.channel_id = c.id AND sk.event_id IS NULL AND sk.revoked_at IS NULL
                    ORDER BY sk.id DESC
                    LIMIT 1
                ) AS stream_key_encrypted
         FROM tv_channels c
         WHERE c.id = :id AND c.organization_id = :organization_id
         LIMIT 1'
    );
    $stmt->execute(['id' => $channelId, 'organization_id' => (int)$organization['id']]);
    $channel = $stmt->fetch();
}

$channels = db()->prepare('SELECT id, name FROM tv_channels WHERE organization_id = :organization_id ORDER BY name ASC');
$channels->execute(['organization_id' => (int)$organization['id']]);
$channels = $channels->fetchAll();

$rawKey = null;
if ($channel && $channel['stream_key_encrypted']) {
    $rawKey = StreamingService::decryptStreamKey($channel['stream_key_encrypted']);
}

$whipBase = (string)tv_config('stream_whip_url');
$whipUrl = ($whipBase !== '' && $rawKey) ? $whipBase . '/' . rawurlencode($rawKey) . '/whip' : null;
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

            <?php if ($channel && !$whipUrl): ?>
                <div class="mt-6 rounded-2xl border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-200">
                    <?= $rawKey ? 'Browser streaming is not configured yet (missing STREAM_WHIP_URL).' : 'This channel has no active stream key.' ?>
                </div>
            <?php elseif ($channel): ?>
                <div class="mt-6 overflow-hidden rounded-[2rem] border border-white/10 bg-black">
                    <video id="preview" autoplay playsinline muted class="aspect-video w-full bg-black"></video>
                </div>

                <div id="goLiveError" class="mt-4 hidden rounded-2xl border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-200"></div>

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

    <?php if (!empty($whipUrl)): ?>
    <script>
        const WHIP_URL = <?= json_encode($whipUrl) ?>;
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

        let pc = null;
        let localStream = null;
        let whipResourceUrl = null;
        let facingMode = 'environment'; // rear camera by default - this is filming an event, not a selfie

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
            btnGoLive.textContent = 'Connecting...';
            setStatus('Connecting');
            errorBox.classList.add('hidden');

            try {
                const iceServers = [{ urls: 'stun:stun.l.google.com:19302' }];
                if (TURN_CREDENTIALS) { iceServers.push(TURN_CREDENTIALS); }
                pc = new RTCPeerConnection({ iceServers });
                localStream.getTracks().forEach((track) => pc.addTrack(track, localStream));

                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);

                // WHIP: POST the SDP offer, get back an SDP answer plus a
                // Location header identifying this publish session (used to
                // DELETE it when stopping).
                const res = await fetch(WHIP_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/sdp' },
                    body: offer.sdp
                });
                if (!res.ok) {
                    throw new Error('Server rejected the connection (HTTP ' + res.status + ').');
                }
                const location = res.headers.get('Location');
                whipResourceUrl = location ? new URL(location, WHIP_URL).toString() : null;

                const answerSdp = await res.text();
                await pc.setRemoteDescription({ type: 'answer', sdp: answerSdp });

                setStatus('Live');
                btnGoLive.textContent = 'Stop Streaming';
                btnGoLive.classList.remove('bg-teal-500', 'hover:bg-teal-400');
                btnGoLive.classList.add('bg-rose-600', 'hover:bg-rose-500');
                btnGoLive.disabled = false;
                btnGoLive.onclick = stopLive;
            } catch (err) {
                showError(err.message || 'Could not go live.');
                setStatus('Not connected');
                btnGoLive.disabled = false;
                btnGoLive.textContent = 'Go Live';
                if (pc) { pc.close(); pc = null; }
            }
        }

        async function stopLive() {
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
            if (wasLive) { await stopLive(); }
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
