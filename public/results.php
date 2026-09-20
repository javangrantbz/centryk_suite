<?php
/**
 * Public, passcode-protected results page: /results/<token> (see the root .htaccess).
 *
 * Shows a form's totals and charts. Names, phone numbers, emails and other free-text
 * answers are left out, UNLESS the company switched on "show every response" for this
 * share, which needs a code of 6+ digits and then lists each entry (for a raffle). A code
 * set by the company in the form builder unlocks it; wrong guesses are throttled.
 */
require_once __DIR__ . '/../app/core/Env.php';
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/FormsService.php';
require_once __DIR__ . '/../app/services/FormResultsShare.php';

Env::load(__DIR__ . '/../.env');
Auth::start();

// Keep it out of search engines, caches and referrer headers.
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$token = (string)($_GET['t'] ?? '');
$share = FormResultsShare::findByToken($token);

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$unlockHours = 4;

$state = 'ok';          // ok | inactive | pin
$error = '';
if (!$share) {
    $state = 'inactive';
    http_response_code(404);
} else {
    $formId = (int)$share['form_id'];
    $unlocked = isset($_SESSION['results_unlock'][$formId]) && $_SESSION['results_unlock'][$formId] > time();

    if (!$unlocked && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $result = FormResultsShare::verify($share, (string)($_POST['pin'] ?? ''), $ip);
        if ($result === 'ok') {
            $_SESSION['results_unlock'][$formId] = time() + $unlockHours * 3600;
            header('Location: ' . $_SERVER['REQUEST_URI']);   // POST -> GET so a refresh doesn't re-submit
            exit;
        }
        $error = $result === 'locked'
            ? 'Too many wrong codes. Please try again in 15 minutes.'
            : 'That code is not right. Please try again.';
    } elseif (!$unlocked && FormResultsShare::isLocked($formId, $ip)) {
        $error = 'Too many wrong codes. Please try again in 15 minutes.';
    }
    $state = $unlocked ? 'ok' : 'pin';
}

$theme = FormsService::theme($share ? (string)$share['theme'] : 'default');

// Relative URLs (favicon, logo) must resolve from this script's folder even at /results/<token>.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($dir, '/') . '/';

$logo = '';
if ($share) {
    $lp = trim((string)($share['company_logo'] ?? ''));
    if (preg_match('#^uploads/companies/[A-Za-z0-9._-]+\.(png|jpe?g|webp|gif)$#i', $lp) && is_file(__DIR__ . '/' . $lp)) {
        $logo = $lp;
    }
}

// Aggregates only. Free-text and contact questions are dropped, never shown.
$shown = [];
$total = 0;
$hiddenCount = 0;
if ($share && $state === 'ok') {
    $summary = FormsService::summary((int)$share['form_id']);
    $total = (int)$summary['total'];
    foreach ($summary['questions'] as $q) {
        if (in_array($q['type'], ['single_choice', 'multiple_choice', 'dropdown', 'yes_no', 'rating', 'number'], true)) {
            unset($q['samples']);
            $shown[] = $q;
        } else {
            $hiddenCount++;
        }
    }
}

// Individual responses, only when the company chose to share them (with a 6+ digit code).
$showResponses = $share && $state === 'ok' && !empty($share['show_responses']);
$entries = [];
if ($showResponses) {
    $qs = array_values(array_filter(
        FormsService::questions((int)$share['form_id']),
        static fn ($q) => $q['type'] !== 'section'
    ));
    $nameQ = $phoneQ = $emailQ = null;
    foreach ($qs as $q) {
        if ($nameQ === null && $q['type'] === 'short_text') { $nameQ = (int)$q['id']; }
        if ($phoneQ === null && $q['type'] === 'phone') { $phoneQ = (int)$q['id']; }
        if ($emailQ === null && $q['type'] === 'email') { $emailQ = (int)$q['id']; }
    }
    foreach (FormsService::allResponses((int)$share['form_id']) as $i => $r) {
        $a = $r['answers'];
        $entry = [
            'number' => $total - $i,                       // entry number, oldest = 1 (handy for a raffle)
            'time'   => date('j M, g:i a', strtotime($r['submitted_at'])),
            'name'   => trim((string)($a[$nameQ] ?? '')),
            'phone'  => trim((string)($a[$phoneQ] ?? '')),
            'email'  => trim((string)($a[$emailQ] ?? '')),
            'other'  => [],
        ];
        foreach ($qs as $q) {
            $qid = (int)$q['id'];
            if (in_array($qid, [$nameQ, $phoneQ, $emailQ], true)) { continue; }
            $v = trim((string)($a[$qid] ?? ''));
            if ($v !== '') { $entry['other'][] = [$q['label'], $v]; }
        }
        $entries[] = $entry;
    }
}

function rs_bar(int $count, int $answered, string $color): string
{
    $pct = $answered > 0 ? round($count / $answered * 100) : 0;
    return '<div class="h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-2.5 rounded-full" style="width:' . $pct . '%;background:' . $color . '"></div></div>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <base href="<?= htmlspecialchars($base) ?>">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title><?= $share ? htmlspecialchars($share['title']) . ' — results' : 'Results' ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } }</script>
    <?php if ($state === 'ok' && !$showResponses): ?><meta http-equiv="refresh" content="60"><?php endif; ?>
    <style>body { background: <?= $theme['bg'] ?>; min-height: 100vh; }</style>
</head>
<body class="font-sans text-slate-800 antialiased">

<?php if ($share): ?>
<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-2.5">
        <?php if ($logo !== ''): ?>
        <img src="<?= htmlspecialchars($logo) ?>" alt="" class="h-9 w-9 shrink-0 rounded-lg object-cover ring-1 ring-slate-200">
        <?php endif; ?>
        <span class="min-w-0 flex-1 truncate text-sm font-bold text-slate-900"><?= htmlspecialchars($share['company_name']) ?></span>
        <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Results</span>
    </div>
    <?php if ($theme['banner']): ?><div class="h-1 w-full" style="background:<?= $theme['banner'] ?>"></div><?php endif; ?>
</header>
<?php endif; ?>

<main class="mx-auto max-w-2xl px-4 py-6 sm:py-10">

<?php if ($state === 'inactive'): ?>
    <div class="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <h1 class="text-lg font-bold text-slate-900">This link isn't active</h1>
        <p class="mt-2 text-sm text-slate-500">The results may no longer be shared, or the link may be wrong.</p>
    </div>

<?php elseif ($state === 'pin'): ?>
    <div class="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
        <h1 class="text-lg font-extrabold text-slate-900"><?= htmlspecialchars($share['title']) ?></h1>
        <p class="mt-1 text-sm text-slate-500">Enter the code you were given to see the results.</p>
        <form method="post" class="mt-5" autocomplete="off">
            <input type="password" name="pin" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                   placeholder="Code" autofocus
                   class="w-full rounded-xl border border-slate-300 px-4 py-3 text-center text-2xl font-bold tracking-[0.4em] focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <?php if ($error !== ''): ?>
            <p class="mt-3 rounded-xl bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
            <button type="submit" class="mt-4 w-full rounded-xl px-5 py-3 text-sm font-bold text-white" style="background:<?= $theme['accent'] ?>">View results</button>
        </form>
    </div>

<?php else: ?>
    <h1 class="text-xl font-extrabold text-slate-900 sm:text-2xl"><?= $theme['emoji'] !== '' ? htmlspecialchars($theme['emoji']) . ' ' : '' ?><?= htmlspecialchars($share['title']) ?></h1>

    <div class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="text-4xl font-extrabold" style="color:<?= $theme['accent'] ?>"><?= $total ?></div>
        <div class="text-sm font-semibold text-slate-500"><?= $total === 1 ? 'response so far' : 'responses so far' ?></div>
        <div class="mt-1 text-[11px] text-slate-400"><?= $showResponses
            ? 'Last checked ' . htmlspecialchars(date('g:i a')) . ' · <a href="" class="font-semibold underline" style="color:' . $theme['accent'] . '">Refresh</a>'
            : 'Updates every minute · last checked ' . htmlspecialchars(date('g:i a')) ?></div>
    </div>

    <?php if ($total === 0): ?>
    <div class="mt-4 rounded-2xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">No responses yet.</div>
    <?php endif; ?>

    <?php if ($showResponses && $entries): ?>
    <section class="mt-4">
        <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
            <h2 class="text-sm font-bold text-slate-900">Entries</h2>
            <input id="entrySearch" type="search" placeholder="Search name, phone or email" autocomplete="off"
                   class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-base focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <div class="mt-2 flex flex-wrap gap-2">
                <button type="button" id="copyNames" class="rounded-xl px-3 py-2 text-xs font-bold text-white" style="background:<?= $theme['accent'] ?>">Copy names &amp; phones</button>
                <button type="button" id="copyEmails" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-bold text-slate-600">Copy emails</button>
                <span id="entryCount" class="ml-auto self-center text-[11px] text-slate-400"><?= count($entries) ?> shown</span>
            </div>
        </div>

        <div id="entryList" class="mt-3 space-y-3">
            <?php foreach ($entries as $e): ?>
            <article class="entry rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200"
                     data-name="<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>"
                     data-phone="<?= htmlspecialchars($e['phone'], ENT_QUOTES) ?>"
                     data-email="<?= htmlspecialchars($e['email'], ENT_QUOTES) ?>"
                     data-search="<?= htmlspecialchars(mb_strtolower($e['name'] . ' ' . $e['phone'] . ' ' . preg_replace('/\D+/', '', $e['phone']) . ' ' . $e['email'] . ' #' . $e['number']), ENT_QUOTES) ?>">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-base font-extrabold text-slate-900"><?= $e['name'] !== '' ? htmlspecialchars($e['name']) : 'No name given' ?></div>
                        <div class="text-[11px] text-slate-400">Entry #<?= (int)$e['number'] ?> · <?= htmlspecialchars($e['time']) ?></div>
                    </div>
                </div>
                <?php if ($e['phone'] !== ''): ?>
                <div class="mt-2 flex items-center justify-between gap-2">
                    <a href="tel:<?= htmlspecialchars(preg_replace('/[^\d+]/', '', $e['phone'])) ?>" class="text-lg font-bold tabular-nums" style="color:<?= $theme['accent'] ?>"><?= htmlspecialchars($e['phone']) ?></a>
                    <button type="button" data-copy="<?= htmlspecialchars($e['phone'], ENT_QUOTES) ?>" class="shrink-0 rounded-lg border border-slate-300 px-2.5 py-1 text-[11px] font-bold text-slate-600">Copy</button>
                </div>
                <?php endif; ?>
                <?php if ($e['email'] !== ''): ?>
                <div class="mt-1.5 flex items-center justify-between gap-2">
                    <a href="mailto:<?= htmlspecialchars($e['email']) ?>" class="min-w-0 truncate text-sm font-semibold text-slate-700 underline"><?= htmlspecialchars($e['email']) ?></a>
                    <button type="button" data-copy="<?= htmlspecialchars($e['email'], ENT_QUOTES) ?>" class="shrink-0 rounded-lg border border-slate-300 px-2.5 py-1 text-[11px] font-bold text-slate-600">Copy</button>
                </div>
                <?php endif; ?>
                <?php if ($e['other']): ?>
                <dl class="mt-3 space-y-1 border-t border-slate-100 pt-2.5">
                    <?php foreach ($e['other'] as [$label, $value]): ?>
                    <div class="text-xs"><dt class="inline text-slate-400"><?= htmlspecialchars($label) ?>:</dt> <dd class="inline font-semibold text-slate-700"><?= htmlspecialchars($value) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
            <p id="noMatch" class="hidden rounded-2xl bg-white p-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">No entries match your search.</p>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($showResponses && $shown): ?>
    <details class="mt-4"><summary class="cursor-pointer rounded-2xl bg-white px-5 py-3 text-sm font-bold text-slate-700 shadow-sm ring-1 ring-slate-200">Summary charts</summary>
    <?php endif; ?>

    <?php foreach ($shown as $q): ?>
    <section class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-bold text-slate-900"><?= htmlspecialchars($q['label']) ?></h2>
        <p class="mt-0.5 text-[11px] text-slate-400">
            <?= (int)$q['answered'] ?> answered<?php if (isset($q['average']) && $q['average'] !== null): ?> · average <?= htmlspecialchars((string)$q['average']) ?><?php endif; ?>
            <?php if (isset($q['min']) && $q['min'] !== null): ?> · range <?= htmlspecialchars((string)$q['min']) ?> to <?= htmlspecialchars((string)$q['max']) ?><?php endif; ?>
        </p>
        <?php if (!empty($q['breakdown'])): ?>
        <div class="mt-3 space-y-2.5">
            <?php foreach ($q['breakdown'] as $label => $count): ?>
            <div>
                <div class="flex items-baseline justify-between gap-3 text-sm">
                    <span class="min-w-0 truncate"><?= htmlspecialchars((string)$label) ?></span>
                    <span class="shrink-0 font-bold tabular-nums"><?= (int)$count ?>
                        <span class="text-xs font-semibold text-slate-400"><?= $q['answered'] > 0 ? round($count / $q['answered'] * 100) : 0 ?>%</span></span>
                </div>
                <?= rs_bar((int)$count, (int)$q['answered'], $theme['accent']) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>

    <?php if ($showResponses && $shown): ?></details><?php endif; ?>

    <?php if ($hiddenCount > 0 && !$showResponses): ?>
    <p class="mt-4 text-center text-[11px] text-slate-400">Names, contact details and written answers are not shown on this page.</p>
    <?php endif; ?>
<?php endif; ?>

</main>

<?php if ($showResponses && $entries): ?>
<div id="copyToast" class="pointer-events-none fixed bottom-5 left-1/2 hidden -translate-x-1/2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-lg"></div>
<script>
(function () {
    const list = document.getElementById('entryList');
    const search = document.getElementById('entrySearch');
    const toast = document.getElementById('copyToast');
    let toastTimer = null;

    function say(msg) {
        toast.textContent = msg;
        toast.classList.remove('hidden');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.add('hidden'), 2200);
    }
    function legacyCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', '');
        ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        return ok;
    }
    function copy(text, doneMsg) {
        const done = () => say(doneMsg);
        const fail = () => say('Could not copy. Press and hold the text to copy it.');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, () => legacyCopy(text) ? done() : fail());
        } else {
            legacyCopy(text) ? done() : fail();
        }
    }
    const visible = () => [...list.querySelectorAll('.entry')].filter(e => !e.classList.contains('hidden'));

    search.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        const qDigits = q.replace(/[\s()-]+/g, '');      // "600 2423" and "600-2423" both find 600-2423
        let n = 0;
        list.querySelectorAll('.entry').forEach(e => {
            const match = !q || e.dataset.search.includes(q) || (qDigits.length >= 3 && e.dataset.search.includes(qDigits));
            e.classList.toggle('hidden', !match);
            if (match) n++;
        });
        document.getElementById('entryCount').textContent = n + ' shown';
        document.getElementById('noMatch').classList.toggle('hidden', n !== 0);
    });

    list.addEventListener('click', (ev) => {
        const b = ev.target.closest('[data-copy]');
        if (b) copy(b.dataset.copy, 'Copied ' + b.dataset.copy);
    });

    document.getElementById('copyNames').addEventListener('click', () => {
        const lines = visible().map(e => [e.dataset.name, e.dataset.phone].filter(Boolean).join(' - ')).filter(Boolean);
        lines.length ? copy(lines.join('\n'), 'Copied ' + lines.length + ' names & phones') : say('Nothing to copy.');
    });
    document.getElementById('copyEmails').addEventListener('click', () => {
        const lines = visible().map(e => e.dataset.email).filter(Boolean);
        lines.length ? copy(lines.join('\n'), 'Copied ' + lines.length + ' emails') : say('No emails to copy.');
    });
})();
</script>
<?php endif; ?>
</body>
</html>
