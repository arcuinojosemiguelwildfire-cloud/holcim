# Database Schema

MySQL 5.7+ / MariaDB 10.4+. All tables use **InnoDB** and **utf8mb4 /
utf8mb4_unicode_ci**. Migrations are in `database/migrations/` and are applied
by `backend/cli/migrate.php`, which tracks them in `schema_migrations`.

## Relationships

```
users ─┬─< import_batches.imported_by        (SET NULL)
       ├─< registration_scans.scanner_user_id (SET NULL)
       └─< audit_logs.user_id                (SET NULL)

events ─┬─< import_batches     (RESTRICT)
        ├─< attendees          (RESTRICT) ─┬─1 attendee_qr_codes   (CASCADE)
        │                                  ├─< registration_scans  (CASCADE, composite FK on attendee_id + event_id)
        │                                  └─< major_entries.attendee_id (SET NULL, optional link)
        ├─< registration_scans (RESTRICT)
        ├─< major_entries      (RESTRICT)
        └─< audit_logs         (SET NULL)

import_batches ─< attendees.import_batch_id, major_entries.import_batch_id (SET NULL)
```

Events can't be deleted while they have attendees, entries, scans or import
batches (RESTRICT). Events are archived, not deleted.

## Tables

### `users`: staff accounts
| Column | Type | Notes |
|--------|------|-------|
| id | INT UNSIGNED PK | |
| name | VARCHAR(150) | |
| email | VARCHAR(190) | **UNIQUE**, stored lower-case |
| password_hash | VARCHAR(255) | `password_hash()` output; never returned by the API |
| role | ENUM(`admin`,`registration_staff`,`event_operator`) | |
| status | ENUM(`active`,`inactive`) | Inactive users can't sign in, and existing sessions end on the next request |
| last_login_at | DATETIME NULL | |
| created_at / updated_at | DATETIME | |

### `events`
| Column | Type | Notes |
|--------|------|-------|
| id | INT UNSIGNED PK | |
| name | VARCHAR(200) | |
| description | TEXT NULL | |
| event_date | DATE | |
| status | ENUM(`draft`,`active`,`completed`,`archived`) | default `draft` |
| active_lock | TINYINT, stored generated | `1` when active, otherwise `NULL`. **UNIQUE** → at most one active event |
| created_at / updated_at | DATETIME | |

### `import_batches`: one row per Excel/CSV/Sheet import
| Column | Notes |
|--------|-------|
| event_id | FK events |
| import_type | ENUM(`attendees`,`major_entries`) |
| original_filename | |
| status | ENUM(`pending`,`processing`,`completed`,`failed`) |
| total_rows / successful_rows / failed_rows | counters |
| column_mapping | JSON: how spreadsheet headers mapped to system fields (**dynamic column mapping**) |
| error_summary | JSON: per-row problems |
| imported_by | FK users (SET NULL) |
| created_at / completed_at | |

### `attendees`
| Column | Notes |
|--------|-------|
| event_id | FK events |
| attendee_code | VARCHAR(64), **UNIQUE per event** (`event_id, attendee_code`), so the same employee code can be reused in a later event |
| full_name, department, email | the standard mapped fields |
| extra_data | JSON: spreadsheet columns that weren't mapped to a dedicated field. Nothing from the client's file is lost |
| import_batch_id | FK import_batches (SET NULL) |

Indexes on (event_id, full_name), (event_id, department) and (event_id, email)
support search and filtering. A UNIQUE (id, event_id) key enables the composite
FK below.

**Migration 009 (Phase 2)** adds `external_identifier` (VARCHAR 190, UNIQUE per event when present), `status` ENUM(`active`,`archived`) and `archived_at` for soft delete. Attendees are archived, never hard-deleted.

### `attendee_qr_codes`
| Column | Notes |
|--------|-------|
| attendee_id | FK attendees (CASCADE), **UNIQUE**: one QR per attendee |
| token | VARCHAR(64) ASCII **binary** collation (case-sensitive), **UNIQUE** |

The token is an **opaque random value** (planned: `Token::random(32)`, 256 bits,
base64url, 43 characters). It contains no name, department, email or code; the
scanner looks it up server-side. Re-issuing a QR replaces the token in place,
and the old token is recorded in `audit_logs`.

### `registration_scans`: successful check-ins
| Column | Notes |
|--------|-------|
| event_id | FK events |
| attendee_id | composite FK (attendee_id, event_id) → attendees(id, event_id), so the database guarantees the attendee belongs to that event |
| qr_code_id | FK attendee_qr_codes (SET NULL) |
| scanner_user_id | FK users (SET NULL) |
| scanned_at | DATETIME |

**UNIQUE (event_id, attendee_id)**: an attendee can be registered only once
per event. Duplicate scans are rejected by the API (scanner phase) and can be
logged to `audit_logs`.

### `major_entries`: Google Form / Sheet responses
| Column | Notes |
|--------|-------|
| event_id | FK events |
| attendee_id | optional FK attendees (SET NULL), for matching an entry to an attendee |
| full_name, department, email | commonly used fields |
| external_identifier | identifies the response in the source sheet. **UNIQUE per event**, so re-imports don't duplicate entries (NULLs allowed) |
| response_data | JSON: the **entire original row**, so new form questions never need a schema change |
| submitted_at | form submission time |
| import_batch_id | FK import_batches (SET NULL) |

### `audit_logs`
| Column | Notes |
|--------|-------|
| id | BIGINT UNSIGNED |
| user_id, event_id | nullable FKs (SET NULL): the history survives deletions |
| action | dotted name, e.g. `auth.login`, `auth.login_failed`, `event.status_changed` |
| description | human-readable sentence |
| metadata | JSON, e.g. `{"changes":{"status":{"from":"draft","to":"active"}}}` |
| ip_address, user_agent | request context |
| created_at | indexed |

## Capacity

The target is 400–1,000+ attendees per event, which is small for InnoDB.
Every lookup the future modules need (token → attendee, attendee code within an
event, registered count per event, entries per event) is backed by a unique or
secondary index, so scans and draws stay index lookups rather than table scans
even at tens of thousands of rows.

## Notes on MySQL vs MariaDB

`JSON` is a native type in MySQL. In MariaDB it's an alias for `LONGTEXT` with an
automatic `JSON_VALID()` check. Both reject invalid JSON. Stored generated
columns (`active_lock`) are supported by both.

### `randomizer_draws` (migration 010, Phase 5)
One row per draw: `event_id`, `attendee_id`, `randomizer_type` (`minor`/`major`), `drawn_by` (user, SET NULL), `selected_at`. Separate from `registration_scans`, so recording a winner never changes eligibility.

### `major_eligibility` (migration 011, Phase 6)
`event_id`, `attendee_id` (composite FK to attendees(id, event_id)), `import_batch_id` (SET NULL), `imported_at`. UNIQUE (event_id, attendee_id). Major eligible = row here + attendee active. The Phase 1 `major_entries` table (full responses) is intentionally not used, so client spreadsheet contents are not stored.
