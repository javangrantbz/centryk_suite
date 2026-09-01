<?php
/**
 * The Centryk Platform — a public reference / capability document.
 *
 * Living page: keep it in step with what actually ships. Each <section> has a
 * stable id used by the sidebar table of contents. When a feature moves from
 * "in progress" to shipped, drop the amber tag.
 *
 * No auth — this is a pitch / reference page (like about.php).
 */
$updated = '30 August 2026';

/* Table of contents — [id, label]. Order = page order. */
$toc = [
    ['what', 'What Centryk is'],
    ['apps', 'The apps at a glance'],
    ['identity', 'One login, one company directory'],
    ['rbac', 'Role-based access control'],
    ['business', 'Centryk Business (paid tier)'],
    ['flows', 'How the modules talk to each other'],
    ['team', 'For your team — HR & payroll'],
    ['customers', 'For your customers'],
    ['connect', 'Centryk Connect'],
    ['reporting', 'Reporting'],
    ['security', 'Security & controls'],
    ['belize', 'Built for Belize'],
    ['qa', 'Questions & answers'],
    ['roadmap', 'What we are building next'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>The Centryk Platform — capability reference</title>
    <meta name="description" content="A full reference to the Centryk platform for Belizean businesses: the apps, how they connect, role-based access, Centryk Business (receivables, reconciliation, routes, multi-entity), reporting, security and Centryk Connect.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } };
    </script>
    <style>
        html { scroll-behavior: smooth; }
        body { scroll-padding-top: 5rem; }
        .grad { background: linear-gradient(90deg, #7c3aed, #3b82f6, #f97316); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; }
        .toc a.active { color: #7c3aed; font-weight: 700; border-color: #7c3aed; }
        .prose-c h3 { font-weight: 800; font-size: 1.05rem; margin-top: 1.6rem; color: #0f172a; }
        .prose-c p { margin-top: .5rem; color: #475569; line-height: 1.7; }
        .prose-c ul { margin-top: .6rem; }
        .prose-c li { margin-top: .3rem; color: #475569; line-height: 1.6; padding-left: 1.1rem; position: relative; }
        .prose-c li::before { content: '▸'; position: absolute; left: 0; color: #a78bfa; }
        .prose-c strong { color: #0f172a; font-weight: 700; }
        section { scroll-margin-top: 5rem; }
        details > summary { list-style: none; cursor: pointer; }
        details > summary::-webkit-details-marker { display: none; }
    </style>
</head>
<body class="bg-white font-sans text-slate-800 antialiased">

<!-- top bar -->
<header class="sticky top-0 z-40 border-b border-slate-100 bg-white/90 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
        <a href="index.php" class="flex items-center gap-2">
            <span class="grid h-7 w-7 place-items-center rounded-lg bg-gradient-to-br from-violet-600 to-blue-500 text-sm font-black text-white">C</span>
            <span class="text-lg font-black tracking-tight text-slate-900">Centryk</span>
            <span class="hidden text-sm font-semibold text-slate-400 sm:inline">· Platform reference</span>
        </a>
        <div class="flex items-center gap-2">
            <a href="about.php" class="rounded-lg px-3 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-800">About</a>
            <a href="login.php" class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-700">Sign in</a>
        </div>
    </div>
</header>

<!-- hero -->
<div class="border-b border-slate-100 bg-slate-50">
    <div class="mx-auto max-w-6xl px-4 py-14">
        <p class="text-xs font-black uppercase tracking-[0.2em] text-violet-600">Capability reference</p>
        <h1 class="mt-2 max-w-3xl text-3xl font-black leading-tight tracking-tight text-slate-900 sm:text-4xl">
            One platform for the whole business — <span class="grad">operations, money, people and customers</span>.
        </h1>
        <p class="mt-4 max-w-2xl text-base font-medium leading-relaxed text-slate-600">
            Centryk is the identity and company hub for a suite of connected apps built for Belizean
            businesses. This page is the full reference — every module, how they share data, who can
            see what, and how it is secured. It is kept in step with what ships.
        </p>
        <p class="mt-4 text-xs font-semibold text-slate-400">Last updated <?= htmlspecialchars($updated) ?></p>
    </div>
</div>

<!-- body: sticky TOC + content -->
<div class="mx-auto max-w-6xl gap-10 px-4 py-12 lg:flex">

    <!-- desktop TOC -->
    <aside class="hidden w-60 shrink-0 lg:block">
        <nav class="toc sticky top-20 space-y-1 text-sm">
            <p class="mb-2 text-[11px] font-black uppercase tracking-[0.15em] text-slate-400">On this page</p>
            <?php foreach ($toc as [$id, $label]): ?>
            <a href="#<?= $id ?>" class="block border-l-2 border-transparent py-1 pl-3 font-semibold text-slate-500 transition hover:text-slate-900"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <!-- mobile jump menu -->
    <details class="mb-8 rounded-xl border border-slate-200 bg-slate-50 p-3 lg:hidden">
        <summary class="flex items-center justify-between text-sm font-bold text-slate-700">Jump to a section <span class="text-slate-400">▾</span></summary>
        <div class="mt-2 grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
            <?php foreach ($toc as [$id, $label]): ?>
            <a href="#<?= $id ?>" class="rounded px-2 py-1 font-semibold text-violet-700 hover:bg-white"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </div>
    </details>

    <div class="prose-c min-w-0 flex-1 space-y-16">

    <!-- ═══ WHAT ═══ -->
    <section id="what">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">What Centryk is</h2>
        <p>
            Centryk is a <strong>hub-and-spoke platform</strong>. The hub owns the shared user and
            company directory, single sign-on, the calendar, notifications, payment links and the
            invoice engine. The spokes are focused apps — point of sale, payroll, and more — that
            authenticate against the hub and read the same roster and identity.
        </p>
        <p>
            A business signs up once. From then on every person and every company exists in one
            place. Turn an app on for the company and the right people can use it immediately with
            the same login — no separate accounts, no re-keying staff lists, no exported
            spreadsheets moving between systems.
        </p>
        <h3>Why it is built this way</h3>
        <ul>
            <li><strong>One source of truth for people and companies.</strong> Hire someone in payroll and they are already known to the POS.</li>
            <li><strong>Data flows instead of being re-entered.</strong> A sale becomes an invoice becomes a customer-account entry becomes a line the bank statement reconciles against.</li>
            <li><strong>Add capability without adding logins.</strong> New modules appear in the app switcher for the people entitled to them.</li>
        </ul>
    </section>

    <!-- ═══ APPS ═══ -->
    <section id="apps">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">The apps at a glance</h2>
        <p>Every app shares the login, the company directory and the notification centre. They are grouped in the dashboard as Business, Centryk Business, Finance, Insights, Operations and Marketing.</p>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <?php
            $apps = [
                ['OnePay', 'Inventory &amp; point of sale', 'Ring up sales, track stock, price breaks for bulk, publish items to the storefront, and (optionally) mirror each checkout into an invoice on the customer\'s account.'],
                ['MyPay', 'HR &amp; payroll', 'Staff records, attendance, pay runs with Belize PAYE and Social Security, payslips, TD4 employee slips, the monthly SSB and PAYE returns, and job-vacancy forms for recruitment.'],
                ['Invoices', 'Quotes, invoices &amp; receipts', 'The shared invoice engine — hosted in the hub, used by OnePay and by Centryk Business Receivables. Send by email, share a link, or export a PDF.'],
                ['Calendar', 'Shared scheduling', 'A company calendar surfaced across the suite, with a drawer preview on every page.'],
                ['Centryk Forms', 'Surveys, polls &amp; feedback', 'Build a form — short text, choices, ratings, yes/no — open it, and share a public link. Responses collect in the hub with a per-question summary and CSV export.'],
                ['Vision Board', 'Digital signage', 'Run the screens in your store or lobby — playlists, promotions, marquee messages, QR codes — driven from your Centryk data: inventory turns into low-stock and promo slides, the calendar into today\'s events.'],
                ['Centryk TV', 'Live broadcasting &amp; ticketing', 'Browser-based live streaming of an event with pay-per-view tickets sold through OneLink (coming soon).'],
                ['Storefront', 'Public menu / catalog', 'Every company gets a public page at a short link — menu, catalog, contact, and a link to pay. Choose which OnePay items appear and to which audience.'],
                ['OneLink', 'Payment links &amp; collections', 'Company-scoped view of what has been collected across POS, invoices and payment forms.'],
            ];
            foreach ($apps as [$n, $tag, $desc]): ?>
            <div class="rounded-xl border border-slate-200 p-4">
                <p class="text-sm font-black text-slate-900"><?= $n ?></p>
                <p class="text-xs font-bold uppercase tracking-wide text-violet-600"><?= $tag ?></p>
                <p class="mt-1.5 text-sm leading-relaxed text-slate-600"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="mt-5">
            <strong>Centryk Business</strong> is different — it is a paid capability tier layered on the
            free hub, not a separate app. It is covered in its own section below.
        </p>
    </section>

    <!-- ═══ IDENTITY ═══ -->
    <section id="identity">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">One login, one company directory</h2>
        <p>
            The hub holds <strong>users</strong>, <strong>companies</strong>, and the
            <strong>membership</strong> that connects them (with a role). Every app trusts that.
        </p>
        <h3>Single sign-on</h3>
        <p>
            When someone opens a spoke app from the dashboard, the hub mints a one-time,
            60-second token. The app redeems it and receives the user together with their
            companies and the apps they are enrolled in. There is no second password to manage.
        </p>
        <h3>Provisioning &amp; the roster</h3>
        <p>
            Spoke apps call the hub over a shared secret to pull a company's roster, provision a
            user, or check someone's status. Add a staff member once and they propagate. Change
            their role or deactivate them and every app sees it.
        </p>
        <h3>The app switcher</h3>
        <p>
            Each person sees only the apps they are enrolled in. Enrolment is per-user and global
            to the person, so a manager who works across two companies carries their app access
            with them.
        </p>
    </section>

    <!-- ═══ RBAC ═══ -->
    <section id="rbac">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Role-based access control</h2>
        <p>Access is decided at three layers that stack. A request has to pass all of them.</p>

        <h3>1 · Company role</h3>
        <p>Every membership carries one role:</p>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[520px] border-collapse text-sm">
                <thead><tr class="border-b-2 border-slate-300 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="py-2 pr-4">Role</th><th class="py-2 pr-4">Can do</th><th class="py-2">Cannot do</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr><td class="py-2 pr-4 font-bold text-slate-800">Admin</td><td class="py-2 pr-4">Everything for the company — settings, members, billing, and every approval (settlement sign-off, write-off approval, credit-hold override)</td><td class="py-2 text-slate-400">—</td></tr>
                    <tr><td class="py-2 pr-4 font-bold text-slate-800">Manager</td><td class="py-2 pr-4">Day-to-day operations — record payments, run routes, reconcile the bank, propose a write-off, import customers</td><td class="py-2 text-slate-500">Approve write-offs or settlements, change commission rules, manage members or billing</td></tr>
                    <tr><td class="py-2 pr-4 font-bold text-slate-800">Employee</td><td class="py-2 pr-4">The apps they are enrolled in — clock in, run their assigned delivery route, use the POS</td><td class="py-2 text-slate-500">See company-wide financials or management tools</td></tr>
                </tbody>
            </table>
        </div>

        <h3>2 · App enrolment</h3>
        <p>
            A person only sees an app if they are enrolled in it. A cashier gets OnePay; they do
            not get payroll. Enrolment is granted at onboarding and adjusted any time.
        </p>

        <h3>3 · Business entitlements</h3>
        <p>
            Centryk Business modules are gated per <strong>company</strong> by an entitlement with a
            level — <strong>full</strong>, <strong>read-only</strong> (view and export while billing
            is being sorted), or <strong>none</strong>. This is enforced on the server for every
            action, not just hidden in the interface, and it is separate from the person's role.
        </p>

        <h3>Groups add a fourth layer</h3>
        <p>
            A company group (multi-entity) has its own roles — <strong>group admin</strong> and
            <strong>group viewer</strong> — and can hold an entitlement that every member company
            inherits. The most permissive of a company's own entitlement and its group's applies.
        </p>

        <h3>Maker–checker where money leaves the books</h3>
        <ul>
            <li><strong>Driver cash settlement</strong> — whoever runs the route declares the cash; a company admin approves before it locks.</li>
            <li><strong>Write-offs</strong> — a manager proposes; a different admin approves. Self-approval is allowed for a one-admin company but is flagged loudly in the audit trail.</li>
            <li><strong>Credit hold</strong> — issuing an invoice to a customer over their limit or on hold is blocked; an override is possible and audited.</li>
        </ul>
    </section>

    <!-- ═══ BUSINESS ═══ -->
    <section id="business">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Centryk Business — the paid tier</h2>
        <p>
            The hub is free. <strong>Centryk Business</strong> is an optional subscription that
            switches on capability for a company — five packages: Accounting, Receivables,
            Reconciliation, Field Sales &amp; Routes, and Enterprise.
        </p>

        <div class="mt-4 rounded-xl border border-violet-200 bg-violet-50 p-5">
            <p class="text-[11px] font-black uppercase tracking-[0.14em] text-violet-600">Free preview · limited time</p>
            <p class="mt-1 text-base font-black text-slate-900">Every Centryk Business package is free to use until 31 December 2027.</p>
            <p class="mt-1 text-sm leading-relaxed text-slate-600">
                We roll out officially in January 2027 and paid plans begin
                <strong>1 January 2028</strong>. Until then any company can switch on the whole tier —
                the general ledger, receivables, bank reconciliation, delivery routes, multi-entity
                structure — from the <strong>Explore more services</strong> page, in one click, with
                no card and no commitment. Turn on the whole tier at once or one package at a time,
                yourself. The earlier you start, the longer you have. A Centryk advisor is there to
                help whenever you want one — never a requirement.
            </p>
        </div>

        <div class="mt-5 space-y-5">
            <div class="rounded-xl border border-slate-200 p-5">
                <p class="text-base font-black text-slate-900">Accounting</p>
                <p class="mt-1 text-sm leading-relaxed text-slate-600">
                    A double-entry general ledger the accounting department works in directly — not a
                    bolt-on to something else. A <strong>Belize starter chart of accounts</strong> (or
                    import your own, including a QuickBooks account list), manual journals with a
                    live balanced check, accounting periods you can lock, and the statements built on
                    them: <strong>trial balance, profit &amp; loss, balance sheet</strong> and
                    general-ledger drill-down. <strong>Expenses and bills</strong> with GST-input
                    tracking and an accounts-payable balance. Once switched on, every invoice, receipt
                    and write-off from Receivables <strong>auto-posts to the ledger</strong> (opening
                    balance taken on the day you start), and a posted payroll run in myPay posts its
                    own journal. Books stay tied to the subledgers to the cent.
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 p-5">
                <p class="text-base font-black text-slate-900">Receivables</p>
                <p class="mt-1 text-sm leading-relaxed text-slate-600">
                    A proper customer ledger over the invoicing you already do. Credit limits and
                    payment terms, a running balance and aging, printable and emailable statements,
                    a collections work-list with drafted and sent reminders, month-end statement
                    runs, bulk customer import, <strong>cheque tracking</strong> (uncleared,
                    post-dated, cleared, bounced — a bounce reverses the receipt), and
                    <strong>write-offs and credit adjustments</strong> (full or partial, approved
                    by a second person, fully reversible) with a bad-debt report. Credit hold is
                    enforced at the point an invoice is issued.
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 p-5">
                <p class="text-base font-black text-slate-900">Reconciliation</p>
                <p class="mt-1 text-sm leading-relaxed text-slate-600">
                    Import a bank statement (CSV, OFX/QFX, or MT940), and Centryk matches deposits to
                    open invoices — with one-click auto-match for the confident ones. Give customers a
                    <strong>payment reference</strong> to quote on a transfer so it matches itself.
                    <strong>Auto-ignore rules</strong> keep bank charges and interest out of the queue.
                    A card / OnePay <strong>settlement deposit is matched against the whole batch</strong>
                    of small sales it represents, in one action. Export any view to CSV.
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 p-5">
                <p class="text-base font-black text-slate-900">Field Sales &amp; Routes</p>
                <p class="mt-1 text-sm leading-relaxed text-slate-600">
                    Plan delivery runs, record what each stop pays (the money posts straight to the
                    customer account), and settle the driver at the end of the day — expected cash
                    versus what was handed in, variance flagged, an admin signs off. Drivers get a
                    <strong>phone-first view</strong> of just their runs. Per-driver
                    <strong>commission</strong> — a company / route / driver rate on collections
                    (including an electronic-only rate that rewards moving off cash) with a printable
                    payroll statement.
                </p>
            </div>
            <div class="rounded-xl border border-slate-200 p-5">
                <p class="text-base font-black text-slate-900">Enterprise (company groups)</p>
                <p class="mt-1 text-sm leading-relaxed text-slate-600">
                    Run several companies as one organisation. A consolidated view of receivables,
                    cash in transit and unmatched deposits across the group, a group-wide activity
                    feed, a printable consolidated AR aging report, and group-level packages the
                    member companies inherit.
                </p>
            </div>
        </div>

        <h3>On top of the packages</h3>
        <ul>
            <li><strong>Business Insights</strong> — a finance dashboard per company: days sales outstanding, collection ratio, aging, write-off rate, cash in transit, reconciliation match rate.</li>
            <li><strong>Belize GST summary</strong> — a monthly output-tax working sheet from your sales, with bad-debt relief and a 12.5% GST-inclusive back-out for invoices with no tax split. A working summary to help prepare the return — not the return, and not tax advice.</li>
            <li><strong>Subscription billing</strong> — monthly charges, and a dunning sweep that drops an overdue subscription to read-only and restores it on payment.</li>
        </ul>
    </section>

    <!-- ═══ FLOWS ═══ -->
    <section id="flows">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">How the modules talk to each other</h2>
        <p>The value is in the hand-offs. A few of the main flows:</p>

        <h3>A sale becomes a reconciled deposit</h3>
        <ul>
            <li>A checkout in <strong>OnePay</strong> mirrors into the <strong>invoice engine</strong> as an invoice on the customer's account (walk-ins go to an anonymous customer).</li>
            <li>If the company runs <strong>Receivables</strong>, a paid electronic sale auto-posts as a receipt on that customer's ledger.</li>
            <li>When the card processor's <strong>settlement deposit</strong> lands in the bank statement, <strong>Reconciliation</strong> recognises it as the sum of that day's batch and matches them all at once.</li>
        </ul>

        <h3>A delivery becomes cash on the account</h3>
        <ul>
            <li>A driver records a collection on a <strong>route</strong> stop.</li>
            <li>It posts as a receipt against the customer's <strong>Receivables</strong> balance immediately.</li>
            <li>End of day, the driver's declared cash is settled and an admin approves; a <strong>variance</strong> notifies the company.</li>
            <li>The bank deposit of that cash reconciles against the settled trip.</li>
        </ul>

        <h3>Inventory becomes a storefront</h3>
        <ul>
            <li>From <strong>OnePay</strong> inventory (or the hub&rsquo;s <em>Sell on Store</em> page) you pick which items go on the <strong>storefront</strong> and to whom &mdash; staff only, the public Centryk Market, or everyone.</li>
            <li>Listed items track the OnePay item&rsquo;s live price and photo; an <em>On Store</em> badge in OnePay shows what&rsquo;s already public.</li>
            <li>A shopper opens the page at your short link, and a payment link on it collects through <strong>OneLink</strong>.</li>
        </ul>

        <h3>Identity everywhere</h3>
        <ul>
            <li>Hire in <strong>MyPay</strong> → the person is provisioned in the hub → they appear on the <strong>OnePay</strong> roster and can be enrolled in the POS.</li>
            <li>Deactivate them once → every app loses their access.</li>
        </ul>

        <h3>Consolidation for groups</h3>
        <ul>
            <li>Each company keeps its own books.</li>
            <li>The <strong>group</strong> view fans out over the member companies for whichever metrics each one is entitled to, and rolls them up.</li>
        </ul>

        <h3>Your data on the wall</h3>
        <ul>
            <li>A screen paired to <strong>Vision Board</strong> pulls its playlist by device token — never a public URL, so in-store content can safely include information a passer-by should not see.</li>
            <li>The direction is data-driven slides rather than static posters: <strong>OnePay</strong> stock into low-stock and promotion slides, the <strong>calendar</strong> into today's events, <strong>MyPay</strong> into birthdays and new-hire welcomes.</li>
        </ul>
    </section>

    <!-- ═══ TEAM ═══ -->
    <section id="team">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">For your team — HR &amp; payroll</h2>
        <p>
            The same login that runs the business is the one your staff use for their own work.
            <strong>MyPay</strong> is the payroll and HR spoke.
        </p>
        <ul>
            <li><strong>Staff records</strong> drawn from the shared company roster — no duplicate list to keep.</li>
            <li><strong>Attendance</strong> — clock in and out; the data feeds the pay run.</li>
            <li><strong>Pay runs</strong> — calculate wages, income tax and Social Security, and produce pay slips.</li>
            <li><strong>Recruitment</strong> — publish a job vacancy with a public application form; applications come back into MyPay and are visible on the Centryk job board.</li>
            <li><strong>Belize statutory</strong> — PAYE and Social Security are calculated on the real contribution tables and stored per pay run. The monthly SSB and PAYE returns and the annual TD4 are prepared for you; <strong>TD4 slips</strong> are issued to each employee (bulk or one at a time, printable and emailable) and appear in the employee's own portal.</li>
        </ul>
        <p>
            An employee opening Centryk sees a focused view — the apps they are enrolled in, their
            own attendance and pay information, the company calendar. They do not see the company's
            financials.
        </p>
    </section>

    <!-- ═══ CUSTOMERS ═══ -->
    <section id="customers">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">For your customers</h2>
        <p>Centryk gives every company a public presence without a separate website build.</p>
        <ul>
            <li><strong>Storefront</strong> — a public page per company with your menu or catalog, contact details and hours, reachable at a short link (<code class="rounded bg-slate-100 px-1 text-xs">/s/your-company</code>) you can put on a card, a bag or a sign.</li>
            <li><strong>Menus &amp; catalogs</strong> — driven from the same product and price data as the POS, so what a customer sees is what they are charged. Bulk price breaks show as a ladder.</li>
            <li><strong>You choose what&rsquo;s listed</strong> — publish OnePay items to the storefront one at a time or in bulk, each to an audience: <em>Employees only</em>, <em>Centryk Market</em> (the public store), or <em>Everyone</em> — with an optional start / end date. Nothing is public until you list it, and it stays in sync with the item&rsquo;s live price and photo.</li>
            <li><strong>Pay online</strong> — a payment link on the storefront and on every invoice; collections roll up in OneLink.</li>
            <li><strong>Shared statements</strong> — email a customer their statement of account, or send them a link; account customers can see exactly what they owe and why.</li>
            <li><strong>Public discovery</strong> — an opt-in business directory and a job board that list companies and vacancies across the platform.</li>
        </ul>
    </section>

    <!-- ═══ CONNECT ═══ -->
    <section id="connect">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Centryk Connect</h2>
        <p>
            Businesses on Centryk can connect to each other — a supplier and a shop, a group of
            partners, a franchise and its locations.
        </p>
        <ul>
            <li><strong>Partnership profiles</strong> — a company decides who it connects with; connecting is an admin decision on both sides.</li>
            <li><strong>Shared campaigns &amp; events</strong> — put a promotion or an event in front of a connected partner.</li>
            <li><strong>Messaging</strong> — a direct channel between connected companies, with a partner inbox / action queue.</li>
        </ul>
        <p>
            Connect is about companies working together on the platform without exposing their
            internal data to each other — you share what you choose to share.
        </p>
    </section>

    <!-- ═══ REPORTING ═══ -->
    <section id="reporting">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Reporting</h2>
        <p>Everything below is available today for a company on the relevant package.</p>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[560px] border-collapse text-sm">
                <thead><tr class="border-b-2 border-slate-300 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="py-2 pr-4">Report</th><th class="py-2 pr-4">What it shows</th><th class="py-2">Output</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 align-top">
                    <tr><td class="py-2 pr-4 font-bold">AR aging</td><td class="py-2 pr-4">Whole-company receivables split current / 1–30 / 31–60 / 61–90 / 90+, with credit flags</td><td class="py-2">Screen · print / PDF</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Customer statement</td><td class="py-2 pr-4">Chronological ledger with running balance, reconciles exactly however a payment was recorded</td><td class="py-2">Screen · print · email</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Collections list</td><td class="py-2 pr-4">Overdue accounts worst-first, when each was last chased</td><td class="py-2">Screen</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Bad-debt report</td><td class="py-2 pr-4">Write-offs by reason, write-off rate against sales, amounts awaiting approval</td><td class="py-2">Screen</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Cheque register</td><td class="py-2 pr-4">Cheques uncleared / post-dated / cleared / bounced, days held, drawee bank</td><td class="py-2">Screen</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">GST summary</td><td class="py-2 pr-4">Monthly output tax from sales, bad-debt relief, net for the return</td><td class="py-2">Screen · print</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Driver performance</td><td class="py-2 pr-4">Per driver: trips, stops, cash vs electronic, variance, flags (30 days)</td><td class="py-2">Screen</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Commission statement</td><td class="py-2 pr-4">Per driver, per trip, for a pay period</td><td class="py-2">Screen · print / PDF</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Bank-line export</td><td class="py-2 pr-4">Unmatched / matched / ignored lines with the matched invoice</td><td class="py-2">CSV</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Group consolidated aging</td><td class="py-2 pr-4">AR aging summed across every company in a group</td><td class="py-2">Screen · print / PDF</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Business Insights</td><td class="py-2 pr-4">DSO, collection ratio, over-90, cash in transit, match rate, write-off rate</td><td class="py-2">Screen</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Activity feed</td><td class="py-2 pr-4">Every action across a company's — or a group's — modules, who and when</td><td class="py-2">Screen</td></tr>
                    <tr><td class="py-2 pr-4 font-bold">Billing summary</td><td class="py-2 pr-4">MRR, billed and collected this month, outstanding, overdue</td><td class="py-2">Screen</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- ═══ SECURITY ═══ -->
    <section id="security">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Security &amp; controls</h2>

        <h3>Access</h3>
        <ul>
            <li>Session-based login for people; passwords are stored hashed, never in plain text.</li>
            <li>Single sign-on tokens are one-time and expire in 60 seconds.</li>
            <li>App-to-app calls (roster, provisioning, payment sync) authenticate with a shared secret compared in constant time — never in a URL.</li>
            <li>Every Business action is checked server-side against the company's entitlement, not just hidden in the interface.</li>
        </ul>

        <h3>Data integrity</h3>
        <ul>
            <li>All database access uses parameterised statements — no query is built by pasting values into text.</li>
            <li>Money movement is transactional: a receipt and its allocations, or a settlement and its links, either all commit or none do.</li>
            <li>Idempotent sync — re-posting the same OnePay sale, or re-importing the same bank line, does not double-count.</li>
        </ul>

        <h3>The audit trail</h3>
        <p>
            Grants and revocations, payments and voids, write-off proposals and approvals,
            settlement sign-offs, commission-rule changes, credit-hold overrides — each writes an
            entry with the actor, the company, a summary and the details. Self-approval by a
            one-admin company is recorded as such. Login events are always logged and never block a
            login.
        </p>

        <h3>Separation of duties</h3>
        <p>
            The maker–checker points listed under access control exist precisely so that no single
            person can move money off the books unobserved.
        </p>

        <h3>Hosting</h3>
        <p>
            Centryk runs on managed cloud hosting with database backups. Deployments are deliberate —
            schema changes are applied and reviewed before code goes live.
        </p>
    </section>

    <!-- ═══ BELIZE ═══ -->
    <section id="belize">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Built for Belize</h2>
        <p>Centryk is designed around how Belizean businesses actually run.</p>
        <ul>
            <li><strong>Belize dollars</strong> throughout.</li>
            <li><strong>GST at 12.5%</strong> — the monthly output-tax summary, and GST-inclusive pricing handled correctly.</li>
            <li><strong>A cash-and-cheque economy</strong> — route settlement and cash-in-transit tracking exist because a lot of money still moves by hand on delivery runs. <strong>Cheque tracking</strong> gives every cheque a lifecycle — uncleared, post-dated, cleared, or bounced — and a bounced cheque automatically reverses the receipt so the customer owes again.</li>
            <li><strong>Districts</strong> — territory reporting by Corozal, Orange Walk, Belize, Cayo, Stann Creek and Toledo is planned for customers and routes.</li>
            <li><strong>The modernisation push</strong> — the impetus for Centryk Business was the call from major Belizean companies to move off cash on delivery routes, have electronic payments post straight to a customer account, and cut manual reconciliation. Those three are exactly what Receivables, Routes and Reconciliation do.</li>
        </ul>
    </section>

    <!-- ═══ Q&A ═══ -->
    <section id="qa">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">Questions &amp; answers</h2>
        <div class="mt-4 space-y-3">
            <?php
            $qa = [
                ['Is Centryk free?', 'The hub — login, company directory, calendar, notifications, the storefront, the invoice engine, and the free apps — is free. OneLink charges a small transactional fee on collections. Centryk Business will be a monthly subscription (per package, per company) from January 2028 — but every package is free to use until 31 December 2027.'],
                ['How does a company get Centryk Business?', 'Until 31 December 2027 a company admin can switch on the whole tier for free in one click from the "Explore more services" page — no card, no advisor call. After the free preview, an advisor helps you choose the packages you want, or a company admin requests one (which creates a lead, not an activation).'],
                ['Does turning on Centryk Business change our existing free features?', 'No. Centryk Business is only ever new capability on top. Nothing that was free moves behind the subscription.'],
                ['Can one person work across several companies?', 'Yes. A person can be a member of many companies, each with its own role, and their app enrolment travels with them.'],
                ['What if a subscription lapses?', 'The package drops to read-only — you can still see and export your data — and returns to full access when billing is resolved. Data is never deleted for non-payment.'],
                ['Who can approve a write-off or a driver settlement?', 'A company admin. The person who proposed or submitted it can approve their own only if they are the company\'s only admin, and that is flagged in the audit trail.'],
                ['Can a driver see other people\'s pay or the company accounts?', 'No. The driver view shows only the runs assigned to that person. Commission rates and totals are visible to managers and admins, not drivers.'],
                ['How does a card sale end up reconciled?', 'The sale posts a receipt to the customer account. When the processor\'s settlement deposit lands in your bank statement, Centryk recognises it as the batch of that day\'s card receipts and matches them together in one click.'],
                ['Do we need a bank integration for reconciliation to be useful?', 'No. You upload the bank statement (CSV, OFX/QFX or MT940). A live bank feed depends on banking infrastructure that is not yet in place; the import-based workbench delivers the value now.'],
                ['Can customers see what they owe?', 'Yes — email them a statement or send a link. Account customers see their ledger and balance; they do not see anything else about your business.'],
                ['Is the GST summary our GST return?', 'No. It is a working summary to help prepare the return, built from your sales. Confirm the treatment of zero-rated and exempt supplies with your accountant. It is not tax advice.'],
                ['Where is our data hosted?', 'On managed cloud hosting with regular database backups. Schema changes are applied deliberately and reviewed before new code is released.'],
                ['Can we run our group of companies together?', 'Yes — the Enterprise package. Each company keeps its own books; the group gives you a consolidated view and lets you grant packages once for everyone.'],
            ];
            foreach ($qa as [$q, $a]): ?>
            <details class="rounded-xl border border-slate-200 p-4">
                <summary class="flex items-center justify-between gap-3 text-sm font-bold text-slate-900">
                    <span><?= htmlspecialchars($q) ?></span><span class="text-slate-300">＋</span>
                </summary>
                <p class="mt-2 text-sm leading-relaxed text-slate-600"><?= htmlspecialchars($a) ?></p>
            </details>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ═══ ROADMAP ═══ -->
    <section id="roadmap">
        <h2 class="text-2xl font-black tracking-tight text-slate-900">What we are building next</h2>
        <ul>
            <li><strong>Cheque handling extras</strong> — auto-invoice a bounce fee, alert when a post-dated cheque comes due, printable deposit slips.</li>
            <li><strong>Districts</strong> on customers and routes for territory reporting.</li>
            <li><strong>GST return v2</strong> — input tax from purchases, a payment (cash) basis option, and a GST-102/103 export.</li>
            <li><strong>Business Tax estimate</strong> — Belize's turnover-based tax alongside the GST summary.</li>
            <li><strong>MyPay</strong> — match the official Income Tax Department TD4 form exactly; capture the TD1 employee declaration.</li>
            <li><strong>Credit-hold at the till</strong> — the same check OnePay checkout and the invoice screen.</li>
            <li><strong>Route optimisation</strong> — maps and stop ordering for delivery runs.</li>
            <li><strong>Live bank feeds</strong> — when the banking infrastructure allows it.</li>
        </ul>
        <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-500">
            This page is kept current as features ship. If something here matters to your decision
            and you want to see it working, ask for a walkthrough.
        </p>
    </section>

    </div>
</div>

<!-- footer -->
<footer class="border-t border-slate-100 bg-slate-50">
    <div class="mx-auto flex max-w-6xl flex-col items-start justify-between gap-3 px-4 py-8 sm:flex-row sm:items-center">
        <p class="text-xs font-semibold text-slate-400">Centryk — the business platform for Belize. Reference updated <?= htmlspecialchars($updated) ?>.</p>
        <div class="flex gap-4 text-xs font-bold text-slate-500">
            <a href="about.php" class="hover:text-slate-800">About</a>
            <a href="contact.php" class="hover:text-slate-800">Contact</a>
            <a href="terms.php" class="hover:text-slate-800">Terms</a>
            <a href="login.php" class="hover:text-slate-800">Sign in</a>
        </div>
    </div>
</footer>

<button id="toTop" class="fixed bottom-6 right-6 hidden h-10 w-10 place-items-center rounded-full bg-slate-900 text-white shadow-lg hover:bg-slate-700" aria-label="Back to top">↑</button>

<script>
// active section in the TOC
(function () {
    var links = Array.prototype.slice.call(document.querySelectorAll('.toc a'));
    var map = {};
    links.forEach(function (a) { map[a.getAttribute('href').slice(1)] = a; });
    var obs = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (e.isIntersecting) {
                links.forEach(function (a) { a.classList.remove('active'); });
                if (map[e.target.id]) map[e.target.id].classList.add('active');
            }
        });
    }, { rootMargin: '-20% 0px -70% 0px' });
    document.querySelectorAll('section[id]').forEach(function (s) { obs.observe(s); });
})();

// back to top
(function () {
    var b = document.getElementById('toTop');
    window.addEventListener('scroll', function () { b.style.display = window.scrollY > 500 ? 'grid' : 'none'; });
    b.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
})();
</script>

</body>
</html>
