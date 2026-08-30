<style>
    @keyframes status-glow {
        0%, 100% {
            box-shadow: 0 0 0 0 rgba(52,211,153,0);
            border-color: rgba(52,211,153,0.25);
        }
        50% {
            box-shadow: 0 0 14px 5px rgba(52,211,153,0.12), 0 0 28px 10px rgba(52,211,153,0.04);
            border-color: rgba(52,211,153,0.55);
        }
    }
    @keyframes dot-blink {
        0%, 100% { opacity: 1; transform: scale(1);    box-shadow: 0 0 0 0   rgba(52,211,153,0.7); }
        40%       { opacity: 0.4; transform: scale(0.85); box-shadow: 0 0 0 0   rgba(52,211,153,0); }
        70%       { opacity: 1; transform: scale(1.3);  box-shadow: 0 0 0 5px rgba(52,211,153,0); }
    }
    .status-chip { animation: status-glow 3s ease-in-out infinite; }
    .status-dot  { animation: dot-blink 2.4s ease-in-out infinite; }
</style>
<!-- ── SITE FOOTER ──────────────────────────────────────────────────────── -->
<footer class="bg-[#0b0f19] text-white" aria-label="Site footer">

    <!-- Main grid -->
    <div class="mx-auto max-w-6xl px-6 pt-16 pb-12">
        <div class="grid gap-12 md:grid-cols-2 lg:grid-cols-[1.8fr_1fr_1fr_1.3fr]">

            <!-- ── Brand ── -->
            <div>
                <a href="index.php" class="inline-block mb-6">
                    <img src="assets/centryk_logo.png" alt="Centryk" class="h-12 w-auto brightness-0 invert">
                </a>
                <p class="text-sm font-semibold leading-relaxed text-white/45 max-w-[280px]">
                    The unified business platform built for Belizean companies. Inventory, payroll, HR, and cashless payments — one login.
                </p>

                <!-- Social / contact icons -->
                <div class="mt-6 flex items-center gap-2">
                    <a href="mailto:info@centryk.net"
                       class="flex h-9 w-9 items-center justify-center rounded-xl bg-white/8 text-white/45 transition hover:bg-white/15 hover:text-white"
                       title="Email us">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </a>
                    <a href="https://www.facebook.com/centryk" target="_blank" rel="noopener"
                       class="flex h-9 w-9 items-center justify-center rounded-xl bg-white/8 text-white/45 transition hover:bg-white/15 hover:text-white"
                       title="Facebook">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>
                    </a>
                    <a href="https://wa.me/5012234567" target="_blank" rel="noopener"
                       class="flex h-9 w-9 items-center justify-center rounded-xl bg-white/8 text-white/45 transition hover:bg-white/15 hover:text-white"
                       title="WhatsApp">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 32 32"><path d="M16.004 2C8.28 2 2 8.278 2 16c0 2.478.648 4.804 1.781 6.822L2 30l7.377-1.752A13.94 13.94 0 0016.004 30C23.722 30 30 23.722 30 16S23.722 2 16.004 2zm0 25.54a11.54 11.54 0 01-5.87-1.594l-.42-.25-4.374 1.04 1.077-4.254-.276-.438A11.502 11.502 0 014.46 16c0-6.358 5.182-11.54 11.544-11.54 6.358 0 11.536 5.182 11.536 11.54S22.362 27.54 16.004 27.54zm6.336-8.637c-.347-.174-2.057-1.015-2.376-1.13-.32-.116-.552-.174-.784.173-.232.347-.898 1.13-1.101 1.362-.202.232-.405.26-.752.087-.347-.174-1.463-.54-2.787-1.72-1.03-.92-1.725-2.055-1.928-2.403-.202-.347-.022-.535.153-.708.157-.156.347-.405.52-.608.174-.202.232-.347.347-.579.116-.232.058-.434-.029-.608-.087-.173-.784-1.89-1.073-2.589-.283-.68-.57-.587-.784-.598-.203-.01-.434-.012-.666-.012s-.608.087-.927.434c-.318.347-1.217 1.188-1.217 2.896 0 1.709 1.246 3.36 1.42 3.592.173.231 2.45 3.74 5.937 5.246.829.358 1.476.571 1.98.731.832.265 1.59.228 2.188.138.667-.1 2.057-.84 2.347-1.651.29-.812.29-1.508.203-1.651-.087-.145-.318-.231-.666-.405z"/></svg>
                    </a>
                </div>

                <!-- Status chip -->
                <div class="status-chip mt-6 inline-flex items-center gap-2 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-3.5 py-1.5">
                    <span class="status-dot h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                    <span class="text-[11px] font-bold text-emerald-400">All systems operational</span>
                </div>
            </div>

            <!-- ── Platform ── -->
            <div>
                <h3 class="text-[10px] font-black uppercase tracking-[0.22em] text-white/30 mb-6">Platform</h3>
                <ul class="space-y-3.5">
                    <li>
                        <a href="about.php" class="text-sm font-semibold text-white/55 transition hover:text-white">
                            About Centryk
                        </a>
                    </li>
                    <li>
                        <a href="about.php#onepay" class="group flex items-center gap-2.5 text-sm font-semibold text-white/55 transition hover:text-white">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-white/90 p-0.5 transition group-hover:bg-white">
                                <img src="assets/onepay_logo.png" alt="" class="h-full w-full object-contain">
                            </span>
                            OnePay — Inventory &amp; POS
                        </a>
                    </li>
                    <li>
                        <a href="about.php#mypay" class="group flex items-center gap-2.5 text-sm font-semibold text-white/55 transition hover:text-white">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-orange-600/25 text-orange-400 transition group-hover:bg-orange-600/40">
                                <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            </span>
                            MyPay — HR &amp; Payroll
                        </a>
                    </li>
                    <li>
                        <a href="about.php#onelink" class="group flex items-center gap-2.5 text-sm font-semibold text-white/55 transition hover:text-white">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-blue-600/25 text-blue-400 transition group-hover:bg-blue-600/40">
                                <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            </span>
                            OneLink Payments
                        </a>
                    </li>
                    <li><a href="platform.php"       class="text-sm font-semibold text-white/55 transition hover:text-white">Platform reference</a></li>
                    <li><a href="about.php#security" class="text-sm font-semibold text-white/55 transition hover:text-white">Security &amp; Trust</a></li>
                    <li><a href="about.php#faq"      class="text-sm font-semibold text-white/55 transition hover:text-white">FAQ</a></li>
                </ul>
            </div>

            <!-- ── Company ── -->
            <div>
                <h3 class="text-[10px] font-black uppercase tracking-[0.22em] text-white/30 mb-6">Explore</h3>
                <ul class="space-y-3.5">
                    <li><a href="store.php"           class="text-sm font-semibold text-white/55 transition hover:text-white">Marketplace</a></li>
                    <li><a href="jobs.php"            class="text-sm font-semibold text-white/55 transition hover:text-white">Job Board</a></li>
                    <li><a href="directory.php"       class="text-sm font-semibold text-white/55 transition hover:text-white">Business Directory</a></li>
                    <li><a href="contact.php"         class="text-sm font-semibold text-white/55 transition hover:text-white">Contact Us</a></li>
                    <li><a href="refer.php"           class="text-sm font-semibold text-white/55 transition hover:text-white">Refer a Business</a></li>
                    <li><a href="terms.php"           class="text-sm font-semibold text-white/55 transition hover:text-white">Terms of Service</a></li>
                    <li><a href="terms.php#privacy"   class="text-sm font-semibold text-white/55 transition hover:text-white">Privacy Policy</a></li>
                </ul>
            </div>

            <!-- ── Get Started ── -->
            <div>
                <h3 class="text-[10px] font-black uppercase tracking-[0.22em] text-white/30 mb-6">Get Started</h3>
                <p class="text-sm font-semibold leading-relaxed text-white/45 mb-5">
                    Free to request. We set up your account and have you running instantly.
                </p>
                <a href="login.php"
                   class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-xs font-black uppercase tracking-[0.12em] text-slate-900 shadow-lg transition hover:bg-slate-100">
                    Request Free Access
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12"/></svg>
                </a>

                <!-- Contact details -->
                <div class="mt-7 space-y-3">
                    <a href="mailto:info@centryk.net"
                       class="flex items-center gap-2.5 text-sm font-semibold text-white/40 transition hover:text-white/70">
                        <svg class="h-4 w-4 shrink-0 text-white/25" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        info@centryk.net
                    </a>
                    <p class="flex items-center gap-2.5 text-sm font-semibold text-white/25">
                        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Belize City, Belize
                    </p>
                </div>

            </div>
        </div>

        <!-- ── App badges strip ── -->
        <div class="mt-14 pt-8 border-t border-white/8 flex flex-wrap items-center gap-3">
            <span class="text-[10px] font-black uppercase tracking-[0.2em] text-white/20 mr-1">Includes</span>

            <span class="inline-flex items-center gap-2 rounded-full border border-purple-500/20 bg-purple-500/8 px-3.5 py-1.5">
                <img src="assets/onepay_logo.png" alt="" class="h-3.5 w-3.5 object-contain">
                <span class="text-[11px] font-black text-purple-300">OnePay</span>
                <span class="hidden sm:inline text-[10px] font-semibold text-purple-400/55">Inventory &amp; POS</span>
            </span>

            <span class="inline-flex items-center gap-2 rounded-full border border-orange-500/20 bg-orange-500/8 px-3.5 py-1.5">
                <svg class="h-3.5 w-3.5 text-orange-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                <span class="text-[11px] font-black text-orange-300">MyPay</span>
                <span class="hidden sm:inline text-[10px] font-semibold text-orange-400/55">HR &amp; Payroll</span>
            </span>

            <span class="inline-flex items-center gap-2 rounded-full border border-blue-500/20 bg-blue-500/8 px-3.5 py-1.5">
                <svg class="h-3.5 w-3.5 text-blue-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                <span class="text-[11px] font-black text-blue-300">OneLink</span>
                <span class="hidden sm:inline text-[10px] font-semibold text-blue-400/55">Payments</span>
            </span>
        </div>
    </div>

    <!-- ── Bottom legal strip ── -->
    <div class="border-t border-white/8">
        <div class="mx-auto max-w-6xl px-6 py-5 flex flex-col sm:flex-row items-center justify-between gap-3">
            <p class="text-xs font-semibold text-white/25">
                &copy; <?php echo date('Y'); ?> Centryk. All rights reserved. &nbsp;&middot;&nbsp; Built in Belize.
            </p>
            <div class="flex items-center gap-5">
                <a href="terms.php"         class="text-xs font-bold text-white/25 transition hover:text-white/55">Terms</a>
                <a href="terms.php#privacy" class="text-xs font-bold text-white/25 transition hover:text-white/55">Privacy</a>
                <a href="contact.php"       class="text-xs font-bold text-white/25 transition hover:text-white/55">Contact</a>
                <a href="login.php"         class="text-xs font-bold text-white/25 transition hover:text-white/55">Sign In</a>
            </div>
        </div>
    </div>

</footer>
