# Registration sessions and raffle eligibility (Phase 10)

> **Update (single Randomizer, migration 036):** the Minor / Major Randomizers were removed. Registration sessions now feed the **Randomizer** name pool: an admin selects one or more sessions per event day on the Randomizer page (`randomizer_pool_sessions`), and the pool is every active attendee of the event with an active registration in at least one selected session (each person once). The `counts_for_major` column is kept but no longer shown or used by any workflow; the scan result no longer shows Major eligibility (the API still returns `majorEligible`). The Minor / Major rules below describe the legacy behaviour and its stored history. See README §9r.

An event day can have more than one **registration session**. The same attendee record, with the same attendee code and the same QR code, is used for every session. Each session gets its own registration row.

```
Juan Dela Cruz  ATT-0001  (one attendee, one QR)
 ├── Oct 14 - 5:00 PM registration
 ├── Oct 15 - 8:00 AM registration
 └── Oct 15 - 5:00 PM registration
```

## 1. Data model (MySQL migration 033, Supabase `20261007090000_…`)

| Table | Purpose |
|---|---|
| `registration_sessions` | Sessions per event day: `label`, `session_date`, `start_time` (Asia/Manila wall time), `description`, `sort_order`, `is_enabled`, **`counts_for_major`**, **`is_current`**, timestamps. At most one current session per day: a `current_lock` generated column with a UNIQUE key in MySQL, a partial unique index in PostgreSQL. A current session must be enabled (CHECK). Labels are unique per day. |
| `session_registrations` | One row per attendee per session, enforced by **UNIQUE (`attendee_id`, `registration_session_id`)**. Columns: `status` (`active` / `removed`), `source` (`scan` / `manual`), `registered_at`, `registered_by`, and `removed_at`, `removed_by`, `removal_reason`. Composite FKs keep the attendee, day and session in the same event. Rows are **never deleted** by the application. |
| `session_registration_changes` | Append-only history of every change: `scanned`, `manually_added`, `restored` and `removed`, with who, when and why. |
| `scan_logs.registration_session_id` | Records the session each scan was made in; NULL for scans made before sessions existed. A new result, `registration_removed`, is also logged. |
| `registration_scans` | Unchanged. It is still the **day-level check-in log**: the first scan of a day, one row per attendee per day. |

All FKs and unique constraints are portable, and the PostgreSQL migration mirrors MySQL. `tools/tests/schema.test.ts` checks that every MySQL FK, index and enum value has its PostgreSQL equivalent. The MySQL → PostgreSQL import copies the three new tables 1:1.

### Existing data

Migration 033 is additive and runs once:
- Every existing event day gets **one default session**, "Registration". It has no start time, is current, and counts for Major.
- Every existing `registration_scans` row is copied into that session as an active `scan` registration, with the same time and scanner.

Eligibility for every existing day is therefore exactly what it was before ("registered that day = Minor and Major eligible"). Migration 033 does not guess times or split days into sessions. Attendees, QR tokens, check-ins, draws and manual entries are not changed; a test proves this with CHECKSUM TABLE.

New event days also start with one default session. Admins rename it and add more sessions; see §4.

## 2. Eligibility rules (derived on the server only)

For **one event day**, for **active** attendees:

| Randomizer | Base pool |
|---|---|
| **Minor** | An active registration in **any** session of the day, **or** a manual Minor addition for the day (`+ Add Participant`). |
| **Major** | An active registration in a session of the day with **`counts_for_major = 1`**, **or** a manual Major addition for the day (`+ Add Participant`). |

The draw pool is the base pool minus anyone with an excluding draw of the same randomizer on the same day. A draw excludes when it is not void and not reset; this is unchanged.

The pools are built with `UNION`, so an attendee with several registrations on a day, such as 8:00 AM and 5:00 PM, is **one** candidate. The no-repeat, void, Randomizer Reset, event-day separation, Minor/Major independence and the day row lock (`SELECT … FOR UPDATE` during a draw) are untouched. The rules live in `SessionRegistration::eligibleSql()` and `RandomizerDraw`. The dashboard counts, the participant lists, the eligibility report and the scan result all use these same rules.

For the 2026 Holcim event:

| Session | counts_for_major | Minor (that day) | Major (that day) |
|---|---|---|---|
| Oct 14 - 5:00 PM | yes | eligible | **eligible** |
| Oct 15 - 8:00 AM | **no** | eligible | **not eligible** |
| Oct 15 - 5:00 PM | yes | eligible | **eligible** |

- An Oct 15 attendee registered **only** at 8:00 AM is not in the Oct 15 Major pool.
- An attendee registered at both 8:00 AM and 5:00 PM appears **once** and is Major-eligible.

Minor eligibility after this change means "registered for the event day in any session", exactly as before, when there was one session per day. It also includes an admin's manual session registration (§5). Minor manual additions work as before.

**Never trusted from the client:** the API only accepts which sessions an attendee is registered for. A `major_eligible` value in a request is ignored, which is tested.

## 3. Scanning

1. The scanner sends the QR value.
2. The server finds the attendee by the existing token. It never regenerates the token or creates an attendee.
3. The server records the scan in the **current session of the active day**, which an admin chose. Scanners cannot choose a session.
4. Possible outcomes:
   - A new registration row (`registered`). The first scan of the day also creates the day check-in.
   - The same attendee in the same session: `already_registered`, with no new row. This holds for simultaneous scans too, through the unique key.
   - A registration an admin removed: `REGISTRATION_REMOVED`, "ask an admin". A scan never silently undoes an admin's decision.

The scan and a session switch are serialized by a shared/exclusive lock on the session rows.

The result shows the session (for example "Registered: October 15 - 5:00 PM") and "Major Randomizer: ELIGIBLE / NOT ELIGIBLE". It shows no ids and no token.

The registration page shows a **CURRENT REGISTRATION** banner. It also shows an amber warning when a later session's start time has passed but an earlier session is still current ("ask an admin to switch"). The counters show this session and today overall.

## 4. Managing sessions (admin only)

**Events › Days › Sessions** on a day lets an admin:
- Add or edit a session: name, date, start time, order, description, enabled, and **"Counts for the Major Randomizer"**.
- **Make current**: switch the session scans are recorded in. Registrations already made are kept.

The current session cannot be disabled, and a disabled session cannot be made current. Registration staff, scanner operators and event operators get 403 on these endpoints.

### Setting up the 2026 event from configuration

```bash
php backend/cli/configure-registration-sessions.php --file=database/registration-sessions/holcim-2026.json          # dry run
php backend/cli/configure-registration-sessions.php --file=database/registration-sessions/holcim-2026.json --apply  # save
```

The file holds the sessions as data. The tool matches days by date and sessions by name, and works in one transaction with audit entries.
- **Oct 14:** the day's default session becomes "Oct 14 - 5:00 PM". Existing Oct 14 registrations stay attached to it, as the requirement asked.
- **Oct 15:** if Oct 15 already has registrations in its default session, the tool **stops** instead of guessing which session they belong to.
- **Re-running:** running the file again never switches an admin's current-session choice back.

## 5. Manage / renew registration (admin)

Attendee details show:
- Major and Minor eligibility per day.
- The registration history: session, time, QR scan or manual, by whom, and the status.
- The change log.

**Admins** get checkboxes per session and **Save registration eligibility**. Registration staff and event operators can view this, but cannot change it.
- **Checking a session** adds a manual registration, or restores a removed one.
- **Unchecking a session** marks the registration `removed` and requires a reason. The row and its history are kept.
- **Archived attendees** cannot be given new registrations.
- **The attendee is never changed:** the attendee record, `attendee_code` and QR token stay as they are.

Audit actions:
- `registration.created` (scan)
- `registration.manually_added`
- `registration.manually_removed`
- `registration.eligibility_changed` (when a day's Minor or Major eligibility flips)
- `registration_session.created`, `registration_session.updated`, `registration_session.changed` (the current session was switched)

## 6. API

| Method | Path | Roles |
|---|---|---|
| GET | `/event-days/{id}/sessions` | admin |
| POST | `/event-days/{id}/sessions` `{label, session_date?, start_time, description?, sort_order?, enabled?, counts_for_major}` | admin |
| PUT | `/registration-sessions/{id}` (same body) | admin |
| PATCH | `/registration-sessions/{id}/activate` | admin |
| GET | `/attendees/{id}/registrations` | admin, registration staff, event operator |
| PUT | `/attendees/{id}/registrations` `{sessions: [{session_id, registered}], reason?}` | admin |

Other endpoints now include session data:
- `POST /registration/scan` adds `registrationSession` and `majorEligible`.
- `GET /registration/summary` adds `registrationSession`, `pendingSession` and `counts.registeredToday`.
- `GET /event-days/current` adds `registrationSession`.

**Reports:**
- `registration.csv`, `eligibility.csv` and `Day N Attendees.xlsx` gain a **Registration Sessions** column at the end.
- "Registered" means any session that day.
- The Major columns follow the session rules.

## 7. Tests

- `php backend/tests/registration-session-test.php <api> --write`: 88 HTTP checks on a development database. It covers:
  - the 11 required scenarios;
  - role restrictions;
  - duplicates, including simultaneous scans;
  - manual add, remove and restore with history;
  - ignored client eligibility;
  - randomizer no-repeat, void and reset;
  - reports;
  - unchanged QR tokens and attendee count.
- `php backend/tests/reset-test.php --confirm-reset`: System Reset also empties the new tables.
- `cd tools && npm test`: MySQL ↔ PostgreSQL schema parity, the backfill in both databases, and an import that includes session data.

## 8. Things only a physical rehearsal can confirm

- The admin **switches to the 5:00 PM session on time** on Oct 15. Scans made while 8:00 AM is still current are recorded as 8:00 AM and are **not** Major-eligible. The desk shows a warning once 5:00 PM has passed, and an admin can fix individual attendees in Attendee details. There is no bulk "move scans between sessions" tool.
- Scanner hardware, network and timing at the desk with two sessions.
- The time comparison for the warning uses the server clock (Asia/Manila). Check the server time before the event.
