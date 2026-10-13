# Supabase migration — Phase 2: Auth, roles and Row Level Security

**Status:** this layer is built **in parallel** to the PHP system. PHP login, sessions,
CSRF and roles are untouched, and the PHP/MySQL app stays the live system. Nothing here
is deployed. Registration scanning, the randomizer, imports, reports and resets are
still PHP-only (later phases).

| Path | What |
|---|---|
| `supabase/migrations/20261008090000_holcim_auth_roles_rls.sql` | `profiles`, role helpers (`private.*`), RLS policies, grants, auth RPCs, `audit_logs.actor_id`. |
| `tools/plan-auth-users.ts` | READ-ONLY planner for moving MySQL `users` to Supabase Auth: counts, issues, and an optional import manifest. |
| `frontend/src/utils/authIdentifier.ts` | Username → internal Auth email mapping (not wired into the UI yet). |
| `tools/tests/auth-rls.test.ts` | RLS / role / escalation tests, in SQL and through a real PostgREST server. |
| `tools/tests/auth-users.test.ts` | Identifier mapping and planner tests. |
| `tools/tests/sql/supabase-auth-shim.sql` | **Test-only** stand-in for Supabase's `auth` schema. Never apply it to a real project. |

---

## 1. Current PHP authentication (verified in the code)

| Topic | Current behaviour | Where |
|---|---|---|
| **Login** | `POST /auth/login {login, password}`. `login` is an email (contains `@`) or a username. The user is looked up, then `password_verify()` runs, always against a dummy hash when the account doesn't exist (no timing leak). It must be `status = active`. On success the session ID is regenerated and the CSRF token rotated; `last_login_at`, `session_version` and `auth.login` are written to the audit log. A `$2y$` hash is upgraded via `password_needs_rehash`. | `AuthService::attempt`, `AuthController::login` |
| **Logout** | `POST /auth/logout` writes `auth.logout`, clears `$_SESSION`, expires the cookie and destroys the session. | `AuthService::logout` |
| **Session** | A PHP file session holding only the user id, role, login time and `session_version`. The cookie is HttpOnly, `Secure` in production, `SameSite=Lax`, strict mode, and lives only for the browser session. | `core/Session.php`, `config/session.php` |
| **Expiration / idle timeout** | **8 hours of inactivity** (`SESSION_LIFETIME=28800`), enforced server-side on every request. There is no absolute maximum lifetime. | `Session::enforceIdleTimeout` |
| **Password hashing** | `password_hash(PASSWORD_DEFAULT)` = bcrypt `$2y$`: cost 10 on PHP 8.3 and earlier, cost 12 on PHP 8.4. Minimum length: 8 for scanner operators (Settings), 10 for accounts made with `create-user.php`. | `ScannerOperatorService`, `cli/create-user.php` |
| **Rate limiting** | 5 failures per login identifier (sha256-hashed) **or** 30 per IP within 15 minutes gives `429 TOO_MANY_ATTEMPTS`. A successful login clears that identifier. The response is the same whether or not the account exists. | `LoginThrottle`, `config/auth.php`, table `login_attempts` |
| **Failed login** | `401 INVALID_CREDENTIALS` (generic) and an `auth.login_failed` audit row whose `reason` is `unknown_login`, `wrong_password` or `inactive_account`. | `AuthService::attempt` |
| **Inactive accounts** | Cannot log in. An open session ends on its next request, because the user row is reloaded per request. | `AuthService::currentUser` |
| **Session invalidation** | `users.session_version` is bumped by a password reset (scanner operators). Every session with an older version ends. Disabling an account also ends its sessions on the next request. | `User::resetPassword`, `AuthService::currentUser` |
| **CSRF** | Synchronizer token stored in the session, delivered by `GET /auth/session`, required in `X-CSRF-Token` on every POST/PUT/PATCH/DELETE. | `utils/Csrf.php`, `CsrfMiddleware` |
| **Roles** | `admin`, `registration_staff`, `event_operator`, `scanner_operator`. | `models/User.php` |
| **Admin accounts** | Created only with `php backend/cli/create-user.php`; no default credentials. Settings › System Reset keeps every admin. | `cli/create-user.php`, `SystemResetService` |
| **Scanner operators** | Created, enabled/disabled and password-reset by an admin in Settings › Scanner Operators. They sign in with a **username** (`^[a-z0-9._-]{3,60}$`) and have no email. | `ScannerOperatorService`, `SettingsController` |
| **Registration staff / event operators** | Email + password accounts created with the CLI. There is no in-app management for them. | `cli/create-user.php` |

### Permissions (verified against `backend/api/routes.php`)

The prompt's list was compared with the real middleware. **Bold** marks behaviour that differs from it.

| Capability | Admin | Registration staff | Event operator | Scanner operator |
|---|:-:|:-:|:-:|:-:|
| Dashboard, events list, attendee list + details, registration history (read) | ✓ | ✓ | ✓ | – |
| Current event / day / session (top bar) | ✓ | ✓ | ✓ | ✓ |
| Event / day / registration-session configuration | ✓ | – | – | – |
| Add attendee (manual) | ✓ | – | ✓ | – |
| Edit / archive / import attendees | ✓ | – | – | – |
| **QR list + summary (all tokens, for printing)** | ✓ | **✓** | – | – |
| View / print one attendee's QR | ✓ | ✓ | **✓** | – |
| Generate / regenerate / export QR | ✓ | – | – | – |
| Registration scanning + scan history | ✓ | ✓ | **–** | ✓ |
| Attendee status lookup (minimal fields) | ✓ | ✓ | ✓ | ✓ |
| Randomizer: view, present, reveal | ✓ | – | ✓ | – |
| Randomizer: name pool sessions, assignments, void reveal | ✓ | – | – | – |
| System Reset, reports, settings | ✓ | – | – | – |
| Manage / renew registration eligibility | ✓ | – | – | – |
| User management | ✓ (scanner operators only, in the app) | – | – | – |

Differences from the prompt:
- Registration staff can list and print **all** QR codes, not just individual ones.
- Event operators **cannot** scan.
- Event operators **can** void draws.
- Admin user management in the app currently covers scanner operators only.

---

## 2. Supabase design

### Identity: Supabase Auth

- **Email + password** for everyone. No custom password code, and no passwords or hashes in application tables. Auth stores them in `auth.users.encrypted_password`.
- **Scanner operators keep their username.** Each gets an internal Auth email `<username>@<domain>`, where the domain comes from `VITE_SUPABASE_USERNAME_EMAIL_DOMAIN` and defaults to `scanner.holcim-event.invalid`:
  - The login form maps a username to that email (`loginIdentifierToEmail`). Users never see it.
  - `profiles.username` stores the username for display.
  - No anonymous "username → email" lookup endpoint exists, so accounts can't be enumerated.
  - Password resets for these accounts are done by an admin through the user-management Edge Function, because no email can reach them.
- *Verify on staging* that Supabase Auth accepts the `.invalid` domain for admin-created users. If it doesn't, use a sub-domain you own that never receives mail (for example `scanner.<your-domain>`).

### Authorization: `public.profiles` (source of truth)

| Column | Notes |
|---|---|
| `id uuid` | PK, FK to `auth.users(id)`, `on delete cascade`. |
| `display_name`, `username` | `username` is unique case-insensitively and uses the PHP pattern. |
| `role` | One of the four roles. **Only** written by migrations, the auth trigger and the admin RPCs. |
| `is_active` | `false` = no access, even with a valid session. |
| `legacy_user_id` | Unique FK to the MySQL-era `users.id`, to keep audit and scan history attributable. |
| `last_login_at`, `created_at`, `updated_at` | |

There is no email, password or hash in this table.

**New Auth users.** A trigger on `auth.users` creates the profile from **`raw_app_meta_data` only**:
- That field is writable only with the service role.
- `raw_user_meta_data` is user-editable and is **ignored**.
- Without an explicit valid role, the profile is **inactive** with no access. A self sign-up that puts `{"role":"admin"}` in its user metadata gets nothing; this is tested.

**JWT claims** are not used for authorization. The role is read from `profiles` on every request, so a role change or deactivation applies to the very next request, as in PHP.

### Reusable checks: `private` schema

These are SECURITY DEFINER functions with `search_path = ''`. The schema is not exposed through the Data API (keep it out of *Exposed schemas*).

| Function | Purpose |
|---|---|
| `private.current_app_role()` | Role of the signed-in **active** user, else NULL. |
| `private.is_active_user()`, `private.has_role(variadic text[])`, `private.is_admin()` | Basic checks. |
| `private.is_staff()` | admin, registration staff, event operator (PHP `$staff`). |
| `private.can_scan()` | admin, registration staff, scanner operator (PHP `$scanners`). |
| `private.can_operate_randomizers()` | admin, event operator (PHP `$randomizerOperators`). |
| `private.require_role(variadic text[])` | Raises `42501` (REST: 403). For future RPCs; not client-callable. |
| `private.write_audit(...)` | Audit writer for RPCs; not client-callable. |

### RLS and grants

- **Every table:** RLS on. **anon: nothing.** **authenticated: SELECT only**, and only through policies of the form `TO authenticated USING ((select private.<check>()))`.
- **No client INSERT/UPDATE/DELETE grant on any table.** Every write will be a role-checked RPC in its phase. This keeps the business rules (audit, locks, one current session, eligibility) on the server.

| Table | Who can SELECT |
|---|---|
| `profiles` | own row (also when inactive); admins: all |
| `events`, `attendees`, `session_registrations`, `session_registration_changes` | admin, registration staff, event operator |
| `event_days`, `registration_sessions` | admin (others: `current_event_context()`) |
| `registration_scans`, `scan_logs` | admin, registration staff, scanner operator |
| `minor_manual_entries`, `major_eligibility`, `randomizer_draws` (legacy history), `predrawn_winners`, `randomizer_pool_sessions` | admin, event operator |
| `major_entries`, `import_batches`, `audit_logs`, `migration_import_runs` | admin |
| **`attendee_qr_codes`, `users`, `login_attempts`** | **nobody** (no grant, no policy) |

### RPCs (exposed; anon cannot execute; each checks role and active itself)

| RPC | Who | Does |
|---|---|---|
| `current_user_context()` | any signed-in user | Own id, name, username, role, active flag. |
| `current_event_context()` | active users | Active event, current day, current registration session (= `GET /event-days/current`). |
| `record_sign_in()` / `record_sign_out()` | signed-in users | Stamps `last_login_at`; writes `auth.login` / `auth.logout`. An inactive account gets `auth.login_refused` and `active: false`, and the app must sign out. |
| `admin_list_users()` | admin | Profiles plus sign-in email (`auth.users` is not readable by clients). |
| `admin_set_user_role(user, role)` | admin | Audited (`user.role_changed`). The last active admin can't be demoted. |
| `admin_set_user_active(user, active)` | admin | Audited (`user.deactivated` / `user.reactivated`). No self-deactivation; the last admin is kept. |

---

## 3. QR token security

- Tokens stay exactly as imported (`text COLLATE "C"`, unchanged by this phase).
- **No client role can read `attendee_qr_codes`, admin included.** This covers direct SELECT, sub-selects, filtering by token and PostgREST embedding (`attendees?select=attendee_qr_codes(token)`), all tested.
- The PHP app currently lets admins and registration staff list all tokens for printing. In Supabase that becomes role-checked RPCs instead of table access.

**Phase 3 secure lookup pattern:**
```text
register_scan(p_scanned text)    SECURITY DEFINER, search_path ''
  private.require_role('admin','registration_staff','scanner_operator')
  token := exact parse (same rules as RegistrationService::extractToken)
  SELECT ... FROM attendee_qr_codes WHERE token = token        -- exact, case-sensitive, indexed
  current session of the active day (FOR SHARE), insert session_registration / day check-in,
  scan_logs, audit; return ONLY {status, attendee name/code/company, session, majorEligible}
```

The token is never returned. Later phases add `get_attendee_qr(attendee_id)` (print one QR: admin, registration staff, event operator) and `export_qr_codes()` (admin), also role-checked.

---

## 4. Sessions: PHP → Supabase

| PHP today | Supabase |
|---|---|
| Session cookie with the user id | Access token (JWT, default 1 h, set in Auth › Sessions) plus a single-use refresh token, kept by `supabase-js`. |
| 8-hour **idle** timeout | **Not automatic.** Supabase's *Inactivity timeout* and *Time-box user sessions* are **Pro plan and up** and are enforced at the next token refresh, so the real cut-off can be up to one JWT lifetime later. On Free, or for a hard 8 h, a later phase adds an app-level idle timer that signs out after 8 h without activity. **Decision needed: plan tier.** |
| `session_version` (password reset logs out) | Changing a password via the Admin API plus **`auth.admin.signOut`** / ban revokes refresh tokens. Already-issued access tokens stay valid until they expire, but are still checked against `profiles.is_active` on every query. |
| Deactivation ends the session on the next request | `admin_set_user_active(false)` gives **immediate** denial: every policy and RPC re-reads `is_active` (tested with the same token before and after). The Edge Function also **bans** the Auth user (`ban_duration`), so no new sign-in or token refresh is possible. |
| Logout destroys the session | `supabase.auth.signOut()` deletes the session server-side (refresh token revoked); the app calls `record_sign_out()` first for the audit row. |
| CSRF token | Not needed for the Data API: requests carry `Authorization: Bearer`, not cookies. Keep it for any cookie-based endpoint. |
| Login throttle (5 per identifier, 30 per IP) | Supabase Auth rate limits (Auth › Rate Limits) plus CAPTCHA if needed. They are not identical to the PHP numbers; review the settings before go-live. |

**Three separate things:**
1. **An Auth user exists:** the person can authenticate, unless banned.
2. **`profiles.is_active`:** whether that identity may use the app. This is checked on every request.
3. **`profiles.role`:** what they may do.

Deleting the Auth user deletes the profile. Prefer deactivation, so history stays attributable.

---

## 5. User management (later phase; needs the service role)

Creating Auth users, setting or resetting passwords, banning and signing out need the **Supabase Admin API (service-role key)**. That runs **only** in an Edge Function such as `admin-users`, with the key in **Edge Function secrets**. It never goes in React, Vite or Vercel env, or in Git. The function must:
1. Read the caller's JWT and check `profiles.role = 'admin' AND is_active`. Doing this through `admin_list_users()` with the caller's token is the simplest correct check.
2. Do the Auth operation:
   - `createUser({email, password | password_hash, email_confirm: true, app_metadata: {role, display_name, username, active}})`
   - `updateUserById` for a password or `ban_duration`
   - `inviteUserByEmail` / password recovery for staff with real email
3. Write the audit row (`user.created`, `user.password_reset`, `user.deactivated`).

Role and active changes that need no Auth call already exist as RPCs. There is **no custom password-reset system**.

---

## 6. Existing users → Supabase Auth

Run the planner on the real database (read-only; it prints counts only, never emails or hashes):

```bash
cd tools
node plan-auth-users.ts --mysql="mysql://readonly:...@127.0.0.1:3306/holcim_event"
node plan-auth-users.ts --mysql=... --out=../database/auth-plan.json      # manifest, no hashes
```

Result on the development copy in my environment (**not** your real database):
- **Accounts:** 119 users (2 admin, 28 registration staff, 28 event operators, 61 scanner operators). Only 4 are active; the rest are smoke-test accounts.
- **Sign-in:** 58 sign in with email and 61 with a username.
- **Password hashes:** all are bcrypt `$2y$` cost 10.
- **Issues:** none blocking.

**Recommended path:**
1. Create Auth users **only for accounts that will be used** (active staff), through the user-management Edge Function, using the planner manifest's `auth_email` and `app_metadata`. History does not need Auth users: legacy rows stay in `users`, and `profiles.legacy_user_id` links the people who do migrate.
2. **Passwords:**
   - **Option A (no reset):** pass the existing bcrypt hash as `password_hash` to `auth.admin.createUser`. Supabase documents bcrypt import, and its migration guide's example is a `$2y$` hash. Use `--include-password-hashes`, which writes a 0600 file; delete it after the import.
   - **Option B (fallback):** if a hash is rejected, test fails or you'd rather rotate, an admin sets a new password for scanner operators. Staff with a real email get an invite or recovery link.
   - **Before choosing:** test one account on staging first.
3. Set **Allow new users to sign up = OFF** in Auth settings. Accounts are admin-created only.
4. **After cutover (Phase 8):** clear `users.password_hash` in Supabase, since it is no longer needed. It is already unreadable by clients.

---

## 7. Audit events

| Event | PHP today | Supabase |
|---|---|---|
| login | `auth.login` | `record_sign_in()` writes `auth.login`, with `actor_id` = the Supabase user. |
| logout | `auth.logout` | `record_sign_out()` writes `auth.logout`. |
| failed login | `auth.login_failed` (with reason) | Never reaches the database (Supabase Auth rejects it). It is visible in Supabase **Auth logs**. An inactive account that does authenticate gets `auth.login_refused`. |
| user created | `user.scanner_operator_created` | Edge Function → `user.created`. |
| user deactivated / reactivated | `user.scanner_operator_*` | `admin_set_user_active` → `user.deactivated` / `user.reactivated`. |
| role changed | (no PHP feature) | `admin_set_user_role` → `user.role_changed`. |

Audit rows are readable by **admins only**. No client role can insert, update or delete them, admin included; only SECURITY DEFINER RPCs write them (tested). `audit_logs.user_id` keeps the legacy id and `actor_id` holds the Supabase user.

## 8. Environment variables

| Variable | Where | Public? |
|---|---|---|
| `VITE_SUPABASE_URL`, `VITE_SUPABASE_ANON_KEY` (anon / `sb_publishable_…`) | Vercel / `frontend/.env.local` | **Public** (in the JS bundle) |
| `VITE_SUPABASE_USERNAME_EMAIL_DOMAIN` | same | **Public** |
| `SUPABASE_SERVICE_ROLE_KEY` / `sb_secret_…` | **Edge Function secrets only** | **Server-only**. The Vite build refuses it in any `VITE_` variable. |
| JWT secret / signing keys | Supabase dashboard only | **Server-only** |
| `SUPABASE_DB_URL` (database password), `PGSSLROOTCERT` | Operator machine for migration tools | **Server-only** |
| `MYSQL_URL` | Operator machine | **Server-only** |

## 9. Supabase dashboard checklist (before any real use)

- **Sign-ups:** Auth › *Allow new users to sign up*: **off**. *Anonymous sign-ins*: **off**.
- **Sessions:** JWT expiry 1 h or less. On Pro, *Inactivity timeout* 8 h to match PHP.
- **Passwords:** minimum length ≥ 8; leaked-password protection on if available.
- **API:** *Exposed schemas* = `public` only, **never `private`**.
- **Rate limits:** review the sign-in rate limits.

## 10. Review of the Phase 1 schema

No Phase 1 migration was edited. Two findings:
- **Supabase default privileges.** Supabase grants new `public` tables and functions to `anon`/`authenticated` by default. The Phase 1 baseline only revoked existing objects, so every later migration must revoke what it creates; the sessions and Phase 2 migrations do. A schema test now enforces "anon: no privileges; authenticated: SELECT only" for every table.
- **Imported password hashes.** `public.users` holds the imported bcrypt hashes. They are unreadable by clients (no grant, RLS, tested), but should be cleared after cutover (§6, step 4).

## 11. Tests

```bash
cd tools
TEST_MYSQL_ADMIN_URL=... TEST_PG_ADMIN_URL=... TEST_POSTGREST_BIN=/path/to/postgrest npm test
```

- **`auth-rls.test.ts`** (needs `TEST_PG_ADMIN_URL`; the REST part also needs `TEST_POSTGREST_BIN`, otherwise it is skipped):
  - anonymous access;
  - a role × table SELECT matrix for 8 personas;
  - QR / credential exposure;
  - every write blocked;
  - profile isolation;
  - context RPCs;
  - admin RPC refusals, inactive users;
  - immediate deactivation;
  - last-admin guard;
  - sign-in auditing;
  - private helpers;
  - REST tests: forged, expired, other-secret and unknown-subject tokens, role-claim tampering, PATCHing your own role, inserting fake audit rows, embedding to reach tokens.
- **`auth-users.test.ts`:** identifier mapping, and the planner (counts only, no hashes, 0600 manifest, read-only).

The local stand-in for Supabase's `auth` schema is `tests/sql/supabase-auth-shim.sql`. A real Supabase project already has the real one; rerun these tests on a Supabase staging project before cutover.
