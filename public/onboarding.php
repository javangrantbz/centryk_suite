<?php
/**
 * First-login company setup wizard.
 * Shown to a company admin whose company hasn't been onboarded yet (see the
 * redirect in index.php). Two steps: nature of business → apps. Posts to
 * api/onboarding/complete.php, then returns to the dashboard.
 */
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/core/DB.php';

Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}

// The company this admin still needs to set up (oldest un-onboarded first).
$pdo  = DB::pdo();
$stmt = $pdo->prepare('
    SELECT c.id, c.name
    FROM companies c
    JOIN company_members cm ON cm.company_id = c.id
    WHERE cm.user_id = :uid AND cm.role = "admin" AND cm.status = "active"
      AND c.onboarded_at IS NULL
    ORDER BY c.created_at ASC
    LIMIT 1');
$stmt->execute(['uid' => (int)$user['id']]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    header('Location: index.php');
    exit;
}

$firstName = trim((string)($user['first_name'] ?? '')) ?: 'there';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <title>Set up <?= htmlspecialchars($company['name']) ?> — Centryk</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } };
  </script>
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900">
  <div class="mx-auto flex min-h-screen max-w-2xl flex-col px-4 py-8">

    <!-- Header -->
    <div class="mb-6">
      <div class="flex items-center gap-2 text-sm font-bold text-violet-600">
        <svg viewBox="0 0 32 32" class="h-7 w-7"><circle cx="16" cy="16" r="16" fill="#7c3aed"/><path d="M16 8l2.5 7.5H26l-6 4.5 2.5 7.5-6-4.5-6 4.5 2.5-7.5-6-4.5h7.5L16 8z" fill="white"/></svg>
        Centryk
      </div>
      <h1 class="mt-4 text-2xl font-black tracking-tight">Welcome, <?= htmlspecialchars($firstName) ?> 👋</h1>
      <p class="mt-1 text-slate-500">Let's set up <span class="font-bold text-slate-700"><?= htmlspecialchars($company['name']) ?></span> — takes about 30 seconds.</p>
    </div>

    <!-- Progress -->
    <div class="mb-6 flex items-center gap-2">
      <div id="dot1" class="h-1.5 flex-1 rounded-full bg-violet-600"></div>
      <div id="dot2" class="h-1.5 flex-1 rounded-full bg-slate-200"></div>
    </div>

    <div class="flex-1 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">

      <!-- ── Step 1: nature of business ─────────────────────────────────────── -->
      <section id="step1">
        <h2 class="text-lg font-black">What kind of business is this?</h2>
        <p class="mb-4 text-sm text-slate-500">This tailors your apps — like what to call the people you serve.</p>

        <div id="typeGrid" class="grid grid-cols-2 gap-2 sm:grid-cols-3"></div>

        <div id="nounWrap" class="mt-5 hidden">
          <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500">What do you call the people you serve?</label>
          <div class="mt-1 flex items-center gap-2">
            <input id="nounS" type="text" placeholder="Customer"
                   class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none">
            <span class="text-slate-400">/</span>
            <input id="nounP" type="text" placeholder="Customers"
                   class="w-40 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none">
          </div>
          <p class="mt-1 text-xs text-slate-400">Singular / plural. You can change this later.</p>
        </div>

        <div class="mt-6 flex items-center justify-between">
          <button onclick="skip()" class="text-sm font-semibold text-slate-400 hover:text-slate-600">Skip for now</button>
          <button id="toStep2" onclick="goStep2()" disabled
                  class="rounded-lg bg-violet-600 px-5 py-2 text-sm font-bold text-white shadow-sm hover:bg-violet-700 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-400">
            Continue
          </button>
        </div>
      </section>

      <!-- ── Step 2: apps ───────────────────────────────────────────────────── -->
      <section id="step2" class="hidden">
        <h2 class="text-lg font-black">Which apps will you use?</h2>
        <p class="mb-4 text-sm text-slate-500">Turn on what you need — you can add more anytime.</p>

        <div id="appList" class="space-y-2"></div>

        <div id="obError" class="mt-4 hidden rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700"></div>

        <div class="mt-6 flex items-center justify-between">
          <button onclick="backStep1()" class="text-sm font-semibold text-slate-400 hover:text-slate-600">&larr; Back</button>
          <button id="finishBtn" onclick="finish()"
                  class="rounded-lg bg-violet-600 px-5 py-2 text-sm font-bold text-white shadow-sm hover:bg-violet-700">
            Finish setup
          </button>
        </div>
      </section>

    </div>
  </div>

  <script>
    const COMPANY_ID = <?= (int)$company['id'] ?>;

    // Business type → default customer noun (mirrors api/onboarding/complete.php).
    const TYPES = [
      { key: 'school',     emoji: '🎓', label: 'School / Education', noun: ['Student', 'Students'] },
      { key: 'gym',        emoji: '🏋️', label: 'Gym / Fitness',     noun: ['Member', 'Members'] },
      { key: 'clinic',     emoji: '🩺', label: 'Clinic / Health',   noun: ['Patient', 'Patients'] },
      { key: 'salon',      emoji: '💈', label: 'Salon / Spa',       noun: ['Client', 'Clients'] },
      { key: 'retail',     emoji: '🛍️', label: 'Retail / Shop',     noun: ['Customer', 'Customers'] },
      { key: 'restaurant', emoji: '🍽️', label: 'Restaurant / Food', noun: ['Customer', 'Customers'] },
      { key: 'services',   emoji: '🧰', label: 'Services',          noun: ['Client', 'Clients'] },
      { key: 'property',   emoji: '🏠', label: 'Property / Rentals',noun: ['Tenant', 'Tenants'] },
      { key: 'other',      emoji: '🏢', label: 'Something else',    noun: ['Customer', 'Customers'] },
    ];

    const APPS = [
      { key: 'onepay',   emoji: '🛒', label: 'OnePay',   desc: 'Inventory, POS & sales',   on: true,  locked: false },
      { key: 'invoice',  emoji: '🧾', label: 'Invoices', desc: 'Bill customers & track pay',on: true,  locked: false },
      { key: 'mypay',    emoji: '👥', label: 'MyPay',    desc: 'HR & payroll',             on: false, locked: false },
      { key: 'calendar', emoji: '📅', label: 'Calendar', desc: 'Included for everyone',    on: true,  locked: true },
    ];

    let chosenType = null;

    // ── Step 1 rendering ──────────────────────────────────────────────────────
    const grid = document.getElementById('typeGrid');
    grid.innerHTML = TYPES.map(t => `
      <button type="button" data-key="${t.key}" onclick="pickType('${t.key}')"
        class="type-card flex flex-col items-center gap-1 rounded-xl border border-slate-200 px-3 py-3 text-center hover:border-violet-300 hover:bg-violet-50/40 transition-colors">
        <span class="text-2xl">${t.emoji}</span>
        <span class="text-xs font-bold text-slate-700">${t.label}</span>
      </button>`).join('');

    function pickType(key){
      chosenType = key;
      const t = TYPES.find(x => x.key === key);
      document.querySelectorAll('.type-card').forEach(c => {
        const on = c.dataset.key === key;
        c.classList.toggle('border-violet-500', on);
        c.classList.toggle('bg-violet-50', on);
        c.classList.toggle('ring-1', on);
        c.classList.toggle('ring-violet-400', on);
      });
      // Pre-fill the noun (only if the user hasn't typed their own).
      const ns = document.getElementById('nounS'), np = document.getElementById('nounP');
      if (!ns.dataset.touched) ns.value = t.noun[0];
      if (!np.dataset.touched) np.value = t.noun[1];
      document.getElementById('nounWrap').classList.remove('hidden');
      document.getElementById('toStep2').disabled = false;
    }
    document.getElementById('nounS').addEventListener('input', e => e.target.dataset.touched = '1');
    document.getElementById('nounP').addEventListener('input', e => e.target.dataset.touched = '1');

    // ── Step 2 rendering ──────────────────────────────────────────────────────
    document.getElementById('appList').innerHTML = APPS.map(a => `
      <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 ${a.locked ? 'opacity-70' : 'cursor-pointer hover:border-violet-300'}">
        <span class="text-2xl">${a.emoji}</span>
        <span class="flex-1">
          <span class="block text-sm font-bold text-slate-800">${a.label}</span>
          <span class="block text-xs text-slate-500">${a.desc}</span>
        </span>
        <input type="checkbox" class="app-check h-4 w-4" value="${a.key}" ${a.on ? 'checked' : ''} ${a.locked ? 'disabled' : ''}>
      </label>`).join('');

    // ── Navigation ────────────────────────────────────────────────────────────
    function goStep2(){
      document.getElementById('step1').classList.add('hidden');
      document.getElementById('step2').classList.remove('hidden');
      document.getElementById('dot2').classList.replace('bg-slate-200', 'bg-violet-600');
    }
    function backStep1(){
      document.getElementById('step2').classList.add('hidden');
      document.getElementById('step1').classList.remove('hidden');
      document.getElementById('dot2').classList.replace('bg-violet-600', 'bg-slate-200');
    }

    async function post(payload){
      const r = await fetch('api/onboarding/complete.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
      });
      return r.json();
    }

    async function finish(){
      const btn = document.getElementById('finishBtn');
      btn.disabled = true; btn.textContent = 'Setting up…';
      const apps = [...document.querySelectorAll('.app-check:checked')].map(c => c.value);
      const res = await post({
        company_id: COMPANY_ID,
        business_type: chosenType,
        customer_noun_singular: document.getElementById('nounS').value.trim(),
        customer_noun_plural: document.getElementById('nounP').value.trim(),
        apps,
      });
      if (!res.success){
        btn.disabled = false; btn.textContent = 'Finish setup';
        const e = document.getElementById('obError');
        e.textContent = res.message || 'Something went wrong.'; e.classList.remove('hidden');
        return;
      }
      window.location.href = 'index.php';
    }

    async function skip(){
      await post({ company_id: COMPANY_ID, skip: true });
      window.location.href = 'index.php';
    }
  </script>
</body>
</html>
