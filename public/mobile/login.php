<?php
require_once __DIR__ . '/../../app/core/Auth.php';
require_once __DIR__ . '/../../app/services/AuthService.php';

Auth::start();
if (AuthService::me()['authenticated']) {
    header('Location: app.php');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Centryk Mobile</title>
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#2563eb">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="apple-touch-icon" href="assets/icons/icon.png">
  <link rel="icon" href="assets/icons/icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] } } } };
  </script>
  <style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; }
    .safe-bottom { padding-bottom: env(safe-area-inset-bottom, 0); }
    .safe-top { padding-top: env(safe-area-inset-top, 0); }
  </style>
</head>
<body class="min-h-screen bg-slate-950 text-white antialiased">
  <main class="relative flex min-h-screen flex-col overflow-hidden px-5 pb-8 pt-6 safe-top safe-bottom">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_top,#1d4ed833,transparent_40%),linear-gradient(180deg,#020617_0%,#0f172a_48%,#111827_100%)]"></div>
    <div class="absolute -left-10 top-24 h-48 w-48 rounded-full bg-blue-500/20 blur-3xl"></div>
    <div class="absolute -right-10 bottom-20 h-56 w-56 rounded-full bg-cyan-400/10 blur-3xl"></div>

    <div class="relative mx-auto flex w-full max-w-sm flex-1 flex-col">
      <div class="mb-8 pt-4">
        <div class="inline-flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 backdrop-blur">
          <img src="../assets/centryk_logo.png" alt="Centryk" class="h-8 w-auto">
          <span class="text-xs font-black uppercase tracking-[0.24em] text-blue-100">Mobile</span>
        </div>
      </div>

      <div class="mb-8">
        <p class="text-xs font-black uppercase tracking-[0.24em] text-blue-200/70">PWA Access</p>
        <h1 class="mt-3 text-4xl font-black leading-tight tracking-tight">Sign in to your mobile workspace.</h1>
        <p class="mt-3 max-w-xs text-sm font-medium leading-relaxed text-slate-300">
          Faster access to approvals, notifications, and company tools from your phone.
        </p>
      </div>

      <div id="offlineBanner" class="mb-4 hidden rounded-2xl border border-amber-300/30 bg-amber-400/10 px-4 py-3 text-sm font-semibold text-amber-100">
        You are offline. Sign-in requires a live connection.
      </div>

      <div class="rounded-3xl border border-white/10 bg-white/8 p-5 shadow-2xl shadow-slate-950/40 backdrop-blur-xl">
        <form id="loginForm" class="space-y-4">
          <div id="loginAlert" class="hidden rounded-2xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm font-semibold text-rose-100"></div>

          <div>
            <label class="mb-2 block text-[11px] font-black uppercase tracking-[0.18em] text-slate-300">Email</label>
            <input name="email" type="email" required autocomplete="email"
              class="w-full rounded-2xl border border-white/10 bg-slate-950/60 px-4 py-3 text-base font-semibold text-white outline-none transition placeholder:text-slate-500 focus:border-blue-400 focus:ring-4 focus:ring-blue-500/20"
              placeholder="you@company.com">
          </div>

          <div>
            <div class="mb-2 flex items-center justify-between gap-3">
              <label class="block text-[11px] font-black uppercase tracking-[0.18em] text-slate-300">Password</label>
              <a href="../forgot-password.php" class="text-[11px] font-bold text-slate-400">Forgot?</a>
            </div>
            <div class="relative">
              <input name="password" id="passwordInput" type="password" required autocomplete="current-password"
                class="w-full rounded-2xl border border-white/10 bg-slate-950/60 px-4 py-3 pr-12 text-base font-semibold text-white outline-none transition placeholder:text-slate-500 focus:border-blue-400 focus:ring-4 focus:ring-blue-500/20"
                placeholder="Enter password">
              <button id="togglePassword" type="button" class="absolute inset-y-0 right-0 px-4 text-slate-400" aria-label="Toggle password visibility">
                <span id="togglePasswordText" class="text-xs font-black uppercase tracking-[0.12em]">Show</span>
              </button>
            </div>
          </div>

          <button id="loginBtn" type="submit"
            class="w-full rounded-2xl bg-blue-500 px-4 py-3.5 text-sm font-black uppercase tracking-[0.16em] text-white shadow-lg shadow-blue-950/30 transition hover:bg-blue-400 disabled:cursor-not-allowed disabled:opacity-70">
            Sign In
          </button>
        </form>

        <div class="mt-5 rounded-2xl border border-white/10 bg-slate-950/30 px-4 py-3 text-xs font-semibold text-slate-300">
          Install this page to your home screen for an app-like shortcut once login is working on your phone.
        </div>

        <button id="installBtn" type="button" class="mt-4 hidden w-full rounded-2xl border border-white/10 bg-white/8 px-4 py-3 text-sm font-black uppercase tracking-[0.16em] text-white transition hover:bg-white/12">
          Install App
        </button>
      </div>

      <div class="mt-6 flex items-center justify-between gap-4 text-xs font-semibold text-slate-400">
        <a href="../desktop.php" class="transition hover:text-white">Desktop site</a>
        <a href="../contact.php" class="transition hover:text-white">Need help?</a>
      </div>
    </div>
  </main>

  <script>
    (function () {
      var loginForm = document.getElementById('loginForm');
      var loginAlert = document.getElementById('loginAlert');
      var loginBtn = document.getElementById('loginBtn');
      var offlineBanner = document.getElementById('offlineBanner');
      var installBtn = document.getElementById('installBtn');
      var passwordInput = document.getElementById('passwordInput');
      var togglePassword = document.getElementById('togglePassword');
      var togglePasswordText = document.getElementById('togglePasswordText');
      var deferredInstallPrompt = null;

      function syncConnectivity() {
        offlineBanner.classList.toggle('hidden', navigator.onLine);
      }

      window.addEventListener('online', syncConnectivity);
      window.addEventListener('offline', syncConnectivity);
      syncConnectivity();

      togglePassword.addEventListener('click', function () {
        var hidden = passwordInput.type === 'password';
        passwordInput.type = hidden ? 'text' : 'password';
        togglePasswordText.textContent = hidden ? 'Hide' : 'Show';
      });

      window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredInstallPrompt = event;
        installBtn.classList.remove('hidden');
      });

      installBtn.addEventListener('click', async function () {
        if (!deferredInstallPrompt) return;
        deferredInstallPrompt.prompt();
        try {
          await deferredInstallPrompt.userChoice;
        } catch (e) {}
        deferredInstallPrompt = null;
        installBtn.classList.add('hidden');
      });

      loginForm.addEventListener('submit', function (e) {
        e.preventDefault();
        loginAlert.classList.add('hidden');
        loginBtn.disabled = true;
        loginBtn.textContent = 'Signing In...';

        var loginRes = null;
        fetch('../api/auth/login.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            email: loginForm.querySelector('[name="email"]').value,
            password: loginForm.querySelector('[name="password"]').value
          })
        })
        .then(function (r) {
          loginRes = r;
          return r.clone().json();
        })
        .then(function (data) {
          if (data.success) {
            window.location.href = 'app.php';
            return;
          }

          loginAlert.textContent = data.message || 'Invalid credentials.';
          loginAlert.classList.remove('hidden');
          loginBtn.disabled = false;
          loginBtn.textContent = 'Sign In';
        })
        .catch(function (err) {
          if (loginRes) {
            loginRes.clone().text().then(function (bodyText) {
              console.error('Mobile login returned non-JSON.', loginRes.status, bodyText);
            }).catch(function () {});
            loginAlert.textContent = 'Login failed (HTTP ' + loginRes.status + ').';
          } else {
            console.error('Mobile login request failed.', err);
            loginAlert.textContent = 'Network error. Please try again.';
          }
          loginAlert.classList.remove('hidden');
          loginBtn.disabled = false;
          loginBtn.textContent = 'Sign In';
        });
      });

      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(function () {});
      }
    }());
  </script>
</body>
</html>
