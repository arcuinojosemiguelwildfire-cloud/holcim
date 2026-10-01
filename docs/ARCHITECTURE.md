# Architecture

## Overview

```
┌──────────────────────────┐   JSON over HTTPS    ┌─────────────────────────────┐     PDO      ┌──────────────┐
│ React SPA (frontend/)    │  cookie + X-CSRF-Token│ PHP API (backend/)          │  prepared    │ MySQL/MariaDB│
│ pages → services →       │ ───────────────────▶ │ index.php → Router →        │ ───────────▶ │ InnoDB       │
│ apiClient (fetch)        │ ◀─────────────────── │ Middleware → Controller →   │  statements  │ utf8mb4      │
└──────────────────────────┘                      │ Service → Model             │              └──────────────┘
                                                  └─────────────────────────────┘
```

The SPA and API are deployed on the **same origin** (`/` and `/api`). In
development, the Vite proxy recreates that, so no CORS is needed and cookies
stay `SameSite=Lax`.

## Backend layers

| Layer | Folder | Responsibility | Must not |
|-------|--------|----------------|----------|
| Front controller | `index.php` | Headers, CORS, global CSRF, dispatch, error → JSON | Contain business logic |
| Routes | `api/routes.php` | Map method + path → controller, attach auth/role middleware | |
| Middleware | `middleware/` | Cross-cutting checks (auth, roles, CSRF, CORS) | Touch business data |
| Controllers | `controllers/` | Read request, validate with `Validator`, call a service, return `Response` | Run SQL |
| Services | `services/` | Business rules (one active event, login policy), transactions, audit logging | Know about HTTP globals |
| Models | `models/` | SQL via PDO prepared statements, row ↔ API shape | Enforce business rules |
| Core | `core/` | Router, Request, Response, Database, Session, Env, Config, HttpException | |
| Utils | `utils/` | Validator, Csrf, Token | |

Errors are thrown as `HttpException` (status + machine code + message) and
rendered in one place. Unexpected exceptions are logged with `error_log()`, and
the client sees a generic `SERVER_ERROR` unless `APP_DEBUG=true`.

## Frontend structure

- `services/apiClient.ts` is the only place that calls `fetch`. It adds
  credentials and the CSRF header, normalises errors into `ApiError` (with
  per-field messages), retries once if the CSRF token expired, and notifies the
  auth layer on 401.
- `components/auth/AuthProvider.tsx` holds the signed-in user **in memory
  only**. Nothing is stored in localStorage, and passwords are never kept after
  submission.
- `components/auth/RouteGuards.tsx`: `RequireAuth` and `GuestOnly`.
- `hooks/useApiQuery.ts` is a small data-loading hook (loading, error, reload,
  stale-response protection).
- `layouts/navigation.ts` is the single source for the sidebar. Each item can
  be restricted by role and flagged `comingSoon`.
- `components/ui/` holds reusable, accessible primitives: Button/ButtonLink,
  form fields with errors wired to `aria-describedby`, a native `<dialog>`
  modal, Alert, Badge, Card and more.

## How future modules plug in

| Phase | Backend | Frontend | Schema already in place |
|-------|---------|----------|-------------------------|
| Attendee import + mapping | `ImportService` (parse CSV/XLSX, apply `column_mapping`), `Attendee` model writes | Attendees page, mapping wizard | `import_batches.column_mapping`, `attendees.extra_data`, unique code per event |
| QR generation/printing | `QrCodeService` using `Token::random()` | Printable ID view | `attendee_qr_codes` (unique token, one per attendee) |
| Registration scanner | `POST /registration/scan` (token → attendee, insert scan, duplicate → 409 + audit) | Camera scanner page | `registration_scans` unique (event, attendee), composite FK |
| Minor randomizer | Draw service using `random_int()` + audit | Fullscreen draw UI | Eligibility = registered attendees (rules defined then) |
| Major import + randomizer | Google Sheet/CSV import → `major_entries` | Major draw UI | `major_entries.response_data`, `external_identifier` |
| Winners / Reports | New `winners` table migration | Reports pages | `audit_logs` |

## Security checklist (Phase 1)

- [x] PDO prepared statements, no SQL concatenation of user input
- [x] Input validation with whitelisted fields
- [x] JSON output with `nosniff`, `X-Frame-Options: DENY`, `no-store`
- [x] CSRF synchronizer token on all state-changing requests (global)
- [x] HttpOnly / SameSite / Secure session cookie, strict mode, ID regeneration on login, idle timeout
- [x] `password_hash` / `password_verify`, timing-safe login for unknown emails
- [x] Role checks in route middleware; user reloaded every request
- [x] `.env` outside version control; `.htaccess` blocks dotfiles and non-API files
- [x] CLI scripts refuse to run over HTTP
- [ ] Login rate limiting / lockout (recommended next; see Known issues in the Phase 1 report)
