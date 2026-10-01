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
| email | VARCHAR(190) NULL | **UNIQUE** when set, stored lower-case (NULL for scanner operators, Phase 8) |
| username | VARCHAR(60) NULL | **UNIQUE** when set, lower-case; login name of scanner operators (migration 028) |
| password_hash | VARCHAR(255) | `password_hash()` output; never returned by the API |
| role | ENUM(`admin`,`registration_staff`,`event_operator`,`scanner_operator`) | `scanner_operator` added in Phase 8 |
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

### `login_attempts` (migration 012, Phase 7)
Failed logins only: `email_hash` (SHA-256), `ip_address`, `attempted_at`. Cleared for an email on successful login; rows older than a day are purged.

### `randomizer_draws` void columns (migration 013, Phase 7)
`voided_at`, `voided_by` (user, SET NULL), `void_reason` (≤200 chars). A voided draw is never deleted.

## Phase 8: multi-day events (migrations 014–029)

### `event_days`
`event_id`, `day_number` (UNIQUE per event), `event_date`, `label` (optional), `status` ENUM(`upcoming`,`active`,`completed`). `active_lock` (stored generated: `event_id` when active, else NULL) is **UNIQUE**, so each event has at most one current day. UNIQUE (id, event_id) backs composite FKs, so a day-specific row can never point at a day of another event. FK to events is RESTRICT (a generated column cannot sit on a cascading FK column).

### Day-specific columns
| Table | Change |
|-------|--------|
| `registration_scans` | `event_day_id` NOT NULL, composite FK (event_day_id, event_id) → event_days. UNIQUE changed from (event_id, attendee_id) to **(event_day_id, attendee_id)**: one check-in per attendee per day. Indexes (event_day_id, scanned_at), (event_day_id, scanner_user_id). |
| `major_eligibility` | `event_day_id` NOT NULL (composite FK), `source` ENUM(`import`,`manual`), `added_by` (FK users, SET NULL), `reason` VARCHAR(200). UNIQUE changed to **(event_day_id, attendee_id)**. |
| `randomizer_draws` | `event_day_id` NOT NULL (composite FK), index (event_day_id, randomizer_type, selected_at). |
| `import_batches` | `event_day_id` NULL (FK SET NULL). Set for Major imports; attendee imports stay event-wide. |

### `minor_manual_entries`
Manual additions to a day's Minor pool: `event_id`, `event_day_id`, `attendee_id`, `added_by`, `reason`, `created_at`. UNIQUE (event_day_id, attendee_id). Composite FKs to event_days and attendees. Never creates a check-in.

### `scan_logs`
Every scan attempt per day: `event_id`, `event_day_id`, `user_id`, `attendee_id` (NULL for invalid QR / wrong event), `result` ENUM(`registered`,`already_registered`,`invalid_qr`,`wrong_event`,`attendee_inactive`), `scanned_at`. Indexes (event_day_id, scanned_at), (event_day_id, user_id, scanned_at), (event_day_id, result). Counts of check-ins still come from `registration_scans`.

### Pools (per current day, active attendees only)
- Minor = `registration_scans` of the day ∪ `minor_manual_entries` of the day
- Major = `major_eligibility` of the day (import or manual)

### Data migration
015 creates Day 1 for every existing event; 017/020/023 back-fill existing scans, Major eligibility and draws to that Day 1 before 018/021/024 make the column NOT NULL and swap the unique keys; 020 sets `added_by` from the import batch; 029 links Major import batches to Day 1. No rows are deleted.
