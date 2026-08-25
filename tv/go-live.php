<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/page-shell.php';

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

// Go Live is meant to be zero-setup - a broadcaster shouldn't have to go
// create a channel on a completely different page first just to start
// streaming. Auto-name it after the company with a sequence suffix
// (in practice always 001, since this only fires when the org has none
// yet) so it isn't a bare "Untitled Channel" before anyone bothers to
// rename it from the Channels page.
if (!$channels && $channelId === 0) {
    $companyName = trim((string)($organization['company_name'] ?? $organization['name'] ?? 'My Channel'));
    $autoName = ($companyName !== '' ? $companyName : 'My Channel') . ' ' . str_pad((string)(count($channels) + 1), 3, '0', STR_PAD_LEFT);
    try {
        $created = TvManagementService::createChannel((int)$organization['id'], (int)$user['id'], ['name' => $autoName]);
        tv_redirect(tv_url('go-live.php?channel_id=' . (int)$created['id']));
    } catch (Throwable $e) {
        // Falls through to the manual-creation messaging below if this
        // somehow fails (e.g. a slug collision from an unlucky race) -
        // the Channels page still works as a fallback.
    }
}

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
<body class="bg-slate-50 text-slate-900">
    <?php tv_render_page_header('Go Live', (string)$organization['name'], [['href' => tv_url('dashboard/channels'), 'label' => 'Channels']]); ?>
    <main class="mx-auto max-w-[920px] px-4 py-3 lg:px-5">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h1 class="text-lg font-black tracking-tight">Go Live</h1>
            <p class="mt-1 text-sm text-slate-500">Stream from this browser. Choose a channel, set a title if needed, and start the broadcast.</p>

            <?php if (!$channels): ?>
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">Couldn't set up a channel automatically - create one from the Channels page, then come back here.</div>
            <?php else: ?>
                <form method="get" class="mt-4">
                    <label class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Channel</label>
                    <select name="channel_id" onchange="this.form.submit()" class="mt-1.5 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-brand">
                        <option value="" class="text-slate-900">Select a channel…</option>
                        <?php foreach ($channels as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" class="text-slate-900" <?= $channelId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php if ($channel && $whipBase === ''): ?>
                    <div class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">Browser streaming is not configured yet (missing `STREAM_WHIP_URL`).</div>
                <?php elseif ($channel): ?>
                    <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-black">
                        <video id="preview" autoplay playsinline muted class="aspect-video w-full bg-black"></video>
                    </div>

                    <div class="mt-4 space-y-3">
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Title</label>
                            <input type="text" id="broadcastTitle" maxlength="150" placeholder="<?= e('Live broadcast - ' . $channel['name']) ?>" class="mt-1.5 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-brand">
                        </div>
                        <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <input type="checkbox" id="saveReplay" class="mt-0.5 h-4 w-4 rounded border-slate-300 bg-white">
                            <span>
                                <span class="block text-sm font-bold text-slate-900">Save a replay</span>
                                <span class="block text-xs leading-5 text-slate-500">Viewers can watch it back after you stop.</span>
                            </span>
                        </label>
                    </div>

                    <div id="goLiveError" class="mt-4 hidden rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"></div>
                    <div id="watchLinkBox" class="mt-4 hidden rounded-lg border border-brand-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                        Share this with viewers: <a id="watchLinkAnchor" href="#" target="_blank" class="font-bold underline"></a>
                    </div>

                    <div class="mt-4 flex items-center gap-2">
                        <button type="button" id="btnSwitchCamera" class="hidden rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-700 hover:bg-slate-100">Switch Camera</button>
                        <button type="button" id="btnGoLive" class="flex-1 rounded-lg bg-brand px-4 py-2.5 text-sm font-black uppercase tracking-[0.12em] text-white transition hover:opacity-90">Go Live</button>
                    </div>
                    <p id="statusLine" class="mt-3 text-center text-xs font-black uppercase tracking-[0.12em] text-slate-400">Not connected</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($channel && $whipBase !== ''): ?>
    <script>
        const WHIP_BASE = <?= json_encode($whipBase) ?>;
        const CHANNEL_ID = <?= json_encode((int)$channel['id']) ?>;
        const CHANNEL_NAME = <?= json_encode((string)$channel['name']) ?>;
        const CSRF_TOKEN = <?= json_encode(tv_csrf_token()) ?>;
        const WATCH_BASE = <?= json_encode(tv_url('watch')) ?>;
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
        let whipUrl = null;
        let facingMode = 'environment';

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
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOKEN },
                body,
            });
            const payload = await res.json().catch(() => null);
            if (!res.ok || !payload || !payload.success) {
                throw new Error((payload && payload.message) || 'Could not start the broadcast.');
            }
            return payload.data;
        }

        function showError(message) { errorBox.textContent = message; errorBox.classList.remove('hidden'); }
        function setStatus(text) { statusLine.textContent = text; }

        async function startPreview() {
            localStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode }, audio: true });
            video.srcObject = localStream;
            btnSwitch.classList.remove('hidden');
        }

        async function goLive() {
            btnGoLive.disabled = true;
            btnGoLive.textContent = whipUrl ? 'Reconnecting...' : 'Starting...';
            setStatus(whipUrl ? 'Reconnecting' : 'Starting broadcast');
            errorBox.classList.add('hidden');
            try {
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
                const res = await fetch(whipUrl, { method: 'POST', headers: { 'Content-Type': 'application/sdp' }, body: offer.sdp });
                if (!res.ok) throw new Error('Server rejected the connection (HTTP ' + res.status + ').');
                const location = res.headers.get('Location');
                whipResourceUrl = location ? new URL(location, whipUrl).toString() : null;
                const answerSdp = await res.text();
                await pc.setRemoteDescription({ type: 'answer', sdp: answerSdp });
                setStatus('Live');
                btnGoLive.textContent = 'Stop Streaming';
                btnGoLive.classList.remove('bg-brand');
                btnGoLive.classList.add('bg-rose-600', 'hover:bg-rose-500');
                btnGoLive.disabled = false;
                btnGoLive.onclick = () => stopLive(false);
            } catch (err) {
                showError(err.message || 'Could not go live.');
                setStatus('Not connected');
                btnGoLive.disabled = false;
                btnGoLive.textContent = 'Go Live';
                if (pc) { pc.close(); pc = null; }
            }
        }

        async function stopLive(internalReconnect = false) {
            btnGoLive.disabled = true;
            btnGoLive.textContent = 'Stopping...';
            try { if (whipResourceUrl) await fetch(whipResourceUrl, { method: 'DELETE' }); } catch (err) {}
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
            btnGoLive.classList.add('bg-brand');
            btnGoLive.disabled = false;
            btnGoLive.textContent = 'Go Live';
            btnGoLive.onclick = goLive;
        }

        async function switchCamera() {
            facingMode = facingMode === 'environment' ? 'user' : 'environment';
            const wasLive = whipResourceUrl !== null;
            if (wasLive) await stopLive(true);
            localStream.getTracks().forEach((t) => t.stop());
            await startPreview();
            if (wasLive) await goLive();
        }

        btnGoLive.onclick = goLive;
        btnSwitch.addEventListener('click', switchCamera);
        startPreview().catch((err) => showError('Camera/microphone access is required: ' + err.message));
    </script>
    <?php endif; ?>
    <?php tv_render_page_footer(); ?>
</body>
</html>
