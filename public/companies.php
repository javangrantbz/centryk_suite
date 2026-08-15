<?php
require_once __DIR__ . '/../app/core/Auth.php';
require_once __DIR__ . '/../app/services/AuthService.php';
Auth::start();
$user = Auth::user();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Companies now lives inside Profile → My Companies. This page is kept only as
// the embedded engine (?embed=1); send any other visit to the account hub.
$embed = (($_GET['embed'] ?? '') === '1');
if (!$embed) {
    $cu = trim($_GET['company_uuid'] ?? '');
    header('Location: profile.php' . ($cu !== '' ? '?company_uuid=' . urlencode($cu) : '') . '#business_profile');
    exit;
}

// Profile embeds this once per sub-tab (Business Profile / Company Members),
// forcing a single view. When forced, the internal Members/Profile tab strip
// is redundant and hidden — the parent's account nav does that switching.
$forceTab = in_array($_GET['tab'] ?? '', ['members', 'profile'], true) ? $_GET['tab'] : '';

$userApps = AuthService::allAppsWithEnrollment((int)$user['id']);
$fullName = htmlspecialchars($user['first_name'] . ' ' . $user['last_name']);
$initial  = strtoupper(substr($user['first_name'], 0, 1));
$email    = htmlspecialchars($user['email']);
$requestedCompanyUuid = trim($_GET['company_uuid'] ?? '');
$storeThemeOptions = [];
foreach (glob(__DIR__ . '/assets/store_theme/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [] as $themeFile) {
    $storeThemeOptions[] = 'assets/store_theme/' . basename($themeFile);
}
sort($storeThemeOptions, SORT_NATURAL);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Companies — Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>
        [data-lucide] { display: inline-block; }
        .modal-backdrop { background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); }

        /* ── Light theme ─────────────────────────────────────────────────── */
        body.light { background-color: #f1f5f9 !important; color: #0f172a; }

        body.light header { background-color: #ffffff !important; border-bottom-color: #e2e8f0 !important; }

        body.light .bg-\[\#0d1117\],
        body.light .bg-\[\#111827\],
        body.light .bg-\[\#0d1420\] { background-color: #ffffff; }
        body.light .bg-\[\#161f2e\] { background-color: #f8fafc; }
        body.light .bg-white\/10,
        body.light .bg-white\/8    { background-color: #f1f5f9; }
        body.light .bg-white\/6    { background-color: #f1f5f9; }
        body.light .bg-white\/4    { background-color: #f8fafc; }
        body.light .bg-slate-700   { background-color: #e2e8f0; }

        body.light .border-white\/10,
        body.light .border-white\/8,
        body.light .border-white\/6 { border-color: #e2e8f0; }
        body.light .divide-white\/6 > :not([hidden]) ~ :not([hidden]) { border-color: #e2e8f0; }

        body.light .text-white      { color: #0f172a; }
        body.light .text-white\/80  { color: #334155; }
        body.light .text-white\/60  { color: #64748b; }
        body.light .text-white\/45  { color: #64748b; }
        body.light .text-white\/40  { color: #94a3b8; }
        body.light .text-white\/35  { color: #94a3b8; }
        body.light .text-white\/30  { color: #94a3b8; }
        body.light .text-white\/25  { color: #cbd5e1; }
        body.light .text-white\/20  { color: #cbd5e1; }

        /* Role badge text — readable on light backgrounds */
        body.light .text-purple-300 { color: #7e22ce; }
        body.light .text-blue-300   { color: #1d4ed8; }
        body.light .text-slate-300  { color: #475569; }

        /* Preserve white text on solid coloured buttons */
        body.light .bg-blue-600.text-white,
        body.light .bg-blue-500.text-white { color: #ffffff; }
        body.light .text-white\/60           { color: #475569; }
        body.light .hover\:bg-white\/8:hover { background-color: #f1f5f9; }

        body.light .hover\:bg-\[\#161f2e\]:hover  { background-color: #f1f5f9; }
        body.light .hover\:border-white\/20:hover { border-color: #cbd5e1; }
        body.light .hover\:bg-white\/15:hover     { background-color: #e2e8f0; }
        body.light .hover\:text-white\/80:hover   { color: #334155; }

        body.light input,
        body.light select { background-color: #f8fafc; border-color: #e2e8f0; color: #0f172a; }
        body.light input::placeholder { color: #94a3b8; }
        body.light input:focus,
        body.light select:focus { background-color: #f1f5f9; border-color: #3b82f6; }
        body.light .focus\:bg-white\/8:focus { background-color: #f1f5f9; }

        body.light .modal-backdrop { background: rgba(0,0,0,0.4); }
    </style>
</head>
<body class="<?= $embed ? 'bg-[#0d1117]' : 'min-h-screen bg-[#0d1117]' ?> font-sans antialiased text-white">
<script>var _ct=localStorage.getItem('centrikyTheme');if(_ct==='dark'){document.body.classList.add('dark');}else{document.body.classList.add('light');}</script>

<?php
// Header middle slot: the company selector (its JS lives in this page).
ob_start(); ?>
        <div class="relative flex-1 max-w-xs" id="companyPickerWrapper">
            <button id="companyPickerBtn"
                class="flex w-full items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-left text-sm font-semibold text-slate-800 transition hover:bg-white hover:border-slate-300 focus:outline-none">
                <i data-lucide="building-2" class="h-4 w-4 shrink-0 text-slate-400"></i>
                <span id="companyPickerLabel" class="flex-1 truncate text-slate-400">Loading…</span>
                <i data-lucide="chevrons-up-down" class="h-3.5 w-3.5 shrink-0 text-slate-400"></i>
            </button>
            <div id="companyDropdown" class="absolute left-0 top-full mt-1.5 hidden w-72 rounded-2xl border border-slate-200 bg-white shadow-xl z-50 overflow-hidden">
                <div class="px-3 py-2 border-b border-slate-100">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Your Companies</p>
                </div>
                <div id="companyDropdownList" class="max-h-64 overflow-y-auto py-1.5"></div>
            </div>
        </div>
<?php $headerMiddleHtml = ob_get_clean();

// Header actions slot: admin-only links.
ob_start(); ?>
<?php include __DIR__ . '/partials/admin_tools_dropdown.php'; ?>
<?php $headerActionsHtml = ob_get_clean();

if ($embed) {
    // No page chrome here (the profile page provides that), but a user who
    // belongs to more than one company still needs a way to switch which one
    // this panel is showing. The picker normally lives in the light header
    // bar; embedded pages have no such bar, so it renders here instead, right
    // above the panel content. Kept in its native light styling — the same
    // control users already know from the standalone Companies page.
    echo '<div class="mb-4">' . $headerMiddleHtml . '</div>';
} else {
    $pageTitle  = 'Companies';
    $headerMaxW = 'max-w-6xl';
    include __DIR__ . '/partials/account_header.php';
}
?>

<!-- Company List View -->
<div id="listView" class="mx-auto max-w-5xl px-6 <?= $embed ? 'pt-2 pb-6' : 'pt-2 pb-8' ?>">
    <?php if (!$embed): ?>
    <div class="mb-2">
        <a href="index.php" class="inline-flex items-center gap-1.5 text-xs font-black uppercase tracking-[0.12em] text-white/35 transition hover:text-white/80">
            <i data-lucide="arrow-left" class="h-3.5 w-3.5"></i>
            Back to Dashboard
        </a>
    </div>
    <?php endif; ?>
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black tracking-tight text-white">Companies</h1>
            <p class="mt-1 text-sm font-semibold text-white/40">Manage your companies and their members across all apps.</p>
        </div>
        <button id="newCompanyBtn"
            class="flex items-center gap-2 rounded-xl bg-white/10 px-4 py-2.5 text-sm font-black text-white transition hover:bg-white/15">
            <i data-lucide="plus" class="h-4 w-4"></i>
            New Company
        </button>
    </div>

    <!-- Companies grid -->
    <div id="companiesGrid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"></div>

    <!-- Empty state -->
    <div id="emptyState" class="hidden rounded-2xl border border-white/8 bg-white/4 p-14 text-center">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-white/8 text-white/30">
            <i data-lucide="building-2" class="h-6 w-6"></i>
        </div>
        <p class="text-sm font-bold text-white/40">No companies yet.</p>
        <p class="mt-1 text-xs text-white/25">Create a company to start adding employees.</p>
    </div>
</div>

<!-- Company Detail View (hidden initially) -->
<div id="detailView" class="hidden mx-auto max-w-5xl px-6 <?= $embed ? 'pt-2 pb-6' : 'pt-2 pb-8' ?>">
    <?php if (!$embed): ?>
    <!-- Standalone only. Embedded in Profile there is no separate "Companies"
         page to go back to — the account nav switches sections instead. -->
    <div class="mb-6 flex items-center gap-3">
        <a href="index.php" class="flex items-center gap-1.5 text-xs font-black uppercase tracking-[0.12em] text-white/35 transition hover:text-white/80">
            <i data-lucide="arrow-left" class="h-3.5 w-3.5"></i>
            Back to Dashboard
        </a>
    </div>
    <?php endif; ?>

    <div class="overflow-hidden rounded-[24px] border border-white/10 bg-[#111827]">
        <!-- Detail header -->
        <div class="flex items-center justify-between border-b border-white/8 px-6 py-5">
            <div>
                <div class="flex items-center gap-2">
                    <h2 id="detailName" class="text-xl font-black tracking-tight text-white"></h2>
                    <span class="text-white/20 font-light mx-1">|</span>
                    <select id="profileBusinessType" class="profile-input rounded-lg border border-white/10 bg-white/6 px-2 py-1 text-[11px] font-bold text-white outline-none transition focus:border-emerald-500 focus:bg-white/8 disabled:opacity-60">
                        <option value="">Not set</option>
                        <option value="school">School / Education</option>
                        <option value="gym">Gym / Fitness</option>
                        <option value="clinic">Clinic / Health</option>
                        <option value="salon">Salon / Spa</option>
                        <option value="grocery">Groceries</option>
                        <option value="retail">Retail / Shop</option>
                        <option value="restaurant">Restaurant / Food</option>
                        <option value="ice_cream">Ice Cream / Dessert Shop</option>
                        <option value="meat_shop">Butcher / Meat Shop</option>
                        <option value="cafeteria">Cafeteria / Food Service</option>
                        <option value="auto_sales">Auto Sales</option>
                        <option value="auto_rental">Auto Rental</option>
                        <option value="services">Services</option>
                        <option value="property">Property / Rentals</option>
                        <option value="other">Something else</option>
                    </select>
                    <button id="renameCompanyBtn" title="Rename company"
                            class="hidden flex items-center justify-center rounded-lg p-1 text-white/25 transition hover:bg-white/10 hover:text-white/70">
                        <i data-lucide="pencil" class="h-3.5 w-3.5"></i>
                    </button>
                </div>
                <div class="mt-1 flex items-center gap-2">
                    <span id="detailRoleBadge" class="rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-[0.12em]"></span>
                    <span id="detailMemberCount" class="text-xs text-white/40"></span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button id="addMemberBtn" class="hidden flex items-center gap-2 rounded-xl bg-white/10 px-4 py-2.5 text-sm font-black text-white transition hover:bg-white/15">
                    <i data-lucide="user-plus" class="h-4 w-4"></i>
                    Add Member
                </button>
                <button id="saveProfileBtn" type="button" class="hidden flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white transition hover:bg-emerald-500">
                    <i data-lucide="save" class="h-4 w-4"></i> Save
                </button>
            </div>
        </div>

        <!-- Tabs (hidden when the parent forces a single sub-tab) -->
        <?php if ($forceTab === ''): ?>
        <div class="flex items-center gap-1 border-b border-white/8 px-4">
            <button type="button" class="co-tab px-4 py-3 text-xs font-black uppercase tracking-[0.1em] border-b-2 border-emerald-500 text-white" data-tab="members">Members</button>
            <button type="button" class="co-tab px-4 py-3 text-xs font-black uppercase tracking-[0.1em] border-b-2 border-transparent text-white/40 transition hover:text-white/70" data-tab="profile">Business Profile</button>
        </div>
        <?php endif; ?>

        <!-- Members panel -->
        <div data-tab-panel="members">
            <div id="membersList" class="divide-y divide-white/6"></div>
            <div id="membersLoading" class="p-10 text-center text-sm text-white/30">Loading members...</div>
        </div>

        <!-- Business profile panel (read by Invoice Maker & other apps) -->
        <div data-tab-panel="profile" class="hidden">
            <div id="profileError" class="hidden mx-6 mt-4 rounded-xl bg-red-500/15 px-4 py-2.5 text-xs font-semibold text-red-400"></div>
            <div id="profileReadonlyNote" class="hidden mx-6 mt-4 rounded-xl bg-white/5 px-4 py-2.5 text-[11px] font-semibold text-white/40">Only company admins can edit this profile.</div>
            <div id="profileSuccess" class="hidden mx-6 mt-4 flex items-center gap-2 rounded-xl border border-emerald-500/25 bg-emerald-500/15 px-4 py-2.5 text-xs font-bold text-emerald-400">
                <i data-lucide="check-circle" class="h-4 w-4"></i><span id="profileSuccessText">Business profile saved.</span>
            </div>

            <div class="space-y-8 p-6">
                <!-- Logo docked left, primary info docked right. The low
                     breakpoint keeps them side-by-side inside the narrow
                     Business Profile embed instead of stacking. -->
                <div class="flex flex-col gap-5 min-[480px]:flex-row min-[480px]:items-start">
                    <!-- Logo Preview (left) -->
                    <div class="flex shrink-0 flex-col items-center min-[480px]:items-start gap-3">
                        <!-- Larger logo: the three short fields beside it don't
                             need much width, so the logo takes the space. -->
                        <div class="relative flex aspect-square w-56 items-center justify-center overflow-hidden rounded-2xl border border-white/10 bg-white/5 sm:w-64">
                            <img id="profileLogoImg" src="" alt="Logo" class="hidden h-full w-full object-contain p-6">
                            <i data-lucide="image" id="profileLogoPlaceholder" class="h-16 w-16 text-white/10"></i>
                        </div>
                        <div class="flex flex-col items-center min-[480px]:items-start gap-1.5">
                            <label id="profileLogoLabel" class="hidden cursor-pointer rounded-xl border border-white/10 bg-white/6 px-4 py-2 text-xs font-black text-white transition hover:bg-white/10">
                                Change logo
                                <input type="file" id="profileLogoInput" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="hidden">
                            </label>
                            <p class="text-[9px] font-bold text-white/20 uppercase tracking-wider">PNG, JPG, WEBP or SVG · Max 2MB</p>
                        </div>
                    </div>

                    <!-- Primary Fields (right of the logo) -->
                    <div class="grid flex-1 grid-cols-1 gap-4">
                        <div>
                            <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-white/35">TIN Number</label>
                            <input id="profileTin" type="text" class="profile-input w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-emerald-500 focus:bg-white/8 disabled:opacity-60" placeholder="Tax ID">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-white/35">Business Email</label>
                            <input id="profileEmail" type="email" class="profile-input w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-emerald-500 focus:bg-white/8 disabled:opacity-60" placeholder="business@email.com">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-white/35">Phone 1</label>
                            <input id="profilePhone" type="tel" class="profile-input w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-emerald-500 focus:bg-white/8 disabled:opacity-60" placeholder="+501 …">
                        </div>
                    </div>
                </div>

                <!-- Secondary Fields -->
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div id="phoneField2" class="hidden">
                            <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-white/35">Phone 2</label>
                            <input id="profilePhone2" type="tel" class="profile-input w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-emerald-500 focus:bg-white/8 disabled:opacity-60" placeholder="Optional">
                        </div>
                        <div id="phoneField3" class="hidden">
                            <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-white/35">Phone 3</label>
                            <input id="profilePhone3" type="tel" class="profile-input w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-emerald-500 focus:bg-white/8 disabled:opacity-60" placeholder="Optional">
                        </div>
                    </div>
                    <button id="addPhoneBtn" type="button" class="hidden flex items-center gap-2 rounded-xl border border-white/10 bg-white/6 px-4 py-2 text-xs font-bold text-white/60 transition hover:bg-white/10 hover:text-white">
                        <i data-lucide="plus" class="h-3.5 w-3.5"></i> Add another phone
                    </button>

                    <div>
                        <div class="mb-2 flex items-end justify-between gap-3">
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-white/35">Store Theme</label>
                            <span id="profileThemeName" class="text-[10px] font-black uppercase tracking-[0.14em] text-emerald-300"></span>
                        </div>
                        <input id="profileStoreTheme" type="hidden" class="profile-input" value="">
                        <div id="profileThemePreview" class="relative flex aspect-[21/7] min-h-28 items-end overflow-hidden rounded-2xl border border-white/10 bg-white/6">
                            <img id="profileThemePreviewImg" src="" alt="" class="hidden absolute inset-0 h-full w-full object-cover">
                            <div class="absolute inset-0 bg-gradient-to-r from-slate-950/80 via-slate-950/25 to-transparent"></div>
                            <div class="relative p-4">
                                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-white/50">Selected Banner</p>
                                <p class="mt-1 text-lg font-black tracking-tight text-white">Business Store Header</p>
                            </div>
                        </div>
                        <div id="profileThemeThumbs" class="mt-3 grid grid-cols-5 gap-2 sm:grid-cols-10">
                            <?php foreach ($storeThemeOptions as $idx => $themePath): ?>
                                <button type="button"
                                        class="profile-theme-option group relative aspect-[4/3] overflow-hidden rounded-xl border border-white/10 bg-white/6 transition hover:border-emerald-400 focus:outline-none focus:ring-2 focus:ring-emerald-400"
                                        data-theme="<?= htmlspecialchars($themePath) ?>"
                                        data-theme-label="Theme <?= str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT) ?>">
                                    <img src="<?= htmlspecialchars($themePath) ?>" alt="" class="h-full w-full object-cover transition group-hover:scale-105">
                                    <span class="profile-theme-check absolute right-1.5 top-1.5 hidden h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg">
                                        <i data-lucide="check" class="h-3.5 w-3.5"></i>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-white/35">Business Address</label>
                        <textarea id="profileAddress" rows="2" class="profile-input w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-emerald-500 focus:bg-white/8 disabled:opacity-60" placeholder="Street, City, Country"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-white/35">Opening Hours</label>
                        <textarea id="profileHours" rows="2" class="profile-input w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-emerald-500 focus:bg-white/8 disabled:opacity-60" placeholder="e.g. Mon–Fri 8:00am–5:00pm&#10;Sat 9:00am–1:00pm"></textarea>
                    </div>

                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/10 bg-white/4 px-4 py-3 transition hover:bg-white/6">
                        <input id="profileDirectoryVisible" type="checkbox" class="profile-input mt-1 h-4 w-4 shrink-0 accent-emerald-500 disabled:opacity-60">
                        <span>
                            <span class="block text-sm font-black text-white">Show this business in Centryk Directory</span>
                            <span class="mt-1 block text-[11px] font-semibold leading-relaxed text-white/35">When enabled, the public home page can list this business name, type, address, and phone.</span>
                        </span>
                    </label>

                    <div>
                        <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-white/35">What you call your customers</label>
                        <div class="flex items-center gap-3">
                            <input id="profileNounS" type="text" class="profile-input w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-emerald-500 focus:bg-white/8 disabled:opacity-60" placeholder="Customer">
                            <span class="shrink-0 text-lg font-light text-white/20">/</span>
                            <input id="profileNounP" type="text" class="profile-input w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-emerald-500 focus:bg-white/8 disabled:opacity-60" placeholder="Customers">
                        </div>
                        <p class="mt-2 text-[10px] font-semibold text-white/25">Singular / plural. Used for invoicing labels (e.g. “Batch Invoice Students”).</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ─── New Company Modal ─── -->
<div id="newCompanyModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="w-full max-w-md rounded-[20px] border border-white/10 bg-[#111827] p-6 shadow-2xl">
        <div class="mb-5 flex items-center justify-between">
            <h3 class="text-lg font-black text-white">New Company</h3>
            <button class="modal-close text-white/30 transition hover:text-white/70">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>
        <div id="newCompanyError" class="mb-3 hidden rounded-xl bg-red-500/15 px-4 py-2.5 text-sm font-semibold text-red-400"></div>
        <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Company Name</label>
        <input id="newCompanyName" type="text" placeholder="e.g. BHI Limited"
            class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
        <div class="mt-5 flex gap-3 justify-end">
            <button class="modal-close rounded-xl px-4 py-2.5 text-sm font-bold text-white/40 transition hover:text-white/70">Cancel</button>
            <button id="createCompanyBtn" class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-black text-white transition hover:bg-blue-500">
                Create Company
            </button>
        </div>
    </div>
</div>

<!-- ─── Rename Company Modal ─── -->
<div id="renameCompanyModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="w-full max-w-md rounded-[20px] border border-white/10 bg-[#111827] p-6 shadow-2xl">
        <div class="mb-5 flex items-center justify-between">
            <h3 class="text-lg font-black text-white">Rename Company</h3>
            <button class="modal-close text-white/30 transition hover:text-white/70">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>
        <div id="renameCompanyError" class="mb-3 hidden rounded-xl bg-red-500/15 px-4 py-2.5 text-sm font-semibold text-red-400"></div>
        <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Company Name</label>
        <input id="renameCompanyInput" type="text" placeholder="Company name"
            class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
        <div class="mt-5 flex gap-3 justify-end">
            <button class="modal-close rounded-xl px-4 py-2.5 text-sm font-bold text-white/40 transition hover:text-white/70">Cancel</button>
            <button id="saveRenameBtn" class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-black text-white transition hover:bg-blue-500">
                Save
            </button>
        </div>
    </div>
</div>

<!-- ─── Remove Member Modal ─── -->
<div id="removeMemberModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="w-full max-w-sm rounded-[20px] border border-white/10 bg-[#111827] p-6 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-lg font-black text-white">Remove Member</h3>
            <button class="modal-close text-white/30 transition hover:text-white/70">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>
        <p class="mb-5 text-sm font-semibold text-white/60">
            Remove <span id="removeMemberName" class="font-black text-white"></span> from this company?
            Their Centryk account will remain active unless they have no other memberships.
        </p>
        <!-- Revoke app access toggle -->
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-white/8 bg-white/4 px-4 py-3 transition hover:bg-white/6">
            <div class="mt-0.5 shrink-0">
                <input id="revokeAppAccessToggle" type="checkbox" class="h-4 w-4 accent-red-500 cursor-pointer">
            </div>
            <div>
                <p class="text-sm font-black text-white">Also revoke app access</p>
                <p class="mt-0.5 text-xs font-semibold text-white/40">Deactivates their OnePay store memberships and removes their MyPay company assignment.</p>
            </div>
        </label>
        <div class="mt-5 flex gap-3 justify-end">
            <button class="modal-close rounded-xl px-4 py-2.5 text-sm font-bold text-white/40 transition hover:text-white/70">Cancel</button>
            <button id="confirmRemoveMemberBtn" class="rounded-xl bg-red-600 px-5 py-2.5 text-sm font-black text-white transition hover:bg-red-500">
                Remove
            </button>
        </div>
    </div>
</div>

<!-- ─── Add Member Modal ─── -->
<div id="addMemberModal" class="modal-backdrop fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="w-full max-w-md rounded-[20px] border border-white/10 bg-[#111827] p-6 shadow-2xl">
        <div class="mb-5 flex items-center justify-between">
            <h3 class="text-lg font-black text-white">Add Member</h3>
            <button class="modal-close text-white/30 transition hover:text-white/70">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>
        <div id="addMemberError" class="mb-3 hidden rounded-xl bg-red-500/15 px-4 py-2.5 text-sm font-semibold text-red-400"></div>

        <div class="space-y-3">
            <div id="inviteEmailWrap">
                <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Email Address</label>
                <input id="inviteEmail" type="email" placeholder="employee@example.com"
                    class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
                <button type="button" id="inviteNoEmailToggle" class="mt-2 text-[11px] font-bold text-blue-400 transition hover:text-blue-300">
                    No email address? Create with username instead →
                </button>
            </div>

            <div id="inviteUsernameWrap" class="hidden">
                <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Username</label>
                <input id="inviteUsername" type="text" placeholder="john.doe"
                    class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
                <p class="mt-2 text-[11px] text-white/40 font-semibold">Login: <span id="inviteUsernamePreview" class="font-mono text-white/70">username@centryk.com</span></p>
                <button type="button" id="inviteBackToEmailBtn" class="mt-1 text-[11px] font-bold text-blue-400 transition hover:text-blue-300">
                    ← Use an email instead
                </button>
            </div>

            <p class="text-[11px] text-white/30 font-semibold">If this person doesn't have a Centryk account yet, fill in their details below.</p>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">First Name</label>
                    <input id="inviteFirstName" type="text" placeholder="John"
                        class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
                </div>
                <div>
                    <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Last Name</label>
                    <input id="inviteLastName" type="text" placeholder="Doe"
                        class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
                </div>
            </div>

            <div>
                <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Temp Password <span class="text-white/20">(new accounts only)</span></label>
                <input id="invitePassword" type="password" placeholder="Set a temporary password"
                    class="w-full rounded-xl border border-white/10 bg-white/6 px-4 py-3 text-sm font-semibold text-white placeholder-white/20 outline-none focus:border-blue-500/50 focus:bg-white/8">
            </div>

            <div>
                <label class="block text-xs font-black uppercase tracking-[0.14em] text-white/40 mb-2">Role</label>
                <select id="inviteRole"
                    class="w-full rounded-xl border border-white/10 bg-[#111827] px-4 py-3 text-sm font-semibold text-white outline-none focus:border-blue-500/50">
                    <option value="employee">Employee</option>
                    <option value="manager">Manager</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
        </div>

        <div class="mt-5 flex gap-3 justify-end">
            <button class="modal-close rounded-xl px-4 py-2.5 text-sm font-bold text-white/40 transition hover:text-white/70">Cancel</button>
            <button id="inviteSubmitBtn" class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-black text-white transition hover:bg-blue-500">
                Add Member
            </button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
(function () {
    if (window.lucide) { lucide.createIcons(); }

    // Set when the parent profile page forces one sub-tab ('members'|'profile').
    var FORCED_TAB = <?= json_encode($forceTab) ?>;
    var EMBED = <?= $embed ? 'true' : 'false' ?>;

    var currentCompany  = null;
    var pendingRemove   = { companyId: null, userId: null };
    var selectedUuid    = null;
    var requestedCompanyUuid = <?= json_encode($requestedCompanyUuid, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var currentUserEmail     = <?= json_encode($user['email'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var membersRequestSeq = 0;

    // ── Header company picker ─────────────────────────────────────────────────
    // (waffle, account menu, theme and logout are owned by account_header.php)
    document.getElementById('companyPickerBtn').addEventListener('click', function (e) {
        e.stopPropagation();
        document.getElementById('companyDropdown').classList.toggle('hidden');
        // appSwitcherDropdown belongs to account_header.php, which the embed
        // never includes — guard it so the picker doesn't throw in embed.
        var asd = document.getElementById('appSwitcherDropdown');
        if (asd) { asd.classList.add('hidden'); }
    });
    document.addEventListener('click', function () {
        document.getElementById('companyDropdown').classList.add('hidden');
    });

    // ── Waffle launch hook: require a company to be selected first ─────────────
    window.centrykAppLaunch = function (appKey) {
        if (!selectedUuid) {
            showToast('Select a company from the dropdown first.', 'error');
            document.getElementById('companyDropdown').classList.remove('hidden');
            return;
        }
        window.location.href = 'switch.php?app=' + encodeURIComponent(appKey) + '&company_uuid=' + encodeURIComponent(selectedUuid);
    };

    // ── Re-render lists when the shared header toggles the theme ───────────────
    document.addEventListener('centryk:themechange', function () {
        loadCompanies();
        if (currentCompany) { loadMembers(currentCompany.id); }
    });

    // ── View helpers ──────────────────────────────────────────────────────────
    function showListView() {
        document.getElementById('listView').classList.remove('hidden');
        document.getElementById('detailView').classList.add('hidden');
        currentCompany = null;
    }
    document.getElementById('detailBackEmbed')?.addEventListener('click', showListView);

    function showDetailView(company) {
        if (currentCompany && String(currentCompany.id) === String(company.id)) {
            return;
        }
        currentCompany = company;
        document.getElementById('listView').classList.add('hidden');
        document.getElementById('detailView').classList.remove('hidden');
        document.getElementById('detailName').textContent = company.name;

        var badge = document.getElementById('detailRoleBadge');
        badge.textContent = company.role.charAt(0).toUpperCase() + company.role.slice(1);
        var colors = { admin: 'bg-purple-500/20 text-purple-300', manager: 'bg-blue-500/20 text-blue-300', employee: 'bg-slate-500/20 text-slate-300' };
        badge.className = 'rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-[0.12em] ' + (colors[company.role] || colors.employee);

        if (company.role === 'admin') {
            document.getElementById('renameCompanyBtn').classList.remove('hidden');
        } else {
            document.getElementById('renameCompanyBtn').classList.add('hidden');
        }

        loadMembers(company.id);
        loadProfile(company.id);
        activateCompanyTab(FORCED_TAB || 'members');
        if (window.lucide) { lucide.createIcons(); }
    }

    // ── Detail tabs (Members / Business Profile) ──────────────────────────────
    function activateCompanyTab(tab) {
        document.querySelectorAll('.co-tab').forEach(function (b) {
            var on = b.dataset.tab === tab;
            b.classList.toggle('text-white', on);
            b.classList.toggle('border-emerald-500', on);
            b.classList.toggle('text-white/40', !on);
            b.classList.toggle('border-transparent', !on);
        });
        document.querySelectorAll('[data-tab-panel]').forEach(function (p) {
            p.classList.toggle('hidden', p.dataset.tabPanel !== tab);
        });
        var isAdmin = currentCompany && currentCompany.role === 'admin';
        document.getElementById('addMemberBtn').classList.toggle('hidden', !(tab === 'members' && isAdmin));
        document.getElementById('saveProfileBtn').classList.toggle('hidden', !(tab === 'profile' && isAdmin));
    }
    document.querySelectorAll('.co-tab').forEach(function (b) {
        b.addEventListener('click', function () { activateCompanyTab(b.dataset.tab); });
    });

    // ── Company business profile ──────────────────────────────────────────────
    var profileLogoFile = null;
    var STORE_THEMES = <?= json_encode($storeThemeOptions, JSON_UNESCAPED_SLASHES) ?>;

    function setProfileLogo(src) {
        var img = document.getElementById('profileLogoImg');
        var ph  = document.getElementById('profileLogoPlaceholder');
        if (src) { img.src = src; img.classList.remove('hidden'); ph.classList.add('hidden'); }
        else     { img.removeAttribute('src'); img.classList.add('hidden'); ph.classList.remove('hidden'); }
    }

    function setProfileTheme(src) {
        var theme = STORE_THEMES.indexOf(src || '') !== -1 ? src : (STORE_THEMES[0] || '');
        var input = document.getElementById('profileStoreTheme');
        var img = document.getElementById('profileThemePreviewImg');
        var label = document.getElementById('profileThemeName');
        if (input) { input.value = theme; }
        if (img) {
            if (theme) { img.src = theme; img.classList.remove('hidden'); }
            else { img.removeAttribute('src'); img.classList.add('hidden'); }
        }
        var activeLabel = '';
        document.querySelectorAll('.profile-theme-option').forEach(function (btn) {
            var on = btn.dataset.theme === theme;
            if (on) { activeLabel = btn.dataset.themeLabel || ''; }
            btn.classList.toggle('border-emerald-400', on);
            btn.classList.toggle('ring-2', on);
            btn.classList.toggle('ring-emerald-400/70', on);
            var check = btn.querySelector('.profile-theme-check');
            if (check) {
                check.classList.toggle('hidden', !on);
                check.classList.toggle('flex', on);
            }
        });
        if (label) { label.textContent = activeLabel; }
    }

    function setProfileEditable(canEdit) {
        document.querySelectorAll('.profile-input').forEach(function (el) { el.disabled = !canEdit; });
        document.querySelectorAll('.profile-theme-option').forEach(function (el) { el.disabled = !canEdit; });
        document.getElementById('profileLogoLabel').classList.toggle('hidden', !canEdit);
        document.getElementById('profileReadonlyNote').classList.toggle('hidden', canEdit);
        updatePhoneAddBtn(canEdit);
    }

    // Phone 2 & 3 are hidden until needed; the "+" reveals the next one.
    function phoneFieldEls() {
        return [document.getElementById('phoneField2'), document.getElementById('phoneField3')];
    }
    function updatePhoneAddBtn(canEdit) {
        var anyHidden = phoneFieldEls().some(function (el) { return el.classList.contains('hidden'); });
        document.getElementById('addPhoneBtn').classList.toggle('hidden', !(canEdit && anyHidden));
    }
    function revealNextPhone() {
        var els = phoneFieldEls();
        for (var i = 0; i < els.length; i++) {
            if (els[i].classList.contains('hidden')) { els[i].classList.remove('hidden'); return els[i]; }
        }
        return null;
    }
    document.getElementById('addPhoneBtn').addEventListener('click', function () {
        var revealed = revealNextPhone();
        updatePhoneAddBtn(true);
        if (revealed) { var inp = revealed.querySelector('input'); if (inp) { inp.focus(); } }
    });

    function loadProfile(companyId) {
        profileLogoFile = null;
        document.getElementById('profileError').classList.add('hidden');
        document.getElementById('profileSuccess').classList.add('hidden');
        fetch('api/companies/profile.php?company_id=' + companyId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { return; }
                var p = data.profile || {};
                document.getElementById('profileEmail').value   = p.email || currentUserEmail || '';
                document.getElementById('profilePhone').value   = p.phone || '';
                document.getElementById('profilePhone2').value  = p.phone2 || '';
                document.getElementById('profilePhone3').value  = p.phone3 || '';
                document.getElementById('profileTin').value     = p.tax_number || '';
                document.getElementById('profileAddress').value = p.address || '';
                document.getElementById('profileHours').value   = p.opening_hours || '';
                document.getElementById('profileDirectoryVisible').checked = String(p.directory_visible ?? '1') !== '0';
                document.getElementById('profileBusinessType').value = p.business_type || '';
                document.getElementById('profileNounS').value   = p.customer_noun_singular || '';
                document.getElementById('profileNounP').value   = p.customer_noun_plural || '';
                // Reveal phone 2/3 only when they already hold a value.
                var has2 = !!(p.phone2 || '').trim() || !!(p.phone3 || '').trim();
                var has3 = !!(p.phone3 || '').trim();
                document.getElementById('phoneField2').classList.toggle('hidden', !has2);
                document.getElementById('phoneField3').classList.toggle('hidden', !has3);
                setProfileLogo(p.logo || '');
                setProfileTheme(p.store_theme || '');
                setProfileEditable(!!data.can_edit);
                if (window.lucide) { lucide.createIcons(); }
            })
            .catch(function () {});
    }

    document.querySelectorAll('.profile-theme-option').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) { return; }
            setProfileTheme(btn.dataset.theme || '');
        });
    });

    document.getElementById('profileLogoInput').addEventListener('change', function () {
        var f = this.files && this.files[0];
        if (!f) { return; }
        profileLogoFile = f;
        var reader = new FileReader();
        reader.onload = function (e) { setProfileLogo(e.target.result); };
        reader.readAsDataURL(f);
    });

    // Changing the business type refreshes the recommended customer noun; the
    // admin can still type a custom one afterwards.
    var NOUN_DEFAULTS = {
        school: ['Student', 'Students'], gym: ['Member', 'Members'], clinic: ['Patient', 'Patients'],
        salon: ['Client', 'Clients'], grocery: ['Customer', 'Customers'], services: ['Client', 'Clients'], property: ['Tenant', 'Tenants'],
        retail: ['Customer', 'Customers'], restaurant: ['Customer', 'Customers'],
        ice_cream: ['Customer', 'Customers'], meat_shop: ['Customer', 'Customers'], cafeteria: ['Customer', 'Customers'],
        other: ['Customer', 'Customers']
    };
    document.getElementById('profileBusinessType').addEventListener('change', function () {
        var d = NOUN_DEFAULTS[this.value];
        if (!d) { return; }
        document.getElementById('profileNounS').value = d[0];
        document.getElementById('profileNounP').value = d[1];
    });

    document.getElementById('saveProfileBtn').addEventListener('click', function () {
        if (!currentCompany) { return; }
        var btn = this;
        var err = document.getElementById('profileError');
        err.classList.add('hidden');
        document.getElementById('profileSuccess').classList.add('hidden');

        var fd = new FormData();
        fd.append('company_id', currentCompany.id);
        fd.append('email',         document.getElementById('profileEmail').value.trim());
        fd.append('phone',         document.getElementById('profilePhone').value.trim());
        fd.append('phone2',        document.getElementById('profilePhone2').value.trim());
        fd.append('phone3',        document.getElementById('profilePhone3').value.trim());
        fd.append('tax_number',    document.getElementById('profileTin').value.trim());
        fd.append('address',       document.getElementById('profileAddress').value.trim());
        fd.append('opening_hours', document.getElementById('profileHours').value.trim());
        fd.append('directory_visible', document.getElementById('profileDirectoryVisible').checked ? '1' : '0');
        fd.append('business_type',          document.getElementById('profileBusinessType').value);
        fd.append('customer_noun_singular', document.getElementById('profileNounS').value.trim());
        fd.append('customer_noun_plural',   document.getElementById('profileNounP').value.trim());
        fd.append('store_theme',            document.getElementById('profileStoreTheme').value);
        if (profileLogoFile) { fd.append('logo', profileLogoFile); }

        var orig = btn.innerHTML;
        btn.disabled = true;
        btn.textContent = 'Saving…';

        fetch('api/companies/update-profile.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = orig;
                if (window.lucide) { lucide.createIcons(); }
                if (!data.success) { err.textContent = data.message || 'Could not save profile.'; err.classList.remove('hidden'); return; }
                profileLogoFile = null;
                if (data.logo) { setProfileLogo(data.logo); }
                var ok = document.getElementById('profileSuccess');
                ok.classList.remove('hidden');
                clearTimeout(window._profileOkTimer);
                window._profileOkTimer = setTimeout(function () { ok.classList.add('hidden'); }, 4000);
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = orig;
                err.textContent = 'Network error. Please try again.';
                err.classList.remove('hidden');
            });
    });

    // ── Modal helpers ─────────────────────────────────────────────────────────
    function openModal(id) {
        var m = document.getElementById(id);
        m.classList.remove('hidden');
        m.classList.add('flex');
    }

    function closeModal(id) {
        var m = document.getElementById(id);
        m.classList.add('hidden');
        m.classList.remove('flex');
    }

    document.querySelectorAll('.modal-close').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[id$="Modal"]').forEach(function (m) {
                m.classList.add('hidden');
                m.classList.remove('flex');
            });
        });
    });

    // ── Header company picker ─────────────────────────────────────────────────
    function buildHeaderPicker(list) {
        var pickerLabel  = document.getElementById('companyPickerLabel');
        var dropdownList = document.getElementById('companyDropdownList');
        var roleColors   = { admin: '#7c3aed', manager: '#2563eb', employee: '#475569' };

        if (!list || !list.length) {
            pickerLabel.textContent = 'No companies';
            dropdownList.innerHTML  = '<p class="px-4 py-3 text-xs text-slate-400">No companies found.</p>';
            return;
        }

        dropdownList.innerHTML = list.map(function (c) {
            var color   = roleColors[c.role] || roleColors.employee;
            var initial = (c.name || '?').charAt(0).toUpperCase();
            return '<button class="hdr-co-opt w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-slate-50 transition"' +
                ' data-id="' + c.id + '" data-uuid="' + escHtml(c.uuid || '') + '" data-name="' + escHtml(c.name) + '">' +
                '<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-black" style="background:' + color + '18;color:' + color + '">' + escHtml(initial) + '</span>' +
                '<div class="min-w-0"><div class="text-sm font-bold text-slate-800 truncate">' + escHtml(c.name) + '</div>' +
                '<div class="text-[10px] font-semibold capitalize" style="color:' + color + '">' + escHtml(c.role) + '</div></div>' +
                '</button>';
        }).join('');

        dropdownList.querySelectorAll('.hdr-co-opt').forEach(function (btn, i) {
            btn.addEventListener('click', function () {
                selectedUuid = btn.dataset.uuid || null;
                pickerLabel.textContent = btn.dataset.name;
                pickerLabel.classList.remove('text-slate-400');
                pickerLabel.classList.add('text-slate-800');
                document.getElementById('companyDropdown').classList.add('hidden');
                showDetailView(list[i]);
            });
        });

        // Embedded panels have no grid to browse, so a multi-company user gets
        // dropped straight into the first company (alphabetical, same order as
        // the picker list) rather than an empty panel — same as the existing
        // single-company case, just no longer limited to exactly one. The
        // standalone Companies page is unaffected and still shows the grid so
        // the choice stays explicit there. A ?company_uuid= deep link (handled
        // further down in loadCompanies) still overrides this afterward.
        if (list.length === 1 || (EMBED && list.length > 1)) {
            selectedUuid = list[0].uuid || null;
            pickerLabel.textContent = list[0].name;
            pickerLabel.classList.remove('text-slate-400');
            pickerLabel.classList.add('text-slate-800');
            showDetailView(list[0]);
        } else {
            pickerLabel.textContent = 'Select a company…';
        }
        if (window.lucide) { lucide.createIcons(); }
    }

    // ── Load companies ────────────────────────────────────────────────────────
    function loadCompanies() {
        fetch('api/companies/list.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var grid  = document.getElementById('companiesGrid');
                var empty = document.getElementById('emptyState');
                grid.innerHTML = '';
                buildHeaderPicker((data.success && data.companies) ? data.companies : []);
                if (!data.success || !data.companies || data.companies.length === 0) {
                    empty.classList.remove('hidden');
                    return;
                }
                empty.classList.add('hidden');
                var matchedCompany = null;
                data.companies.forEach(function (c) {
                    var card = document.createElement('button');
                    card.className = 'group flex flex-col rounded-2xl border border-white/10 bg-[#111827] p-5 text-left transition hover:border-white/20 hover:bg-[#161f2e] active:scale-[0.98]';
                    var roleColors = { admin: '#7c3aed', manager: '#2563eb', employee: '#475569' };
                    var color = roleColors[c.role] || roleColors.employee;
                    card.innerHTML = '<div class="mb-4 flex h-11 w-11 items-center justify-center rounded-xl text-white" style="background:' + color + '22">' +
                        '<i data-lucide="building-2" class="h-5 w-5" style="color:' + color + '"></i></div>' +
                        '<div class="text-base font-black tracking-tight text-white">' + escHtml(c.name) + '</div>' +
                        '<div class="mt-1 flex items-center gap-2">' +
                        '<span class="rounded-full px-2 py-0.5 text-[10px] font-black uppercase tracking-[0.1em]" style="background:' + color + '22;color:' + color + '">' + escHtml(c.role) + '</span>' +
                        '<span class="text-xs text-white/30">' + c.member_count + ' member' + (c.member_count == 1 ? '' : 's') + '</span>' +
                        '</div>';
                    card.addEventListener('click', function () { showDetailView(c); });
                    grid.appendChild(card);
                    if (requestedCompanyUuid && c.uuid === requestedCompanyUuid) {
                        matchedCompany = c;
                    }
                });
                if (matchedCompany) {
                    selectedUuid = matchedCompany.uuid || null;
                    document.getElementById('companyPickerLabel').textContent = matchedCompany.name;
                    document.getElementById('companyPickerLabel').classList.remove('text-slate-400');
                    document.getElementById('companyPickerLabel').classList.add('text-slate-800');
                    requestedCompanyUuid = null;
                    if (!currentCompany || String(currentCompany.id) !== String(matchedCompany.id)) {
                        showDetailView(matchedCompany);
                    }
                }
                if (window.lucide) { lucide.createIcons(); }
            });
    }

    // ── Load members ──────────────────────────────────────────────────────────
    function loadMembers(companyId) {
        var list    = document.getElementById('membersList');
        var loading = document.getElementById('membersLoading');
        var requestId = ++membersRequestSeq;
        list.innerHTML = '';
        loading.classList.remove('hidden');

        fetch('api/companies/members.php?company_id=' + companyId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (requestId !== membersRequestSeq || !currentCompany || String(currentCompany.id) !== String(companyId)) {
                    return;
                }
                loading.classList.add('hidden');
                if (!data.success) { return; }
                document.getElementById('detailMemberCount').textContent = data.members.length + ' member' + (data.members.length === 1 ? '' : 's');

                data.members.forEach(function (m) {
                    var row = document.createElement('div');
                    row.className = 'flex items-center justify-between gap-4 px-6 py-4';
                    var initial = (m.first_name || '?').charAt(0).toUpperCase();
                    var roleColors = { admin: 'bg-purple-500/20 text-purple-300', manager: 'bg-blue-500/20 text-blue-300', employee: 'bg-slate-500/20 text-slate-300' };
                    var roleClass = roleColors[m.role] || roleColors.employee;
                    var isMe = (m.id == <?= (int)$user['id'] ?>);
                    var isAdmin = (currentCompany && currentCompany.role === 'admin');

                    // App access pills
                    var appsHtml = '';
                    if ((isAdmin || isMe) && m.apps && m.apps.length) {
                        appsHtml = '<div class="flex items-center gap-1.5">';
                        m.apps.forEach(function (app) {
                            var enrolledCls = app.enrolled
                                ? 'border-emerald-500/40 bg-emerald-500/15 text-emerald-400'
                                : 'border-white/10 bg-white/5 text-white/25';
                            var checkIcon = app.enrolled
                                ? '<svg class="h-2.5 w-2.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>'
                                : '<svg class="h-2.5 w-2.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>';
                            var clickable = isAdmin && !isMe;
                            appsHtml += '<button class="app-access-pill flex items-center gap-1 rounded-full border px-2 py-0.5 text-[9px] font-black uppercase tracking-[0.08em] transition'
                                + (clickable ? ' cursor-pointer hover:opacity-70' : ' cursor-default')
                                + ' ' + enrolledCls + '"'
                                + ' data-uid="' + m.id + '"'
                                + ' data-app="' + escHtml(app.key) + '"'
                                + ' data-enrolled="' + (app.enrolled ? '1' : '0') + '"'
                                + (clickable ? '' : ' disabled') + '>'
                                + checkIcon + ' ' + escHtml(app.label)
                                + '</button>';
                        });
                        appsHtml += '</div>';
                    }

                    row.innerHTML = '<div class="flex items-center gap-3 min-w-0">' +
                        '<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-700 text-sm font-black text-white">' + escHtml(initial) + '</div>' +
                        '<div class="min-w-0">' +
                        '<div class="text-sm font-bold text-white truncate">' + escHtml(m.first_name + ' ' + m.last_name) + (isMe ? ' <span class="text-white/30 text-xs">(you)</span>' : '') + '</div>' +
                        '<div class="text-xs text-white/40 truncate">' + escHtml(m.email) + '</div>' +
                        '</div></div>' +
                        '<div class="flex items-center gap-3 shrink-0">' +
                        appsHtml +
                        '<span class="rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] ' + roleClass + '">' + escHtml(m.role) + '</span>' +
                        (isAdmin && !isMe ? '<button class="remove-member text-white/20 transition hover:text-red-400" data-uid="' + m.id + '" data-name="' + escHtml(m.first_name + ' ' + m.last_name) + '" title="Remove member"><i data-lucide="user-minus" class="h-4 w-4"></i></button>' : '') +
                        '</div>';

                    list.appendChild(row);
                });

                // App access toggle
                list.querySelectorAll('.app-access-pill:not([disabled])').forEach(function (pill) {
                    pill.addEventListener('click', function () {
                        var uid      = parseInt(pill.dataset.uid, 10);
                        var appKey   = pill.dataset.app;
                        var enrolled = pill.dataset.enrolled === '1';
                        var grant    = !enrolled;

                        pill.style.opacity = '0.4';
                        fetch('api/apps/toggle-access.php', {
                            method:  'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body:    JSON.stringify({ user_id: uid, app_key: appKey, grant: grant }),
                        })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            pill.style.opacity = '';
                            if (data.success) {
                                // Reload the member list to reflect the change
                                loadMembers(companyId);
                            } else {
                                showToast(data.message || 'Could not update access.', 'error');
                            }
                        })
                        .catch(function () {
                            pill.style.opacity = '';
                            showToast('Network error.', 'error');
                        });
                    });
                });

                list.querySelectorAll('.remove-member').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        pendingRemove.companyId = companyId;
                        pendingRemove.userId    = parseInt(btn.dataset.uid, 10);
                        document.getElementById('removeMemberName').textContent = btn.dataset.name || 'this member';
                        document.getElementById('revokeAppAccessToggle').checked = false;
                        openModal('removeMemberModal');
                    });
                });

                if (window.lucide) { lucide.createIcons(); }
            })
            .catch(function () {
                if (requestId !== membersRequestSeq) {
                    return;
                }
                loading.classList.add('hidden');
                list.innerHTML = '<div class="px-6 py-6 text-sm font-semibold text-red-400">Could not load members.</div>';
            });
    }

    // ── Create company ────────────────────────────────────────────────────────
    document.getElementById('newCompanyBtn').addEventListener('click', function () {
        document.getElementById('newCompanyName').value = '';
        document.getElementById('newCompanyError').classList.add('hidden');
        openModal('newCompanyModal');
    });

    document.getElementById('createCompanyBtn').addEventListener('click', function () {
        var name = document.getElementById('newCompanyName').value.trim();
        var errEl = document.getElementById('newCompanyError');
        if (!name) { errEl.textContent = 'Company name is required.'; errEl.classList.remove('hidden'); return; }

        var btn = document.getElementById('createCompanyBtn');
        btn.textContent = 'Creating...';
        btn.disabled = true;

        fetch('api/companies/create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: name })
        }).then(function (r) { return r.json(); })
        .then(function (data) {
            btn.textContent = 'Create Company';
            btn.disabled = false;
            if (!data.success) { errEl.textContent = data.message; errEl.classList.remove('hidden'); return; }
            closeModal('newCompanyModal');
            loadCompanies();
        });
    });

    // ── Rename company ────────────────────────────────────────────────────────
    document.getElementById('renameCompanyBtn').addEventListener('click', function () {
        var modal = document.getElementById('renameCompanyModal');
        var input = document.getElementById('renameCompanyInput');
        var err   = document.getElementById('renameCompanyError');
        input.value = currentCompany ? currentCompany.name : '';
        err.classList.add('hidden');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(function () { input.focus(); input.select(); }, 50);
    });

    document.getElementById('saveRenameBtn').addEventListener('click', function () {
        var modal = document.getElementById('renameCompanyModal');
        var input = document.getElementById('renameCompanyInput');
        var err   = document.getElementById('renameCompanyError');
        var btn   = document.getElementById('saveRenameBtn');
        var name  = input.value.trim();

        if (!name) {
            err.textContent = 'Company name cannot be empty.';
            err.classList.remove('hidden');
            return;
        }
        if (!currentCompany) { return; }

        btn.disabled    = true;
        btn.textContent = 'Saving…';
        err.classList.add('hidden');

        fetch('api/companies/rename.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ company_id: currentCompany.id, name: name }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled    = false;
            btn.textContent = 'Save';
            if (data.success) {
                currentCompany.name = name;
                document.getElementById('detailName').textContent = name;
                // Update company card and picker label if this is the selected company
                var pickerLbl = document.getElementById('companyPickerLabel');
                if (pickerLbl && pickerLbl.textContent !== 'Select a company…' && pickerLbl.textContent !== 'No companies') {
                    pickerLbl.textContent = name;
                }
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                loadCompanies();
            } else {
                err.textContent = data.message || 'Could not rename company.';
                err.classList.remove('hidden');
            }
        })
        .catch(function () {
            btn.disabled    = false;
            btn.textContent = 'Save';
            err.textContent = 'Network error. Please try again.';
            err.classList.remove('hidden');
        });
    });

    // ── Add member ────────────────────────────────────────────────────────────
    var inviteNoEmailMode = false;

    function inviteSyncUsername() {
        var first    = (document.getElementById('inviteFirstName').value || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        var last     = (document.getElementById('inviteLastName').value  || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        var userEl   = document.getElementById('inviteUsername');
        var previewEl = document.getElementById('inviteUsernamePreview');
        if (userEl && !userEl.value) {
            userEl.value = [first, last].filter(Boolean).join('.');
        }
        if (previewEl) {
            previewEl.textContent = ((userEl && userEl.value.trim()) || 'username') + '@centryk.com';
        }
    }
    function inviteSetNoEmail(enabled) {
        inviteNoEmailMode = enabled;
        document.getElementById('inviteEmailWrap').classList.toggle('hidden', enabled);
        document.getElementById('inviteUsernameWrap').classList.toggle('hidden', !enabled);
        if (enabled) inviteSyncUsername();
    }
    document.getElementById('inviteNoEmailToggle').addEventListener('click', function () {
        inviteSetNoEmail(true);
        setTimeout(function () { document.getElementById('inviteUsername').focus(); }, 30);
    });
    document.getElementById('inviteBackToEmailBtn').addEventListener('click', function () {
        inviteSetNoEmail(false);
        setTimeout(function () { document.getElementById('inviteEmail').focus(); }, 30);
    });
    ['inviteFirstName','inviteLastName','inviteUsername'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', function () { if (inviteNoEmailMode) inviteSyncUsername(); });
    });

    document.getElementById('addMemberBtn').addEventListener('click', function () {
        ['inviteEmail','inviteFirstName','inviteLastName','invitePassword','inviteUsername'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
        document.getElementById('inviteRole').value = 'employee';
        document.getElementById('addMemberError').classList.add('hidden');
        inviteSetNoEmail(false);
        openModal('addMemberModal');
    });

    document.getElementById('inviteSubmitBtn').addEventListener('click', function () {
        var errEl = document.getElementById('addMemberError');
        var emailVal;
        if (inviteNoEmailMode) {
            var u = (document.getElementById('inviteUsername').value.trim() || '').toLowerCase().replace(/[^a-z0-9._-]/g, '');
            if (!u) { errEl.textContent = 'Username is required.'; errEl.classList.remove('hidden'); return; }
            emailVal = u + '@centryk.com';
        } else {
            emailVal = document.getElementById('inviteEmail').value.trim();
        }
        var payload = {
            company_id: currentCompany.id,
            email:      emailVal,
            first_name: document.getElementById('inviteFirstName').value.trim(),
            last_name:  document.getElementById('inviteLastName').value.trim(),
            password:   document.getElementById('invitePassword').value,
            role:       document.getElementById('inviteRole').value
        };

        if (!payload.email) { errEl.textContent = inviteNoEmailMode ? 'Username is required.' : 'Email is required.'; errEl.classList.remove('hidden'); return; }

        var btn = document.getElementById('inviteSubmitBtn');
        btn.textContent = 'Adding...';
        btn.disabled = true;

        fetch('api/companies/invite.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); })
        .then(function (data) {
            btn.textContent = 'Add Member';
            btn.disabled = false;
            if (!data.success) { errEl.textContent = data.message; errEl.classList.remove('hidden'); return; }
            closeModal('addMemberModal');
            loadMembers(currentCompany.id);
        });
    });

    // ── Remove member ─────────────────────────────────────────────────────────
    document.getElementById('confirmRemoveMemberBtn').addEventListener('click', function () {
        var btn    = this;
        var cid    = pendingRemove.companyId;
        var uid    = pendingRemove.userId;
        var revoke = document.getElementById('revokeAppAccessToggle').checked;
        if (!cid || !uid) { return; }
        btn.textContent = 'Removing...';
        btn.disabled = true;
        fetch('api/companies/remove-member.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ company_id: cid, user_id: uid, revoke_app_access: revoke })
        }).then(function (r) { return r.json(); })
        .then(function (data) {
            btn.textContent = 'Remove';
            btn.disabled = false;
            closeModal('removeMemberModal');
            if (!data.success) { alert(data.message); return; }
            loadMembers(cid);
        });
    });


    // ── Escape key closes modals ──────────────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('[id$="Modal"]').forEach(function (m) {
                m.classList.add('hidden');
                m.classList.remove('flex');
            });
        }
    });

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function showToast(msg, type) {
        var wrap  = document.getElementById('toastWrap');
        var toast = document.createElement('div');
        var isErr = type === 'error';
        toast.className = 'pointer-events-auto flex items-center gap-2.5 rounded-xl px-4 py-3 text-sm font-semibold shadow-lg '
            + (isErr ? 'bg-rose-600 text-white' : 'bg-slate-900 text-white');
        toast.innerHTML = (isErr
            ? '<svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>'
            : '<svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>')
            + '<span>' + escHtml(msg) + '</span>';
        wrap.appendChild(toast);
        setTimeout(function () { toast.style.opacity = '0'; setTimeout(function () { toast.remove(); }, 300); }, 3500);
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    loadCompanies();
})();
</script>
<div id="toastWrap" class="pointer-events-none fixed bottom-6 left-1/2 z-[60] flex -translate-x-1/2 flex-col items-center gap-2"></div>
<?php if ($embed): ?>
<script>
// Report our content height to the parent (Profile) so it can size the iframe.
(function () {
    function postHeight() {
        var h = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
        parent.postMessage({ type: 'centryk-embed-height', height: h }, '*');
    }
    window.addEventListener('load', postHeight);
    if (window.ResizeObserver) { new ResizeObserver(postHeight).observe(document.body); }
    document.addEventListener('click', function () { setTimeout(postHeight, 350); });
    setTimeout(postHeight, 400);
})();
</script>
<?php endif; ?>
</body>
</html>
