<?php
require_once __DIR__ . '/../app/core/Auth.php';
Auth::start();
// Already signed in? Send them home.
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Forgot Password — Centryk</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } };
    </script>
</head>
<body class="min-h-screen bg-slate-100 font-sans antialiased text-slate-900">
<div class="h-[3px] w-full bg-gradient-to-r from-purple-600 via-blue-500 to-orange-500"></div>

<main class="flex min-h-[calc(100vh-3px)] items-center justify-center px-4 py-10">
    <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm">

        <!-- Logo -->
        <div class="mb-6 flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-900 text-white">
                <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5">
                    <rect x="3" y="3" width="7" height="7" rx="1.5"/>
                    <rect x="14" y="3" width="7" height="7" rx="1.5"/>
                    <rect x="3" y="14" width="7" height="7" rx="1.5"/>
                    <rect x="14" y="14" width="7" height="7" rx="1.5"/>
                </svg>
            </span>
            <span class="text-xl font-black tracking-tight text-slate-900">Centryk</span>
        </div>

        <!-- Form view -->
        <div id="formView">
            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Account recovery</p>
            <h1 class="mt-1.5 text-2xl font-black tracking-tight text-slate-950">Forgot your password?</h1>
            <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">Enter the email for your Centryk account and we'll send you a link to reset your password.</p>

            <form id="forgotForm" class="mt-6 space-y-4">
                <div id="alert" class="hidden rounded-2xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600"></div>
                <div>
                    <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Email Address</label>
                    <input id="email" type="email" required autofocus placeholder="you@company.com"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
                <button id="submitBtn"
                    class="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-black uppercase tracking-[0.12em] text-white shadow transition-all duration-200 hover:bg-slate-700 active:scale-[0.99] focus:outline-none focus:ring-4 focus:ring-slate-300">
                    Send Reset Link
                </button>
            </form>
        </div>

        <!-- Success view -->
        <div id="successView" class="hidden text-center">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100">
                <svg class="h-7 w-7 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h1 class="text-xl font-black tracking-tight text-slate-950">Check your inbox</h1>
            <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">If an account exists for that email, we've sent a link to reset your password. The link expires in 30 minutes.</p>
        </div>

        <div class="mt-6 border-t border-slate-100 pt-4 text-center">
            <a href="login.php" class="text-sm font-semibold text-slate-400 transition hover:text-slate-700">
                ← <span class="font-black text-slate-600 hover:text-slate-900">Back to sign in</span>
            </a>
        </div>
    </section>
</main>

<script>
(function () {
    var form     = document.getElementById('forgotForm');
    var alertBox = document.getElementById('alert');
    var btn      = document.getElementById('submitBtn');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        alertBox.classList.add('hidden');
        btn.disabled    = true;
        btn.textContent = 'Sending…';

        fetch('api/auth/forgot-password.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ email: document.getElementById('email').value }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                document.getElementById('formView').classList.add('hidden');
                document.getElementById('successView').classList.remove('hidden');
            } else {
                alertBox.textContent = data.message || 'Something went wrong.';
                alertBox.classList.remove('hidden');
                btn.disabled    = false;
                btn.textContent = 'Send Reset Link';
            }
        })
        .catch(function () {
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.classList.remove('hidden');
            btn.disabled    = false;
            btn.textContent = 'Send Reset Link';
        });
    });
}());
</script>
</body>
</html>
