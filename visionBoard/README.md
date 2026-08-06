# 🦙 Belize Zoo Signage CMS

A content management system for the digital display in the zoo entrance hallway.
Manage it from any computer or phone; the hallway TV shows the scheduled program
(photos, videos, and biography cards) on a loop.

Built with **PHP 8 + MySQL/MariaDB** (runs on XAMPP), **Tailwind CSS**, and
vanilla JavaScript. Tailwind is **compiled locally** (`assets/css/tailwind.css`),
so the whole system — admin panel and TV player — works fully offline, even if
the internet drops.

### Rebuilding the admin styles
If you edit any admin template and add new Tailwind classes, regenerate the
stylesheet by double-clicking **`build-css.bat`** (uses the bundled offline CLI
in `tools\`). No internet or Node required.

---

## 1. First-time setup

1. Start **Apache** and **MySQL** in the XAMPP control panel.
2. In a browser go to: `http://localhost/zoocm/install.php`
   This creates the database, tables, and the default admin account.
3. Sign in at `http://localhost/zoocm/admin/login.php`
   - **Username:** `admin`
   - **Password:** `admin123`
4. Go to **Settings → Change your password** and set a real password.

> If MySQL uses a password, edit `config/config.php` (`DB_USER` / `DB_PASS`).

## 2. Putting content on the TV

1. **Media Library** – upload photos and videos.
2. **Content** – create items:
   - *Image* / *Video* → pick an uploaded file, set how long it shows.
   - *Biography* → a text card (title, animal name, description).
3. **Playlists** – make a playlist (e.g. “Entrance Loop”), add content items,
   drag the order with ▲ ▼, and set per-item durations.
4. **Schedules** – tell the TV *when* to play a playlist:
   - Date range, time range, days of week, and a **priority**
     (higher priority wins when two schedules overlap).
   - Leave a field blank for “always”. If nothing is scheduled, the first
     active playlist plays as a fallback.

## 3. The hallway TV

Open this URL on the TV’s browser and press **⛶ Fullscreen**:

```
http://<this-computer-ip>/zoocm/display/
```

- Find the computer’s IP with `ipconfig` (e.g. `192.168.1.50`) so other
  devices/the TV on the same network can reach it:
  `http://192.168.1.50/zoocm/display/`
- The display auto-refreshes: it re-checks the schedule every few seconds and at the
  end of each loop, so changes you make appear without touching the TV.
- The display sends a heartbeat to the dashboard every few seconds. If the
  browser loses server contact repeatedly, or resumes after a long stall, it
  reloads itself.
- Windows kiosk startup one-liner: put a shortcut in the Startup folder that
  runs `chrome.exe --kiosk http://<this-computer-ip>/zoocm/display/`.

### Dashboard operations
- **Now / Next** shows what the TV reports it is playing, what is queued next,
  and whether the display has checked in recently.
- **Announcement override** instantly pushes a full-screen message to the TV
  for a set number of minutes. It interrupts the normal loop and clears itself
  automatically when the timer expires, or you can clear it from the dashboard.

### Display options (Settings → Display)
- **Slide transition** – choose how content changes: *Fade*, *Slide*, *Zoom*, or
  *Ken Burns* (a slow cinematic pan & zoom on photos).
- **Visitor QR codes** – show one or more on-screen QR codes (website, zoo map,
  tickets, donations…), each with its own caption. Add/edit/reorder/delete them
  in **Settings → Visitor QR codes**, toggle them all on/off, and set how many
  **seconds each code shows** — with more than one, the card rotates through them
  with a soft fade. The QR images are generated **on the TV itself**, so they
  show even with no internet (visitors need internet only to open the link).
  Powered by the bundled `assets/js/qrcode.min.js` (MIT).

## Folder layout

| Path | Purpose |
|------|---------|
| `install.php` | One-time database installer |
| `config/config.php` | DB credentials & app settings |
| `admin/` | Management panel (login, media, content, playlists, schedules, settings) |
| `display/` | Full-screen TV player |
| `api/current.php` | JSON feed the player reads |
| `uploads/` | Stored media (script execution blocked here) |
| `includes/`, `assets/` | Shared PHP helpers, CSS, JS |

## Security notes

- Passwords are hashed (`password_hash`); forms are CSRF-protected.
- Uploads are validated by extension **and** MIME type, and PHP execution is
  disabled inside `uploads/`.
- This is designed for a **trusted local network**. If you ever expose it to the
  public internet, put it behind HTTPS and review access first.
