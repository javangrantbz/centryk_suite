# Centryk

Centryk is the **identity + company hub** in a multi-app suite. It owns the shared
user/company directory and single sign-on; the other apps (spokes) authenticate
against it and pull rosters/identity from it.

- **Hub:** `centryk` (this repo) — auth, companies, SSO, calendar, notifications, banking/OneLink, invoice-maker host.
- **Spokes:** `myPay` (payroll), `onepay`, `invoice-maker`. Siblings under `C:\xampp\htdocs\`.

## Stack & environment

- **PHP** (plain, no framework) on **XAMPP** for Windows. Interpreter: `C:\xampp\php\php.exe`.
- **MySQL** via XAMPP: `C:\xampp\mysql\bin\mysql.exe`, user `root`, no password (local dev).
- Database: **`centryk_core`**. Configured in `app/config/database.php`, overridable via `.env`.
- Only Composer dependency: `phpmailer/phpmailer`.
- Config precedence: `.env` (loaded by `app/core/Env.php` into `$_ENV`) → defaults in `app/config/*.php`.

## Layout

- `app/core/` — framework primitives: `DB` (PDO singleton), `Auth`, `Env`, `Audit`, `Response`, `require_admin.php`.
- `app/config/` — `config.php`, `database.php`, `mail.php`, `notifications.php`.
- `app/services/` — business logic (e.g. `AuthService`).
- `public/` — web root; page controllers are top-level `.php` files (`login.php`, `companies.php`, `calendar.php`, …).
- `public/api/<domain>/` — JSON endpoints grouped by domain (`auth`, `apps`, `calendar`, `banking`, `webhooks`, …).
- `public/partials/` — shared view fragments (`dashboard.php`, `app_switcher.php`, …).
- `database/` — plain `.sql` migrations, applied by hand. `schema.sql` is the base; `add_*.sql` are incremental.

## Key concepts

- **Data model:** `users`, `companies`, `company_members` (join, with `role` = admin|manager|employee),
  `apps`, `user_app_access` (per-user app enrolment), `sso_tokens`, `login_events`.
- **Auth (`app/core/Auth.php`):** session-based for the hub UI; `attempt()` verifies against
  `users.password_hash`, sets `$_SESSION['user_id']`. Login events always logged (never block login on audit failure).
- **SSO:** `Auth::issueToken()` mints a one-time, 60-second token; the spoke redeems it via
  `Auth::consumeToken($token, $appKey)`, which returns the user plus their `companies` and enrolled `apps`.
  Launch URL pattern: `switch.php?app=<key>`.
- **Server-to-server APIs:** spokes call hub endpoints (e.g. `api/apps/company_roster.php`,
  `provision_user.php`, `user_status.php`) authenticated by a **shared `PROVISION_SECRET`** in `.env`,
  compared with `hash_equals`. Calendar endpoints use their own app_key/secret.
- **Responses:** use the `Response` helper (`Response::ok($data)`, `Response::error($msg, $code)`).

## Conventions

- Syntax-check before committing: `C:/xampp/php/php.exe -l <file>`.
- Use **PDO prepared statements** (`DB::pdo()`), never string-interpolated SQL. `ATTR_EMULATE_PREPARES` is off.
- Filter to `status = "active"` on users/companies/memberships unless intentionally including inactive.
- Endpoints are POST + JSON body (`php://input`); reject other methods with 405.
- New DB changes go in a new `database/add_*.sql` file; there is no automatic migration runner.

## Cautions

- **`api/apps/provision_user.php` has a history of role-downgrade bugs** — on 2026-06-03 it clobbered
  every company admin to `employee`. Treat any change to provisioning/role-write logic as high-risk:
  never blanket-write `role` without preserving existing elevated roles, and verify against the
  audit trail. (See memory `incident-provision-role-downgrade`, fix commit `8597b8a`.)
