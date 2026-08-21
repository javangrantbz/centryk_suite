<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/AuthService.php';

Auth::start();
$me = AuthService::me();

if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}

$currentUser = $me['user'];
$pdo = DB::pdo();

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) {
    header('Location: requests.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT id, uuid, first_name, last_name, email, phone, status, mfa_enabled, is_admin,
           onboarding_seen, last_login_at, created_at, updated_at
    FROM users WHERE id = :id LIMIT 1
');
$stmt->execute(['id' => $userId]);
$target = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    header('Location: requests.php');
    exit;
}

$companiesStmt = $pdo->prepare('
    SELECT c.uuid, c.name, c.status AS company_status, cm.role, cm.status AS membership_status, cm.created_at AS joined_at
    FROM company_members cm
    JOIN companies c ON c.id = cm.company_id
    WHERE cm.user_id = :uid
    ORDER BY cm.created_at ASC
');
$companiesStmt->execute(['uid' => $userId]);
$companies = $companiesStmt->fetchAll(PDO::FETCH_ASSOC);

$loginEventsStmt = $pdo->prepare('
    SELECT ip_address, user_agent, success, created_at
    FROM login_events
    WHERE email = :email
    ORDER BY created_at DESC
    LIMIT 10
');
$loginEventsStmt->execute(['email' => $target['email']]);
$loginEvents = $loginEventsStmt->fetchAll(PDO::FETCH_ASSOC);

function up_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function up_date($v): string {
    $v = (string)($v ?? '');
    if ($v === '') return '—';
    $ts = strtotime($v);
    return $ts ? date('M j, Y', $ts) : '—';
}
function up_datetime($v): string {
    $v = (string)($v ?? '');
    if ($v === '') return '—';
    $ts = strtotime($v);
    return $ts ? date('M j, Y g:ia', $ts) : '—';
}

$fullName = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? '')) ?: 'Unnamed';

ob_start();
include __DIR__ . '/partials/admin_tools_dropdown.php';
$headerActionsHtml = ob_get_clean();

$pageTitle  = $fullName;
$headerMaxW = 'max-w-4xl';
$awCurrent  = 'centryk';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title><?= up_h($fullName) ?> - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>[data-lucide] { display: inline-block; }</style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased">
<?php include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-4xl px-4 pt-1 pb-8">

    <a href="requests.php" class="mb-4 inline-flex items-center gap-1.5 text-xs font-black uppercase tracking-[0.12em] text-slate-500 hover:text-slate-800">
        <i data-lucide="arrow-left" class="h-3.5 w-3.5"></i> All Signups
    </a>

    <!-- User header -->
    <div class="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center gap-4 bg-slate-950 px-5 py-5 text-white">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-sky-500 text-xl font-black text-slate-900">
                <?= up_h(strtoupper(substr($target['first_name'] ?: '?', 0, 1))) ?>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-black tracking-tight"><?= up_h($fullName) ?></h1>
                    <span class="rounded-full border px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] <?= $target['status'] === 'active' ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300' : 'border-white/15 bg-white/5 text-white/50' ?>"><?= up_h($target['status']) ?></span>
                    <?php if (!empty($target['is_admin'])): ?>
                    <span class="rounded-full border border-violet-400/30 bg-violet-400/10 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-violet-300">Platform Admin</span>
                    <?php endif; ?>
                    <?php if (!empty($target['mfa_enabled'])): ?>
                    <span class="rounded-full border border-cyan-400/30 bg-cyan-400/10 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-cyan-300">MFA On</span>
                    <?php endif; ?>
                </div>
                <p class="mt-1 text-xs font-semibold text-white/55"><?= up_h($target['email']) ?><?= !empty($target['phone']) ? ' · ' . up_h($target['phone']) : '' ?></p>
            </div>
        </div>

        <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                <p class="text-sm font-black text-slate-800"><?= up_date($target['created_at']) ?></p>
                <p class="mt-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-400">Signed Up</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                <p class="text-sm font-black text-slate-800"><?= up_datetime($target['last_login_at']) ?></p>
                <p class="mt-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-400">Last Login</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                <p class="text-sm font-black text-slate-800"><?= !empty($target['onboarding_seen']) ? 'Completed' : 'Not seen' ?></p>
                <p class="mt-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-400">Onboarding</p>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                <p class="text-sm font-black text-slate-800"><?= count($companies) ?></p>
                <p class="mt-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-400">Companies</p>
            </div>
        </div>
    </div>

    <!-- Companies -->
    <section class="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-sm font-black tracking-tight">Companies</h2>
        </div>
        <div class="divide-y divide-slate-100">
            <?php if (!$companies): ?>
            <div class="px-5 py-6 text-center text-sm font-semibold text-slate-400">Not a member of any company.</div>
            <?php endif; ?>
            <?php foreach ($companies as $c): ?>
            <div class="flex items-center justify-between px-5 py-3 text-sm">
                <div class="min-w-0">
                    <p class="truncate font-bold text-slate-800"><?= up_h($c['name']) ?></p>
                    <p class="truncate text-xs text-slate-400">Joined <?= up_date($c['joined_at']) ?> · Company <?= up_h($c['company_status']) ?></p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em] text-slate-600"><?= up_h($c['role']) ?></span>
                    <span class="text-[10px] font-black uppercase tracking-[0.1em] <?= $c['membership_status'] === 'active' ? 'text-emerald-600' : 'text-slate-400' ?>"><?= up_h($c['membership_status']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Recent sign-in activity -->
    <section class="mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-sm font-black tracking-tight">Recent Sign-In Activity</h2>
        </div>
        <div class="divide-y divide-slate-100">
            <?php if (!$loginEvents): ?>
            <div class="px-5 py-6 text-center text-sm font-semibold text-slate-400">No sign-in attempts recorded yet.</div>
            <?php endif; ?>
            <?php foreach ($loginEvents as $ev): ?>
            <div class="flex items-center justify-between px-5 py-3 text-sm">
                <div class="min-w-0">
                    <p class="truncate font-semibold text-slate-700"><?= up_h($ev['ip_address'] ?: 'Unknown IP') ?></p>
                    <p class="truncate text-[11px] text-slate-400"><?= up_h(mb_strimwidth((string)($ev['user_agent'] ?? ''), 0, 70, '…')) ?></p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-[10px] font-black uppercase tracking-[0.1em] <?= !empty($ev['success']) ? 'text-emerald-600' : 'text-rose-500' ?>"><?= !empty($ev['success']) ? 'Success' : 'Failed' ?></p>
                    <p class="mt-0.5 text-[11px] text-slate-400"><?= up_datetime($ev['created_at']) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Reset password -->
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="text-sm font-black tracking-tight">Reset Password</h2>
            <p class="mt-0.5 text-xs font-semibold text-slate-400">Neither option shows you their password — it stays reset-link based, same as the self-service flow.</p>
        </div>
        <div class="p-5">
            <div id="resetAlert" class="mb-3 hidden rounded-xl border p-3 text-xs font-semibold"></div>
            <?php if ($target['status'] !== 'active'): ?>
            <p class="text-sm font-semibold text-amber-600">This account is inactive. Activate it on the Signups page before resetting the password.</p>
            <?php else: ?>
            <div class="flex flex-wrap gap-2">
                <button type="button" onclick="triggerReset(true)" id="btnEmailReset" class="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-800">
                    <i data-lucide="mail" class="h-3.5 w-3.5"></i> Email Reset Link to User
                </button>
                <button type="button" onclick="triggerReset(false)" id="btnRelayReset" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-slate-700 transition hover:bg-slate-50">
                    <i data-lucide="link" class="h-3.5 w-3.5"></i> Get a Link to Relay Myself
                </button>
            </div>
            <div id="resetLinkBox" class="mt-3 hidden rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="mb-1.5 text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">Reset Link (expires in 30 minutes, single use)</p>
                <div class="flex items-center gap-2">
                    <input id="resetLinkInput" type="text" readonly class="w-full truncate rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-mono text-slate-700">
                    <button type="button" onclick="copyResetLink()" class="shrink-0 rounded-lg bg-slate-950 px-3 py-2 text-[10px] font-black uppercase tracking-[0.1em] text-white hover:bg-slate-800">Copy</button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
if (window.lucide) { lucide.createIcons(); }
const TARGET_USER_ID = <?= (int)$target['id'] ?>;

function showResetAlert(msg, ok) {
    const el = document.getElementById('resetAlert');
    el.textContent = msg;
    el.className = 'mb-3 rounded-xl border p-3 text-xs font-semibold ' + (ok ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700');
    el.classList.remove('hidden');
}

async function triggerReset(sendEmail) {
    const label = sendEmail ? 'Email a password reset link to this user?' : 'Generate a password reset link to relay yourself?';
    if (!confirm(label)) return;

    document.getElementById('btnEmailReset').disabled = true;
    document.getElementById('btnRelayReset').disabled = true;
    document.getElementById('resetLinkBox').classList.add('hidden');

    try {
        const res = await fetch('api/admin/reset-user-password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: TARGET_USER_ID, send_email: sendEmail }),
        });
        const data = await res.json();
        if (!data.success) {
            showResetAlert(data.message || 'Could not reset password.', false);
        } else if (sendEmail) {
            showResetAlert(data.mail_status === 'sent' || data.mail_status === 'logged' ? 'Reset email sent.' : 'Reset started, but the email could not be confirmed as delivered — check email_log.', true);
        } else {
            showResetAlert('Reset link generated below — copy and send it to the user yourself.', true);
            document.getElementById('resetLinkInput').value = data.reset_link || '';
            document.getElementById('resetLinkBox').classList.remove('hidden');
        }
    } catch (e) {
        showResetAlert('Network error. Please try again.', false);
    } finally {
        document.getElementById('btnEmailReset').disabled = false;
        document.getElementById('btnRelayReset').disabled = false;
    }
}

function copyResetLink() {
    const input = document.getElementById('resetLinkInput');
    input.select();
    navigator.clipboard?.writeText(input.value).catch(() => {});
}
</script>
</body>
</html>
