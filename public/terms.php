<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Terms &amp; Privacy — Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>
        html { scroll-behavior: smooth; }
        .prose-centryk h2 { font-size: 1.125rem; font-weight: 900; color: #0f172a; margin-top: 2rem; margin-bottom: 0.5rem; letter-spacing: -0.01em; }
        .prose-centryk h3 { font-size: 0.875rem; font-weight: 800; color: #1e293b; margin-top: 1.25rem; margin-bottom: 0.25rem; }
        .prose-centryk p  { font-size: 0.875rem; font-weight: 600; line-height: 1.75; color: #475569; margin-bottom: 0.75rem; }
        .prose-centryk ul { margin-bottom: 0.75rem; padding-left: 1.25rem; list-style: disc; }
        .prose-centryk li { font-size: 0.875rem; font-weight: 600; line-height: 1.75; color: #475569; }
        .prose-centryk a  { color: #1d4ed8; text-decoration: underline; }
    </style>
</head>
<body class="bg-white font-sans antialiased text-slate-900">

<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500 sticky top-0 z-50"></div>

<!-- NAV -->
<nav class="sticky top-[3px] z-40 border-b border-slate-200 bg-white/95 backdrop-blur-sm">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-6 py-3">
        <a href="index.php" class="flex items-center">
            <img src="../centryk_logo.png" alt="Centryk" class="h-14 w-auto">
        </a>
        <div class="flex-1"></div>
        <div class="hidden md:flex items-center gap-2">
            <?php $awAlign = 'left'; include __DIR__ . '/partials/app_switcher.php'; ?>
            <div class="h-4 w-px bg-slate-200"></div>
            <a href="about.php"   class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition">About</a>
            <a href="contact.php" class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition">Contact</a>
        </div>
        <a href="login.php" class="ml-3 rounded-xl bg-slate-900 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-700">Sign In</a>
    </div>
</nav>

<!-- HERO -->
<section class="relative overflow-hidden bg-[linear-gradient(135deg,#0d1117_0%,#0f1928_50%,#111d35_100%)] px-6 py-16 text-white">
    <div class="absolute left-0 top-0 h-80 w-80 rounded-full bg-blue-700/10 blur-3xl -translate-x-1/2 -translate-y-1/4"></div>
    <div class="relative mx-auto max-w-3xl">
        <span class="inline-block rounded-full border border-white/15 bg-white/8 px-4 py-1.5 text-[11px] font-black uppercase tracking-[0.22em] text-white/60 mb-5">Legal</span>
        <h1 class="text-4xl font-black leading-tight tracking-tight">Terms &amp; Privacy</h1>
        <p class="mt-4 text-base font-semibold leading-relaxed text-white/55 max-w-xl">
            These documents govern your use of Centryk and our applications. Please read them carefully.
        </p>
        <div class="mt-6 flex flex-wrap gap-3">
            <a href="#terms"   class="rounded-xl border border-white/15 bg-white/8 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white/80 transition hover:bg-white/15">Terms of Service</a>
            <a href="#privacy" class="rounded-xl border border-white/15 bg-white/8 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white/80 transition hover:bg-white/15">Privacy Policy</a>
        </div>
        <p class="mt-5 text-[11px] font-bold text-white/30 uppercase tracking-[0.16em]">Effective date: <?php echo date('F j, Y'); ?> &nbsp;·&nbsp; Governed by the laws of Belize</p>
    </div>
</section>

<!-- CONTENT -->
<div class="mx-auto max-w-4xl px-6 py-14">
    <div class="grid gap-10 lg:grid-cols-[200px_1fr] items-start">

        <!-- Sticky sidebar -->
        <div class="hidden lg:block sticky top-20">
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400 mb-3">Contents</p>
            <nav class="space-y-1">
                <a href="#terms"              class="block text-xs font-bold text-slate-600 hover:text-slate-900 py-1 transition">Terms of Service</a>
                <a href="#terms-acceptance"   class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Acceptance</a>
                <a href="#terms-accounts"     class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Accounts</a>
                <a href="#terms-usage"        class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Acceptable Use</a>
                <a href="#terms-payment"      class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Payment Processing</a>
                <a href="#terms-ip"           class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Intellectual Property</a>
                <a href="#terms-liability"    class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Liability</a>
                <a href="#terms-termination"  class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Termination</a>
                <a href="#terms-law"          class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Governing Law</a>
                <div class="h-px bg-slate-200 my-2"></div>
                <a href="#privacy"            class="block text-xs font-bold text-slate-600 hover:text-slate-900 py-1 transition">Privacy Policy</a>
                <a href="#privacy-collect"    class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Data We Collect</a>
                <a href="#privacy-use"        class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">How We Use It</a>
                <a href="#privacy-sharing"    class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Sharing</a>
                <a href="#privacy-security"   class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Security</a>
                <a href="#privacy-rights"     class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Your Rights</a>
                <a href="#privacy-contact"    class="block text-xs font-semibold text-slate-400 hover:text-slate-700 py-0.5 pl-3 transition">Contact</a>
            </nav>
        </div>

        <!-- Main content -->
        <div class="prose-centryk">

            <!-- TERMS OF SERVICE -->
            <div id="terms" class="rounded-2xl border border-slate-200 bg-white p-7 shadow-sm mb-8">
                <div class="mb-6 pb-5 border-b border-slate-100">
                    <span class="inline-block rounded-full bg-slate-900 px-3 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-white mb-3">Terms of Service</span>
                    <h1 class="text-2xl font-black tracking-tight text-slate-950">Centryk Terms of Service</h1>
                    <p class="text-xs font-bold text-slate-400 mt-1">Last updated: <?php echo date('F j, Y'); ?></p>
                </div>

                <div id="terms-acceptance">
                    <h2>1. Acceptance of Terms</h2>
                    <p>By accessing or using Centryk and its associated applications — including OnePay and MyPay — you agree to be bound by these Terms of Service. If you do not agree, do not use the platform. These terms apply to all users, including company administrators, managers, and employees.</p>
                </div>

                <div id="terms-accounts">
                    <h2>2. Accounts &amp; Access</h2>
                    <p>Access to Centryk is granted by invitation or approval from our team. You are responsible for maintaining the confidentiality of your login credentials. You must not share your account with others or allow unauthorized access.</p>
                    <ul>
                        <li>You must provide accurate information when requesting access.</li>
                        <li>You are responsible for all activity that occurs under your account.</li>
                        <li>You must notify us immediately of any unauthorized use of your account.</li>
                        <li>Accounts are non-transferable and personal to the registered user.</li>
                    </ul>
                    <p>Company administrators are responsible for managing the roles and permissions of their team members. Removing a user's company access is the administrator's responsibility.</p>
                </div>

                <div id="terms-usage">
                    <h2>3. Acceptable Use</h2>
                    <p>You agree to use Centryk only for lawful business purposes. You must not:</p>
                    <ul>
                        <li>Attempt to gain unauthorized access to any part of the platform or its systems.</li>
                        <li>Use the platform to transmit malicious code, spam, or fraudulent information.</li>
                        <li>Reverse-engineer, decompile, or attempt to extract the source code of any application.</li>
                        <li>Use Centryk to process transactions that are illegal under Belizean law or international regulations.</li>
                        <li>Interfere with or disrupt the integrity or performance of the platform or its data.</li>
                    </ul>
                </div>

                <div id="terms-payment">
                    <h2>4. Payment Processing (OneLink)</h2>
                    <p>Centryk provides access to the OneLink payment gateway through OnePay. By using OneLink to process card transactions, you agree to:</p>
                    <ul>
                        <li>Comply with all applicable card network rules and regulations.</li>
                        <li>Not use OneLink for prohibited transaction types, including fraudulent sales or money laundering.</li>
                        <li>Accept that settlement timelines and fees are subject to the terms of the payment network and financial institution involved.</li>
                        <li>Maintain accurate records of all transactions processed through the gateway.</li>
                    </ul>
                    <p>Centryk is not liable for declined transactions, chargebacks, or disputes initiated by cardholders. You are responsible for resolving payment disputes with your customers in accordance with applicable laws.</p>
                </div>

                <div id="terms-ip">
                    <h2>5. Intellectual Property</h2>
                    <p>All software, interfaces, designs, logos, and content within Centryk, OnePay, and MyPay are the intellectual property of Centryk and its licensors. You are granted a limited, non-exclusive, non-transferable license to use the platform solely for your business operations.</p>
                    <p>You retain ownership of all business data you input into the platform, including employee records, inventory data, transaction history, and payroll information. We do not claim ownership of your data.</p>
                </div>

                <div id="terms-liability">
                    <h2>6. Limitation of Liability</h2>
                    <p>Centryk is provided "as is." While we make every effort to ensure reliability and accuracy, we do not guarantee uninterrupted access or error-free operation. To the fullest extent permitted by law:</p>
                    <ul>
                        <li>Centryk is not liable for any loss of data, revenue, or profit resulting from use of the platform.</li>
                        <li>We are not responsible for third-party services or integrations, including payment networks.</li>
                        <li>Our total liability to you for any claim shall not exceed the amount you paid us in the three months preceding the claim.</li>
                    </ul>
                </div>

                <div id="terms-termination">
                    <h2>7. Termination</h2>
                    <p>We reserve the right to suspend or terminate your access to Centryk at any time if we determine you have violated these Terms of Service, engaged in fraudulent activity, or pose a risk to other users or the platform. You may also request termination of your account at any time by contacting us.</p>
                    <p>Upon termination, your access to the platform will be revoked. You may request an export of your business data within 30 days of termination.</p>
                </div>

                <div id="terms-changes">
                    <h2>8. Changes to These Terms</h2>
                    <p>We may update these Terms of Service from time to time. We will notify active users of material changes via email. Continued use of the platform after changes take effect constitutes acceptance of the new terms.</p>
                </div>

                <div id="terms-law">
                    <h2>9. Governing Law</h2>
                    <p>These Terms of Service are governed by and construed in accordance with the laws of Belize. Any disputes arising under these terms shall be subject to the exclusive jurisdiction of the courts of Belize.</p>
                </div>
            </div>

            <!-- PRIVACY POLICY -->
            <div id="privacy" class="rounded-2xl border border-slate-200 bg-white p-7 shadow-sm">
                <div class="mb-6 pb-5 border-b border-slate-100">
                    <span class="inline-block rounded-full bg-slate-900 px-3 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-white mb-3">Privacy Policy</span>
                    <h1 class="text-2xl font-black tracking-tight text-slate-950">Centryk Privacy Policy</h1>
                    <p class="text-xs font-bold text-slate-400 mt-1">Last updated: <?php echo date('F j, Y'); ?></p>
                </div>

                <p>Centryk is committed to protecting the privacy of the businesses and individuals who use our platform. This Privacy Policy explains what information we collect, how we use it, and the choices you have.</p>

                <div id="privacy-collect">
                    <h2>1. Information We Collect</h2>
                    <h3>Account Information</h3>
                    <p>When you request or receive access to Centryk, we collect your name, email address, and company name. Administrators may also provide additional contact details.</p>
                    <h3>Business Data</h3>
                    <p>Data you enter into OnePay or MyPay — including inventory records, sales transactions, employee information, payroll data, and time records — is stored on our servers and is owned by you.</p>
                    <h3>Usage Data</h3>
                    <p>We collect technical information such as IP addresses, browser type, pages visited, and actions taken within the platform. This helps us identify issues, improve performance, and detect unauthorized activity.</p>
                    <h3>Payment Data</h3>
                    <p>Transaction records processed through OneLink are logged in our system. Sensitive card data (full card numbers, CVV) is never stored on Centryk's servers. Payment processing is handled by secure, encrypted gateway connections.</p>
                </div>

                <div id="privacy-use">
                    <h2>2. How We Use Your Information</h2>
                    <ul>
                        <li>To provide, operate, and maintain the Centryk platform and its applications.</li>
                        <li>To send account-related communications, including welcome emails and password resets.</li>
                        <li>To respond to your support requests and enquiries.</li>
                        <li>To monitor for security threats, fraud, and unauthorized access.</li>
                        <li>To improve our services based on aggregated usage patterns.</li>
                        <li>To comply with legal obligations under Belizean law.</li>
                    </ul>
                    <p>We do not use your business data for advertising, profiling, or selling to third parties.</p>
                </div>

                <div id="privacy-sharing">
                    <h2>3. Data Sharing</h2>
                    <p>We do not sell, trade, or rent your personal or business data to third parties. We may share information only in the following circumstances:</p>
                    <ul>
                        <li><strong>Service providers:</strong> We work with trusted vendors (such as email delivery services and hosting providers) who process data on our behalf under strict confidentiality agreements.</li>
                        <li><strong>Payment networks:</strong> Transaction data is shared with payment processors as required to authorize and settle card payments through OneLink.</li>
                        <li><strong>Legal requirements:</strong> We may disclose data when required by law, court order, or a valid government request under Belizean jurisdiction.</li>
                        <li><strong>Business transfer:</strong> In the event of a merger or acquisition, your data may be transferred as part of the business assets, with prior notice to active users.</li>
                    </ul>
                </div>

                <div id="privacy-security">
                    <h2>4. Data Security</h2>
                    <p>We implement industry-standard security measures to protect your data, including:</p>
                    <ul>
                        <li>Encrypted data transmission using HTTPS/TLS.</li>
                        <li>Hashed password storage — your password is never stored in plain text.</li>
                        <li>Server-side session management with short-lived, single-use tokens for app launches.</li>
                        <li>Role-based access control enforced at the server level.</li>
                        <li>Company-level data isolation — no user can access another company's data.</li>
                    </ul>
                    <p>While we take strong precautions, no system is 100% secure. We encourage you to use strong, unique passwords and to report any suspicious activity immediately.</p>
                </div>

                <div id="privacy-retention">
                    <h2>5. Data Retention</h2>
                    <p>We retain your data for as long as your account is active or as needed to provide services. When you request account deletion, we will delete or anonymize your personal data within 30 days, except where retention is required by law (such as financial records).</p>
                </div>

                <div id="privacy-rights">
                    <h2>6. Your Rights</h2>
                    <p>As a Centryk user, you have the right to:</p>
                    <ul>
                        <li><strong>Access</strong> — request a copy of the personal data we hold about you.</li>
                        <li><strong>Correction</strong> — ask us to correct inaccurate or incomplete data.</li>
                        <li><strong>Deletion</strong> — request deletion of your account and associated personal data.</li>
                        <li><strong>Portability</strong> — request an export of your business data in a machine-readable format.</li>
                        <li><strong>Objection</strong> — object to certain uses of your data, subject to applicable law.</li>
                    </ul>
                    <p>To exercise any of these rights, contact us at <a href="mailto:info@centryk.net">info@centryk.net</a>.</p>
                </div>

                <div id="privacy-cookies">
                    <h2>7. Cookies &amp; Local Storage</h2>
                    <p>Centryk uses browser session cookies to maintain your login state and local storage to remember your theme preference (dark/light mode). We do not use tracking cookies or third-party advertising cookies.</p>
                </div>

                <div id="privacy-contact">
                    <h2>8. Contact &amp; Questions</h2>
                    <p>If you have questions or concerns about this Privacy Policy or how your data is handled, please contact us:</p>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 mt-3 not-prose">
                        <p class="text-sm font-bold text-slate-900">Centryk — Privacy</p>
                        <p class="text-sm font-semibold text-slate-500">Belize City, Belize</p>
                        <a href="mailto:info@centryk.net" class="text-sm font-bold text-blue-600 hover:underline">info@centryk.net</a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>

</body>
</html>
