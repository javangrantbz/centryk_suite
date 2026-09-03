<?php
/**
 * Belize public & bank holidays — platform-admin editor.
 *
 * The national holiday list (Holidays Act, Ch. 289) with the Labour Act pay
 * multiplier for hours worked on each day. Drives calendar markers in Centryk
 * and the holiday panels in MyPay (which pulls api/calendar/holidays.php).
 *
 * Read/write: PublicHolidays service; api/holidays/save.php + delete.php.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/services/PublicHolidays.php';

Auth::start();
$me = AuthService::me();
if (!$me['authenticated'] || empty($me['user']['is_admin'])) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}
$user = $me['user'];

$years = PublicHolidays::years();
$thisYear = (int)date('Y');
foreach ([$thisYear, $thisYear + 1] as $y) {
    if (!in_array($y, $years, true)) { $years[] = $y; }
}
sort($years);

$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $thisYear;
if (!in_array($selectedYear, $years, true)) { $selectedYear = $thisYear; }

$holidays = PublicHolidays::year($selectedYear);

function h_esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pageTitle = 'Public Holidays';
$headerMaxW = 'max-w-5xl';
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
    <title>Public Holidays - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>[data-lucide] { display: inline-block; }</style>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased text-slate-900">
<?php include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-5xl px-4 pt-1 pb-6">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-rose-500">Belize</p>
            <h1 class="mt-1 text-2xl font-black tracking-tight">Public &amp; Bank Holidays</h1>
            <p class="mt-1 text-sm font-semibold text-slate-500">
                National list (Holidays Act, Ch. 289). <b class="text-slate-600">Pay rate</b> is the multiplier for
                hours <em>worked</em> on that day &mdash; 2&times; for Good Friday, Easter Monday, Christmas Day;
                1.5&times; for the rest (Labour Act). MyPay reads this list.
            </p>
        </div>
        <form method="get" class="flex items-center gap-2">
            <select name="year" onchange="this.form.submit()" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-700 shadow-sm outline-none focus:border-rose-400">
                <?php foreach ($years as $y): ?>
                <option value="<?= $y ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div id="alert" class="mb-4 hidden rounded-xl border px-4 py-3 text-sm font-bold"></div>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-[720px] w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Pay rate</th>
                        <th class="px-4 py-3">Note</th>
                        <th class="px-4 py-3">Active</th>
                        <th class="px-4 py-3 text-right">&nbsp;</th>
                    </tr>
                </thead>
                <tbody id="holidayRows" class="divide-y divide-slate-100">
                    <?php foreach ($holidays as $hh): ?>
                    <tr class="hol-row" data-id="<?= (int)$hh['id'] ?>">
                        <td class="px-4 py-2.5"><input type="date" data-f="holiday_date" value="<?= h_esc($hh['holiday_date']) ?>" class="rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold outline-none focus:border-rose-400"></td>
                        <td class="px-4 py-2.5"><input type="text" data-f="name" value="<?= h_esc($hh['name']) ?>" maxlength="120" class="w-52 rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold outline-none focus:border-rose-400"></td>
                        <td class="px-4 py-2.5">
                            <select data-f="category" class="rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold outline-none focus:border-rose-400">
                                <?php foreach (['both' => 'Public & bank', 'public' => 'Public', 'bank' => 'Bank'] as $v => $lbl): ?>
                                <option value="<?= $v ?>" <?= $hh['category'] === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="px-4 py-2.5">
                            <select data-f="pay_rate" class="rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold outline-none focus:border-rose-400">
                                <option value="1.50" <?= (float)$hh['pay_rate'] === 1.5 ? 'selected' : '' ?>>1.5&times;</option>
                                <option value="2.00" <?= (float)$hh['pay_rate'] === 2.0 ? 'selected' : '' ?>>2&times;</option>
                                <option value="1.00" <?= (float)$hh['pay_rate'] === 1.0 ? 'selected' : '' ?>>1&times;</option>
                            </select>
                        </td>
                        <td class="px-4 py-2.5"><input type="text" data-f="observed_note" value="<?= h_esc($hh['observed_note']) ?>" maxlength="120" class="w-44 rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold outline-none focus:border-rose-400"></td>
                        <td class="px-4 py-2.5"><input type="checkbox" data-f="active" <?= (int)$hh['active'] === 1 ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500"></td>
                        <td class="px-4 py-2.5 text-right">
                            <button type="button" class="hol-save rounded-lg bg-slate-950 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.1em] text-white transition hover:bg-slate-800">Save</button>
                            <button type="button" class="hol-del rounded-lg border border-rose-200 bg-rose-50 px-2 py-1.5 text-[10px] font-black uppercase tracking-[0.1em] text-rose-700 transition hover:bg-rose-100">Del</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$holidays): ?>
                    <tr><td colspan="7" class="px-4 py-8 text-center text-sm font-semibold text-slate-400">No holidays for <?= $selectedYear ?> yet — add them below.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr id="holidayAddRow" class="border-t border-slate-200 bg-slate-50">
                        <td class="px-4 py-3"><input type="date" data-f="holiday_date" value="<?= $selectedYear ?>-01-01" class="rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold outline-none focus:border-rose-400"></td>
                        <td class="px-4 py-3"><input type="text" data-f="name" placeholder="Holiday name" maxlength="120" class="w-52 rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold outline-none focus:border-rose-400"></td>
                        <td class="px-4 py-3">
                            <select data-f="category" class="rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold outline-none focus:border-rose-400">
                                <option value="both">Public &amp; bank</option><option value="public">Public</option><option value="bank">Bank</option>
                            </select>
                        </td>
                        <td class="px-4 py-3">
                            <select data-f="pay_rate" class="rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold outline-none focus:border-rose-400">
                                <option value="1.50">1.5&times;</option><option value="2.00">2&times;</option><option value="1.00">1&times;</option>
                            </select>
                        </td>
                        <td class="px-4 py-3"><input type="text" data-f="observed_note" placeholder="e.g. Moved from Sun" maxlength="120" class="w-44 rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-semibold outline-none focus:border-rose-400"></td>
                        <td class="px-4 py-3"><input type="checkbox" data-f="active" checked class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500"></td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" id="holidayAddBtn" class="rounded-lg bg-rose-600 px-3 py-1.5 text-[10px] font-black uppercase tracking-[0.1em] text-white transition hover:bg-rose-500">Add</button>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</main>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
if (window.lucide) { lucide.createIcons(); }
(function () {
    var alertEl = document.getElementById('alert');
    function showAlert(msg, ok) {
        alertEl.textContent = msg;
        alertEl.className = 'mb-4 rounded-xl border px-4 py-3 text-sm font-bold '
            + (ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800');
        alertEl.classList.remove('hidden');
        setTimeout(function () { alertEl.classList.add('hidden'); }, 3500);
    }

    function readRow(row) {
        var out = {};
        row.querySelectorAll('[data-f]').forEach(function (el) {
            out[el.dataset.f] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value.trim();
        });
        return out;
    }

    async function post(path, body) {
        var res = await fetch('api/holidays/' + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        var data = await res.json().catch(function () { return {}; });
        if (!res.ok || !data.success) { throw new Error(data.message || 'Something went wrong.'); }
        return data;
    }

    document.getElementById('holidayRows').addEventListener('click', async function (e) {
        var row = e.target.closest('.hol-row');
        if (!row) { return; }
        if (e.target.classList.contains('hol-save')) {
            var body = readRow(row);
            body.id = Number(row.dataset.id);
            try { await post('save.php', body); showAlert('Saved.', true); }
            catch (err) { showAlert(err.message, false); }
        } else if (e.target.classList.contains('hol-del')) {
            if (!confirm('Delete this holiday?')) { return; }
            try {
                await post('delete.php', { id: Number(row.dataset.id) });
                row.remove();
                showAlert('Deleted.', true);
            } catch (err) { showAlert(err.message, false); }
        }
    });

    document.getElementById('holidayAddBtn').addEventListener('click', async function () {
        var body = readRow(document.getElementById('holidayAddRow'));
        if (!body.name) { showAlert('Enter a name first.', false); return; }
        try {
            await post('save.php', body);
            location.reload();
        } catch (err) { showAlert(err.message, false); }
    });
})();
</script>
</body>
</html>
