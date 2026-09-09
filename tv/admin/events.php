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

        if (isset($_POST['price_event'])) {
            if (!tv_role_at_least('broadcaster')) {
                throw new RuntimeException('Broadcaster access required to change pricing.');
            }
            $newPrice = TvManagementService::updateEventPricing((int)$organization['id'], (int)$_POST['event_id'], $_POST['price_amount'] ?? '', (int)$user['id']);
            tv_flash('success', $newPrice === null
                ? 'This event is now free to watch.'
                : 'Pay-per-view price set to BZD ' . number_format($newPrice, 2) . '.');
            tv_redirect(tv_url('dashboard/events'));
        }

        if (isset($_POST['grant_access'])) {
            if (!tv_role_at_least('admin')) {
                throw new RuntimeException('Admin access required to grant free access.');
            }
            $who = TvManagementService::grantEventAccess((int)$organization['id'], (int)$_POST['event_id'], (string)($_POST['email'] ?? ''), (int)$user['id']);
            tv_flash('success', $who . ' can now watch this event for free.');
            tv_redirect(tv_url('dashboard/events'));
        }

        if (isset($_POST['revoke_access'])) {
            if (!tv_role_at_least('admin')) {
                throw new RuntimeException('Admin access required to remove access.');
            }
            TvManagementService::revokeEventAccess((int)$organization['id'], (int)$_POST['event_id'], (int)$_POST['user_id'], (int)$user['id']);
            tv_flash('success', 'Free access removed.');
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

// Pay-per-view supporting data: whether OneLink can actually take money for
// this org, plus who has paid / been comped into each event.
$paymentConfigured = TvPaymentService::isPaymentConfigured((int)$organization['id']);
$hasPricedEvent = false;
foreach ($events as $e) {
    if ((float)($e['price_amount'] ?? 0) > 0) {
        $hasPricedEvent = true;
        break;
    }
}

$purchasersByEvent = [];
$failedCountByEvent = [];
$compsByEvent = [];
if ($hasPricedEvent) {
    $paymentsStmt = db()->prepare(
        'SELECT p.event_id, p.amount, p.currency, p.status, p.card_brand, p.card_last4, p.created_at,
                u.first_name, u.last_name, u.email
         FROM tv_payments p
         JOIN users u ON u.id = p.user_id
         WHERE p.organization_id = :organization_id
         ORDER BY p.created_at DESC'
    );
    $paymentsStmt->execute(['organization_id' => (int)$organization['id']]);
    foreach ($paymentsStmt->fetchAll() as $row) {
        $eid = (int)$row['event_id'];
        if ($row['status'] === 'succeeded') {
            $purchasersByEvent[$eid][] = $row;
        } elseif ($row['status'] === 'failed') {
            $failedCountByEvent[$eid] = ($failedCountByEvent[$eid] ?? 0) + 1;
        }
    }

    $compsStmt = db()->prepare(
        'SELECT a.event_id, a.user_id, a.created_at,
                u.first_name, u.last_name, u.email,
                g.first_name AS granted_by_first, g.last_name AS granted_by_last
         FROM tv_event_access a
         JOIN tv_events e ON e.id = a.event_id
         JOIN users u ON u.id = a.user_id
         LEFT JOIN users g ON g.id = a.granted_by
         WHERE e.organization_id = :organization_id AND a.granted_by IS NOT NULL
         ORDER BY a.created_at DESC'
    );
    $compsStmt->execute(['organization_id' => (int)$organization['id']]);
    foreach ($compsStmt->fetchAll() as $row) {
        $compsByEvent[(int)$row['event_id']][] = $row;
    }
}

$viewerName = static function (array $row): string {
    $name = trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
    return $name !== '' ? $name : (string)($row['email'] ?? 'Unknown');
};

tv_render_admin_header('Events', 'events');
?>

<?php if ($hasPricedEvent && !$paymentConfigured): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        <p class="font-bold">Pay-per-view isn't ready to take payments yet.</p>
        <p class="mt-1 leading-6">You've set a price on at least one event, but OneLink card processing hasn't been set up for <?= e((string)($organization['company_name'] ?? $organization['name'])) ?> yet. Viewers will see a "check back later" message on the payment screen until Centryk finishes connecting OneLink for your company.</p>
    </div>
<?php endif; ?>
<div class="grid gap-4 xl:grid-cols-[0.85fr_1.15fr]">
    <section class="rounded-lg bg-white p-4 shadow-sm">
        <h3 class="text-base font-black">Create Event</h3>
        <?php if ($channels === []): ?>
            <p class="mt-2 text-sm leading-6 text-slate-500">An event lives on a channel, and this organization doesn't have one yet. Create a channel first, then come back here to schedule events on it.</p>
            <a href="<?= e(tv_url('dashboard/channels')) ?>" class="mt-4 inline-flex rounded-md bg-brand-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-brand-600">Go to Channels</a>
        <?php else: ?>
        <p class="mt-2 text-sm text-slate-500">Schedule a broadcast on one of your channels. You'll get an OBS stream key to go live.</p>
        <form method="post" enctype="multipart/form-data" class="mt-4 space-y-4">
            <?= tv_csrf_field() ?>
            <input type="hidden" name="create_event" value="1">
            <div><label class="text-sm font-semibold">Event Name</label><input name="title" required class="mt-2 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"></div>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="text-sm font-semibold">Channel</label><select name="channel_id" required class="mt-2 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"><?php foreach ($channels as $channel): ?><option value="<?= (int)$channel['id'] ?>"><?= e($channel['name']) ?></option><?php endforeach; ?></select></div>
                <div><label class="text-sm font-semibold">Type</label><select name="event_type" class="mt-2 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"><option value="sports">Sports</option><option value="business">Business</option><option value="education">Education</option><option value="church">Church</option><option value="government">Government</option><option value="conference">Conference</option><option value="entertainment">Entertainment</option><option value="other">Other</option></select></div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="text-sm font-semibold">Start At</label><input type="datetime-local" name="start_at" required class="mt-2 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"></div>
                <div><label class="text-sm font-semibold">End At</label><input type="datetime-local" name="end_at" class="mt-2 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"></div>
            </div>
            <div><label class="text-sm font-semibold">Description</label><textarea name="description" rows="3" class="mt-2 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"></textarea></div>
            <div class="grid gap-4 md:grid-cols-2">
            <div><label class="text-sm font-semibold">Visibility</label><select name="visibility" class="mt-2 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"><option value="public">Public</option><option value="authenticated">Authenticated</option><option value="private">Private</option></select></div>
                <div><label class="text-sm font-semibold">Status</label><select name="status" class="mt-2 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"><option value="scheduled">Scheduled</option><option value="draft">Draft</option><option value="live">Live</option></select></div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700">Access scope</p>
                <div class="mt-3 grid gap-2 md:grid-cols-3">
                    <div class="rounded-md bg-white p-3 text-xs leading-5 text-slate-600 shadow-sm">
                        <p class="font-bold text-slate-900">Public</p>
                        <p class="mt-1">Anyone can watch the event page.</p>
                    </div>
                    <div class="rounded-md bg-white p-3 text-xs leading-5 text-slate-600 shadow-sm">
                        <p class="font-bold text-slate-900">Authenticated</p>
                        <p class="mt-1">Any signed-in Centryk user can watch.</p>
                    </div>
                    <div class="rounded-md bg-white p-3 text-xs leading-5 text-slate-600 shadow-sm">
                        <p class="font-bold text-slate-900">Private</p>
                        <p class="mt-1">Only explicitly granted viewers can watch.</p>
                    </div>
                </div>
                <p class="mt-3 text-[11px] leading-5 text-slate-500">Your organization members can manage and watch their own events. Use visibility to control who outside the organization can access the event.</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700">Pay-per-view</p>
                <p class="mt-2 text-[11px] leading-5 text-slate-500">Charge viewers a one-time fee to watch. Leave the price blank (or 0) for a free event. Your own organization members always watch free; everyone else pays once and keeps access, including the replay.</p>
                <div class="mt-3 flex items-center gap-2">
                    <span class="text-sm font-semibold text-slate-500">BZD</span>
                    <input name="price_amount" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" class="w-40 rounded-md border border-slate-200 px-3 py-2 text-sm">
                </div>
                <?php if (!$paymentConfigured): ?>
                    <p class="mt-2 text-[11px] font-semibold leading-5 text-amber-700">Heads up: OneLink card processing isn't connected for your company yet, so viewers won't be able to pay until Centryk sets it up.</p>
                <?php endif; ?>
            </div>
            <div><label class="text-sm font-semibold">Thumbnail</label><input type="file" name="thumbnail" accept="image/*" class="mt-2 w-full rounded-md border border-slate-200 px-3 py-2 text-sm"></div>
            <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <input type="checkbox" name="is_replay_enabled" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300">
                <span>
                    <span class="block text-sm font-semibold text-slate-900">Save a replay</span>
                    <span class="block text-xs leading-5 text-slate-500">Once the broadcast ends, viewers can watch it back on the same event page. Off by default.</span>
                </span>
            </label>
            <div class="rounded-lg bg-slate-50 p-4">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700">Sports Details</p>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <input name="sport" placeholder="Sport" class="rounded-md border border-slate-200 px-3 py-2 text-sm">
                    <input name="competition" placeholder="Competition" class="rounded-md border border-slate-200 px-3 py-2 text-sm">
                    <input name="home_team" placeholder="Home Team" class="rounded-md border border-slate-200 px-3 py-2 text-sm">
                    <input name="away_team" placeholder="Away Team" class="rounded-md border border-slate-200 px-3 py-2 text-sm">
                    <input name="venue" placeholder="Venue" class="rounded-md border border-slate-200 px-3 py-2 text-sm">
                    <input name="round_name" placeholder="Round" class="rounded-md border border-slate-200 px-3 py-2 text-sm">
                </div>
            </div>
            <button class="rounded-md bg-brand-700 px-4 py-2 text-sm font-bold text-white">Create Event</button>
        </form>
        <?php endif; ?>
    </section>

    <section class="rounded-lg bg-white p-4 shadow-sm">
        <form method="get" class="flex flex-wrap gap-2">
            <input name="query" value="<?= e($filters['query']) ?>" placeholder="Search events" class="flex-1 rounded-md border border-slate-200 px-3.5 py-2 text-sm">
            <select name="status" class="rounded-md border border-slate-200 px-3.5 py-2 text-sm">
                <option value="">All statuses</option>
                <?php foreach (['draft','scheduled','live','ended','cancelled'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-bold text-white">Filter</button>
        </form>

        <div class="mt-4 space-y-3">
            <?php foreach ($events as $event): ?>
                <?php $streamKey = StreamingService::decryptStreamKey($event['stream_key_encrypted']); ?>
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700"><?= e($event['channel_name']) ?></p>
                            <h3 class="mt-2 text-lg font-black"><?= e($event['title']) ?></h3>
                            <p class="mt-2 text-sm text-slate-500"><?= e(tv_format_datetime($event['start_at'])) ?></p>
                            <?php if (!empty($event['home_team']) && !empty($event['away_team'])): ?>
                                <p class="mt-3 text-sm font-semibold text-slate-700"><?= e($event['home_team']) ?> <?= (int)$event['home_score'] ?> - <?= (int)$event['away_score'] ?> <?= e($event['away_team']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-col items-end gap-1.5">
                            <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-[0.14em] <?= e(tv_status_badge_class((string)$event['status'])) ?>"><?= e($event['status']) ?></span>
                            <?php if ((float)($event['price_amount'] ?? 0) > 0): ?>
                                <span class="rounded-full bg-brand-100 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.14em] text-brand-700">PPV &middot; BZD <?= number_format((float)$event['price_amount'], 2) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($event['is_replay_enabled'])): ?>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Replay: <?= e((string)($event['replay_status'] ?: 'none')) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <div class="rounded-md bg-slate-50 p-3">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">OBS Server</p>
                            <p class="mt-2 text-sm font-semibold text-slate-800"><?= e(StreamingService::getIngestUrl()) ?></p>
                        </div>
                        <div class="rounded-md bg-slate-50 p-3">
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Event Stream Key</p>
                            <p class="mt-2 break-all text-sm font-semibold text-slate-800"><?= e((string)($streamKey ?: 'Not available')) ?></p>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="rounded-md border border-slate-200 px-3.5 py-2 text-sm font-bold text-slate-700">Watch page</a>
                        <form method="post" class="flex flex-wrap gap-3">
                            <?= tv_csrf_field() ?>
                            <input type="hidden" name="status_event" value="1">
                            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                            <select name="status" class="rounded-md border border-slate-200 px-3.5 py-2 text-sm">
                                <?php foreach (['draft','scheduled','live','ended','cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= $event['status'] === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option><?php endforeach; ?>
                            </select>
                            <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-bold text-white">Update Status</button>
                        </form>
                    </div>
                    <?php if (!empty($event['home_team']) && !empty($event['away_team'])): ?>
                        <form method="post" class="mt-4 flex flex-wrap items-end gap-3">
                            <?= tv_csrf_field() ?>
                            <input type="hidden" name="score_event" value="1">
                            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                            <div><label class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500"><?= e($event['home_team']) ?></label><input name="home_score" type="number" min="0" value="<?= (int)$event['home_score'] ?>" class="mt-2 w-24 rounded-md border border-slate-200 px-3.5 py-2 text-sm"></div>
                            <div><label class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500"><?= e($event['away_team']) ?></label><input name="away_score" type="number" min="0" value="<?= (int)$event['away_score'] ?>" class="mt-2 w-24 rounded-md border border-slate-200 px-3.5 py-2 text-sm"></div>
                            <button class="rounded-md border border-slate-200 px-3.5 py-2 text-sm font-bold text-slate-700">Update Score</button>
                        </form>
                    <?php endif; ?>

                    <?php
                    $eid = (int)$event['id'];
                    $priceAmount = (float)($event['price_amount'] ?? 0);
                    $purchasers = $purchasersByEvent[$eid] ?? [];
                    $comps = $compsByEvent[$eid] ?? [];
                    $gross = 0.0;
                    foreach ($purchasers as $p) { $gross += (float)$p['amount']; }
                    $failed = $failedCountByEvent[$eid] ?? 0;
                    ?>
                    <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700">Pay-per-view</p>
                                <p class="mt-1 text-sm text-slate-600">
                                    <?php if ($priceAmount > 0): ?>
                                        <span class="font-bold text-slate-900"><?= count($purchasers) ?></span> purchase<?= count($purchasers) === 1 ? '' : 's' ?>
                                        &middot; <span class="font-bold text-slate-900">BZD <?= number_format($gross, 2) ?></span> collected
                                        <?php if (count($comps) > 0): ?> &middot; <?= count($comps) ?> free<?php endif; ?>
                                        <?php if ($failed > 0): ?> &middot; <span class="text-rose-600"><?= (int)$failed ?> failed</span><?php endif; ?>
                                    <?php else: ?>
                                        Free event. Set a price to charge non-members for access.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <form method="post" class="flex items-end gap-2">
                                <?= tv_csrf_field() ?>
                                <input type="hidden" name="price_event" value="1">
                                <input type="hidden" name="event_id" value="<?= $eid ?>">
                                <div>
                                    <label class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Price (BZD)</label>
                                    <input name="price_amount" type="number" min="0" step="0.01" inputmode="decimal" value="<?= $priceAmount > 0 ? number_format($priceAmount, 2, '.', '') : '' ?>" placeholder="0.00" class="mt-1 w-28 rounded-md border border-slate-200 px-3.5 py-2 text-sm">
                                </div>
                                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-bold text-white"><?= $priceAmount > 0 ? 'Update price' : 'Set price' ?></button>
                            </form>
                        </div>

                        <?php if ($priceAmount > 0 && (count($purchasers) > 0 || count($comps) > 0 || tv_role_at_least('admin'))): ?>
                            <details class="mt-3 border-t border-slate-200 pt-3">
                                <summary class="cursor-pointer text-sm font-bold text-slate-700">Access &amp; purchasers</summary>
                                <div class="mt-3 space-y-3">
                                    <?php if (count($purchasers) > 0): ?>
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-left text-sm">
                                                <thead><tr class="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-400"><th class="pb-1 pr-3">Viewer</th><th class="pb-1 pr-3">Paid</th><th class="pb-1 pr-3">Card</th><th class="pb-1">When</th></tr></thead>
                                                <tbody>
                                                <?php foreach ($purchasers as $p): ?>
                                                    <tr class="border-t border-slate-100">
                                                        <td class="py-1.5 pr-3"><?= e($viewerName($p)) ?><span class="block text-[11px] text-slate-400"><?= e((string)$p['email']) ?></span></td>
                                                        <td class="py-1.5 pr-3 font-semibold">BZD <?= number_format((float)$p['amount'], 2) ?></td>
                                                        <td class="py-1.5 pr-3 text-slate-500"><?= e(trim(((string)($p['card_brand'] ?? '')) . ' ' . ($p['card_last4'] ? '····' . $p['card_last4'] : ''))) ?: '—' ?></td>
                                                        <td class="py-1.5 text-slate-500"><?= e(tv_format_datetime($p['created_at'], 'M j, Y g:i A')) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>

                                    <?php foreach ($comps as $c): ?>
                                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-white px-3 py-2">
                                            <div class="text-sm">
                                                <span class="font-semibold text-slate-800"><?= e($viewerName($c)) ?></span>
                                                <span class="text-slate-400">· <?= e((string)$c['email']) ?></span>
                                                <span class="block text-[11px] text-slate-400">Free access
                                                    <?php $gb = trim(((string)($c['granted_by_first'] ?? '')) . ' ' . ((string)($c['granted_by_last'] ?? ''))); ?>
                                                    <?= $gb !== '' ? 'from ' . e($gb) : '' ?> · <?= e(tv_format_datetime($c['created_at'], 'M j, Y')) ?></span>
                                            </div>
                                            <?php if (tv_role_at_least('admin')): ?>
                                                <form method="post" onsubmit="return confirm('Remove this person\'s free access?');">
                                                    <?= tv_csrf_field() ?>
                                                    <input type="hidden" name="revoke_access" value="1">
                                                    <input type="hidden" name="event_id" value="<?= $eid ?>">
                                                    <input type="hidden" name="user_id" value="<?= (int)$c['user_id'] ?>">
                                                    <button class="rounded-full border border-slate-200 px-3 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50">Remove</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if (tv_role_at_least('admin')): ?>
                                        <form method="post" class="flex flex-wrap items-end gap-2 border-t border-slate-200 pt-3">
                                            <?= tv_csrf_field() ?>
                                            <input type="hidden" name="grant_access" value="1">
                                            <input type="hidden" name="event_id" value="<?= $eid ?>">
                                            <div class="flex-1">
                                                <label class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Grant free access by email</label>
                                                <input name="email" type="email" required placeholder="person@example.com" class="mt-1 w-full rounded-md border border-slate-200 px-3.5 py-2 text-sm">
                                            </div>
                                            <button class="rounded-md border border-slate-200 px-3.5 py-2 text-sm font-bold text-slate-700">Grant access</button>
                                        </form>
                                        <p class="text-[11px] leading-5 text-slate-400">They need a Centryk account with that email. Members of your organization already watch free and don't need this.</p>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($events === []): ?><div class="rounded-md border border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500">No events match the current filters.</div><?php endif; ?>
        </div>
    </section>
</div>
<?php tv_render_admin_footer(); ?>
