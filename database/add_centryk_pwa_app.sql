-- Registers the Centryk PWA (C:\xampp\htdocs\centryk_pwa) as a launchable
-- app, same mechanism OnePay/MyPay/Calendar/etc already use. Auto-granted to
-- every active company member (see AuthService::syncCompanyAppAccess), same
-- treatment as Vision Board and Centryk TV - this is cross-cutting hub
-- tooling, not a per-company opt-in app.

INSERT INTO apps (`key`, label, description, url_local, url_production, icon, color, sort_order, opt_in, status)
VALUES (
  'centryk_pwa',
  'Centryk App',
  'Mobile hub for approvals, notifications, and reports across the suite.',
  'http://localhost/centryk_pwa/public/sso.php',
  '',
  '📱',
  '#2563eb',
  1,
  0,
  'active'
)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  description = VALUES(description),
  url_local = VALUES(url_local),
  icon = VALUES(icon),
  color = VALUES(color),
  status = VALUES(status);
