<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-shell.php';

$user = tv_require_organization();
$organization = tv_active_organization();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tv_verify_csrf();

    try {
        if (isset($_POST['create_channel'])) {
            if (!tv_role_at_least('admin')) {
                throw new RuntimeException('Admin access required to create channels.');
            }

            $channel = TvManagementService::createChannel((int)$organization['id'], (int)$user['id'], $_POST);
            tv_flash('success', 'Channel created. Stream key: ' . $channel['stream_key']);
            tv_redirect(tv_url('dashboard/channels'));
        }

        if (isset($_POST['regenerate_key'])) {
            if (!tv_role_at_least('admin')) {
                throw new RuntimeException('Admin access required to rotate stream keys.');
            }

            $channelId = (int)($_POST['channel_id'] ?? 0);
            $key = StreamingService::regenerateChannelStreamKey((int)$organization['id'], $channelId, (int)$user['id']);
            tv_flash('success', 'Channel stream key regenerated. New key: ' . $key['raw_key']);
            tv_redirect(tv_url('dashboard/channels'));
        }
    } catch (Throwable $e) {
        tv_flash('error', $e->getMessage());
        tv_redirect(tv_url('dashboard/channels'));
    }
}

$channels = db()->prepare(
    'SELECT c.*,
            (
                SELECT sk.stream_key_encrypted
                FROM tv_stream_keys sk
                WHERE sk.channel_id = c.id AND sk.event_id IS NULL AND sk.revoked_at IS NULL
                ORDER BY sk.id DESC
                LIMIT 1
            ) AS stream_key_encrypted
     FROM tv_channels c
     WHERE c.organization_id = :organization_id
     ORDER BY c.created_at DESC'
);
$channels->execute(['organization_id' => (int)$organization['id']]);
$channels = $channels->fetchAll();

tv_render_admin_header('Channels', 'channels');
?>
<div class="grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
    <section class="rounded-[2rem] bg-white p-6 shadow-sm">
        <h3 class="text-xl font-black">Create Channel</h3>
        <p class="mt-2 text-sm text-slate-500">Build branded channels for sports, ceremonies, services, or internal broadcasts.</p>
        <form method="post" enctype="multipart/form-data" class="mt-6 space-y-4">
            <?= tv_csrf_field() ?>
            <input type="hidden" name="create_channel" value="1">
            <div><label class="text-sm font-semibold">Channel Name</label><input name="name" required class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none"></div>
            <div><label class="text-sm font-semibold">Description</label><textarea name="description" rows="3" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none"></textarea></div>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="text-sm font-semibold">Visibility</label><select name="visibility" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none"><option value="public">Public</option><option value="authenticated">Authenticated</option><option value="private">Private</option></select></div>
                <div><label class="text-sm font-semibold">Status</label><select name="status" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="text-sm font-semibold">Channel Logo</label><input type="file" name="logo" accept="image/*" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
                <div><label class="text-sm font-semibold">Cover Image</label><input type="file" name="cover_image" accept="image/*" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
            </div>
            <button class="rounded-full bg-brand-700 px-5 py-3 text-sm font-bold text-white">Create Channel</button>
        </form>
    </section>

    <section class="rounded-[2rem] bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <h3 class="text-xl font-black">Channel Directory</h3>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] text-slate-600"><?= count($channels) ?> total</span>
        </div>
        <div class="mt-6 space-y-5">
            <?php foreach ($channels as $channel): ?>
                <?php $rawKey = StreamingService::decryptStreamKey($channel['stream_key_encrypted']); ?>
                <div class="rounded-[1.75rem] border border-slate-200 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-700"><?= e($channel['visibility']) ?></p>
                            <h4 class="mt-2 text-xl font-black"><?= e($channel['name']) ?></h4>
                            <p class="mt-2 text-sm leading-6 text-slate-500"><?= e((string)($channel['description'] ?: 'No description added yet.')) ?></p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] <?= e(tv_status_badge_class((string)$channel['status'])) ?>"><?= e($channel['status']) ?></span>
                    </div>
                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">RTMP Server</p>
                            <p class="mt-2 text-sm font-semibold text-slate-800"><?= e(StreamingService::getIngestUrl()) ?></p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Stream Key</p>
                            <p class="mt-2 break-all text-sm font-semibold text-slate-800"><?= e((string)($rawKey ?: 'Not available')) ?></p>
                        </div>
                    </div>
                    <?php if (tv_role_at_least('admin')): ?>
                        <form method="post" class="mt-5" onsubmit="return confirm('Regenerate the stream key? The old key will stop working.');">
                            <?= tv_csrf_field() ?>
                            <input type="hidden" name="regenerate_key" value="1">
                            <input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>">
                            <button class="rounded-full border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50">Regenerate Stream Key</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($channels === []): ?><div class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500">No channels created yet.</div><?php endif; ?>
        </div>
    </section>
</div>
<?php tv_render_admin_footer(); ?>

