<?php
/**
 * Online conference room (a Calendar event hosted on Centryk TV).
 *
 * Access is by invitation only - the event's creator and attendees - so this
 * deliberately does NOT go through tv_gate_coming_soon(): an employee invited
 * to a meeting must be able to join even while the public TV pages are gated.
 * The video itself is Jitsi Meet (see ConferenceService::jitsiDomain()).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/page-shell.php';
require_once __DIR__ . '/../app/services/ConferenceService.php';

$token = trim((string)($_GET['c'] ?? ''));
$user = tv_user();
if (!$user) {
    tv_redirect(centryk_public_url() . '/login.php?redirect=' . urlencode(tv_current_path()));
}

$conf = ConferenceService::accessFor((int)$user['id'], $token);

/** Small centered message page (not invited / ended / waiting). */
function tv_conference_message(string $title, string $body, string $extra = ''): never
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> | <?= e((string)tv_config('app_name')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap'); body{font-family:'Plus Jakarta Sans',sans-serif;}</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <?php tv_render_page_header('Conference', 'Centryk TV'); ?>
    <main class="mx-auto max-w-xl px-4 py-16 text-center">
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-rose-600">Online conference</p>
        <h1 class="mt-2 text-2xl font-black text-slate-900"><?= e($title) ?></h1>
        <p class="mt-3 text-sm leading-6 text-slate-500"><?= e($body) ?></p>
        <?= $extra ?>
        <a href="<?= e(centryk_public_url() . '/calendar.php') ?>" class="mt-6 inline-flex rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-100">Back to calendar</a>
    </main>
    <?php tv_render_page_footer(); ?>
</body>
</html>
    <?php
    exit;
}

if (!$conf) {
    http_response_code(403);
    tv_conference_message('Not on the guest list', 'This conference is only open to the people the host invited. Ask the host to add you to the calendar event.');
}

$isHost = (int)$conf['created_by'] === (int)$user['id'];
$state = $conf['state'];
$now = time();

if ($state === 'ended') {
    tv_conference_message('This conference has ended', $conf['title'] . ' was scheduled for ' . ConferenceService::whenLabel($conf) . '.');
}

// Guests wait until the early-join window opens; the host can always open the room.
$opensAt = $conf['start_ts'] - ConferenceService::EARLY_JOIN_MINUTES * 60;
if (!$isHost && $state === 'upcoming' && $now < $opensAt) {
    $secs = max(1, $opensAt - $now);
    tv_conference_message(
        $conf['title'],
        'Starts ' . ConferenceService::whenLabel($conf) . ', hosted by ' . ($conf['host_name'] ?: 'your colleague') . '. The room opens ' . ConferenceService::EARLY_JOIN_MINUTES . ' minutes before.',
        '<script>setTimeout(function () { location.reload(); }, ' . (int)min($secs, 300) * 1000 . ');</script>'
    );
}

ConferenceService::recordJoin($conf, (int)$user['id']);

$domain = ConferenceService::jitsiDomain();
$jwt = ConferenceService::jitsiJwt($conf, $user, $isHost);
$displayName = (string)($user['display_name'] ?? 'Guest');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($conf['title']) ?> | Conference | <?= e((string)tv_config('app_name')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        #confRoom { height: calc(100vh - 150px); min-height: 420px; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">
    <?php tv_render_page_header('Conference', (string)$conf['title']); ?>

    <main class="mx-auto max-w-[1400px] space-y-2 px-4 py-3 lg:px-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <h1 class="truncate text-base font-black text-slate-900"><?= e($conf['title']) ?></h1>
                <p class="text-[11px] font-semibold text-slate-500">
                    <?= e(ConferenceService::whenLabel($conf)) ?> &middot; hosted by <?= e($isHost ? 'you' : ($conf['host_name'] ?: 'the host')) ?>
                    <?php if (!empty($conf['company_name'])): ?>&middot; <?= e((string)$conf['company_name']) ?><?php endif; ?>
                </p>
                <p id="confWho" class="mt-0.5 text-[11px] font-semibold text-slate-400"></p>
            </div>
            <div class="flex items-center gap-2">
                <a id="confLeave" href="<?= e(centryk_public_url() . '/calendar.php') ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-600 transition hover:bg-slate-100">Leave</a>
                <?php if ($isHost): ?>
                    <button id="confEnd" type="button" class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-rose-500">End for everyone</button>
                <?php endif; ?>
            </div>
        </div>
        <div id="confRoom" class="overflow-hidden rounded-lg border border-slate-200 bg-slate-900"></div>
    </main>

    <script src="https://<?= e($domain) ?>/external_api.js"></script>
    <script>
        (function () {
            var TOKEN = <?= json_encode($conf['room_token']) ?>;
            var HB = <?= json_encode(tv_url('api/conference/heartbeat.php')) ?>;
            var END = <?= json_encode(tv_url('api/conference/end.php')) ?>;
            var HOME = <?= json_encode(centryk_public_url() . '/calendar.php') ?>;
            var who = document.getElementById('confWho');
            var left = false;

            function post(url, body, keepalive) {
                return fetch(url, {
                    method: 'POST', credentials: 'same-origin', keepalive: !!keepalive,
                    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
                }).then(function (r) { return r.json(); }).catch(function () { return null; });
            }

            if (!window.JitsiMeetExternalAPI) {
                document.getElementById('confRoom').innerHTML =
                    '<div class="flex h-full items-center justify-center p-8 text-center text-sm text-slate-300">The conference video service could not be reached. Check your connection and reload.</div>';
                return;
            }

            var options = {
                roomName: <?= json_encode(ConferenceService::roomName($conf['room_token'])) ?>,
                parentNode: document.getElementById('confRoom'),
                width: '100%', height: '100%',
                userInfo: { displayName: <?= json_encode($displayName) ?>, email: <?= json_encode((string)($user['email'] ?? '')) ?> },
                configOverwrite: { subject: <?= json_encode($conf['title']) ?>, disableDeepLinking: true },
                interfaceConfigOverwrite: { SHOW_JITSI_WATERMARK: false, MOBILE_APP_PROMO: false }
            };
            <?php if ($jwt): ?>options.jwt = <?= json_encode($jwt) ?>;<?php endif; ?>

            var api = new JitsiMeetExternalAPI(<?= json_encode($domain) ?>, options);

            function beat() {
                if (left) return;
                post(HB, { token: TOKEN }).then(function (d) {
                    if (!d || !d.success) return;
                    if (d.ended) { left = true; location.href = HOME; return; }
                    var names = (d.participants || []).map(function (p) { return p.name; }).filter(Boolean);
                    who.textContent = names.length ? 'In the room: ' + names.join(', ') : '';
                });
            }
            beat();
            setInterval(beat, 30000);

            function leave() {
                if (left) return;
                left = true;
                post(HB, { token: TOKEN, leave: true }, true);
            }
            api.addListener('readyToClose', function () { leave(); location.href = HOME; });
            document.getElementById('confLeave').addEventListener('click', leave);
            window.addEventListener('pagehide', leave);

            var end = document.getElementById('confEnd');
            if (end) {
                end.addEventListener('click', function () {
                    if (!window.confirm('End this conference for everyone?')) return;
                    left = true;
                    post(END, { token: TOKEN }).then(function () { try { api.executeCommand('hangup'); } catch (e) {} location.href = HOME; });
                });
            }
        })();
    </script>
    <?php tv_render_page_footer(); ?>
</body>
</html>
