# API Reference (Phase 1)

Base URL:

- Local (Apache/XAMPP): `http://localhost/Holcim/backend/api`
- Local through the Vite dev server: `http://localhost:5173/api`
- Production (recommended layout): `https://your-domain/api`

All responses are JSON. Success responses look like
`{ "success": true, "data": ..., "message"?: "..." }`, and failures look like
`{ "success": false, "error": { "code", "message", "details"? } }`.

## Conventions

- **Cookies:** the API uses a PHP session cookie (`holcim_session`). Browser
  clients must send credentials (`fetch(..., { credentials: 'include' })`).
- **CSRF:** every `POST`, `PUT`, `PATCH` and `DELETE` request must include an
  `X-CSRF-Token` header with the token from `GET /auth/session` (or from the
  login response). A missing or wrong token returns
  `403 CSRF_TOKEN_MISMATCH`.
- **JSON bodies:** send `Content-Type: application/json`. Invalid JSON returns
  `400 BAD_REQUEST`.
- **Field names:** request bodies use snake_case (`event_date`). Response
  objects use camelCase (`eventDate`).
- **Validation errors** return `422` with per-field messages in
  `error.details.fields`.

## System

### `GET /health`
Public. Liveness and database connectivity. Returns 200 when healthy and 503
when the database is unreachable.

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "app": "Holcim Event System",
    "environment": "local",
    "database": "connected",
    "time": "2026-10-01T13:27:41+08:00"
  },
  "message": "Holcim Event System API is running."
}
```

## Authentication

### `GET /auth/session`
Public. Always 200. Use it on app start-up.

```json
{ "success": true, "data": { "authenticated": false, "user": null, "csrfToken": "Ab3...43chars" } }
```

### `POST /auth/login`
Requires CSRF. Body: `{ "email": "admin@example.com", "password": "..." }`

- `200` → `{ "user": User, "csrfToken": "<new token>" }`. The session ID and CSRF token are rotated.
- `401 INVALID_CREDENTIALS`: wrong email or password, or an inactive account. The message is deliberately the same in every case.
- `422 VALIDATION_ERROR`: missing or invalid email, or missing password.

`User`:

```json
{ "id": 1, "name": "Maria Santos", "email": "admin@example.com",
  "role": "admin", "status": "active", "lastLoginAt": "2026-10-01 13:28:29" }
```

Roles: `admin`, `registration_staff`, `event_operator`.

### `POST /auth/logout`
Requires CSRF. Destroys the session. `200` with `data: null`.

### `GET /auth/me`
**Protected example.** `200 { "user": User }` when signed in, `401 UNAUTHENTICATED` otherwise.

## Dashboard

### `GET /dashboard/summary`
Signed in.

```json
{
  "success": true,
  "data": {
    "activeEvent": { "id": 2, "name": "Year-End Party 2026", "description": "...",
                     "eventDate": "2026-12-12", "status": "active",
                     "createdAt": "...", "updatedAt": "..." },
    "metrics": {
      "totalAttendees": { "value": 0, "available": true },
      "registered":     { "value": 0, "available": true },
      "minorEligible":  { "value": 0, "available": false },
      "majorEligible":  { "value": 0, "available": false }
    }
  }
}
```

`activeEvent` is `null` when no event is active. `available: false` means the
module that defines that metric isn't built yet, so the value is reported as 0
rather than guessed. `totalAttendees` and `registered` are real counts from
`attendees` and `registration_scans` for the active event.

## Events

`Event`: `{ id, name, description, eventDate, status, createdAt, updatedAt }`.
Statuses: `draft`, `active`, `completed`, `archived`.

**Rule:** at most one event can be `active`. Activating a second one returns
`409 CONFLICT` naming the event that is currently active. The database enforces
the same rule with a unique index, so it holds even under concurrent requests.

| Method & path | Access | Body | Success |
|---------------|--------|------|---------|
| `GET /events` | Signed in | – | `200 { events: Event[] }` (active first, then newest date) |
| `GET /events/{id}` | Signed in | – | `200 { event }`, or `404` |
| `POST /events` | Admin + CSRF | `{ name*, description, event_date*, status* }` | `201 { event }` |
| `PUT /events/{id}` | Admin + CSRF | same as POST (full update) | `200 { event }` |
| `PATCH /events/{id}/status` | Admin + CSRF | `{ status* }` | `200 { event }` |

Validation: `name` 1–200 characters; `description` up to 5000 characters
(optional; send an empty string to clear it); `event_date` a real calendar date
in `YYYY-MM-DD` format; `status` one of the four statuses.

Non-admins get `403 FORBIDDEN` on writes. Every change is written to
`audit_logs` (`event.created`, `event.updated`, `event.status_changed`) with a
before/after diff in `metadata`.

## Planned route groups (not implemented)

These will be registered in `backend/api/routes.php` as their phases are built:
`/attendees`, `/imports`, `/qr-codes`, `/registration`, `/minor-draws`,
`/major-entries`, `/major-draws`, `/winners`, `/reports`, `/audit-logs`,
`/users`.
