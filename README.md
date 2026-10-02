# Holcim Event System

An online event management and prize-draw system: attendee import, QR-based
registration, minor and major randomizers, winner management, reports and an
audit trail.

> **Current status: Phase 8 (multi-day events).** Events run over several Event Days with one current day; registration, Minor/Major eligibility, draws and reports are per day. New **Scanner Operator** role (scanner only, username login, managed in Settings) and **+ Add Participant** for manual Minor/Major pool additions. See [§9g](#9g-multi-day-events-scanner-operators-and-manual-participants-phase-8).
>
> Phase 7 (event-day readiness): login rate limiting, CSV reports, draw voiding, the Major QR (`/major-form` → `MAJOR_FORM_URL`), a readiness check and the [deployment guide](docs/DEPLOYMENT.md) and [event-day checklist](docs/EVENT_DAY_CHECKLIST.md).
>
> Phase 6 (Major): Phase 6 adds Major eligibility import (exported Google Sheet CSV/XLSX), the Major Eligibility page and the Major Randomizer.
>
> Phase 5 (Minor Randomizer): Phase 5 adds the server-side Minor draw, draw history and Event Fullscreen Mode.
>
> Phase 4 (Registration): Phase 4 adds the QR scanner page (hardware USB/Bluetooth scanner since Phase 9.1), check-in, duplicate protection and Minor draw eligibility.
>
> Phase 3 (QR codes): Phase 3 adds attendee QR generation, the QR / ID Generator page, regeneration and A4 bulk printing.
>
> Phase 2 (Attendees): Phase 1 delivered the foundation
> (schema, API, auth, admin UI, dashboard, events). Phase 2 adds attendee
> import (CSV/XLSX with dynamic column mapping, validation and duplicate
> detection) and attendee management (search, filter, pagination, view, edit,
> archive). QR codes, scanning, randomizers, Google Sheets, winners, reports and
> fullscreen mode are **not implemented yet**.

---

## Table of contents

1. [Project purpose](#1-project-purpose)
2. [Technology stack](#2-technology-stack)
3. [Folder structure](#3-folder-structure)
4. [Local setup (quick start)](#4-local-setup-quick-start)
5. [XAMPP requirements](#5-xampp-requirements)
6. [MySQL database setup](#6-mysql-database-setup)
7. [Environment configuration](#7-environment-configuration)
8. [Running the React frontend](#8-running-the-react-frontend)
9. [How the PHP API works](#9-how-the-php-api-works)
10. [Creating the first admin](#10-creating-the-first-admin)
11. [Building the frontend for production](#11-building-the-frontend-for-production)
12. [Deployment considerations](#12-deployment-considerations)
13. [Git workflow](#13-git-workflow)
14. [Testing](#14-testing)
15. [Troubleshooting](#15-troubleshooting)

More detail lives in [`docs/`](docs): [architecture](docs/ARCHITECTURE.md),
[API reference](docs/API.md) and [database schema](docs/DATABASE.md).

---

## 1. Project purpose

The finished system will support a corporate event from start to finish:

| # | Module | Phase 1 status |
|---|--------|----------------|
| 1 | Event management | **Built** (create, view, edit, set status) |
| 2 | Attendee import (Excel/CSV, dynamic column mapping) | **Built** (Phase 2) |
| 3 | Automatic attendee QR generation (opaque tokens) | **Built** (Phase 3) |
| 4 | Printable attendee QR/ID | **Built** (Phase 3, A4 label sheets) |
| 5 | Registration QR scanner | **Built** (Phase 4) |
| 6 | Minor randomizer | **Built** (Phase 5) |
| 7 | Major randomizer | **Built** (Phase 6) |
| 8 | Google Form / Google Sheet response import | Schema ready |
| 9 | Winner management | Planned |
| 10 | Reports | **Built** (Phase 7, CSV exports) |
| 11 | Audit logs | **Recording** logins, logouts and event changes |
| 12 | Fullscreen event mode | **Built** for the Minor Randomizer (reusable stage) |
| 13 | Multi-day events, scanner operators, manual draw participants | **Built** (Phase 8) |

The system is built around one **active event** at a time and its **current
Event Day**: the dashboard, registration, eligibility and randomizers always
work on the current day of the active event.

## 2. Technology stack

| Layer | Technology |
|-------|------------|
| Frontend | React 19, TypeScript (strict), Vite, Tailwind CSS v4, React Router 7, Lucide icons |
| Backend | Plain PHP 8.1+ (no framework, no Composer packages), REST-style JSON API, PDO |
| Database | MySQL 5.7+ / MariaDB 10.4+ (InnoDB, utf8mb4) |
| Local environment | XAMPP (Apache + PHP + MariaDB). Docker is not required |
| Production | Any standard PHP + MySQL host with Apache `mod_rewrite` (or nginx, see §12) |

The backend has no Composer dependencies on purpose, so it deploys by simply
uploading files to shared hosting.

## 3. Folder structure

```
holcim/
├── .htaccess               # Local safety net: blocks .git, database/, docs/ when the repo is in htdocs
├── .gitignore
├── README.md
│
├── backend/                # PHP API (served by Apache; everything routes to index.php)
│   ├── .htaccess           # Rewrites every request to index.php; denies dotfiles
│   ├── .env.example        # Local (XAMPP) configuration template
│   ├── .env.production.example
│   ├── index.php           # Front controller: headers, CORS, CSRF, routing, error handling
│   ├── bootstrap.php       # Autoloader, .env, config, time zone (shared by HTTP + CLI)
│   ├── api/routes.php      # Route table: every endpoint is declared here
│   ├── config/             # app.php, database.php, session.php (read from environment)
│   ├── core/               # Small framework: Router, Request, Response, Database, Session, Env, Config, HttpException
│   ├── controllers/        # HTTP layer: validate input, call a service, return a Response
│   ├── middleware/         # AuthMiddleware (login/roles), CsrfMiddleware, CorsMiddleware
│   ├── models/             # Data access (PDO prepared statements only)
│   ├── services/           # Business rules (AuthService, EventService, DashboardService, AuditLogger)
│   ├── utils/              # Validator, Csrf, Token (secure random tokens)
│   ├── cli/                # migrate.php, create-user.php (command line only)
│   └── tests/              # smoke-test.php (end-to-end API checks)
│
├── frontend/               # React SPA
│   ├── index.html
│   ├── package.json
│   ├── vite.config.ts      # Dev proxy /api -> XAMPP, build base path
│   ├── .env.example
│   ├── public/             # favicon, .htaccess (SPA fallback copied into dist/)
│   └── src/
│       ├── App.tsx         # Routes
│       ├── main.tsx
│       ├── components/     # ui/ (Button, FormField, Modal...), layout/, auth/, events/, dashboard/
│       ├── layouts/        # AdminLayout, navigation config
│       ├── pages/          # LoginPage, DashboardPage, EventsPage, ComingSoonPage, NotFoundPage
│       ├── services/       # apiClient (fetch + CSRF + errors), auth/event/dashboard services
│       ├── hooks/          # useAuth, useApiQuery
│       ├── types/          # API, auth, event, dashboard types
│       ├── utils/          # formatting, labels, cn()
│       └── assets/
│
├── database/
│   ├── migrations/         # 001_...sql to 008_...sql, applied in order
│   └── seeders/            # Intentionally empty (no fake data), see README inside
│
└── docs/                   # ARCHITECTURE.md, API.md, DATABASE.md
```

> `backend/core/` is an addition to the originally proposed structure. It holds
> the small framework pieces (router, request/response, DB, session) so they
> stay separate from business code in `services/` and data access in `models/`.

## 4. Local setup (quick start)

Assumes macOS with XAMPP installed at `/Applications/XAMPP`. Windows paths are
noted where they differ.

```bash
# 1. Put the project in XAMPP's web root
cd /Applications/XAMPP/xamppfiles/htdocs        # Windows: C:\xampp\htdocs
git clone https://github.com/arcuinojosemiguelwildfire-cloud/holcim.git Holcim
cd Holcim

# 2. Start Apache and MySQL in the XAMPP control panel (manager-osx)

# 3. Create the database (see §6 for a dedicated DB user)
/Applications/XAMPP/xamppfiles/bin/mysql -u root -e \
  "CREATE DATABASE holcim_event CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Configure the backend
cp backend/.env.example backend/.env             # then edit DB_* if needed

# 5. Create the tables
/Applications/XAMPP/xamppfiles/bin/php backend/cli/migrate.php

# 6. Create the first admin (prompts for name, email, password)
/Applications/XAMPP/xamppfiles/bin/php backend/cli/create-user.php

# 7. Check the API
curl http://localhost/Holcim/backend/api/health

# 8. Run the frontend
cd frontend
npm install
npm run dev                                      # open http://localhost:5173
```

The folder name `Holcim` matters: the default API URL is
`http://localhost/Holcim/backend/api`. If you use another folder name, set
`DEV_API_PROXY_TARGET` in `frontend/.env.local` (see §7).

> **Tip:** add XAMPP's binaries to your PATH so you can type `php` and `mysql`:
> `echo 'export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"' >> ~/.zshrc`

## 5. XAMPP requirements

| Requirement | Notes |
|-------------|-------|
| XAMPP with **PHP 8.1 or newer** | PHP 8.2+ recommended. Check with `php -v` |
| Apache **mod_rewrite** | Enabled by default in XAMPP |
| `AllowOverride All` for htdocs | XAMPP default. Needed for the `.htaccess` files |
| PHP extensions: `pdo_mysql`, `mbstring`, `json`, `zip`, `simplexml` | All bundled and enabled in XAMPP (`zip`/`simplexml` read .xlsx imports). `curl` is only needed for the smoke test |
| MariaDB/MySQL running | Start it from the XAMPP control panel |
| **Node.js 20.19+ or 22.12+** and npm | Only for frontend development and builds. Not needed on the production server |

Verify PHP extensions:

```bash
/Applications/XAMPP/xamppfiles/bin/php -m | grep -Ei "pdo_mysql|mbstring|json"
```

> The CLI `php` that XAMPP ships is the same PHP that Apache uses. If your
> system also has Homebrew PHP, call XAMPP's binary explicitly as shown above,
> or make sure both have `pdo_mysql`.

## 6. MySQL database setup

### Create the database

Using the terminal (or the SQL tab in phpMyAdmin at <http://localhost/phpmyadmin>):

```sql
CREATE DATABASE holcim_event CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Recommended: a dedicated database user

XAMPP's `root` user has no password, which is fine on your own machine. Using a
dedicated user locally mirrors production and avoids surprises later:

```sql
CREATE USER 'holcim'@'localhost' IDENTIFIED BY 'choose-a-local-password';
CREATE USER 'holcim'@'127.0.0.1' IDENTIFIED BY 'choose-a-local-password';
GRANT ALL PRIVILEGES ON holcim_event.* TO 'holcim'@'localhost';
GRANT ALL PRIVILEGES ON holcim_event.* TO 'holcim'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Then set `DB_USERNAME=holcim` and `DB_PASSWORD=...` in `backend/.env`.

### Run the migrations

```bash
php backend/cli/migrate.php            # apply pending migrations
php backend/cli/migrate.php --status   # show applied / pending
```

The runner applies `database/migrations/*.sql` in filename order and records
each one in a `schema_migrations` table, so running it again is safe.

**Without a terminal:** in phpMyAdmin, select `holcim_event` → Import, and
import the files in `database/migrations/` one by one **in numeric order**
(001 → 008). If you do this, don't also run `migrate.php` on the same database:
it would see no `schema_migrations` records and try the files again. The
`CREATE TABLE IF NOT EXISTS` statements make that harmless, but the CLI is the
recommended path.

### Adding a migration (future phases)

Create the next numbered file, e.g. `database/migrations/009_create_winners_table.sql`,
containing **one** SQL statement. Never edit a migration that has already run on
a shared or production database; add a new one instead.

## 7. Environment configuration

Configuration comes from environment variables. Locally they are read from
`backend/.env`; in production they can come from the same file or from real
environment variables set by the host, which take precedence.
**`.env` files are git-ignored. Never commit real credentials.**

### Backend (`backend/.env`)

| Variable | Local (XAMPP) | Production | Purpose |
|----------|---------------|------------|---------|
| `APP_NAME` | `Holcim Event System` | same | Shown in `/health` |
| `APP_ENV` | `local` | `production` | Environment name |
| `APP_DEBUG` | `true` | **`false`** | `true` puts exception messages in API errors |
| `APP_TIMEZONE` | `Asia/Manila` | `Asia/Manila` | PHP time zone; the MySQL session time zone is aligned to it |
| `APP_URL` | `http://localhost:5173` | `https://your-domain` | Public base URL. QR codes contain `{APP_URL}/q/{token}`; empty = token only. **Set before printing QR codes** |
| `MAJOR_FORM_URL` | *(empty)* | client's form link | Target of `/major-form` (the Major QR). Empty = safe "not available yet" page |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_MAX_ATTEMPTS_PER_IP` / `LOGIN_LOCKOUT_MINUTES` | `5` / `30` / `15` | same | Failed logins allowed per email / per IP before a temporary lockout |
| `DB_HOST` | `127.0.0.1` | host's DB server | Use `127.0.0.1` rather than `localhost` on macOS XAMPP (TCP instead of a socket path) |
| `DB_PORT` | `3306` | `3306` | |
| `DB_DATABASE` | `holcim_event` | your DB name | |
| `DB_USERNAME` | `root` or `holcim` | your DB user | |
| `DB_PASSWORD` | *(empty for root)* | **strong secret** | Quote it if it contains spaces or `#`: `DB_PASSWORD="a b#c"` |
| `SESSION_NAME` | `holcim_session` | same | Session cookie name |
| `SESSION_LIFETIME` | `28800` | `28800` | Idle timeout in seconds (8 h) |
| `SESSION_PATH` | `/` | `/` | Cookie path |
| `SESSION_DOMAIN` | *(empty)* | *(empty)* or your domain | Cookie domain |
| `SESSION_SECURE_COOKIE` | `false` | **`true`** | Send the cookie only over HTTPS. Must be `false` on `http://localhost` |
| `SESSION_SAMESITE` | `Lax` | `Lax` | `Lax`, `Strict` or `None` |
| `CORS_ALLOWED_ORIGINS` | *(empty)* | *(empty)* | Only if the SPA runs on a **different origin** than the API. Comma-separated |

Templates: `backend/.env.example` (local) and `backend/.env.production.example`.

### Frontend (`frontend/.env.local`, optional)

| Variable | Default | Purpose |
|----------|---------|---------|
| `VITE_API_BASE_URL` | `/api` | Where the browser sends API calls. In dev keep `/api` (proxied). In production, the public path of the API |
| `VITE_BASE_PATH` | `/` | Public path the built app is served from |
| `DEV_API_PROXY_TARGET` | `http://localhost/Holcim/backend` | Dev only, not exposed to the browser: where Vite forwards `/api` |

Only `VITE_*` variables reach browser code, and they are public. **Never put
secrets in frontend environment variables.**

## 8. Running the React frontend

```bash
cd frontend
npm install        # first time only
npm run dev        # http://localhost:5173
```

Other scripts:

| Command | What it does |
|---------|--------------|
| `npm run dev` | Dev server with hot reload |
| `npm run typecheck` | TypeScript check (strict mode) |
| `npm run lint` | ESLint |
| `npm run build` | Type-check + production build into `frontend/dist/` |
| `npm run preview` | Serve the production build locally |

**How the dev server reaches PHP:** the browser only talks to
`http://localhost:5173`. Requests to `/api/*` are proxied by Vite to
`http://localhost/Holcim/backend/api/*` (Apache in XAMPP). Because the browser
sees a single origin, the session cookie and CSRF protection work with **no
CORS configuration**. Apache and MySQL must be running.

## 9. How the PHP API works

### Request lifecycle

```
Browser ──> /Holcim/backend/api/events
             │  backend/.htaccess rewrites everything to index.php
             ▼
          index.php
             ├─ security headers (nosniff, DENY framing, no-store)
             ├─ CORS (only for allow-listed origins)
             ├─ Request::fromGlobals()  → path "/events", parsed JSON body
             ├─ CSRF check for POST/PUT/PATCH/DELETE (global, cannot be forgotten)
             ├─ Router (api/routes.php) → route middleware (auth / roles)
             │                          → Controller → Service → Model (PDO)
             └─ Response::send()  → JSON
          Any exception → JSON error (no stack traces unless APP_DEBUG=true)
```

The router strips the install location automatically, so the same code works at
`http://localhost/Holcim/backend/api/...`, at `https://example.com/api/...`
(backend uploaded as `/api`), and under `php -S`.

### Response format

Every endpoint returns one of two shapes:

```json
{ "success": true, "data": { ... }, "message": "optional" }
```

```json
{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "The given data was invalid.",
  "details": { "fields": { "name": ["Event name is required."] } } } }
```

Error codes: `BAD_REQUEST` (400), `UNAUTHENTICATED` / `INVALID_CREDENTIALS` (401),
`FORBIDDEN` / `CSRF_TOKEN_MISMATCH` (403), `NOT_FOUND` (404),
`METHOD_NOT_ALLOWED` (405), `CONFLICT` (409), `VALIDATION_ERROR` (422),
`SERVER_ERROR` (500).

### Phase 1 endpoints

| Method | Path | Access | Purpose |
|--------|------|--------|---------|
| GET | `/api/health` | Public | API + database status |
| GET | `/api/auth/session` | Public | `{authenticated, user, csrfToken}`. Always 200 |
| POST | `/api/auth/login` | Public + CSRF | Email/password login |
| POST | `/api/auth/logout` | CSRF | End the session |
| GET | `/api/auth/me` | Signed in | **Protected route example**: 401 if not signed in |
| GET | `/api/dashboard/summary` | Admin, registration staff, event operator | Active event, current day + per-day metrics |
| GET | `/api/events` | Signed in | List events (active first) |
| GET | `/api/events/{id}` | Signed in | One event |
| POST | `/api/events` | Admin | Create event |
| PUT | `/api/events/{id}` | Admin | Update event |
| PATCH | `/api/events/{id}/status` | Admin | Change status |
| GET | `/api/attendees` | Admin, registration staff, event operator | Search/filter/paginate attendees of the active event |
| GET | `/api/attendees/{id}` | Admin, registration staff, event operator | One attendee |
| PUT | `/api/attendees/{id}` | Admin | Edit name, department, email, external ID (never the code) |
| PATCH | `/api/attendees/{id}/status` | Admin | Archive / restore (soft delete) |
| POST | `/api/attendees/import/parse` | Admin | Upload CSV/XLSX (multipart `file`, ≤ 5 MB) → headers, rows, suggested mapping |
| POST | `/api/attendees/import/preview` | Admin | Validate + duplicate check, no writes |
| POST | `/api/attendees/import` | Admin | Import only new valid rows |
| GET | `/api/qr-codes/summary` | Admin, registration staff | Active / generated / missing counts |
| GET | `/api/qr-codes` | Admin, registration staff | Active attendees with QR status (`qr_status=generated\|missing`) |
| POST | `/api/qr-codes/generate-missing` | Admin | Create QR only for active attendees without one |
| GET | `/api/qr-codes/print?ids=` | Admin, registration staff | Print data (all, or selected IDs) |
| GET / POST | `/api/attendees/{id}/qr` | Viewers / Admin | QR state / generate if missing |
| POST | `/api/attendees/{id}/qr/regenerate` | Admin | Replace token (old QR becomes invalid) |
| GET | `/api/registration/summary` | Admin, registration staff, scanner operator | Current day: total / registered / remaining, my / all scans today, latest 20 check-ins |
| GET | `/api/randomizers/minor` | Admin, event operator | Event, eligible count, latest 10 winners |
| POST | `/api/randomizers/minor/draw` | Admin, event operator | Server-side draw; records it; returns the winner (409 `NO_ELIGIBLE_ATTENDEES` if the pool is empty) |
| GET | `/api/randomizers/major` · POST `/api/randomizers/major/draw` | Admin, event operator | Same as Minor, drawing from Major Eligible attendees |
| GET | `/api/major-eligibility` · `/api/major-eligibility/imports` | Admin, event operator | Major Eligible list (search/department/pages) and import history |
| POST | `/api/major-eligibility/import/parse` · `/preview` · `/import` | Admin | Upload → match preview → commit |
| POST | `/api/randomizers/draws/{id}/void` | Admin, event operator | Mark a draw VOID `{reason?}` (kept in history; eligibility unchanged) |
| GET | `/api/reports/registration.csv` · `major-eligibility.csv` · `draws.csv` `?scope=day\|all` | Admin | CSV exports for the current day or all days of the active event |
| GET | `/api/major-form` | Public | 302 redirect to `MAJOR_FORM_URL` (or a safe "not available yet" page) |
| GET | `/api/major-form/info` | Admin, event operator | Whether the form is configured + the QR target URL |
| POST | `/api/registration/scan` | Admin, registration staff, scanner operator | `{token}` (token or full QR URL) → `registered` / `already_registered` (for the current day), or 422 `INVALID_QR` / `WRONG_EVENT` / `ATTENDEE_INACTIVE`; 409 `NO_ACTIVE_DAY` |
| GET | `/api/registration/scans?view=mine\|all&result=all\|successful\|already_registered\|invalid&page=` | Admin, registration staff, scanner operator | Scan attempts of the current day (20 per page) |
| GET | `/api/registration/attendees?search=` | Signed in | Read-only lookup: attendee + registered today |
| GET | `/api/event-days/current` | Signed in | Active event + current Event Day |
| GET / POST | `/api/events/{id}/days` | Admin | List / add event days `{event_date, label?}` |
| PUT · PATCH | `/api/event-days/{id}` · `/api/event-days/{id}/activate` | Admin | Edit a day / make it the current day |
| GET | `/api/randomizers/{minor\|major}/participants?search=&page=` | Admin, event operator | Today's pool with source (registration / import / manual) |
| GET | `/api/randomizers/{minor\|major}/candidates?search=` | Admin, event operator | Active attendees to add, with `alreadyEligible` |
| POST | `/api/randomizers/{minor\|major}/participants` | Admin, event operator | `{attendee_id, reason?}` manual add for today (409 `ALREADY_ELIGIBLE`) |
| GET / POST | `/api/settings/scanner-operators` | Admin | List / create `{name, username, password, password_confirmation, status}` |
| PATCH · POST | `/api/settings/scanner-operators/{id}` · `/{id}/password` | Admin | Enable/disable `{status}` / reset password |

Full request/response examples: [docs/API.md](docs/API.md).

### Authentication and security

- **Sessions**, not tokens in localStorage. The cookie is `HttpOnly`,
  `SameSite=Lax`, `Secure` in production, with strict mode and an
  8-hour idle timeout. The session ID is regenerated on login.
- **CSRF**: synchronizer token. The SPA gets it from `/auth/session` and sends
  it as `X-CSRF-Token` on every state-changing request. It rotates on login.
- **Passwords** are hashed with `password_hash()` (bcrypt by default) and
  rehashed automatically if PHP's default algorithm changes. Login always runs
  `password_verify()`, even for unknown emails, so response timing doesn't
  reveal which accounts exist.
- **Authorization**: route middleware `AuthMiddleware::authenticated()` and
  `AuthMiddleware::roles('admin', ...)`. The user is reloaded from the
  database on every request, so deactivating an account or changing a role
  takes effect immediately.
- **SQL**: PDO with real prepared statements (`ATTR_EMULATE_PREPARES=false`).
  No user input is ever concatenated into SQL. Dynamic column lists come from
  whitelists.
- **Input**: `Validator` returns only declared fields (no mass assignment) and
  reports every field error at once.
- **Audit log**: logins, failed logins, logouts and every event
  create/update/status change are written to `audit_logs`.

### Adding an endpoint (future phases)

1. Add data access to `backend/models/` (prepared statements only).
2. Put business rules in `backend/services/`.
3. Add a controller method that validates input with `Validator` and returns `Response`.
4. Register the route in `backend/api/routes.php` with the right middleware.

## 9a. Attendee import rules (Phase 2)

- Workflow: upload → preview → map columns → validate → duplicate check → confirm → database. The server re-validates everything; spreadsheet contents are never trusted.
- Accepted: `.csv` (comma, semicolon, tab or pipe; UTF-8 or Windows-1252) and `.xlsx` (first sheet), up to 5 MB / 5,000 rows. `.xls` is rejected with instructions to re-save as `.xlsx`/CSV.
- The first non-empty row is the header row. Column names can be anything; mappings are suggested from common names (Name, Employee Name, Dept, Email Address, Employee No., …) and the admin can change them.
- Required: **Full Name only** (Phase 9.1). Optional: Department, Email (must be valid if present), Employee ID / External Identifier. Rows without a department or employee ID are imported normally and still get an internal attendee code (`ATT-0001`…), a QR code, registration and draw eligibility. The internal attendee code is always system-generated and separate from the client's Employee ID.
- Duplicates (inside the file and against existing attendees of the event, archived included), checked in order: External Identifier → Email → normalised Full Name + Department (trimmed, spaces collapsed, case-insensitive; a blank department only matches another blank department, so “Juan Delacruz / HR” and “Juan Delacruz / (blank)” are different rows). A match is ignored when both sides have *different* external IDs (or emails), so two different people are never merged. No fuzzy matching.
- Duplicates and invalid rows are skipped and listed; existing attendees are never modified. New attendees get the next code `ATT-0001`, `ATT-0002`, … per event. Codes are never reassigned or editable.
- Unmapped columns are saved in `attendees.extra_data`. Each import is recorded in `import_batches` and `audit_logs`.

## 9b. Attendee QR codes (Phase 3)

- One QR record per attendee (`attendee_qr_codes`). Token = 24 random bytes, base64url (32 chars, 192 bits), unique index. No personal data in the QR.
- QR content: `{APP_URL}/q/{token}` (or just the token if `APP_URL` is empty). The Phase 4 scanner reads the last path segment, so both work.
- Tokens never change on edits or re-imports. **Generate missing** only creates codes for active attendees without one. Only **Regenerate** (with confirmation) replaces a token; the old one is gone immediately and the change is audit-logged.
- Archived attendees get no new QR and are excluded from counts and printing; their existing QR records are kept.
- Images are rendered in the browser with the `qrcode` npm package (error correction Q, 4-module quiet zone): SVG for screen/print, 1200 px PNG for download. No image files are stored on the server.
- Every QR is shown, printed and downloaded as a card: QR, then the attendee's **full name** (bold) and **department** (only if present) centred underneath. The attendee code, token and internal IDs are not printed on the card (Phase 9.1). **Download QR** saves a 1200 px-wide PNG card named `ATT-0001-Juan-Dela-Cruz.png` (ASCII letters, digits and hyphens only; never the token).
- Print sheet (`/print/qr`, opens in a new tab): A4, Standard 12 labels/page (44 mm QR) or Large 6/page (64 mm QR), dashed cut guides, explicit page breaks. Print at 100% / actual size.

## 9c. Registration and Minor eligibility (Phase 4)

- **Registration page** (admin, registration staff, scanner operators) uses a **physical QR scanner** in keyboard mode (USB or Bluetooth HID; no driver or SDK). The scanner types the QR value into the focused **Scan Attendee QR** box and presses Enter; the page submits it, clears and refocuses the box, and shows the result (Registration Successful / Already Registered / Invalid QR Code / Invalid Event / Attendee Inactive) for ~3.5 s. Typing anywhere on the page outside a text field sends focus back to the box, so no mouse is needed between attendees. Scans that arrive while one is being checked are queued in order. **The device camera is not used** (the `qr-scanner` package was removed in Phase 9.1).
- The box accepts whatever the QR contains (`{APP_URL}/q/{token}` or the bare token); the server validates it exactly as before.
- The server resolves the token → attendee, checks the active event and the attendee status, then inserts into `registration_scans`. The existing UNIQUE (event_id, attendee_id) key guarantees one check-in per attendee even with several scanners; a second scan returns `already_registered` and inserts nothing. Regenerated (old) tokens no longer exist and return `INVALID_QR`.
- **Minor eligible = attendee has a `registration_scans` row for the active event and is still active.** No separate eligibility table. Phase 5 will draw from this pool.
- Successful check-ins are audit-logged (`registration.checked_in`, with the staff member). Invalid and duplicate scans are not logged.

## 9d. Minor Randomizer (Phase 5)

- **Eligible pool** (queried on every draw, server-side): active attendees of the active event with a `registration_scans` row. Nothing else maintains eligibility.
- **Draw:** the server picks the winner with `random_int()` (cryptographically secure), stores it in `randomizer_draws` (event, attendee, type `minor`, user, time; migration 010) and returns it. The browser only animates the result: it receives the winner plus a random sample of up to 24 other eligible names for the rolling effect, never the full pool.
- **No repeat-winner rule:** winners stay eligible; `registration_scans` is never modified. If a rule is needed later it can be built on `randomizer_draws`.
- **Access:** admin and event operator (registration staff get 403).
- **Event Fullscreen Mode:** "Enter fullscreen" uses the standard browser Fullscreen API on the stage element, so the sidebar and top bar disappear. Space/Enter draws (works with presenter remotes); Esc or the corner button exits. Browsers require a click or key press to enter fullscreen and always let the viewer exit; the app cannot hide browser UI on its own. The stage (`components/randomizer/RandomizerStage.tsx`) is reusable for the Major Randomizer.

## 9e. Major eligibility and Major Randomizer (Phase 6)

- **Source:** the client's form responses in Google Sheets, downloaded as CSV or XLSX (File › Download) and imported on **Major Eligibility › Import responses**. No Google API/OAuth.
- **Mapping:** any column names. Map whichever identifiers the form collected: Attendee Code, External Identifier (Employee ID/No.), Email, Full Name + Department. Suggestions are made from the headers.
- **Matching** (exact after trimming, collapsing spaces and ignoring case; no fuzzy matching). Every mapped identifier with a value is checked:
  - an identifier that matches 2+ attendees, or identifiers pointing to different attendees → **ambiguous** (skipped)
  - the single candidate has a different code / external ID / email than the row → **ambiguous** (conflict, skipped)
  - no match → **unmatched**; no identifier values → **invalid**
  - exactly one consistent match → **matched**; archived attendees are reported and not made eligible; attendees already eligible (or repeated in the file) are reported as **already eligible**.
  - Names may be spelled differently in the form; when a code, external ID or email matches, the name is not used to reject the row.
- **Major eligible = a `major_eligibility` row for the active event AND the attendee is active.** Unique per (event, attendee), so re-imports never duplicate. Attendee data and `registration_scans` are never modified. Spreadsheet contents are not stored; only counts, row numbers and reasons go into `import_batches` (type `major_entries`) and the audit log.
- **Major Randomizer:** same stage, fullscreen and server-side `random_int()` draw as Minor, using `randomizer_draws.randomizer_type = 'major'`. Winners stay eligible (no repeat rule).

## 9f. Event-day features (Phase 7)

- **Login rate limiting:** failed logins are counted per email (stored hashed) and per IP within `LOGIN_LOCKOUT_MINUTES`; reaching `LOGIN_MAX_ATTEMPTS` (email) or `LOGIN_MAX_ATTEMPTS_PER_IP` returns a generic 429 until the window passes. A successful login clears that email's failures. Same response whether or not the account exists.
- **Reports** (admin): Registration, Major eligibility and Draw winners CSVs for the active event only (UTF-8 for Excel; cells starting with `= + - @` are neutralised).
- **Void draw** (admin, event operator): marks a draw VOID with time, user and an optional reason. The record stays in history and reports; registration and Minor/Major eligibility are untouched (no repeat rule).
- **Major QR** (`/major-qr`, admin, event operator): large high-contrast QR for the LED screen, with fullscreen. It encodes `{APP_URL}/major-form`; that route redirects to `MAJOR_FORM_URL` (server config only), so the client can change the form link without a new QR. Scanning only opens the form; eligibility still comes from the Major import.
- **Readiness:** `php backend/cli/check-readiness.php`, the QR Generator and print sheet warn when `APP_URL` is blank or local. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) and [docs/EVENT_DAY_CHECKLIST.md](docs/EVENT_DAY_CHECKLIST.md).

## 9g. Multi-day events, scanner operators and manual participants (Phase 8)

- **Event days** (`event_days`): every event has Day 1, 2, 3… with a date and optional label. Exactly one day of the active event is the **current day**; the server resolves it on every request (no event/day ID is ever accepted from the browser for scans, pools, draws or imports). Admin: **Events › Days** to add a day, edit it, and **Set as current**. The top bar shows "Current Event Day: Day 2 — October 11, 2026" for every role. Switching days (forward or back) never deletes or resets anything: it only moves the current-day pointer. The status shown for each day is derived from the current day (earlier days "Completed", later days "Upcoming").
- **Registration per day:** the same attendee QR is used every day. `registration_scans` is unique per (event day, attendee), so an attendee checks in once per day; a second scan the same day is "Already registered for Day N". Counts (Total / Registered / Remaining) are for the current day. Every scan attempt (including invalid / wrong event / inactive) goes to `scan_logs` for the Recent Scans list.
- **Minor pool (per day)** = active attendees registered today **or** manually added today (`minor_manual_entries`).
- **Major pool (per day)** = active attendees with a `major_eligibility` row for today. A response import grants eligibility for the current day only (`source = import`); earlier days' rows and import history are kept.
- **+ Add Participant** (Minor and Major Randomizer pages; admin + event operator): search by code, name, department or email, optional reason. Creates a day-specific `source = manual` record with the user and time. It never changes the attendee, QR code or registration and never creates a check-in. Duplicates (already registered/added/imported today) return 409 "already eligible". Archived attendees and attendees of other events are refused.
- **Draws per day:** `randomizer_draws.event_day_id`; recent winners show today's draws; only draws of the current day can be voided.
- **Scanner Operator role** (`scanner_operator`): created by an admin in **Settings › Scanner Operators** (display name, username, password + confirmation, status; enable/disable; reset password). Passwords are hashed with `password_hash()` and never returned. Scanner operators sign in with their **username** (the login field accepts email or username), land on the scanner dashboard and can only: scan (USB/Bluetooth QR scanner), see **My scans today** / **All scans today**, browse Recent Scans (My Scans / All Scans, filter All / Successful / Already registered / Invalid, paginated, current day only) and use the read-only attendee lookup. Every other endpoint returns 403. Disabling an account or resetting its password ends its open sessions on the next request (`users.session_version`, migration 030).
- **Reports** have a scope toggle: current day or all days (Event Day column on every row).

Permission matrix (enforced in `backend/api/routes.php`):

| Area | admin | registration_staff | event_operator | scanner_operator |
|------|:-----:|:-----:|:-----:|:-----:|
| Events, event days, attendee import/edit, QR generation, Major import, reports, settings | ✓ | | | |
| View dashboard, events, attendees | ✓ | ✓ | ✓ | |
| QR view/print | ✓ | ✓ | | |
| Registration scanner, scan history | ✓ | ✓ | | ✓ |
| Attendee status lookup | ✓ | ✓ | ✓ | ✓ |
| Randomizers, + Add Participant, void draw, Major eligibility list | ✓ | | ✓ | |

## 10. Creating the first admin

There are **no default or hard-coded credentials**. Create accounts from the
command line (run from the project root):

```bash
# Interactive: prompts for name, email and password (input hidden)
/Applications/XAMPP/xamppfiles/bin/php backend/cli/create-user.php

# Or pass name/email; you will still be prompted for the password
php backend/cli/create-user.php --name="Maria Santos" --email=maria@example.com

# Other roles
php backend/cli/create-user.php --name="Gate Staff 1" --email=gate1@example.com --role=registration_staff
php backend/cli/create-user.php --name="Stage Operator" --email=stage@example.com --role=event_operator
```

Rules: valid unique email, password at least 10 characters. Roles: `admin`
(default), `registration_staff`, `event_operator`. Scanner operators are not
created here: an admin adds them in **Settings › Scanner Operators** (username
login, password at least 8 characters). For automation, pipe the
password with `--password-stdin`. Never pass a password as a command-line
argument, because it would end up in shell history.

Phase 1 permissions: every signed-in role can view the dashboard and events;
only `admin` can create or change events. Module-specific permissions arrive
with each module.

## 11. Building the frontend for production

```bash
cd frontend
npm ci
VITE_API_BASE_URL=/api VITE_BASE_PATH=/ npm run build
```

The output in `frontend/dist/` is static (HTML, JS, CSS). It includes an
`.htaccess` that serves `index.html` for client-side routes, so refreshing
`/events` doesn't 404 on Apache.

To try a production build inside XAMPP without the dev server:

```bash
VITE_BASE_PATH=/Holcim/frontend/dist/ VITE_API_BASE_URL=/Holcim/backend/api npm run build
# open http://localhost/Holcim/frontend/dist/
```

## 12. Deployment considerations

**Recommended layout** (one domain, HTTPS, no CORS needed):

```
/home/account/
├── holcim-backend/            # backend/ folder, OUTSIDE the public web root if the host allows
│   └── .env                   # production config (chmod 600)
└── public_html/               # web root
    ├── index.html, assets/, .htaccess    # contents of frontend/dist
    └── api/                   # backend/ uploaded here (or a symlink/alias to holcim-backend)
```

- Build the frontend with `VITE_API_BASE_URL=/api` and `VITE_BASE_PATH=/`.
- Upload `backend/` as `public_html/api/`. Its `.htaccess` routes every request
  to `index.php`, so `.env`, `config/`, `cli/` and so on are never served. If
  the host lets you keep code outside the web root, put only a thin `api/`
  folder there that points at it, as an extra layer of protection.
- Create `.env` from `backend/.env.production.example`: `APP_DEBUG=false`,
  `SESSION_SECURE_COOKIE=true`, strong DB password. Or set the same variables in
  the hosting panel.
- Run `php backend/cli/migrate.php` and `php backend/cli/create-user.php` over
  SSH. Without SSH, import the migration files in order via phpMyAdmin and ask
  the host for a one-off CLI run to create the admin. **Do not** expose the CLI
  scripts over the web; they refuse to run outside the CLI anyway.
- **HTTPS is required** (secure cookies). Most hosts provide free Let's Encrypt.
- Upload only what's needed: never `.git/`, `node_modules/`, `frontend/src/`,
  `database/` or `.env` files from your machine.
- **nginx** instead of Apache: route `/api/` to `backend/index.php`
  (`try_files $uri /api/index.php?$query_string;`), deny `/api/.env` and other
  dotfiles, and use `try_files $uri /index.html;` for the SPA.
- **PHP sessions** default to files. On a single server that's fine. If you
  ever run several app servers, move sessions to a shared store first.
- **Back up the database** before and after every event day.

## 13. Git workflow

- `main` always holds deployable code.
- Work on short-lived branches named after the phase or change, e.g.
  `phase-2/attendee-import`, `fix/event-date-validation`.
- Keep commits small and focused, with imperative messages:
  `Add attendee import column mapping`, `Fix CSRF retry on expired session`.
- Open a pull request into `main`, review, then merge.
- Never commit `.env` files, credentials, `node_modules/`, `frontend/dist/`,
  database dumps or local XAMPP files. `.gitignore` already covers these. Run
  `git status` before every commit to check.
- Schema changes are always new migration files, never edits to applied ones.

```bash
git checkout -b phase-2/attendee-import
# ...work...
git add -p
git commit -m "Add attendee import column mapping"
git push -u origin phase-2/attendee-import
```

## 14. Testing

**API smoke test** (needs PHP's curl extension; XAMPP has it):

```bash
HOLCIM_TEST_EMAIL=admin@example.com HOLCIM_TEST_PASSWORD='your-password' \
  php backend/tests/smoke-test.php http://localhost/Holcim/backend/api
```

It checks health, 404 handling, 401 on protected routes, CSRF rejection, bad
and invalid logins, login, role info, the dashboard, the event list and
logout. Add `--write` to also create and edit a test event. **This writes real
rows, so use it only on a development database.** Set
`HOLCIM_TEST_STAFF_EMAIL` / `HOLCIM_TEST_STAFF_PASSWORD` to also check that a
non-admin gets 403 when creating an event.

**Phase 8 multi-day smoke test** (development database only):

```bash
HOLCIM_TEST_EMAIL=admin@example.com HOLCIM_TEST_PASSWORD='your-password' \
  php backend/tests/phase8-smoke-test.php http://localhost/Holcim/backend/api --write
```

It creates its own "Phase 8 Smoke …" event (Day 1 + Day 2, attendees A–D),
test accounts with random passwords, scans and draws, then checks day
isolation (registration, scanner counts, Minor/Major pools, manual
participants, draws, voids, reports), switching back and forth between days,
scanner-operator permissions, and that a password reset or disable signs the
operator out. While it runs, the currently active event is set to completed;
afterwards it is restored, the smoke events are archived and the test
accounts disabled. It refuses to run without `--write` or with
`APP_ENV=production`.

**Frontend:** `npm run typecheck`, `npm run lint`, `npm run build`. The build
prints a "chunks larger than 500 kB" notice (≈520 kB, ≈155 kB gzipped). It is
informational only: the build succeeds and the app loads normally.

## 15. Troubleshooting

| Symptom | Fix |
|---------|-----|
| `/api/health` returns **404 from Apache** (HTML page) | `mod_rewrite` is off or `AllowOverride` isn't `All` for htdocs |
| Health says `"database": "unavailable"` | Check `DB_*` in `backend/.env`, that MySQL is running in XAMPP, and use `DB_HOST=127.0.0.1` |
| Login works but the next request is 401 | `SESSION_SECURE_COOKIE=true` on plain `http://` — set it to `false` locally |
| `CSRF_TOKEN_MISMATCH` | The session expired or cookies are blocked. Reload the page |
| Frontend shows "Cannot connect to the server" | Apache isn't running, or the project folder isn't `htdocs/Holcim` (set `DEV_API_PROXY_TARGET`) |
| `could not find driver` | Enable `pdo_mysql` for the PHP binary you are using |
| `npm run dev` fails on startup | Upgrade Node.js to 20.19+ or 22.12+ |
