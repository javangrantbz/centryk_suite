<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact — Centryk</title>
    <meta name="description" content="Get in touch with the Centryk team. We're here to help Belizean businesses get set up and running.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } }</script>
    <style>
        html { scroll-behavior: smooth; }
        .section-fade { opacity: 0; transform: translateY(20px); transition: opacity 0.5s ease, transform 0.5s ease; }
        .section-fade.visible { opacity: 1; transform: translateY(0); }
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
        <div class="hidden md:flex items-center gap-1">
            <a href="about.php"   class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition">About</a>
            <a href="contact.php" class="px-3 py-1.5 rounded-lg text-sm font-bold   text-slate-900 bg-slate-100 rounded-lg transition">Contact</a>
            <a href="refer.php"   class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition">Refer</a>
            <a href="terms.php"   class="px-3 py-1.5 rounded-lg text-sm font-semibold text-slate-500 hover:text-slate-900 hover:bg-slate-100 transition">Terms</a>
        </div>
        <a href="index.php" class="ml-3 rounded-xl bg-slate-900 px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-white transition hover:bg-slate-700">Sign In</a>
    </div>
</nav>

<!-- HERO -->
<section class="relative overflow-hidden bg-[linear-gradient(135deg,#0d1117_0%,#0f1928_50%,#111d35_100%)] px-6 py-20 text-white">
    <div class="absolute left-0 top-0 h-96 w-96 rounded-full bg-blue-700/10 blur-3xl -translate-x-1/2 -translate-y-1/4"></div>
    <div class="absolute right-0 bottom-0 h-80 w-80 rounded-full bg-purple-700/8 blur-3xl translate-x-1/3"></div>
    <div class="relative mx-auto max-w-3xl text-center">
        <span class="inline-block rounded-full border border-white/15 bg-white/8 px-4 py-1.5 text-[11px] font-black uppercase tracking-[0.22em] text-white/60 mb-6">We're here to help</span>
        <h1 class="text-5xl font-black leading-tight tracking-tight">Get in Touch</h1>
        <p class="mt-5 text-lg font-semibold leading-relaxed text-white/55 max-w-xl mx-auto">
            Have a question about Centryk, OnePay, or MyPay? Want to request access or get a demo?
            Our team is ready to help your business get set up.
        </p>
    </div>
</section>

<!-- CONTACT METHODS + FORM -->
<section class="px-6 py-16 bg-white section-fade">
    <div class="mx-auto max-w-5xl">
        <div class="grid gap-10 lg:grid-cols-[1fr_1.6fr] items-start">

            <!-- Left: contact info -->
            <div class="space-y-5">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-400 mb-2">Reach us</p>
                    <h2 class="text-2xl font-black tracking-tight text-slate-950">Let's talk</h2>
                    <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">Whether you're a new business exploring Centryk or an existing client needing support, we'll get back to you within one business day.</p>
                </div>

                <div class="space-y-3">
                    <div class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Email</p>
                            <a href="mailto:info@centryk.net" class="text-sm font-bold text-slate-900 hover:text-blue-600 transition">info@centryk.net</a>
                            <p class="text-xs font-semibold text-slate-400 mt-0.5">General enquiries &amp; support</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Phone</p>
                            <a href="tel:+5012234567" class="text-sm font-bold text-slate-900 hover:text-blue-600 transition">+501 223-4567</a>
                            <p class="text-xs font-semibold text-slate-400 mt-0.5">Mon – Fri, 8 AM – 5 PM (CST)</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Location</p>
                            <p class="text-sm font-bold text-slate-900">Belize City, Belize</p>
                            <p class="text-xs font-semibold text-slate-400 mt-0.5">Serving businesses nationwide</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-400">Response Time</p>
                            <p class="text-sm font-bold text-slate-900">Within 1 business day</p>
                            <p class="text-xs font-semibold text-slate-400 mt-0.5">Usually much faster</p>
                        </div>
                    </div>
                </div>

                <!-- Quick access CTA -->
                <div class="rounded-2xl border-2 border-slate-900 bg-slate-900 p-5 text-white">
                    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-white/50 mb-1">Ready to start?</p>
                    <p class="text-sm font-black text-white mb-3">Skip the form — request your free account directly from the login page.</p>
                    <a href="index.php" class="inline-block rounded-xl bg-white px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-slate-900 transition hover:bg-slate-100">
                        Get Instant Access
                    </a>
                </div>
            </div>

            <!-- Right: contact form -->
            <div class="rounded-2xl border border-slate-200 bg-white p-7 shadow-sm">
                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400 mb-1">Send a message</p>
                <h3 class="text-xl font-black tracking-tight text-slate-950 mb-6">We'll get back to you shortly</h3>

                <div id="contactSuccess" class="hidden rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center">
                    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100">
                        <svg class="h-6 w-6 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <p class="text-sm font-black text-emerald-800">Message sent!</p>
                    <p class="text-xs font-semibold text-emerald-600 mt-1">We'll be in touch within one business day.</p>
                </div>

                <form id="contactForm" class="space-y-4">
                    <div id="contactAlert" class="hidden rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600"></div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Your Name</label>
                            <input id="cName" type="text" required placeholder="Jane Smith"
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Email Address</label>
                            <input id="cEmail" type="email" required placeholder="you@company.com"
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Company Name</label>
                            <input id="cCompany" type="text" placeholder="Your Business (optional)"
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Subject</label>
                            <select id="cSubject"
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                                <option value="general">General Enquiry</option>
                                <option value="demo">Request a Demo</option>
                                <option value="onepay">OnePay Question</option>
                                <option value="mypay">MyPay Question</option>
                                <option value="onelink">OneLink / Payments</option>
                                <option value="support">Technical Support</option>
                                <option value="billing">Billing</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Message</label>
                        <textarea id="cMessage" rows="5" required placeholder="Tell us how we can help…"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-semibold text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100 resize-none"></textarea>
                    </div>

                    <button id="contactBtn" type="submit"
                        class="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-black uppercase tracking-[0.12em] text-white shadow transition hover:bg-slate-700 active:scale-[0.99] focus:outline-none focus:ring-4 focus:ring-slate-300">
                        Send Message
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer class="border-t border-slate-200 bg-white px-6 py-8">
    <div class="mx-auto max-w-6xl flex flex-col items-center justify-between gap-4 md:flex-row">
        <div class="flex items-center gap-2.5">
            <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-900 text-white">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-3.5 w-3.5"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
            </div>
            <span class="text-sm font-black tracking-tight text-slate-900">Centryk</span>
        </div>
        <div class="flex flex-wrap justify-center gap-5 text-xs font-bold text-slate-400">
            <a href="about.php"   class="hover:text-slate-700 transition">About</a>
            <a href="contact.php" class="hover:text-slate-700 transition">Contact</a>
            <a href="refer.php"   class="hover:text-slate-700 transition">Refer</a>
            <a href="terms.php"   class="hover:text-slate-700 transition">Terms</a>
            <a href="index.php"   class="hover:text-slate-700 transition">Sign In</a>
        </div>
        <p class="text-xs font-bold text-slate-300">&copy; <?php echo date('Y'); ?> Centryk. All rights reserved.</p>
    </div>
</footer>

<script>
(function () {
    document.querySelectorAll('.section-fade').forEach(function (el) {
        new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { if (e.isIntersecting) e.target.classList.add('visible'); });
        }, { threshold: 0.08 }).observe(el);
    });

    var form    = document.getElementById('contactForm');
    var btn     = document.getElementById('contactBtn');
    var alert   = document.getElementById('contactAlert');
    var success = document.getElementById('contactSuccess');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        alert.classList.add('hidden');
        btn.disabled    = true;
        btn.textContent = 'Sending…';

        fetch('api/contact/submit.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name:    document.getElementById('cName').value,
                email:   document.getElementById('cEmail').value,
                company: document.getElementById('cCompany').value,
                subject: document.getElementById('cSubject').value,
                message: document.getElementById('cMessage').value,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                form.classList.add('hidden');
                success.classList.remove('hidden');
            } else {
                alert.textContent = data.message || 'Something went wrong. Please try again.';
                alert.classList.remove('hidden');
                btn.disabled    = false;
                btn.textContent = 'Send Message';
            }
        })
        .catch(function () {
            alert.textContent = 'Network error. Please try again.';
            alert.classList.remove('hidden');
            btn.disabled    = false;
            btn.textContent = 'Send Message';
        });
    });
}());
</script>

</body>
</html>
