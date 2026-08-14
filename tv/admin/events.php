<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-shell.php';

$user = tv_require_organization();
$organization = tv_active_organization();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tv_verify_csrf();

    try {
        if (isset($_POST['create_event'])) {
            if (!tv_role_at_least('broadcaster')) {
                throw new RuntimeException('Broadcaster access required to create events.');
            }

            $event = TvManagementService::createEvent((int)$organization['id'], (int)$user['id'], $_POST);
            tv_flash('success', 'Event created. OBS stream key: ' . $event['stream_key']);
            tv_redirect(tv_url('dashboard/events'));
        }

        if (isset($_POST['status_event'])) {
            TvManagementService::updateEventStatus((int)$organization['id'], (int)$_POST['event_id'], (string)$_POST['status'], (int)$user['id']);
            tv_flash('success', 'Event status updated.');
            tv_redirect(tv_url('dashboard/events'));
        }

        if (isset($_POST['score_event'])) {
            TvManagementService::updateSportsScore((int)$organization['id'], (int)$_POST['event_id'], (int)$_POST['home_score'], (int)$_POST['away_score'], (int)$user['id']);
            tv_flash('success', 'Sports score updated.');
            tv_redirect(tv_url('dashboard/events'));
        }
    } catch (Throwable $e) {
        tv_flash('error', $e->getMessage());
        tv_redirect(tv_url('dashboard/events'));
    }
}

$channels = db()->prepare('SELECT id, name FROM tv_channels WHERE organization_id = :organization_id ORDER BY name ASC');
$channels->execute(['organization_id' => (int)$organization['id']]);
$channels = $channels->fetchAll();

$filters = [
    'status' => trim((string)($_GET['status'] ?? '')),
    'query' => trim((string)($_GET['query'] ?? '')),
];

$sql = 'SELECT e.*, c.name AS channel_name, sk.stream_key_encrypted,
               sed.home_team, sed.away_team, sed.home_score, sed.away_score
        FROM tv_events e
        JOIN tv_channels c ON c.id = e.channel_id
        LEFT JOIN tv_stream_keys sk ON sk.id = e.stream_key_id
        LEFT JOIN tv_sports_event_details sed ON sed.event_id = e.id
        WHERE e.organization_id = :organization_id';
$params = ['organization_id' => (int)$organization['id']];
if ($filters['status'] !== '') {
    $sql .= ' AND e.status = :status';
    $params['status'] = $filters['status'];
}
if ($filters['query'] !== '') {
    $sql .= ' AND (e.title LIKE :query OR c.name LIKE :query)';
    $params['query'] = '%' . $filters['query'] . '%';
}
$sql .= ' ORDER BY e.start_at DESC';

$events = db()->prepare($sql);
$events->execute($params);
$events = $events->fetchAll();

tv_render_admin_header('Events', 'events');
?>
<div class="grid gap-6 xl:grid-cols-[0.85fr_1.15fr]">
    <section class="rounded-[2rem] bg-white p-6 shadow-sm">
        <h3 class="text-xl font-black">Create Event</h3>
        <p class="mt-2 text-sm text-slate-500">Generate an event record, schedule it, and issue broadcaster credentials.</p>
        <form method="post" enctype="multipart/form-data" class="mt-6 space-y-4">
            <?= tv_csrf_field() ?>
            <input type="hidden" name="create_event" value="1">
            <div><label class="text-sm font-semibold">Event Name</label><input name="title" required class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="text-sm font-semibold">Channel</label><select name="channel_id" required class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><?php foreach ($channels as $channel): ?><option value="<?= (int)$channel['id'] ?>"><?= e($channel['name']) ?></option><?php endforeach; ?></select></div>
                <div><label class="text-sm font-semibold">Type</label><select name="event_type" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="sports">Sports</option><option value="business">Business</option><option value="education">Education</option><option value="church">Church</option><option value="government">Government</option><option value="conference">Conference</option><option value="entertainment">Entertainment</option><option value="other">Other</option></select></div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="text-sm font-semibold">Start At</label><input type="datetime-local" name="start_at" required class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="text-sm font-semibold">End At</label><input type="datetime-local" name="end_at" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
            </div>
            <div><label class="text-sm font-semibold">Description</label><textarea name="description" rows="3" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></textarea></div>
            <div class="grid gap-4 md:grid-cols-2">
            <div><label class="text-sm font-semibold">Visibility</label><select name="visibility" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="public">Public</option><option value="authenticated">Authenticated</option><option value="private">Private</option></select></div>
                <div><label class="text-sm font-semibold">Status</label><select name="status" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><option value="scheduled">Scheduled</option><option value="draft">Draft</option><option value="live">Live</option></select></div>
            </div>
            <div class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-700">Access scope</p>
                <div class="mt-3 grid gap-2 md:grid-cols-3">
                    <div class="rounded-2xl bg-white p-3 text-xs leading-5 text-slate-600 shadow-sm">
                        <p class="font-bold text-slate-900">Public</p>
                        <p class="mt-1">Anyone can watch the event page.</p>
                    </div>
                    <div class="rounded-2xl bg-white p-3 text-xs leading-5 text-slate-600 shadow-sm">
                        <p class="font-bold text-slate-900">Authenticated</p>
                        <p class="mt-1">Any signed-in Centryk user can watch.</p>
                    </div>
                    <div class="rounded-2xl bg-white p-3 text-xs leading-5 text-slate-600 shadow-sm">
                        <p class="font-bold text-slate-900">Private</p>
                        <p class="mt-1">Only explicitly granted viewers can watch.</p>
                    </div>
                </div>
                <p class="mt-3 text-[11px] leading-5 text-slate-500">Your organization members can manage and watch their own events. Use visibility to control who outside the organization can access the event.</p>
            </div>
            <div><label class="text-sm font-semibold">Thumbnail</label><input type="file" name="thumbnail" accept="image/*" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
            <div class="rounded-[1.5rem] bg-slate-50 p-4">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-700">Sports Details</p>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <input name="sport" placeholder="Sport" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                    <input name="competition" placeholder="Competition" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                    <input name="home_team" placeholder="Home Team" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                    <input name="away_team" placeholder="Away Team" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                    <input name="venue" placeholder="Venue" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                    <input name="round_name" placeholder="Round" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm">
                </div>
            </div>
            <button class="rounded-full bg-brand-700 px-5 py-3 text-sm font-bold text-white">Create Event</button>
        </form>
    </section>

    <section class="rounded-[2rem] bg-white p-6 shadow-sm">
        <form method="get" class="flex flex-wrap gap-3">
            <input name="query" value="<?= e($filters['query']) ?>" placeholder="Search events" class="flex-1 rounded-full border border-slate-200 px-4 py-3 text-sm">
            <select name="status" class="rounded-full border border-slate-200 px-4 py-3 text-sm">
                <option value="">All statuses</option>
                <?php foreach (['draft','scheduled','live','ended','cancelled'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="rounded-full bg-slate-900 px-5 py-3 text-sm font-bold text-white">Filter</button>
        </form>

        <div class="mt-6 space-y-5">
            <?php foreach ($events as $event): ?>
                <?php $streamKey = StreamingService::decryptStreamKey($event['stream_key_encrypted']); ?>
                <div class="rounded-[1.75rem] border border-slate-200 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-700"><?= e($event['channel_name']) ?></p>
                            <h3 class="mt-2 text-xl font-black"><?= e($event['title']) ?></h3>
                            <p class="mt-2 text-sm text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                            <?php if (!empty($event['home_team']) && !empty($event['away_team'])): ?>
                                <p class="mt-3 text-sm font-semibold text-slate-700"><?= e($event['home_team']) ?> <?= (int)$event['home_score'] ?> - <?= (int)$event['away_score'] ?> <?= e($event['away_team']) ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] <?= e(tv_status_badge_class((string)$event['status'])) ?>"><?= e($event['status']) ?></span>
                    </div>
                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">OBS Server</p>
                            <p class="mt-2 text-sm font-semibold text-slate-800"><?= e(StreamingService::getIngestUrl()) ?></p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Event Stream Key</p>
                            <p class="mt-2 break-all text-sm font-semibold text-slate-800"><?= e((string)($streamKey ?: 'Not available')) ?></p>
                        </div>
                    </div>
                    <div class="mt-5 flex flex-wrap gap-3">
                        <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700">Watch page</a>
                        <form method="post" class="flex flex-wrap gap-3">
                            <?= tv_csrf_field() ?>
                            <input type="hidden" name="status_event" value="1">
                            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                            <select name="status" class="rounded-full border border-slate-200 px-4 py-2 text-sm">
                                <?php foreach (['draft','scheduled','live','ended','cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= $event['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?>
                            </select>
                            <button class="rounded-full bg-slate-900 px-4 py-2 text-sm font-bold text-white">Update Status</button>
                        </form>
                    </div>
                    <?php if (!empty($event['home_team']) && !empty($event['away_team'])): ?>
                        <form method="post" class="mt-4 flex flex-wrap items-end gap-3">
                            <?= tv_csrf_field() ?>
                            <input type="hidden" name="score_event" value="1">
                            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                            <div><label class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500"><?= e($event['home_team']) ?></label><input name="home_score" type="number" min="0" value="<?= (int)$event['home_score'] ?>" class="mt-2 w-24 rounded-full border border-slate-200 px-4 py-2 text-sm"></div>
                            <div><label class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500"><?= e($event['away_team']) ?></label><input name="away_score" type="number" min="0" value="<?= (int)$event['away_score'] ?>" class="mt-2 w-24 rounded-full border border-slate-200 px-4 py-2 text-sm"></div>
                            <button class="rounded-full border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700">Update Score</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($events === []): ?><div class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500">No events match the current filters.</div><?php endif; ?>
        </div>
    </section>
</div>
<?php tv_render_admin_footer(); ?>
