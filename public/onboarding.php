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
  <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900">

  <!-- Fixed header + progress — stays pinned while the card below scrolls -->
  <div class="fixed inset-x-0 top-0 z-20 border-b border-slate-200 bg-slate-50/95 backdrop-blur-sm">
    <div class="mx-auto max-w-2xl px-4 pb-4 pt-8">
      <div class="mb-6">
        <img src="assets/centryk_logo.png" alt="Centryk" class="h-7 w-auto">
        <h1 class="mt-4 text-2xl font-black tracking-tight">Welcome, <?= htmlspecialchars($firstName) ?> 👋</h1>
        <p class="mt-1 text-slate-500">Let's set up <span class="font-bold text-slate-700"><?= htmlspecialchars($company['name']) ?></span> — takes about 30 seconds.</p>
      </div>
      <div class="flex items-center gap-2">
        <div id="dot1" class="h-1.5 flex-1 rounded-full bg-violet-600"></div>
        <div id="dot2" class="h-1.5 flex-1 rounded-full bg-slate-200"></div>
      </div>
    </div>
  </div>

  <div class="mx-auto flex min-h-screen max-w-2xl flex-col px-4 pb-8 pt-[210px]">

    <div class="flex-1 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">

      <!-- ── Step 1: nature of business ─────────────────────────────────────── -->
      <section id="step1">
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-black">What type of business do you run?</h2>
            <p class="text-sm text-slate-500">We'll use this to personalize Centryk for your business.</p>
          </div>
          <button id="toStep2" onclick="goStep2()" disabled
                  class="shrink-0 rounded-lg bg-violet-600 px-5 py-2 text-sm font-bold text-white shadow-sm hover:bg-violet-700 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-400">
            Continue
          </button>
        </div>

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
      </section>

      <!-- ── Step 2: apps ───────────────────────────────────────────────────── -->
      <section id="step2" class="hidden">
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-black">Which apps will you use?</h2>
            <p class="text-sm text-slate-500">On by default — turn off anything you don't need.</p>
          </div>
          <button id="finishBtn" onclick="finish()"
                  class="shrink-0 rounded-lg bg-violet-600 px-5 py-2 text-sm font-bold text-white shadow-sm hover:bg-violet-700">
            Finish setup
          </button>
        </div>

        <div id="appList" class="space-y-2"></div>

        <div id="obError" class="mt-4 hidden rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700"></div>

        <div class="mt-6">
          <button onclick="backStep1()" class="text-sm font-semibold text-slate-400 hover:text-slate-600">&larr; Back</button>
        </div>
      </section>

    </div>
  </div>

  <script>
    const COMPANY_ID = <?= (int)$company['id'] ?>;

    // Business type → default customer noun (mirrors api/onboarding/complete.php).
    const TYPES = [
      { key: 'school',     icon: 'graduation-cap', color: 'blue',    label: 'School / Education', noun: ['Student', 'Students'] },
      { key: 'gym',        icon: 'dumbbell',        color: 'orange',  label: 'Gym / Fitness',     noun: ['Member', 'Members'] },
      { key: 'clinic',     icon: 'stethoscope',     color: 'rose',    label: 'Clinic / Health',   noun: ['Patient', 'Patients'] },
      { key: 'salon',      icon: 'scissors',        color: 'pink',    label: 'Salon / Spa',       noun: ['Client', 'Clients'] },
      { key: 'retail',     icon: 'shopping-bag',    color: 'violet',  label: 'Retail / Shop',     noun: ['Customer', 'Customers'] },
      { key: 'restaurant', icon: 'utensils',        color: 'amber',   label: 'Restaurant / Food', noun: ['Customer', 'Customers'] },
      { key: 'ice_cream',  icon: 'ice-cream-cone',  color: 'fuchsia', label: 'Ice Cream / Dessert',noun: ['Customer', 'Customers'] },
      { key: 'meat_shop',  icon: 'beef',            color: 'red',     label: 'Butcher / Meat Shop',noun: ['Customer', 'Customers'] },
      { key: 'cafeteria',  icon: 'utensils-crossed',color: 'teal',    label: 'Cafeteria / Food Service', noun: ['Customer', 'Customers'] },
      { key: 'auto_sales', icon: 'car',             color: 'sky',     label: 'Auto Sales',        noun: ['Buyer', 'Buyers'] },
      { key: 'auto_rental',icon: 'key-round',       color: 'indigo',  label: 'Auto Rental',       noun: ['Renter', 'Renters'] },
      { key: 'services',   icon: 'wrench',          color: 'slate',   label: 'Services',          noun: ['Client', 'Clients'] },
      { key: 'property',   icon: 'home',            color: 'emerald', label: 'Property / Rentals',noun: ['Tenant', 'Tenants'] },
      { key: 'other',      icon: 'building-2',      color: 'gray',    label: 'Something else',    noun: ['Customer', 'Customers'] },
    ];

    // Real choices, all on by default — deselect what you don't want. Whichever
    // are checked get granted (api/onboarding/complete.php) and show up in the
    // waffle switcher; anything left unchecked still shows up on the dashboard
    // as an app you can turn on later. Calendar is the one exception — every
    // company gets it, so it stays locked-on. OnePay's two nested rows (Store,
    // OneLink Payments) aren't separate apps at all, just bundled features.
    const APPS = [
      { key: 'onepay',      icon: 'shopping-cart', color: 'purple',  label: 'OnePay',       desc: 'Inventory, POS & sales',              on: true, locked: false },
      { key: 'invoice',     icon: 'file-text',     color: 'emerald', label: 'Invoices',     desc: 'Bill customers & track pay',          on: true, locked: false },
      { key: 'mypay',       icon: 'users',         color: 'orange',  label: 'MyPay',        desc: 'HR & payroll',                        on: true, locked: false },
      { key: 'visionboard', icon: 'monitor-play',  color: 'rose',    label: 'Vision Board', desc: 'Digital signage for your screens',    on: true, locked: false },
      { key: 'tv',          icon: 'tv',            color: 'cyan',    label: 'Centryk TV',   desc: 'Live streaming & broadcasting',       on: true, locked: false },
      { key: 'calendar',    icon: 'calendar',      color: 'teal',    label: 'Calendar',     desc: 'Included for everyone',               on: true, locked: true },
    ];

    let chosenType = null;

    // ── Step 1 rendering ──────────────────────────────────────────────────────
    const grid = document.getElementById('typeGrid');
    grid.innerHTML = TYPES.map(t => `
      <button type="button" data-key="${t.key}" onclick="pickType('${t.key}')"
        class="type-card flex flex-col items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-3 text-center hover:border-violet-300 hover:bg-violet-50/40 transition-colors">
        <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-${t.color}-500 text-white shadow-sm">
          <i data-lucide="${t.icon}" class="h-6 w-6" stroke-width="2.25"></i>
        </span>
        <span class="text-sm font-bold text-slate-700">${t.label}</span>
      </button>`).join('');
    if (window.lucide) lucide.createIcons();

    function pickType(key){
      const changed = (chosenType !== key);
      chosenType = key;
      const t = TYPES.find(x => x.key === key);
      document.querySelectorAll('.type-card').forEach(c => {
        const on = c.dataset.key === key;
        c.classList.toggle('border-violet-500', on);
        c.classList.toggle('bg-violet-50', on);
        c.classList.toggle('ring-1', on);
        c.classList.toggle('ring-violet-400', on);
      });
      // Switching to a DIFFERENT type refreshes the recommended noun. Any edits
      // you make after selecting a type stick — re-clicking the same type won't
      // wipe them.
      if (changed) {
        document.getElementById('nounS').value = t.noun[0];
        document.getElementById('nounP').value = t.noun[1];
      }
      document.getElementById('nounWrap').classList.remove('hidden');
      document.getElementById('toStep2').disabled = false;
    }

    // ── Step 2 rendering ──────────────────────────────────────────────────────
    // Store and OneLink Payments ride along with OnePay — neither is its own
    // app_access row, they're just bundled features. Shown nested under OnePay
    // and mirror its checkbox rather than being their own toggle.
    const onepaySubRows = [
      { id: 'storeSubRow',   icon: 'store',       label: 'Centryk Store',    note: 'included automatically with OnePay' },
      { id: 'onelinkSubRow', icon: 'credit-card', label: 'OneLink Payments', note: 'included automatically with OnePay' },
    ];
    const onepaySubRowsHtml = onepaySubRows.map(r => `
      <div id="${r.id}" class="ml-6 flex items-center gap-2.5 border-l-2 border-purple-100 py-1.5 pl-3 text-slate-400">
        <i data-lucide="corner-down-right" class="h-3.5 w-3.5 shrink-0"></i>
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-purple-50 text-purple-500">
          <i data-lucide="${r.icon}" class="h-3.5 w-3.5"></i>
        </span>
        <span class="flex-1 text-xs font-semibold">${r.label} <span class="font-normal text-slate-400">— ${r.note}</span></span>
      </div>`).join('');

    document.getElementById('appList').innerHTML = APPS.map(a => `
      <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 ${a.locked ? 'opacity-70' : 'cursor-pointer hover:border-violet-300'}">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-${a.color}-500 text-white shadow-sm">
          <i data-lucide="${a.icon}" class="h-6 w-6" stroke-width="2.25"></i>
        </span>
        <span class="flex-1">
          <span class="block text-[15px] font-bold text-slate-800">${a.label}</span>
          <span class="block text-[13px] text-slate-500">${a.desc}</span>
        </span>
        <input type="checkbox" class="app-check h-4 w-4" value="${a.key}" ${a.on ? 'checked' : ''} ${a.locked ? 'disabled' : ''}>
      </label>${a.key === 'onepay' ? onepaySubRowsHtml : ''}`).join('');
    if (window.lucide) lucide.createIcons();

    // OnePay's sub-rows dim/undim with its checkbox since they aren't real toggles.
    const onepayCheck = document.querySelector('.app-check[value="onepay"]');
    const syncOnepaySubRows = () => {
      onepaySubRows.forEach(r => {
        document.getElementById(r.id).classList.toggle('opacity-40', !onepayCheck.checked);
      });
    };
    onepayCheck.addEventListener('change', syncOnepaySubRows);
    syncOnepaySubRows();

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
  </script>
</body>
</html>
