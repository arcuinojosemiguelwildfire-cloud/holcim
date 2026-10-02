# Production deployment (event server)

The system needs **PHP 8.1+**, **MySQL 5.7+ / MariaDB 10.4+**, **Apache with mod_rewrite** (or equivalent nginx rules) and **HTTPS**.

## 1. Required configuration (`backend/.env`)

Copy `backend/.env.production.example` to `backend/.env` on the server (never commit it) and set:

| Variable | Value | Why it matters |
|----------|-------|----------------|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Errors must not reveal internals |
| `APP_URL` | `https://your-event-domain` (no trailing slash) | **Attendee QR codes contain `{APP_URL}/q/<token>` and the Major QR uses `{APP_URL}/major-form`.** Set it before generating/printing QR codes. Never print event labels while it is blank or `localhost`. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | your database | Use a dedicated DB user with a strong password |
| `SESSION_SECURE_COOKIE` | `true` | Login cookie only over HTTPS |
| `MAJOR_FORM_URL` | the client's form link (e.g. Google Form) | Target of the Major QR. Can be changed at any time without changing the QR. Empty = scanners see "form not available yet". |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_MAX_ATTEMPTS_PER_IP` / `LOGIN_LOCKOUT_MINUTES` | `5` / `30` / `15` | Login brute-force protection (defaults shown) |

## 2. HTTPS and the registration scanners

Registration uses **physical QR scanners** (USB or Bluetooth, keyboard/HID mode) on the Registration page; the device camera is not used. Configure each scanner to send **Enter** after the code (the usual default) and to read QR codes. Still serve the system over **HTTPS** (secure login cookies; most hosts provide free Let's Encrypt certificates).

## 3. Install / update steps

```bash
# Frontend build (on your computer), then upload frontend/dist/* to the web root
cd frontend && npm ci && VITE_API_BASE_URL=/api VITE_BASE_PATH=/ npm run build

# On the server (backend uploaded as <web root>/api)
php backend/cli/migrate.php            # apply all migrations (001-030)
php backend/cli/create-user.php        # first admin, if needed
php backend/cli/check-readiness.php    # must report "No blocking problems found."
```

`check-readiness.php` verifies APP_ENV/APP_DEBUG, that APP_URL is HTTPS and not local, secure cookies, MAJOR_FORM_URL, PHP extensions, database connection, migrations, an admin account, scanner operators, the active event, its event days and the current day (warns if the current day's date is not today), and attendees without QR codes. It prints no passwords.

### Upgrading an existing Phase 7 installation to Phase 8

Take a backup first (`mysqldump`, see §5), then run `php backend/cli/migrate.php`. Migrations 014–029 are additive and keep all data:

1. `event_days` is created and **every existing event gets a Day 1** on its event date (active event → Day 1 is the current day; draft → upcoming; others → completed).
2. `registration_scans`, `major_eligibility` and `randomizer_draws` get `event_day_id`, back-filled to that event's Day 1, then made NOT NULL with foreign keys. The uniqueness rules change from per-event to **per-day** (`event_day_id, attendee_id`), so the same QR can check in again on Day 2.
3. Existing Major eligibility rows become `source = import` with `added_by` = the user who ran the import; Major import batches are linked to Day 1.
4. New tables `minor_manual_entries` (manual Minor additions) and `scan_logs` (every scan attempt per day).
5. `users` gets the `scanner_operator` role, an optional unique `username`, and `email` becomes optional (scanner operators have none).

Phase 8.1 adds migration 030 (`users.session_version`, used to sign a scanner operator out when an admin resets their password).

Nothing is deleted. After migrating, open **Events › Days** to add Day 2, 3… and check the current day.

## 4. URLs at the event

| URL | Who | Purpose |
|-----|-----|---------|
| `https://domain/` | Staff | Admin app (login) |
| `https://domain/registration` | Registration staff, scanner operators | Hardware QR scanner input + scanner dashboard (scanner operators land here after login) |
| `https://domain/major-qr` | Event operator | LED screen with the Major QR (fullscreen) |
| `https://domain/minor-randomizer`, `/major-randomizer` | Event operator | Draw stages (fullscreen), + Add Participant |
| `https://domain/settings` | Admin | Scanner Operators (add, enable/disable, reset password) |
| `https://domain/major-form` | Public (from the Major QR) | Redirects to `MAJOR_FORM_URL` |
| `https://domain/q/<token>` | Encoded in attendee QR labels | Read by the scanner; not meant to be opened |

## 5. Backups

Back up the database before each event day, after registration closes, and after the draws (multi-day events: at the end of every day):

```bash
mysqldump -u USER -p DB_NAME > holcim-$(date +%Y%m%d-%H%M).sql
```
