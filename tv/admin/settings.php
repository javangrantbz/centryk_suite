<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-shell.php';

$user = tv_require_organization();
if (!tv_role_at_least('admin')) {
    http_response_code(403);
    exit('Admin access required.');
}

$organization = tv_active_organization();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tv_verify_csrf();
    try {
        TvManagementService::updateOrganizationProfile((int)$organization['id'], (int)$user['id'], $_POST);
        tv_flash('success', 'Organization settings updated.');
    } catch (Throwable $e) {
        tv_flash('error', $e->getMessage());
    }
    tv_redirect(tv_url('dashboard/settings'));
}

tv_render_admin_header('Settings', 'settings');
?>
<section class="rounded-[2rem] bg-white p-6 shadow-sm">
    <h3 class="text-xl font-black">Organization Settings</h3>
    <form method="post" enctype="multipart/form-data" class="mt-6 space-y-4">
        <?= tv_csrf_field() ?>
        <div><label class="text-sm font-semibold">Organization Name</label><input name="name" value="<?= e((string)$organization['name']) ?>" required class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
        <div><label class="text-sm font-semibold">Description</label><textarea name="description" rows="4" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"><?= e((string)$organization['description']) ?></textarea></div>
        <div class="grid gap-4 md:grid-cols-2">
            <div><label class="text-sm font-semibold">Email</label><input name="email" value="<?= e((string)$organization['email']) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
            <div><label class="text-sm font-semibold">Phone</label><input name="phone" value="<?= e((string)$organization['phone']) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <div><label class="text-sm font-semibold">Website</label><input name="website" value="<?= e((string)$organization['website']) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
            <div><label class="text-sm font-semibold">Timezone</label><input name="timezone" value="<?= e((string)$organization['timezone']) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <div><label class="text-sm font-semibold">Logo</label><input type="file" name="logo" accept="image/*" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
            <div><label class="text-sm font-semibold">Banner</label><input type="file" name="banner" accept="image/*" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm"></div>
        </div>
        <button class="rounded-full bg-brand-700 px-5 py-3 text-sm font-bold text-white">Save Settings</button>
    </form>
</section>
<?php tv_render_admin_footer(); ?>

