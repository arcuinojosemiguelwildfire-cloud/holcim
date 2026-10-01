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

## 2. HTTPS and the registration camera

Browsers only give web pages camera access on **HTTPS** (or `http://localhost` during development).
A phone or tablet opening `http://192.168.x.x` **will be refused camera access**. There is no safe workaround; serve the system over HTTPS (most hosts provide free Let's Encrypt certificates). Handheld USB/Bluetooth QR scanners work without the camera, through the input box on the Registration page.

## 3. Install / update steps

```bash
# Frontend build (on your computer), then upload frontend/dist/* to the web root
cd frontend && npm ci && VITE_API_BASE_URL=/api VITE_BASE_PATH=/ npm run build

# On the server (backend uploaded as <web root>/api)
php backend/cli/migrate.php            # apply all migrations (001-013)
php backend/cli/create-user.php        # first admin, if needed
php backend/cli/check-readiness.php    # must report "No blocking problems found."
```

`check-readiness.php` verifies APP_ENV/APP_DEBUG, that APP_URL is HTTPS and not local, secure cookies, MAJOR_FORM_URL, PHP extensions, database connection, migrations, an admin account, the active event, and attendees without QR codes. It prints no passwords.

## 4. URLs at the event

| URL | Who | Purpose |
|-----|-----|---------|
| `https://domain/` | Staff | Admin app (login) |
| `https://domain/registration` | Registration staff | Camera scanner |
| `https://domain/major-qr` | Event operator | LED screen with the Major QR (fullscreen) |
| `https://domain/minor-randomizer`, `/major-randomizer` | Event operator | Draw stages (fullscreen) |
| `https://domain/major-form` | Public (from the Major QR) | Redirects to `MAJOR_FORM_URL` |
| `https://domain/q/<token>` | Encoded in attendee QR labels | Read by the scanner; not meant to be opened |

## 5. Backups

Back up the database before the event, after registration closes, and after the draws:

```bash
mysqldump -u USER -p DB_NAME > holcim-$(date +%Y%m%d-%H%M).sql
```
