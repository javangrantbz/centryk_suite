<?php
/**
 * Public form fill page. /f.php?t=<share_token>  (also /f/<token> via .htaccess)
 *
 * Renders an open form for anyone with the link. If the form requires login,
 * an unauthenticated visitor is asked to sign in. The builder can preview a
 * draft/closed form with ?preview=1 (must be an admin/manager of the company).
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/FormsService.php';

$token = trim((string)($_GET['t'] ?? ''));
$form = $token !== '' ? FormsService::getFormByToken($token) : null;

Auth::start();
$viewer = Auth::user();

$isPreview = false;
if ($form && !empty($_GET['preview']) && $viewer) {
    $m = DB::pdo()->prepare("
        SELECT 1 FROM company_members
        WHERE user_id = :uid AND company_id = :cid AND status = 'active' AND role IN ('admin','manager') LIMIT 1
    ");
    $m->execute(['uid' => (int)$viewer['id'], 'cid' => (int)$form['company_id']]);
    $isPreview = (bool)$m->fetchColumn();
}

$state = 'ok';          // ok | notfound | closed | draft | login
if (!$form) {
    $state = 'notfound';
} elseif ($form['status'] !== 'open' && !$isPreview) {
    $state = $form['status'] === 'closed' ? 'closed' : 'draft';
} elseif ($form['access'] === 'login_required' && !$viewer) {
    $state = 'login';
}

$questions = $state === 'ok' ? FormsService::questions((int)$form['id']) : [];

$loginUrl = 'login.php?redirect=' . rawurlencode('f.php?t=' . $token);
$pageTitle = $form ? $form['title'] : 'Form';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
        colors: { brand: { 600: '#4f46e5', 700: '#4338ca' } } } } }</script>
    <style>
        body { background: #f1f5f9; }
        .q-choice:has(input:checked) { border-color: #4f46e5; background: #eef2ff; }
        input:focus, textarea:focus, select:focus { outline: none; box-shadow: 0 0 0 2px #c7d2fe; border-color: #4f46e5; }
    </style>
</head>
<body class="font-sans text-slate-800 antialiased">
<div class="mx-auto max-w-xl px-4 py-8 sm:py-14">

<?php if ($state === 'notfound'): ?>
    <div class="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <h1 class="text-lg font-bold text-slate-900">Form not found</h1>
        <p class="mt-2 text-sm text-slate-500">This link may be wrong or the form may have been removed.</p>
    </div>

<?php elseif ($state === 'closed'): ?>
    <div class="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <h1 class="text-lg font-bold text-slate-900"><?= htmlspecialchars($form['title']) ?></h1>
        <p class="mt-2 text-sm text-slate-500">This form is closed and no longer accepting responses.</p>
    </div>

<?php elseif ($state === 'draft'): ?>
    <div class="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <h1 class="text-lg font-bold text-slate-900"><?= htmlspecialchars($form['title']) ?></h1>
        <p class="mt-2 text-sm text-slate-500">This form isn't open yet. Check back soon.</p>
    </div>

<?php elseif ($state === 'login'): ?>
    <div class="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <h1 class="text-lg font-bold text-slate-900"><?= htmlspecialchars($form['title']) ?></h1>
        <p class="mt-2 text-sm text-slate-500">You need to be signed in to Centryk to respond to this form.</p>
        <a href="<?= htmlspecialchars($loginUrl) ?>" class="mt-4 inline-block rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-brand-700">Sign in</a>
    </div>

<?php else: ?>
    <?php if ($isPreview): ?>
    <div class="mb-4 rounded-xl bg-amber-100 px-4 py-2.5 text-xs font-bold text-amber-800">
        Preview — this <?= htmlspecialchars($form['status']) ?> form is not collecting responses. Submitting is disabled.
    </div>
    <?php endif; ?>

    <div id="formCard" class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
        <div class="border-b border-slate-100 pb-4">
            <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-brand-600"><?= htmlspecialchars($form['company_name']) ?></p>
            <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl"><?= htmlspecialchars($form['title']) ?></h1>
            <?php if (!empty($form['description'])): ?>
            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600"><?= htmlspecialchars($form['description']) ?></p>
            <?php endif; ?>
        </div>

        <form id="fillForm" class="mt-5 space-y-6">
            <?php foreach ($questions as $q): ?>
                <?php if ($q['type'] === 'section'): ?>
                <div class="pt-2">
                    <h2 class="text-base font-bold text-slate-900"><?= htmlspecialchars($q['label']) ?></h2>
                    <?php if ($q['help_text']): ?><p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($q['help_text']) ?></p><?php endif; ?>
                </div>
                <?php continue; endif; ?>

                <div class="q" data-qid="<?= (int)$q['id'] ?>" data-type="<?= htmlspecialchars($q['type']) ?>" data-required="<?= $q['required'] ? '1' : '0' ?>">
                    <label class="block text-sm font-bold text-slate-800">
                        <?= htmlspecialchars($q['label']) ?><?php if ($q['required']): ?> <span class="text-rose-500">*</span><?php endif; ?>
                    </label>
                    <?php if ($q['help_text']): ?><p class="mt-0.5 text-xs text-slate-500"><?= htmlspecialchars($q['help_text']) ?></p><?php endif; ?>
                    <div class="mt-2">
                        <?php
                        $name = 'q' . (int)$q['id'];
                        switch ($q['type']):
                            case 'long_text': ?>
                                <textarea name="<?= $name ?>" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
                            <?php break; case 'number': ?>
                                <input type="number" step="any" name="<?= $name ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <?php break; case 'date': ?>
                                <input type="date" name="<?= $name ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <?php break; case 'dropdown': ?>
                                <select name="<?= $name ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                                    <option value="">Choose…</option>
                                    <?php foreach ($q['options'] as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php break; case 'yes_no': ?>
                                <div class="flex gap-2">
                                    <?php foreach (['Yes', 'No'] as $opt): ?>
                                    <label class="q-choice flex-1 cursor-pointer rounded-xl border border-slate-300 px-4 py-2.5 text-center text-sm font-semibold">
                                        <input type="radio" name="<?= $name ?>" value="<?= $opt ?>" class="hidden"><?= $opt ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php break; case 'rating': $max = (int)($q['config']['max'] ?? 5); ?>
                                <div class="flex flex-wrap gap-2">
                                    <?php for ($i = 1; $i <= $max; $i++): ?>
                                    <label class="q-choice flex h-10 w-10 cursor-pointer items-center justify-center rounded-xl border border-slate-300 text-sm font-bold">
                                        <input type="radio" name="<?= $name ?>" value="<?= $i ?>" class="hidden"><?= $i ?>
                                    </label>
                                    <?php endfor; ?>
                                </div>
                            <?php break; case 'single_choice': ?>
                                <div class="space-y-2">
                                    <?php foreach ($q['options'] as $opt): ?>
                                    <label class="q-choice flex cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                                        <input type="radio" name="<?= $name ?>" value="<?= htmlspecialchars($opt) ?>" class="h-4 w-4"><?= htmlspecialchars($opt) ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php break; case 'multiple_choice': ?>
                                <div class="space-y-2">
                                    <?php foreach ($q['options'] as $opt): ?>
                                    <label class="q-choice flex cursor-pointer items-center gap-3 rounded-xl border border-slate-300 px-4 py-2.5 text-sm">
                                        <input type="checkbox" name="<?= $name ?>[]" value="<?= htmlspecialchars($opt) ?>" class="h-4 w-4"><?= htmlspecialchars($opt) ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php break; default: ?>
                                <input type="text" name="<?= $name ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <?php endswitch; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div id="formErr" class="hidden rounded-xl bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700"></div>

            <button type="submit" id="submitBtn" class="w-full rounded-xl bg-brand-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-brand-700 disabled:opacity-50" <?= $isPreview ? 'disabled' : '' ?>>
                Submit
            </button>
        </form>
    </div>

    <div id="doneCard" class="hidden rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
        </div>
        <p id="doneMsg" class="mt-4 text-base font-semibold text-slate-800"></p>
    </div>

    <p class="mt-6 text-center text-[11px] text-slate-400">Powered by Centryk Forms</p>

    <script>
    const TOKEN = <?= json_encode($token) ?>;
    const form = document.getElementById('fillForm');
    const errBox = document.getElementById('formErr');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errBox.classList.add('hidden');

        const answers = {};
        let firstMissing = null;
        document.querySelectorAll('.q').forEach(q => {
            const qid = q.dataset.qid;
            const type = q.dataset.type;
            const required = q.dataset.required === '1';
            let val;
            if (type === 'multiple_choice') {
                val = [...q.querySelectorAll('input:checked')].map(i => i.value);
                if (required && !val.length && !firstMissing) firstMissing = q;
                if (val.length) answers[qid] = val;
            } else if (type === 'single_choice' || type === 'yes_no' || type === 'rating') {
                const c = q.querySelector('input:checked');
                val = c ? c.value : '';
                if (required && !val && !firstMissing) firstMissing = q;
                if (val) answers[qid] = val;
            } else {
                const el = q.querySelector('input, textarea, select');
                val = el ? el.value.trim() : '';
                if (required && !val && !firstMissing) firstMissing = q;
                if (val) answers[qid] = val;
            }
        });

        if (firstMissing) {
            errBox.textContent = 'Please answer all required questions.';
            errBox.classList.remove('hidden');
            firstMissing.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        const btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.textContent = 'Submitting…';
        try {
            const res = await fetch('api/forms/submit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: TOKEN, answers }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) throw new Error(data.message || 'Could not submit your response.');
            document.getElementById('formCard').classList.add('hidden');
            document.getElementById('doneMsg').textContent = data.confirmation_message;
            document.getElementById('doneCard').classList.remove('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (err) {
            errBox.textContent = err.message;
            errBox.classList.remove('hidden');
            btn.disabled = false;
            btn.textContent = 'Submit';
        }
    });
    </script>
<?php endif; ?>

</div>
</body>
</html>
