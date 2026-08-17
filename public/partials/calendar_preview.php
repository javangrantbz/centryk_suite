<?php
/**
 * Shared Centryk calendar preview — dropdown of the user's upcoming events.
 *
 * Self-contained: emits its own markup AND behaviour. Lazy-loads
 * api/calendar/upcoming-mine.php the first time the dropdown is opened.
 * Include wherever the calendar button should sit in a header.
 */
?>
<!-- Calendar preview -->
<div class="relative shrink-0" id="calPreviewWrap">
    <button id="calPreviewBtn" title="Upcoming events"
            class="flex h-9 w-9 items-center justify-center rounded-xl text-slate-500 transition hover:bg-teal-50 hover:text-teal-600">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
    </button>
    <div id="calPreviewDropdown" class="absolute right-0 top-full z-50 mt-1.5 hidden w-80 rounded-xl border border-slate-200 bg-white shadow-xl overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500">Upcoming events</span>
            <a href="calendar.php" id="calPreviewOpenLink" class="text-[11px] font-bold text-teal-600 hover:text-teal-700">Open Calendar &rarr;</a>
        </div>
        <div id="calPreviewBody" class="max-h-80 overflow-y-auto p-2">
            <p class="px-3 py-6 text-center text-xs text-slate-400">Loading&hellip;</p>
        </div>
    </div>
</div>

<!-- Calendar preview behaviour (shared across Centryk apps). -->
<script>
(function () {
    const cb = document.getElementById('calPreviewBtn');
    const cd = document.getElementById('calPreviewDropdown');
    if (!cb || !cd) return;
    let calLoaded = false;

    cb.addEventListener('click', e => {
        e.stopPropagation();
        const opening = cd.classList.contains('hidden');
        cd.classList.toggle('hidden');
        if (opening) {
            loadCalPreview();
            document.dispatchEvent(new CustomEvent('centryk:dropdown-open', { detail: { id: cd.id } }));
        }
    });
    document.addEventListener('click', () => cd.classList.add('hidden'));
    // Close if a different header dropdown (waffle, notifications, user menu,
    // ...) just opened - see account_header.php for the shared convention.
    document.addEventListener('centryk:dropdown-open', e => {
        if (e.detail && e.detail.id !== cd.id) cd.classList.add('hidden');
    });

    const openLink = document.getElementById('calPreviewOpenLink');
    if (openLink) {
        openLink.addEventListener('click', e => {
            if (typeof window.centrykOpenCalendarDrawer !== 'function') return; // fall back to the plain href
            e.preventDefault();
            cd.classList.add('hidden');
            window.centrykOpenCalendarDrawer(window.CENTRYK_ACTIVE_COMPANY_UUID || '');
        });
    }

    function loadCalPreview() {
        if (calLoaded) return;
        calLoaded = true;
        const body = document.getElementById('calPreviewBody');
        fetch('api/calendar/upcoming-mine.php')
            .then(r => r.json())
            .then(d => {
                const evts = (d && d.events) || [];
                body.innerHTML = evts.length
                    ? evts.map(calPreviewRow).join('')
                    : '<p class="px-3 py-6 text-center text-xs text-slate-400">No upcoming events.</p>';
            })
            .catch(() => { calLoaded = false; body.innerHTML = '<p class="px-3 py-6 text-center text-xs text-slate-400">Couldn\'t load events.</p>'; });
    }

    function calPreviewRow(ev) {
        const d   = new Date((ev.event_date || '') + 'T00:00:00');
        const mon = isNaN(d) ? '' : d.toLocaleString('en-US', { month: 'short' });
        const day = isNaN(d) ? '' : d.getDate();
        const esc = s => String(s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        return '<div class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-slate-50">' +
            '<div class="flex flex-col items-center justify-center h-11 w-11 shrink-0 rounded-lg bg-teal-50 text-teal-700">' +
                '<span class="text-[9px] font-black uppercase leading-none">' + mon + '</span>' +
                '<span class="text-base font-black leading-none mt-0.5">' + day + '</span>' +
            '</div>' +
            '<div class="min-w-0">' +
                '<p class="text-sm font-bold text-slate-800 truncate">' + esc(ev.title) + '</p>' +
                '<p class="text-[11px] font-semibold text-slate-400 capitalize">' + esc(ev.event_type || 'event') + '</p>' +
            '</div>' +
        '</div>';
    }
})();
</script>
