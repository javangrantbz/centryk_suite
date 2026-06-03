<?php
require_once __DIR__ . '/../app/services/PasswordResetService.php';

$token   = trim($_GET['token'] ?? '');
$valid   = false;
$greeting = 'there';

if ($token !== '') {
    try {
        $info = (new PasswordResetService())->validateToken($token);
        if ($info) {
            $valid    = true;
            $greeting = $info['first_name'] ?: 'there';
        }
    } catch (Throwable $e) {
        $valid = false;
    }
}

$safeToken    = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
$safeGreeting = htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title>Reset Password — Centryk</title>
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

        <?php if (!$valid): ?>

        <!-- Invalid / expired token -->
        <div class="rounded-2xl border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-bold text-red-700">This reset link is invalid or has expired.</p>
            <p class="mt-1 text-xs font-semibold text-red-500">Reset links are valid for 30 minutes and can only be used once.</p>
        </div>
        <a href="forgot-password.php"
           class="mt-5 block w-full rounded-xl bg-slate-900 px-4 py-3 text-center text-sm font-black uppercase tracking-[0.12em] text-white shadow transition hover:bg-slate-700">
            Request a new link
        </a>

        <?php else: ?>

        <!-- Reset form -->
        <div id="formView">
            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Account recovery</p>
            <h1 class="mt-1.5 text-2xl font-black tracking-tight text-slate-950">Set a new password</h1>
            <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500">Hi <?= $safeGreeting ?>, choose a new password for your Centryk account.</p>

            <form id="resetForm" class="mt-6 space-y-4">
                <input type="hidden" id="token" value="<?= $safeToken ?>">
                <div id="alert" class="hidden rounded-2xl border border-red-200 bg-red-50 p-3 text-xs font-semibold text-red-600"></div>

                <div>
                    <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">New Password</label>
                    <input id="password" type="password" required minlength="8" placeholder="At least 8 characters"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>
                <div>
                    <label class="mb-1.5 block text-[10px] font-black uppercase tracking-[0.18em] text-slate-500">Confirm Password</label>
                    <input id="confirmPassword" type="password" required minlength="8" placeholder="Repeat your password"
                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                </div>

                <!-- Strength bar -->
                <div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                        <div id="strengthBar" class="h-full rounded-full transition-all duration-300" style="width:0%"></div>
                    </div>
                    <p id="strengthLabel" class="mt-1 text-[10px] font-bold text-slate-400"></p>
                </div>

                <button id="submitBtn"
                    class="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-black uppercase tracking-[0.12em] text-white shadow transition-all duration-200 hover:bg-slate-700 active:scale-[0.99] focus:outline-none focus:ring-4 focus:ring-slate-300 disabled:opacity-50">
                    Update Password
                </button>
            </form>
        </div>

        <!-- Success -->
        <div id="successView" class="hidden text-center">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100">
                <svg class="h-7 w-7 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h1 class="text-xl font-black tracking-tight text-slate-950">Password updated</h1>
            <p class="mt-2 text-sm font-semibold leading-relaxed text-slate-500 mb-6">You can now sign in to Centryk and your apps with your new password.</p>
            <a href="login.php"
               class="inline-block w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-black uppercase tracking-[0.12em] text-white shadow transition hover:bg-slate-700">
                Go to Sign In →
            </a>
        </div>

        <?php endif; ?>

        <div class="mt-6 border-t border-slate-100 pt-4 text-center">
            <a href="login.php" class="text-sm font-semibold text-slate-400 transition hover:text-slate-700">
                ← <span class="font-black text-slate-600 hover:text-slate-900">Back to sign in</span>
            </a>
        </div>
    </section>
</main>

<script>
(function () {
    var form = document.getElementById('resetForm');
    if (!form) return; // invalid-token state has no form

    var pwInput       = document.getElementById('password');
    var strengthBar   = document.getElementById('strengthBar');
    var strengthLabel = document.getElementById('strengthLabel');

    function scorePassword(p) {
        var s = 0;
        if (p.length >= 8)          s++;
        if (p.length >= 12)         s++;
        if (/[A-Z]/.test(p))        s++;
        if (/[0-9]/.test(p))        s++;
        if (/[^A-Za-z0-9]/.test(p)) s++;
        return s;
    }

    pwInput.addEventListener('input', function () {
        var score  = scorePassword(this.value);
        var colors = ['bg-red-400','bg-orange-400','bg-amber-400','bg-lime-500','bg-emerald-500'];
        var labels = ['Very weak','Weak','Fair','Good','Strong'];
        strengthBar.className   = 'h-full rounded-full transition-all duration-300 ' + (colors[score - 1] || 'bg-slate-200');
        strengthBar.style.width = Math.min(score * 20, 100) + '%';
        strengthLabel.textContent = this.value ? (labels[score - 1] || '') : '';
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var alertBox = document.getElementById('alert');
        var btn      = document.getElementById('submitBtn');
        var pw       = document.getElementById('password').value;
        var confirm  = document.getElementById('confirmPassword').value;

        alertBox.classList.add('hidden');

        if (pw !== confirm) {
            alertBox.textContent = 'Passwords do not match.';
            alertBox.classList.remove('hidden');
            return;
        }

        btn.disabled    = true;
        btn.textContent = 'Updating…';

        fetch('api/auth/reset-password.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                token:            document.getElementById('token').value,
                password:         pw,
                confirm_password: confirm,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                document.getElementById('formView').classList.add('hidden');
                document.getElementById('successView').classList.remove('hidden');
            } else {
                alertBox.textContent = data.message || 'Could not reset password.';
                alertBox.classList.remove('hidden');
                btn.disabled    = false;
                btn.textContent = 'Update Password';
            }
        })
        .catch(function () {
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.classList.remove('hidden');
            btn.disabled    = false;
            btn.textContent = 'Update Password';
        });
    });
}());
</script>
</body>
</html>
