<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/core/DB.php';

Auth::start();
$me = AuthService::me();

if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    header('Location: login.php');
    exit;
}

$user = $me['user'];
$pdo = DB::pdo();

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$siteRoot = preg_replace('#/public$#', '', rtrim($scriptDir, '/'));
$siteRoot = $siteRoot === '/' ? '' : $siteRoot;
$visionBoardBase = $scheme . '://' . $host . $siteRoot . '/visionBoard';
$shortBase = $scheme . '://' . $host . $siteRoot . '/vb/';

$rows = $pdo->query(
    "SELECT
        p.id AS playlist_id,
        p.name AS playlist_name,
        p.description AS playlist_description,
        p.is_active AS playlist_active,
        p.created_at AS playlist_created_at,
        c.id AS company_id,
        c.name AS company_name,
        s.id AS screen_id,
        s.name AS screen_name,
        s.slug AS short_slug,
        s.pair_token,
        s.is_active AS screen_active
     FROM vb_playlists p
     JOIN companies c ON c.id = p.company_id
     LEFT JOIN vb_screens s ON s.playlist_id = p.id
     ORDER BY c.name ASC, p.created_at ASC, p.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$totalBoards = count($rows);
$withShortUrls = count(array_filter($rows, static fn ($row) => !empty($row['short_slug'])));
$withoutScreens = count(array_filter($rows, static fn ($row) => empty($row['screen_id'])));

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Vision Board Links - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php $pageTitle = 'Vision Board Links'; $headerMaxW = 'max-w-7xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<div class="mx-auto max-w-7xl px-4 pt-1 pb-5">
    <div class="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-950 px-5 py-5 text-white">
            <div>
                <h1 class="text-xl font-black tracking-tight">Vision Board Links</h1>
                <p class="mt-1 text-xs font-semibold text-white/55">All vBoards and their display links. Visible only to Centryk admins.</p>
            </div>
        </div>

        <div class="grid gap-3 p-5 pb-0 sm:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white px-4 py-3">
                <div class="text-xl font-black"><?= (int) $totalBoards ?></div>
                <div class="text-xs font-semibold text-slate-500">vBoards</div>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                <div class="text-xl font-black text-emerald-700"><?= (int) $withShortUrls ?></div>
                <div class="text-xs font-semibold text-emerald-600">With short URL</div>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                <div class="text-xl font-black text-amber-700"><?= (int) $withoutScreens ?></div>
                <div class="text-xs font-semibold text-amber-600">Without screen</div>
            </div>
        </div>

        <div class="p-5">
            <?php if (!$rows): ?>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-5 py-8 text-center text-sm text-slate-500">No Vision Board playlists found.</div>
            <?php else: ?>
                <div class="overflow-x-auto rounded-2xl border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr class="text-left text-xs font-black uppercase tracking-[0.12em] text-slate-500">
                                <th class="px-4 py-3">Company</th>
                                <th class="px-4 py-3">vBoard</th>
                                <th class="px-4 py-3">Screen</th>
                                <th class="px-4 py-3">Long URL</th>
                                <th class="px-4 py-3">Short URL</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $longUrl = !empty($row['pair_token']) ? ($visionBoardBase . '/display/?screen=' . rawurlencode((string) $row['pair_token'])) : '';
                                $shortUrl = !empty($row['short_slug']) ? ($shortBase . rawurlencode((string) $row['short_slug'])) : '';
                                $playlistName = trim((string) ($row['playlist_name'] ?? '')) ?: 'Untitled';
                                $screenName = trim((string) ($row['screen_name'] ?? '')) ?: 'No screen';
                                ?>
                                <tr class="align-top">
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-slate-900"><?= htmlspecialchars((string) $row['company_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="mt-1 text-xs text-slate-500">Company ID: <?= (int) $row['company_id'] ?></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-bold text-slate-900"><?= htmlspecialchars($playlistName, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="mt-1 text-xs text-slate-500">vBoard ID: <?= (int) $row['playlist_id'] ?></div>
                                        <div class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] <?= !empty($row['playlist_active']) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">
                                            <?= !empty($row['playlist_active']) ? 'Active' : 'Inactive' ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-slate-800"><?= htmlspecialchars($screenName, ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($row['screen_id'])): ?>
                                            <div class="mt-1 text-xs text-slate-500">Screen ID: <?= (int) $row['screen_id'] ?></div>
                                            <div class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] <?= !empty($row['screen_active']) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">
                                                <?= !empty($row['screen_active']) ? 'Enabled' : 'Disabled' ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="mt-1 text-xs text-amber-600">No screen attached</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($longUrl !== ''): ?>
                                            <div class="flex items-start gap-2">
                                                <input readonly value="<?= htmlspecialchars($longUrl, ENT_QUOTES, 'UTF-8') ?>" onclick="this.select()" class="w-[26rem] max-w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700">
                                                <button type="button" class="copy-btn rounded-lg border border-slate-300 px-2.5 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50" data-copy="<?= htmlspecialchars($longUrl, ENT_QUOTES, 'UTF-8') ?>">Copy</button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">No long URL</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($shortUrl !== ''): ?>
                                            <div class="flex items-start gap-2">
                                                <input readonly value="<?= htmlspecialchars($shortUrl, ENT_QUOTES, 'UTF-8') ?>" onclick="this.select()" class="w-64 max-w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700">
                                                <button type="button" class="copy-btn rounded-lg border border-slate-300 px-2.5 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50" data-copy="<?= htmlspecialchars($shortUrl, ENT_QUOTES, 'UTF-8') ?>">Copy</button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">No short URL</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.copy-btn').forEach(function (button) {
    button.addEventListener('click', async function () {
        const value = button.getAttribute('data-copy') || '';
        if (!value) return;
        try {
            await navigator.clipboard.writeText(value);
            const original = button.textContent;
            button.textContent = 'Copied';
            setTimeout(function () {
                button.textContent = original;
            }, 1000);
        } catch (error) {
            window.prompt('Copy URL', value);
        }
    });
});
</script>
</body>
</html>
