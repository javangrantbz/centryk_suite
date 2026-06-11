<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/NotificationService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$userId = (int)$user['id'];

// Opening the page clears "new" notifications.
NotificationService::markAllRead($userId);
$notifications = NotificationService::recent($userId, 50);

function notif_time_ago(?string $ts): string
{
    if (!$ts) return '';
    $t = strtotime($ts);
    if (!$t) return '';
    $diff = max(1, time() - $t);
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $t);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Notifications — Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>
        [data-lucide] { display: inline-block; }
        body.light { background-color: #f1f5f9 !important; color: #0f172a; }
        body.light header { background-color: #ffffff !important; border-bottom-color: #e2e8f0 !important; }
        body.light .bg-\[\#0d1117\], body.light .bg-\[\#111827\] { background-color: #ffffff; }
        body.light .bg-white\/5 { background-color: #f8fafc; }
        body.light .border-white\/10 { border-color: #e2e8f0; }
        body.light .text-white { color: #0f172a; }
        body.light .text-white\/60 { color: #64748b; }
        body.light .text-white\/40 { color: #94a3b8; }
    </style>
</head>
<body class="min-h-screen bg-[#0d1117] font-sans antialiased text-white">
<script>var _ct=localStorage.getItem('centrikyTheme');if(_ct==='light'){document.body.classList.add('light');}</script>

<?php $pageTitle = 'Notifications'; $headerMaxW = 'max-w-3xl'; $awCurrent = 'account'; include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-3xl px-6 py-8">
    <div class="mb-5 flex items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-orange-500/15 text-orange-400">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        </span>
        <div>
            <h1 class="text-xl font-black leading-tight">Notifications</h1>
            <p class="text-xs text-white/40">Activity across all your Centryk apps</p>
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
        <?php if (empty($notifications)): ?>
            <div class="px-6 py-16 text-center text-sm text-white/40">You're all caught up. Nothing to show yet.</div>
        <?php else: ?>
            <ul class="divide-y divide-white/10">
                <?php foreach ($notifications as $n):
                    $accent = (!empty($n['color']) && preg_match('/^#/', $n['color'])) ? $n['color'] : '#f97316';
                    $href = $n['url'] !== '' ? $n['url'] : '#';
                ?>
                <li>
                    <a href="<?= htmlspecialchars($href) ?>" class="flex gap-3 px-5 py-4 transition hover:bg-white/5">
                        <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" style="background:<?= htmlspecialchars($accent) ?>"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold text-white"><?= htmlspecialchars($n['title']) ?></span>
                            <?php if ($n['body'] !== ''): ?>
                            <span class="mt-0.5 block text-[13px] text-white/60"><?= htmlspecialchars($n['body']) ?></span>
                            <?php endif; ?>
                            <span class="mt-1 block text-[10px] font-bold uppercase tracking-widest text-white/40"><?= htmlspecialchars($n['app_key']) ?> &middot; <?= htmlspecialchars(notif_time_ago($n['created_at'])) ?></span>
                        </span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
</body>
</html>
