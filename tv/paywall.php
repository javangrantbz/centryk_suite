<?php
/**
 * Checkout for a 'paid' event a logged-in viewer hasn't bought access to
 * yet. watch.php redirects here instead of a flat 403 when the event is
 * specifically gated by price rather than by private/authenticated
 * visibility. Posts to api/payments/charge_for_access.php - see
 * TvPaymentService for why access is only ever granted from OneLink's own
 * confirmed response, never from this page's own say-so.
 */
require_once __DIR__ . '/includes/bootstrap.php';

tv_gate_coming_soon();

$user = tv_user();
if (!$user) {
    tv_redirect(centryk_public_url() . '/login.php?redirect=' . urlencode(tv_current_path()));
}

$slug = trim((string)($_GET['event'] ?? ''));
$event = $slug !== '' ? tv_find_event_by_slug($slug) : null;
if (!$event || (string)($event['channel_visibility'] ?? '') !== 'paid') {
    http_response_code(404);
    exit('Event not found.');
}

if (tv_can_watch_event($event, $user)) {
    tv_redirect(tv_url('watch/' . $event['slug']));
}

$price = (float)($event['price_amount'] ?? 0);
if ($price <= 0) {
    http_response_code(404);
    exit('This event is not available for purchase.');
}

$paymentReady = TvPaymentService::isPaymentConfigured((int)$event['organization_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($event['title']) ?> | <?= e((string)tv_config('app_name')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap'); body{font-family:'Plus Jakarta Sans',sans-serif;}</style>
</head>
<body class="bg-slate-950 text-white">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-10">
        <a href="<?= e(tv_url('watch/' . $event['slug'])) ?>" class="text-xs font-bold uppercase tracking-[0.3em] text-brand-300 text-teal-300">&larr; Back to event</a>

        <div class="mt-6 rounded-[2rem] border border-white/10 bg-white/5 p-8">
            <p class="text-xs font-bold uppercase tracking-[0.28em] text-teal-300"><?= e($event['organization_name']) ?></p>
            <h1 class="mt-2 text-2xl font-black tracking-tight"><?= e($event['title']) ?></h1>
            <p class="mt-4 text-4xl font-black"><?= e($event['price_currency'] ?? 'BZD') ?> <?= number_format($price, 2) ?></p>
            <p class="mt-1 text-sm text-slate-400">One-time payment for access to this event.</p>

            <?php if (!$paymentReady): ?>
            <div class="mt-6 rounded-2xl border border-amber-400/30 bg-amber-400/10 p-4 text-sm text-amber-200">
                This organization hasn't finished setting up payments yet. Please check back later.
            </div>
            <?php else: ?>
            <div id="payError" class="mt-6 hidden rounded-2xl border border-rose-400/30 bg-rose-400/10 p-4 text-sm text-rose-200"></div>
            <form id="payForm" class="mt-6 space-y-4">
                <?= tv_csrf_field() ?>
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                <div>
                    <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Card Number</label>
                    <input name="card_number" inputmode="numeric" autocomplete="cc-number" required
                           class="mt-1.5 w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm outline-none focus:border-teal-400" placeholder="4242 4242 4242 4242">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Expiry (MMYY)</label>
                        <input name="card_expiry" inputmode="numeric" autocomplete="cc-exp" required maxlength="4"
                               class="mt-1.5 w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm outline-none focus:border-teal-400" placeholder="1229">
                    </div>
                    <div>
                        <label class="text-xs font-bold uppercase tracking-widest text-slate-400">CVV</label>
                        <input name="card_cvv" inputmode="numeric" autocomplete="cc-csc" required maxlength="4"
                               class="mt-1.5 w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm outline-none focus:border-teal-400" placeholder="123">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Cardholder Name</label>
                    <input name="card_holder" autocomplete="cc-name" required
                           class="mt-1.5 w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm outline-none focus:border-teal-400" placeholder="Jane Doe">
                </div>
                <button type="submit" id="payBtn"
                        class="w-full rounded-2xl bg-teal-500 px-4 py-3.5 text-sm font-black uppercase tracking-widest text-slate-950 transition hover:bg-teal-400">
                    Pay <?= e($event['price_currency'] ?? 'BZD') ?> <?= number_format($price, 2) ?>
                </button>
                <p class="text-center text-[11px] text-slate-500">Payments are processed by OneLink. Card details are never stored by Centryk TV.</p>
            </form>
            <?php endif; ?>
        </div>
    </main>

    <script>
        const form = document.getElementById('payForm');
        if (form) {
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const btn = document.getElementById('payBtn');
                const err = document.getElementById('payError');
                err.classList.add('hidden');
                btn.disabled = true;
                btn.textContent = 'Processing...';

                try {
                    const res = await fetch('<?= e(tv_url('api/payments/charge_for_access.php')) ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams(new FormData(form))
                    });
                    const payload = await res.json();
                    if (!payload.success) {
                        throw new Error(payload.message || 'Payment failed.');
                    }
                    window.location.href = '<?= e(tv_url('watch/' . $event['slug'])) ?>';
                } catch (ex) {
                    err.textContent = ex.message;
                    err.classList.remove('hidden');
                    btn.disabled = false;
                    btn.textContent = 'Try Again';
                }
            });
        }
    </script>
</body>
</html>
