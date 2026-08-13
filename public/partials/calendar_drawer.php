<?php
/**
 * Shared Centryk calendar drawer — slides the real calendar.php month view in
 * over whatever page the user is on, instead of navigating away from it.
 *
 * Self-contained: emits its own markup AND behaviour (loads calendar.php in an
 * iframe with ?embed=1, which hides that page's own header/footer chrome).
 * Include wherever the waffle app switcher / calendar preview can appear.
 *
 * Also defines window.centrykAppLaunch(appKey), the hook account_header.php's
 * waffle already checks for before falling back to its default switch.php
 * navigation — so no edit to account_header.php's script is needed to route
 * the Calendar tile here instead.
 *
 * Portable: set window.CENTRYK_BASE_URL before this include when reusing it
 * from a page that doesn't live at the Centryk web root (e.g. invoice-maker,
 * nested under /invoice-maker/) — defaults to '' (today's relative paths).
 */
?>
<!-- Calendar drawer -->
<div id="calDrawerBackdrop" class="fixed inset-0 z-[60] hidden bg-slate-900/40 transition-opacity"></div>
<div id="calDrawerPanel"
     class="fixed inset-y-0 right-0 z-[70] flex w-[92vw] max-w-6xl translate-x-full transform flex-col bg-slate-100 shadow-2xl transition-transform duration-300 ease-out">
    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
        <div class="flex items-center gap-2">
            <i data-lucide="calendar" class="h-4 w-4 text-teal-500"></i>
            <span class="text-sm font-black text-slate-800">Calendar</span>
        </div>
        <div class="flex items-center gap-2">
            <a id="calDrawerFullLink" href="calendar.php" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-bold text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">
                Open full page
                <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
            </a>
            <button id="calDrawerCloseBtn" type="button" title="Close"
                    class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
        </div>
    </div>
    <iframe id="calDrawerFrame" title="Calendar" class="min-h-0 flex-1 border-0 bg-slate-100"></iframe>
</div>

<script>
(function () {
    var backdrop = document.getElementById('calDrawerBackdrop');
    var panel    = document.getElementById('calDrawerPanel');
    var frame    = document.getElementById('calDrawerFrame');
    var fullLink = document.getElementById('calDrawerFullLink');
    var closeBtn = document.getElementById('calDrawerCloseBtn');
    if (!backdrop || !panel || !frame) return;

    var base = window.CENTRYK_BASE_URL || '';
    if (base && base.slice(-1) !== '/') base += '/';

    window.centrykOpenCalendarDrawer = function (companyUuid) {
        var src = base + 'calendar.php?embed=1';
        if (companyUuid) {
            src += '&company_uuid=' + encodeURIComponent(companyUuid);
            fullLink.href = base + 'calendar.php?company_uuid=' + encodeURIComponent(companyUuid);
        } else {
            fullLink.href = base + 'calendar.php';
        }
        frame.src = src;
        backdrop.classList.remove('hidden');
        requestAnimationFrame(function () {
            panel.classList.remove('translate-x-full');
        });
    };

    function closeDrawer() {
        panel.classList.add('translate-x-full');
        backdrop.classList.add('hidden');
    }

    closeBtn.addEventListener('click', closeDrawer);
    backdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !backdrop.classList.contains('hidden')) closeDrawer();
    });

    // Default waffle launch behaviour (mirrors account_header.php's own
    // fallback) — except Calendar, which opens the drawer instead of
    // navigating the current page away.
    window.centrykAppLaunch = function (appKey) {
        if (appKey === 'calendar') {
            window.centrykOpenCalendarDrawer(window.CENTRYK_ACTIVE_COMPANY_UUID || '');
            return;
        }
        if (appKey === 'store') {
            window.location.href = base + 'store.php';
            return;
        }
        var uuid = window.CENTRYK_ACTIVE_COMPANY_UUID || '';
        window.location.href = base + 'switch.php?app=' + encodeURIComponent(appKey) + (uuid ? '&company_uuid=' + encodeURIComponent(uuid) : '');
    };
})();
</script>
