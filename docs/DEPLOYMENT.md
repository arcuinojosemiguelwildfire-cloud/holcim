# Production deployment (event server)

The system needs **PHP 8.1+**, **MySQL 5.7+ / MariaDB 10.4+**, **Apache with mod_rewrite** (or equivalent nginx rules) and **HTTPS**.

## 1. Required configuration (`backend/.env`)

Copy `backend/.env.production.example` to `backend/.env` on the server (never commit it) and set:

| Variable | Value | Why it matters |
|----------|-------|----------------|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Errors must not reveal internals |
| `APP_URL` | `https://your-event-domain` (no trailing slash) | Optional. **Not used in attendee QR codes** (they contain only the token since Phase 9.5), so labels printed on the local system stay valid online. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | your database | Use a dedicated DB user with a strong password |
| `SESSION_SECURE_COOKIE` | `true` | Login cookie only over HTTPS |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_MAX_ATTEMPTS_PER_IP` / `LOGIN_LOCKOUT_MINUTES` | `5` / `30` / `15` | Login brute-force protection (defaults shown) |

## 2. HTTPS and the registration scanners

Registration uses **physical QR scanners** (USB or Bluetooth, keyboard/HID mode) on the Registration page; the device camera is not used. Configure each scanner to send **Enter** after the code (the usual default) and to read QR codes. Still serve the system over **HTTPS** (secure login cookies; most hosts provide free Let's Encrypt certificates).

## 3. Install / update steps

```bash
# Frontend build (on your computer), then upload frontend/dist/* to the web root
cd frontend && npm ci && VITE_API_BASE_URL=/api VITE_BASE_PATH=/ npm run build

# On the server (backend uploaded as <web root>/api)
php backend/cli/migrate.php            # apply all migrations (001-031)
php backend/cli/create-user.php        # first admin, if needed
php backend/cli/check-readiness.php    # must report "No blocking problems found."
```

`check-readiness.php` verifies APP_ENV/APP_DEBUG, secure cookies, PHP extensions, database connection, migrations, an admin account, scanner operators, the active event, its event days and the current day (warns if the current day's date is not today), and attendees without QR codes. It prints no passwords.

### Upgrading an existing Phase 7 installation to Phase 8

Take a backup first (`mysqldump`, see §5), then run `php backend/cli/migrate.php`. Migrations 014–029 are additive and keep all data:

1. `event_days` is created and **every existing event gets a Day 1** on its event date (active event → Day 1 is the current day; draft → upcoming; others → completed).
2. `registration_scans`, `major_eligibility` and `randomizer_draws` get `event_day_id`, back-filled to that event's Day 1, then made NOT NULL with foreign keys. The uniqueness rules change from per-event to **per-day** (`event_day_id, attendee_id`), so the same QR can check in again on Day 2.
3. Existing Major eligibility rows become `source = import` with `added_by` = the user who ran the import; Major import batches are linked to Day 1.
4. New tables `minor_manual_entries` (manual Minor additions) and `scan_logs` (every scan attempt per day).
5. `users` gets the `scanner_operator` role, an optional unique `username`, and `email` becomes optional (scanner operators have none).

Phase 8.1 adds migration 030 (`users.session_version`, used to sign a scanner operator out when an admin resets their password).

### Phase 9.2 (migration 031)

Migration 031 adds the optional `attendees.company` column (no data changes). Major eligibility now comes from registration; the Major QR (`/major-form`, `MAJOR_FORM_URL`), the Major form and the Major eligibility import are removed. `MAJOR_FORM_URL` can be deleted from `backend/.env` (it is ignored). Old imported Major rows stay in the database as history. Excel exports need the PHP `zip` extension (already required for XLSX imports).

Nothing is deleted. After migrating, open **Events › Days** to add Day 2, 3… and check the current day.

### Phase 9.3 (no migration)

Adds manual **Add Attendee** and **Settings › System Reset**. No database change. If the production server was used for testing, an admin can run System Reset **once, before the real event** (take a backup first). It removes all events, attendees, registrations, draws, imports and non-admin accounts and keeps the admin account(s). Afterwards recreate staff, event operator and scanner operator accounts.

### Phase 9.4 (migration 032)

Run `php backend/cli/migrate.php` to apply migration 032 (adds `randomizer_draws.reset_at` / `reset_by`; no data changes). Adds Settings › Randomizer Reset. Winners.xlsx and the draw CSV get two extra columns at the end.

### Phase 9.5 (no migration)

Attendee QR codes now contain only the token. Printed labels do not depend on `APP_URL` or the domain. When moving the local data online, transfer the `attendee_qr_codes` table together with `attendees` and `events` (a full dump/restore keeps every ID and link); do **not** re-import and generate QR codes again online.

### Moving the local database online (QR codes already printed)

Before: `php backend/cli/verify-qr-migration.php --save` on the local machine, then `mysqldump`. After restoring the full dump online: `php backend/cli/verify-qr-migration.php --compare=<manifest.json>` must print `RESULT: PASS`. See README §9m. The tool is read-only.

## 4. URLs at the event

| URL | Who | Purpose |
|-----|-----|---------|
| `https://domain/` | Staff | Admin app (login) |
| `https://domain/registration` | Registration staff, scanner operators | Hardware QR scanner input + scanner dashboard (scanner operators land here after login) |
| `https://domain/minor-randomizer`, `/major-randomizer` | Event operator | Draw stages (fullscreen), + Add Participant |
| `https://domain/settings` | Admin | Scanner Operators (add, enable/disable, reset password) |
| (none) | Attendee QR labels contain only the token, not a URL | Read by the hardware scanner on the Registration page |

## 5. Backups

Back up the database before each event day, after registration closes, and after the draws (multi-day events: at the end of every day):

```bash
mysqldump -u USER -p DB_NAME > holcim-$(date +%Y%m%d-%H%M).sql
```
