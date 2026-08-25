<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/page-shell.php';

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
<body class="bg-slate-50 text-slate-900">
    <?php tv_render_page_header('Unlock Event', (string)$event['title'], [['href' => tv_url('watch/' . $event['slug']), 'label' => 'Back']]); ?>
    <main class="mx-auto flex min-h-[calc(100vh-60px)] max-w-md flex-col justify-center px-4 py-6">
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-brand-700"><?= e($event['organization_name']) ?></p>
            <h1 class="mt-1 text-xl font-black tracking-tight"><?= e($event['title']) ?></h1>
            <p class="mt-4 text-3xl font-black text-slate-900"><?= e($event['price_currency'] ?? 'BZD') ?> <?= number_format($price, 2) ?></p>
            <p class="mt-1 text-sm text-slate-500">One-time payment for access to this event.</p>

            <?php if (!$paymentReady): ?>
                <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">This organization hasn't finished setting up payments yet. Please check back later.</div>
            <?php else: ?>
                <div id="payError" class="mt-5 hidden rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"></div>
                <form id="payForm" class="mt-5 space-y-4">
                    <?= tv_csrf_field() ?>
                    <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                    <div>
                        <label class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Card Number</label>
                        <input name="card_number" inputmode="numeric" autocomplete="cc-number" required class="mt-1.5 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-brand" placeholder="4242 4242 4242 4242">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Expiry (MMYY)</label>
                            <input name="card_expiry" inputmode="numeric" autocomplete="cc-exp" required maxlength="4" class="mt-1.5 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-brand" placeholder="1229">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">CVV</label>
                            <input name="card_cvv" inputmode="numeric" autocomplete="cc-csc" required maxlength="4" class="mt-1.5 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-brand" placeholder="123">
                        </div>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-400">Cardholder Name</label>
                        <input name="card_holder" autocomplete="cc-name" required class="mt-1.5 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm outline-none focus:border-brand" placeholder="Jane Doe">
                    </div>
                    <button type="submit" id="payBtn" class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-black uppercase tracking-[0.12em] text-white transition hover:opacity-90">
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
                    if (!payload.success) throw new Error(payload.message || 'Payment failed.');
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
    <?php tv_render_page_footer(); ?>
</body>
</html>
