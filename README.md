# Holcim Event System

An online event management and prize-draw system: attendee import, QR-based
registration, minor and major randomizers, winner management, reports and an
audit trail.

> **Current status: Phase 3 (QR codes).** Phase 3 adds attendee QR generation, the QR / ID Generator page, regeneration and A4 bulk printing.
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
| 5 | Registration QR scanner | Schema ready |
| 6 | Minor randomizer | Planned |
| 7 | Major randomizer | Planned |
| 8 | Google Form / Google Sheet response import | Schema ready |
| 9 | Winner management | Planned |
| 10 | Reports | Planned |
| 11 | Audit logs | **Recording** logins, logouts and event changes |
| 12 | Fullscreen event mode | Planned |

The system is built around one **active event** at a time: the dashboard,
registration and randomizers always work on the active event.

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
| GET | `/api/dashboard/summary` | Signed in | Active event + metrics |
| GET | `/api/events` | Signed in | List events (active first) |
| GET | `/api/events/{id}` | Signed in | One event |
| POST | `/api/events` | Admin | Create event |
| PUT | `/api/events/{id}` | Admin | Update event |
| PATCH | `/api/events/{id}/status` | Admin | Change status |
| GET | `/api/attendees` | Signed in | Search/filter/paginate attendees of the active event |
| GET | `/api/attendees/{id}` | Signed in | One attendee |
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
- Required: Full Name, Department. Optional: Email (must be valid if present), External Identifier.
- Duplicates (inside the file and against existing attendees of the event, archived included), checked in order: External Identifier → Email → normalised Full Name + Department (trimmed, spaces collapsed, case-insensitive). A match is ignored when both sides have *different* external IDs (or emails), so two different people are never merged. No fuzzy matching.
- Duplicates and invalid rows are skipped and listed; existing attendees are never modified. New attendees get the next code `ATT-0001`, `ATT-0002`, … per event. Codes are never reassigned or editable.
- Unmapped columns are saved in `attendees.extra_data`. Each import is recorded in `import_batches` and `audit_logs`.

## 9b. Attendee QR codes (Phase 3)

- One QR record per attendee (`attendee_qr_codes`). Token = 24 random bytes, base64url (32 chars, 192 bits), unique index. No personal data in the QR.
- QR content: `{APP_URL}/q/{token}` (or just the token if `APP_URL` is empty). The Phase 4 scanner reads the last path segment, so both work.
- Tokens never change on edits or re-imports. **Generate missing** only creates codes for active attendees without one. Only **Regenerate** (with confirmation) replaces a token; the old one is gone immediately and the change is audit-logged.
- Archived attendees get no new QR and are excluded from counts and printing; their existing QR records are kept.
- Images are rendered in the browser with the `qrcode` npm package (error correction Q, 4-module quiet zone): SVG for screen/print, 1200 px PNG for download. No image files are stored on the server.
- Print sheet (`/print/qr`, opens in a new tab): A4, Standard 12 labels/page (44 mm QR) or Large 6/page (64 mm QR), dashed cut guides, explicit page breaks. Print at 100% / actual size.

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
(default), `registration_staff`, `event_operator`. For automation, pipe the
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

**Frontend:** `npm run typecheck`, `npm run lint`, `npm run build`.

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
