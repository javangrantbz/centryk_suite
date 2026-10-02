<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';
require_once __DIR__ . '/../app/services/InsightsService.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: login.php?redirect=' . urlencode(basename(__FILE__) . ($qs !== '' ? '?' . $qs : '')));
    exit;
}

$companies = InsightsService::companiesFor($user);
$companyId = (int)($_GET['company_id'] ?? 0);
if ($companyId <= 0 || !in_array($companyId, array_map('intval', array_column($companies, 'id')), true)) {
    $companyId = $companies ? (int)$companies[0]['id'] : 0;
}
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) { $days = 30; }
$companyName = '';
foreach ($companies as $c) { if ((int)$c['id'] === $companyId) { $companyName = (string)$c['name']; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Insights - Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
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
        /* The page-level light theme rewrites .text-white to dark; these keep real white text. */
        .ins-on { background: #0f172a; color: #fff !important; }
        .ins-white { color: #fff !important; }
        .ins-white50 { color: rgba(255,255,255,.55) !important; }
        .ins-fade { opacity: 0; transform: translateY(8px); animation: ins-in .45s cubic-bezier(.22,1,.36,1) forwards; }
        @keyframes ins-in { to { opacity: 1; transform: none; } }
        .ins-skel { background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%); background-size: 200% 100%; animation: ins-shimmer 1.2s infinite; }
        @keyframes ins-shimmer { to { background-position: -200% 0; } }
        .ins-ghost canvas { opacity: .45; filter: grayscale(1); pointer-events: none; }
    </style>
</head>
<body class="min-h-screen bg-[#0d1117] font-sans antialiased text-white">
<script>var _ct=localStorage.getItem('centrikyTheme');if(_ct!=='dark'){document.body.classList.add('light');}</script>

<?php $pageTitle = 'Insights'; $headerMaxW = 'max-w-7xl'; $awCurrent = 'centryk'; include __DIR__ . '/partials/account_header.php'; ?>

<main class="mx-auto max-w-7xl px-6 pb-10 pt-1">

<?php if (!$companies): ?>
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-8 text-center text-slate-700">
        <p class="text-lg font-black">Insights are for owners, admins and managers</p>
        <p class="mt-1 text-sm font-semibold text-slate-500">Ask an admin of your company to give you the manager role to see the company dashboard.</p>
        <a href="index.php" class="mt-4 inline-flex rounded-xl bg-slate-900 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] ins-white">Back to dashboard</a>
    </div>
<?php else: ?>

    <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Company insights</p>
            <h1 class="mt-0.5 text-2xl font-black tracking-tight text-slate-900" id="insTitle"><?= htmlspecialchars($companyName) ?></h1>
            <p class="mt-0.5 text-xs font-semibold text-slate-500">Only the apps you're enrolled in are shown. Empty charts show what each app can do for you.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if (count($companies) > 1): ?>
                <select id="insCompany" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 outline-none focus:border-slate-400">
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $companyId ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <div id="insDays" class="inline-flex overflow-hidden rounded-xl border border-slate-200 bg-white text-xs font-black">
                <?php foreach ([7 => '7d', 30 => '30d', 90 => '90d', 365 => '12m'] as $d => $lbl): ?>
                    <button type="button" data-days="<?= $d ?>" class="px-3 py-2 transition <?= $d === $days ? 'ins-on' : 'text-slate-500 hover:bg-slate-50' ?>"><?= $lbl ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div id="insNext" class="mt-4 hidden"></div>
    <div id="insKpis" class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5"></div>
    <div id="insGrid" class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3"></div>
    <p id="insError" class="mt-6 hidden rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-600"></p>

<script>
(function () {
    var companyId = <?= (int)$companyId ?>;
    var days = <?= (int)$days ?>;

    var APP = {
        core:        { name: 'Team',        color: '#0ea5e9' },
        mypay:       { name: 'MyPay',       color: '#f97316' },
        onepay:      { name: 'OnePay',      color: '#6366f1' },
        invoice:     { name: 'Invoices',    color: '#10b981' },
        forms:       { name: 'Forms',       color: '#14b8a6' },
        calendar:    { name: 'Calendar',    color: '#3b82f6' },
        visionboard: { name: 'Signage',     color: '#f43f5e' },
        tv:          { name: 'Centryk TV',  color: '#a855f7' }
    };
    var ORDER = ['core', 'mypay', 'onepay', 'invoice', 'forms', 'calendar', 'visionboard', 'tv'];
    var PALETTE = ['#6366f1', '#14b8a6', '#f59e0b', '#f43f5e', '#3b82f6', '#10b981', '#a855f7', '#64748b'];
    var MONEY = /cash|sales|payroll|top$/;
    var charts = [];

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function money(n) { return '$' + Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 }); }
    function toneClass(t) { return t === 'warn' ? 'text-amber-600' : t === 'good' ? 'text-emerald-600' : 'text-slate-400'; }

    function url(params) {
        var u = new URLSearchParams(location.search);
        Object.keys(params).forEach(function (k) { u.set(k, params[k]); });
        return location.pathname + '?' + u.toString();
    }

    // ── charts ────────────────────────────────────────────────────────────────
    function chartConfig(w, ghost) {
        var src = ghost ? w.sample : w;
        var color = (APP[w.app] || APP.core).color;
        var isMoney = MONEY.test(w.id);
        var muted = ['#cbd5e1', '#94a3b8', '#e2e8f0', '#cbd5e1'];
        var datasets = (src.datasets || []).map(function (ds, i) {
            var base = { label: ds.label, data: ds.data };
            if (w.type === 'doughnut') {
                base.backgroundColor = ghost ? muted : PALETTE;
                base.borderColor = '#fff'; base.borderWidth = 2;
            } else if (w.type === 'line') {
                base.borderColor = ghost ? '#94a3b8' : color; base.backgroundColor = ghost ? 'rgba(148,163,184,.15)' : color + '26';
                base.fill = true; base.tension = .35; base.pointRadius = 0; base.pointHoverRadius = 4; base.borderWidth = 2.5;
            } else {
                base.backgroundColor = ghost ? muted[i % muted.length] : (i === 0 ? color : color + '66');
                base.borderRadius = 6; base.maxBarThickness = 30;
            }
            return base;
        });
        var opts = {
            responsive: true, maintainAspectRatio: false, animation: { duration: ghost ? 0 : 600 },
            plugins: {
                legend: { display: !ghost && (w.type === 'doughnut' || datasets.length > 1), position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 11, weight: '600' } } },
                tooltip: { enabled: !ghost, callbacks: isMoney ? { label: function (c) { return ' ' + c.dataset.label + ': ' + money(c.parsed.y != null ? c.parsed.y : c.parsed); } } : {} }
            }
        };
        if (w.type === 'doughnut') { opts.cutout = '62%'; }
        else {
            opts.scales = {
                x: { grid: { display: false }, ticks: { display: !ghost, font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
                y: { beginAtZero: true, grid: { color: '#eef2f7' }, ticks: { display: !ghost, font: { size: 10 }, callback: function (v) { return isMoney ? money(v) : v; } } }
            };
        }
        return { type: w.type, data: { labels: src.labels, datasets: datasets }, options: opts };
    }

    function card(w) {
        var a = APP[w.app] || APP.core;
        var ghost = w.empty || w.offline;
        var el = document.createElement('section');
        el.className = 'ins-fade relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm';
        el.innerHTML =
            '<div class="h-1" style="background:' + a.color + '"></div>' +
            '<div class="flex items-start justify-between gap-2 px-4 pt-3">' +
                '<div class="min-w-0"><h3 class="truncate text-sm font-black text-slate-900">' + esc(w.title) + '</h3>' +
                (w.subtitle ? '<p class="truncate text-[11px] font-semibold text-slate-400">' + esc(w.subtitle) + '</p>' : '') + '</div>' +
                '<span class="shrink-0 rounded-full px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.1em]" style="background:' + a.color + '1a;color:' + a.color + '">' + esc(a.name) + '</span>' +
            '</div>' +
            '<div class="relative px-4 pb-2 pt-2 ' + (ghost ? 'ins-ghost' : '') + '"><div class="h-56"><canvas></canvas></div>' +
            (ghost ? overlay(w, a) : '') + '</div>' +
            '<div class="flex items-center justify-between border-t border-slate-100 px-4 py-2">' +
                '<span class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-300">' + (ghost ? 'Sample preview' : '') + '</span>' +
                '<a href="' + esc(w.open.href) + '" class="text-[11px] font-black text-slate-500 transition hover:text-slate-900">' + esc(w.open.label) + ' &rarr;</a>' +
            '</div>';
        return el;
    }

    function overlay(w, a) {
        if (w.offline) {
            return '<div class="absolute inset-0 flex items-center justify-center p-4">' +
                '<div class="max-w-[16rem] rounded-xl border border-slate-200 bg-white/95 p-3 text-center shadow-lg">' +
                '<p class="text-xs font-black text-slate-800">Couldn\'t reach ' + esc(a.name) + ' right now</p>' +
                '<p class="mt-0.5 text-[11px] font-semibold text-slate-500">Your data is safe. Try again in a moment.</p>' +
                '<button type="button" onclick="location.reload()" class="mt-2 rounded-lg bg-slate-900 px-3 py-1.5 text-[11px] font-black ins-white">Retry</button></div></div>';
        }
        if (w._quiet) {
            return '<div class="absolute inset-0 flex items-center justify-center p-4">' +
                '<p class="rounded-full border border-slate-200 bg-white/95 px-3 py-1.5 text-[11px] font-bold text-slate-500 shadow">Fills in as ' + esc(a.name) + ' gets used</p></div>';
        }
        return '<div class="absolute inset-0 flex items-center justify-center p-4">' +
            '<div class="max-w-[17rem] rounded-xl border border-slate-200 bg-white/95 p-3.5 text-center shadow-lg">' +
            '<p class="text-sm font-black leading-snug text-slate-900">' + esc(w.cta.title) + '</p>' +
            '<p class="mt-1 text-[11px] font-semibold leading-relaxed text-slate-500">' + esc(w.cta.body) + '</p>' +
            '<a href="' + esc(w.cta.href) + '" class="mt-2.5 inline-flex rounded-lg px-3.5 py-1.5 text-[11px] font-black ins-white transition hover:opacity-90" style="background:' + a.color + '">' + esc(w.cta.label) + '</a></div></div>';
    }

    function kpiTile(k) {
        var a = APP[k.app] || APP.core;
        return '<div class="ins-fade rounded-2xl border border-slate-200 bg-white p-3.5 shadow-sm" style="border-left:4px solid ' + a.color + '">' +
            '<p class="text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">' + esc(k.label) + '</p>' +
            '<p class="mt-1 text-2xl font-black tracking-tight text-slate-900">' + esc(k.value) + '</p>' +
            '<p class="mt-0.5 truncate text-[11px] font-bold ' + toneClass(k.tone) + '">' + esc(k.sub || '') + '</p></div>';
    }

    // ── load ──────────────────────────────────────────────────────────────────
    function skeleton() {
        document.getElementById('insKpis').innerHTML = new Array(5).join('<div class="ins-skel h-24 rounded-2xl"></div>');
        document.getElementById('insGrid').innerHTML = new Array(7).join('<div class="ins-skel h-80 rounded-2xl"></div>');
    }

    function load() {
        skeleton();
        document.getElementById('insError').classList.add('hidden');
        fetch('api/insights/summary.php?company_id=' + companyId + '&days=' + days, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { throw new Error(d.message || 'Could not load insights.'); }
                draw(d);
            })
            .catch(function (e) {
                document.getElementById('insKpis').innerHTML = '';
                document.getElementById('insGrid').innerHTML = '';
                var box = document.getElementById('insError');
                box.textContent = e.message; box.classList.remove('hidden');
            });
    }

    function draw(d) {
        charts.forEach(function (c) { c.destroy(); }); charts = [];
        document.getElementById('insKpis').innerHTML = d.kpis.map(kpiTile).join('');

        var widgets = d.widgets.slice().sort(function (a, b) { return ORDER.indexOf(a.app) - ORDER.indexOf(b.app); });
        var grid = document.getElementById('insGrid');
        grid.innerHTML = '';
        var ctaShown = {};
        widgets.forEach(function (w) {
            if (w.empty && !w.offline) { if (ctaShown[w.app]) { w._quiet = true; } else { ctaShown[w.app] = 1; } }
            var el = card(w);
            grid.appendChild(el);
            var ghost = w.empty || w.offline;
            var cfg = chartConfig(w, ghost);
            if (cfg.data.labels && cfg.data.labels.length) {
                charts.push(new Chart(el.querySelector('canvas'), cfg));
            }
        });

        // "Get more from Centryk": the next best actions, taken from the empty charts.
        var todo = widgets.filter(function (w) { return w.empty && !w.offline && w.cta && w.cta.title; });
        var seen = {}, picks = [];
        todo.forEach(function (w) { if (!seen[w.app] && picks.length < 4) { seen[w.app] = 1; picks.push(w); } });
        var next = document.getElementById('insNext');
        if (picks.length >= 2) {
            next.classList.remove('hidden');
            next.innerHTML = '<div class="rounded-2xl border border-slate-200 bg-gradient-to-r from-slate-900 to-slate-800 p-4 ins-white shadow-sm">' +
                '<p class="text-[10px] font-black uppercase tracking-[0.2em] ins-white50">Get more from Centryk</p>' +
                '<div class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">' + picks.map(function (w) {
                    var a = APP[w.app] || APP.core;
                    return '<a href="' + esc(w.cta.href) + '" class="rounded-xl bg-white/10 p-3 transition hover:bg-white/15">' +
                        '<span class="inline-block h-1.5 w-6 rounded-full" style="background:' + a.color + '"></span>' +
                        '<p class="mt-2 text-xs font-black leading-snug ins-white">' + esc(w.cta.title) + '</p>' +
                        '<p class="mt-0.5 text-[11px] font-bold" style="color:' + a.color + '">' + esc(w.cta.label) + ' &rarr;</p></a>';
                }).join('') + '</div></div>';
        } else {
            next.classList.add('hidden');
            next.innerHTML = '';
        }
    }

    // ── controls ──────────────────────────────────────────────────────────────
    var daysWrap = document.getElementById('insDays');
    daysWrap.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-days]');
        if (!b) { return; }
        days = parseInt(b.dataset.days, 10);
        daysWrap.querySelectorAll('button').forEach(function (x) {
            var on = x === b;
            x.classList.toggle('ins-on', on);
            x.classList.toggle('text-slate-500', !on); x.classList.toggle('hover:bg-slate-50', !on);
        });
        history.replaceState(null, '', url({ company_id: companyId, days: days }));
        load();
    });
    var sel = document.getElementById('insCompany');
    if (sel) {
        sel.addEventListener('change', function () { location.href = url({ company_id: sel.value, days: days }); });
    }

    load();
})();
</script>
<?php endif; ?>
</main>
</body>
</html>
