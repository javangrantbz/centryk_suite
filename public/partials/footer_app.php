<!-- ── APP FOOTER (logged-in pages) ────────────────────────────────────────
     Minimal by design — no marketing columns, no Sign In. Full marketing
     footer.php stays reserved for the public/logged-out pages. -->
<footer class="border-t border-slate-200 bg-white" aria-label="Site footer">
    <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-6 py-5 text-center sm:flex-row sm:text-left">
        <p class="text-xs font-semibold text-slate-400">
            &copy; <?php echo date('Y'); ?> Centryk. All rights reserved.
        </p>
        <div class="flex items-center gap-5">
            <a href="terms.php"         class="text-xs font-bold text-slate-400 transition hover:text-slate-700">Terms</a>
            <a href="terms.php#privacy" class="text-xs font-bold text-slate-400 transition hover:text-slate-700">Privacy</a>
            <a href="contact.php"       class="text-xs font-bold text-slate-400 transition hover:text-slate-700">Contact</a>
        </div>
    </div>
</footer>
