-- Holcim Event System - Supabase migration Phase 2: Auth, profiles, roles, RLS.
--
-- Supabase Auth (auth.users) authenticates; THIS migration authorizes:
--   * public.profiles      one row per auth user: role, active flag, display name,
--                          optional username (scanner operators). No passwords, no email.
--   * private.*            role helpers used by RLS policies and RPCs (SECURITY DEFINER,
--                          search_path = '', schema NOT exposed through the Data API).
--   * RLS policies         SELECT-only, role-based, active accounts only. No client-side
--                          INSERT/UPDATE/DELETE on any table: every write will be a
--                          role-checked RPC added in its own phase (scan, attendees,
--                          randomizer, admin). attendee_qr_codes has NO client access.
--   * RPCs                 current_user_context, current_event_context, record_sign_in,
--                          record_sign_out, admin_list_users, admin_set_user_role,
--                          admin_set_user_active.
--
-- The authoritative role is public.profiles.role, writable only by the database owner
-- (migrations), the auth.users trigger (from app_metadata, which only the service role
-- can set) and the admin RPCs. JWT claims and user_metadata are never trusted for roles.
-- See docs/SUPABASE_AUTH.md.

-- ---------------------------------------------------------------- private schema
create schema if not exists private;
revoke all on schema private from public;
-- Policies run as the calling role, so authenticated needs USAGE + EXECUTE on the
-- helpers. The schema is not in the Data API's exposed schemas: not callable over REST.
grant usage on schema private to authenticated;

-- ---------------------------------------------------------------- profiles
create table public.profiles (
  id              uuid primary key references auth.users (id) on delete cascade,
  display_name    varchar(150) not null,
  username        varchar(60),          -- scanner operators sign in with a username (see docs)
  role            text not null default 'scanner_operator'
                  check (role in ('admin', 'registration_staff', 'event_operator', 'scanner_operator')),
  is_active       boolean not null default false,
  legacy_user_id  integer unique references public.users (id) on delete set null on update cascade,
  last_login_at   timestamptz,
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  constraint chk_profiles_username check (username is null or username ~ '^[a-z0-9._-]{3,60}$')
);
comment on table public.profiles is
  'Application profile per Supabase Auth user. role + is_active are the authorization source of truth.';
create unique index uq_profiles_username on public.profiles (lower(username));
create index idx_profiles_role_active on public.profiles (role, is_active);
create trigger trg_profiles_updated_at before update on public.profiles
  for each row execute function public.set_updated_at();

-- ---------------------------------------------------------------- audit: Supabase actor
-- audit_logs.user_id keeps pointing at the legacy users table (history);
-- actor_id records the Supabase user for events written from now on.
alter table public.audit_logs
  add column actor_id uuid references public.profiles (id) on delete set null;
create index idx_audit_logs_actor on public.audit_logs (actor_id);

-- ---------------------------------------------------------------- role helpers
-- The ONLY place the current user's role is read. NULL when not signed in, no
-- profile, or the profile is inactive - so an inactive account matches no policy.
create or replace function private.current_app_role()
returns text
language sql stable security definer set search_path = ''
as $$
  select p.role from public.profiles p
  where p.id = (select auth.uid()) and p.is_active
$$;

create or replace function private.is_active_user()
returns boolean
language sql stable security definer set search_path = ''
as $$
  select exists (select 1 from public.profiles p where p.id = (select auth.uid()) and p.is_active)
$$;

create or replace function private.has_role(variadic roles text[])
returns boolean
language sql stable security definer set search_path = ''
as $$
  select coalesce(private.current_app_role() = any (roles), false)
$$;

create or replace function private.is_admin()
returns boolean
language sql stable security definer set search_path = ''
as $$ select private.has_role('admin') $$;

-- Role groups = the PHP route groups in backend/api/routes.php.
-- $staff: admin, registration staff, event operator (dashboard, events, attendees).
create or replace function private.is_staff()
returns boolean
language sql stable security definer set search_path = ''
as $$ select private.has_role('admin', 'registration_staff', 'event_operator') $$;

-- $scanners: admin, registration staff, scanner operator (registration desk).
create or replace function private.can_scan()
returns boolean
language sql stable security definer set search_path = ''
as $$ select private.has_role('admin', 'registration_staff', 'scanner_operator') $$;

-- $randomizerOperators: admin, event operator.
create or replace function private.can_operate_randomizers()
returns boolean
language sql stable security definer set search_path = ''
as $$ select private.has_role('admin', 'event_operator') $$;

-- Raises 42501 (PostgREST: 403) unless the caller is an ACTIVE user with one of the roles.
create or replace function private.require_role(variadic roles text[])
returns void
language plpgsql stable security definer set search_path = ''
as $$
begin
  if (select auth.uid()) is null then
    raise exception 'Not signed in.' using errcode = '42501';
  end if;
  if not private.has_role(variadic roles) then
    raise exception 'Not allowed for this account.' using errcode = '42501';
  end if;
end $$;

-- Audit writer for SECURITY DEFINER RPCs (never granted to clients).
create or replace function private.write_audit(p_action text, p_description text, p_event_id integer default null, p_metadata jsonb default null)
returns void
language plpgsql volatile security definer set search_path = ''
as $$
declare
  v_headers jsonb := nullif(current_setting('request.headers', true), '')::jsonb;
  v_actor uuid := (select auth.uid());
begin
  insert into public.audit_logs (user_id, actor_id, event_id, action, description, metadata, ip_address, user_agent, created_at)
  values (
    (select p.legacy_user_id from public.profiles p where p.id = v_actor),
    case when exists (select 1 from public.profiles p where p.id = v_actor) then v_actor end,
    p_event_id, left(p_action, 100), left(p_description, 500), p_metadata,
    left(split_part(coalesce(v_headers ->> 'x-forwarded-for', ''), ',', 1), 45),
    left(v_headers ->> 'user-agent', 255),
    now()
  );
end $$;

revoke all on all functions in schema private from public, anon, authenticated;
grant execute on function private.current_app_role(), private.is_active_user(), private.has_role(text[]),
  private.is_admin(), private.is_staff(), private.can_scan(), private.can_operate_randomizers()
  to authenticated;
-- require_role / write_audit: only used inside SECURITY DEFINER functions (run as owner).

-- ---------------------------------------------------------------- new auth user -> profile
-- Runs for every new auth.users row (admin-created, invited or self sign-up).
-- Role / active / username / legacy link come ONLY from raw_app_meta_data (writable only
-- with the service role). raw_user_meta_data is user-editable and is ignored here.
-- Without an explicit valid role the profile is INACTIVE scanner_operator = no access.
create or replace function private.handle_new_auth_user()
returns trigger
language plpgsql security definer set search_path = ''
as $$
declare
  v_app jsonb := coalesce(new.raw_app_meta_data, '{}'::jsonb);
  v_role text := v_app ->> 'role';
  v_valid boolean := coalesce(v_role in ('admin', 'registration_staff', 'event_operator', 'scanner_operator'), false);
  v_legacy integer;
begin
  if (v_app ->> 'legacy_user_id') ~ '^\d{1,10}$' then
    select u.id into v_legacy from public.users u where u.id = (v_app ->> 'legacy_user_id')::integer;
  end if;
  insert into public.profiles (id, display_name, username, role, is_active, legacy_user_id)
  values (
    new.id,
    left(coalesce(nullif(btrim(v_app ->> 'display_name'), ''), nullif(split_part(coalesce(new.email, ''), '@', 1), ''), 'User'), 150),
    lower(nullif(btrim(v_app ->> 'username'), '')),
    case when v_valid then v_role else 'scanner_operator' end,
    v_valid and coalesce((v_app ->> 'active')::boolean, true),
    v_legacy
  )
  on conflict (id) do nothing;
  return new;
end $$;
revoke all on function private.handle_new_auth_user() from public;

create trigger on_auth_user_created_holcim_profile
  after insert on auth.users
  for each row execute function private.handle_new_auth_user();

-- ---------------------------------------------------------------- RLS policies (SELECT only)
alter table public.profiles enable row level security;

-- Grants: start from nothing, then allow SELECT where a policy exists. Writes have no
-- grant at all, so a mistake in a policy can never allow INSERT/UPDATE/DELETE.
revoke all on public.profiles from anon, authenticated;
do $$
declare
  t text;
begin
  foreach t in array array[
    'profiles', 'events', 'event_days', 'registration_sessions', 'attendees',
    'session_registrations', 'session_registration_changes', 'registration_scans', 'scan_logs',
    'minor_manual_entries', 'major_eligibility', 'randomizer_draws', 'major_entries',
    'import_batches', 'audit_logs', 'migration_import_runs'
  ] loop
    execute format('revoke all on public.%I from anon, authenticated', t);
    execute format('grant select on public.%I to authenticated', t);
  end loop;
  -- No client access at all (not even SELECT): legacy credentials, login throttle,
  -- and QR tokens (Phase 3 adds a controlled lookup function).
  foreach t in array array['users', 'login_attempts', 'attendee_qr_codes'] loop
    execute format('revoke all on public.%I from anon, authenticated', t);
  end loop;
end $$;
revoke all on all sequences in schema public from anon, authenticated;

-- profiles: everyone sees their own row (even when inactive, so the app can say
-- "account disabled"); active admins see all. No write policies.
create policy profiles_select_own on public.profiles
  for select to authenticated using (id = (select auth.uid()));
create policy profiles_select_admin on public.profiles
  for select to authenticated using ((select private.is_admin()));

-- events: staff (GET /events). Scanner operators get the active event via current_event_context().
create policy events_select_staff on public.events
  for select to authenticated using ((select private.is_staff()));

-- event days and registration sessions: admin (GET /events/{id}/days, /event-days/{id}/sessions).
create policy event_days_select_admin on public.event_days
  for select to authenticated using ((select private.is_admin()));
create policy registration_sessions_select_admin on public.registration_sessions
  for select to authenticated using ((select private.is_admin()));

-- attendees (no QR tokens in this table): staff (GET /attendees, /attendees/{id}).
create policy attendees_select_staff on public.attendees
  for select to authenticated using ((select private.is_staff()));

-- registration history: staff (GET /attendees/{id}/registrations).
create policy session_registrations_select_staff on public.session_registrations
  for select to authenticated using ((select private.is_staff()));
create policy session_registration_changes_select_staff on public.session_registration_changes
  for select to authenticated using ((select private.is_staff()));

-- day check-ins and scan attempts: the registration desk roles (/registration/summary, /scans).
create policy registration_scans_select_scanners on public.registration_scans
  for select to authenticated using ((select private.can_scan()));
create policy scan_logs_select_scanners on public.scan_logs
  for select to authenticated using ((select private.can_scan()));

-- randomizer pools, manual participants and draws: admin + event operator.
create policy minor_manual_entries_select_randomizer on public.minor_manual_entries
  for select to authenticated using ((select private.can_operate_randomizers()));
create policy major_eligibility_select_randomizer on public.major_eligibility
  for select to authenticated using ((select private.can_operate_randomizers()));
create policy randomizer_draws_select_randomizer on public.randomizer_draws
  for select to authenticated using ((select private.can_operate_randomizers()));

-- admin only: legacy Major entries (PII), import batches, audit log, import runs.
create policy major_entries_select_admin on public.major_entries
  for select to authenticated using ((select private.is_admin()));
create policy import_batches_select_admin on public.import_batches
  for select to authenticated using ((select private.is_admin()));
create policy audit_logs_select_admin on public.audit_logs
  for select to authenticated using ((select private.is_admin()));
create policy migration_import_runs_select_admin on public.migration_import_runs
  for select to authenticated using ((select private.is_admin()));

-- users, login_attempts, attendee_qr_codes: RLS on, no policy, no grant -> no client access.

-- ---------------------------------------------------------------- RPCs (exposed, role-checked)
-- Who am I? Works for inactive accounts too (returns active = false) so the app can
-- show "account disabled". Never returns anything about other users.
create or replace function public.current_user_context()
returns jsonb
language plpgsql stable security definer set search_path = ''
as $$
declare
  v_uid uuid := (select auth.uid());
  v jsonb;
begin
  if v_uid is null then
    raise exception 'Not signed in.' using errcode = '42501';
  end if;
  select jsonb_build_object(
      'id', p.id, 'displayName', p.display_name, 'username', p.username,
      'role', p.role, 'active', p.is_active, 'lastLoginAt', p.last_login_at)
    into v
  from public.profiles p where p.id = v_uid;
  return coalesce(v, jsonb_build_object('id', v_uid, 'active', false, 'role', null));
end $$;

-- Current event / day / registration session (= GET /event-days/current): any ACTIVE user.
create or replace function public.current_event_context()
returns jsonb
language plpgsql stable security definer set search_path = ''
as $$
declare
  v_event public.events%rowtype;
  v_day public.event_days%rowtype;
  v_session public.registration_sessions%rowtype;
begin
  perform private.require_role('admin', 'registration_staff', 'event_operator', 'scanner_operator');
  select * into v_event from public.events e where e.status = 'active' limit 1;
  if v_event.id is not null then
    select * into v_day from public.event_days d where d.event_id = v_event.id and d.status = 'active' limit 1;
  end if;
  if v_day.id is not null then
    select * into v_session from public.registration_sessions s where s.event_day_id = v_day.id and s.is_current limit 1;
  end if;
  return jsonb_build_object(
    'event', case when v_event.id is null then null else jsonb_build_object('id', v_event.id, 'name', v_event.name) end,
    'eventDay', case when v_day.id is null then null else jsonb_build_object(
      'id', v_day.id, 'dayNumber', v_day.day_number, 'eventDate', v_day.event_date, 'label', v_day.label) end,
    'registrationSession', case when v_session.id is null then null else jsonb_build_object(
      'label', v_session.label, 'sessionDate', v_session.session_date,
      'startTime', to_char(v_session.start_time, 'HH24:MI')) end
  );
end $$;

-- Called by the app right after a successful Supabase sign-in: stamps last_login_at and
-- writes auth.login to the audit log. For an inactive account (or one without a profile)
-- it writes auth.login_refused and returns active = false - the app must then sign out.
-- (No exception here, otherwise the refusal could not be audited: it would roll back.
-- Access is denied regardless, because every policy and RPC checks is_active.)
create or replace function public.record_sign_in()
returns jsonb
language plpgsql volatile security definer set search_path = ''
as $$
declare
  v_uid uuid := (select auth.uid());
begin
  if v_uid is null then
    raise exception 'Not signed in.' using errcode = '42501';
  end if;
  if not exists (select 1 from public.profiles p where p.id = v_uid and p.is_active) then
    perform private.write_audit('auth.login_refused', 'Sign-in refused: account inactive or without a profile.', null,
      jsonb_build_object('reason', 'inactive_account', 'auth_user', v_uid));
    return public.current_user_context();
  end if;
  update public.profiles set last_login_at = now() where id = v_uid;
  perform private.write_audit('auth.login', 'User logged in.');
  return public.current_user_context();
end $$;

create or replace function public.record_sign_out()
returns void
language plpgsql volatile security definer set search_path = ''
as $$
begin
  if (select auth.uid()) is null then
    raise exception 'Not signed in.' using errcode = '42501';
  end if;
  perform private.write_audit('auth.logout', 'User logged out.');
end $$;

-- Admin: list accounts with their sign-in email (auth.users is not readable by clients).
create or replace function public.admin_list_users()
returns table (id uuid, email text, display_name text, username text, role text, is_active boolean,
               last_login_at timestamptz, created_at timestamptz, legacy_user_id integer)
language plpgsql stable security definer set search_path = ''
as $$
begin
  perform private.require_role('admin');
  return query
    select p.id, u.email::text, p.display_name::text, p.username::text, p.role, p.is_active,
           p.last_login_at, p.created_at, p.legacy_user_id
    from public.profiles p join auth.users u on u.id = p.id
    order by p.display_name, p.id;
end $$;

-- Admin: change a user's role. The last active admin cannot be demoted.
create or replace function public.admin_set_user_role(p_user_id uuid, p_role text)
returns jsonb
language plpgsql volatile security definer set search_path = ''
as $$
declare
  v_before public.profiles%rowtype;
begin
  perform private.require_role('admin');
  if p_role is null or p_role not in ('admin', 'registration_staff', 'event_operator', 'scanner_operator') then
    raise exception 'Unknown role.' using errcode = '22023';
  end if;
  -- Serialise admin changes so two admins cannot demote each other at once.
  perform 1 from public.profiles where role = 'admin' and is_active for update;
  select * into v_before from public.profiles where id = p_user_id for update;
  if v_before.id is null then
    raise exception 'User not found.' using errcode = 'P0002';
  end if;
  if v_before.role = p_role then
    return jsonb_build_object('id', p_user_id, 'role', p_role, 'changed', false);
  end if;
  if v_before.role = 'admin' and v_before.is_active
     and (select count(*) from public.profiles where role = 'admin' and is_active) <= 1 then
    raise exception 'The last active admin cannot lose the admin role.' using errcode = '42501';
  end if;
  update public.profiles set role = p_role where id = p_user_id;
  perform private.write_audit('user.role_changed',
    format('Changed role of %s from %s to %s.', v_before.display_name, v_before.role, p_role), null,
    jsonb_build_object('target_user', p_user_id, 'from', v_before.role, 'to', p_role));
  return jsonb_build_object('id', p_user_id, 'role', p_role, 'changed', true);
end $$;

-- Admin: deactivate / reactivate. Takes effect on the user's very next request (every
-- policy re-reads profiles.is_active). Banning the auth user / revoking sessions is done
-- by the user-management Edge Function (service role) - see docs/SUPABASE_AUTH.md.
create or replace function public.admin_set_user_active(p_user_id uuid, p_active boolean)
returns jsonb
language plpgsql volatile security definer set search_path = ''
as $$
declare
  v_before public.profiles%rowtype;
begin
  perform private.require_role('admin');
  if p_active is null then
    raise exception 'Active must be true or false.' using errcode = '22023';
  end if;
  perform 1 from public.profiles where role = 'admin' and is_active for update;
  select * into v_before from public.profiles where id = p_user_id for update;
  if v_before.id is null then
    raise exception 'User not found.' using errcode = 'P0002';
  end if;
  if v_before.is_active = p_active then
    return jsonb_build_object('id', p_user_id, 'active', p_active, 'changed', false);
  end if;
  if not p_active and p_user_id = (select auth.uid()) then
    raise exception 'You cannot deactivate your own account.' using errcode = '42501';
  end if;
  if not p_active and v_before.role = 'admin'
     and (select count(*) from public.profiles where role = 'admin' and is_active) <= 1 then
    raise exception 'The last active admin cannot be deactivated.' using errcode = '42501';
  end if;
  update public.profiles set is_active = p_active where id = p_user_id;
  perform private.write_audit(case when p_active then 'user.reactivated' else 'user.deactivated' end,
    format('%s %s (%s).', case when p_active then 'Reactivated' else 'Deactivated' end, v_before.display_name, v_before.role), null,
    jsonb_build_object('target_user', p_user_id, 'role', v_before.role));
  return jsonb_build_object('id', p_user_id, 'active', p_active, 'changed', true);
end $$;

-- Exposed RPCs: signed-in users only (each checks role/active itself). Never anon.
revoke all on function public.current_user_context(), public.current_event_context(), public.record_sign_in(),
  public.record_sign_out(), public.admin_list_users(), public.admin_set_user_role(uuid, text),
  public.admin_set_user_active(uuid, boolean) from public, anon;
grant execute on function public.current_user_context(), public.current_event_context(), public.record_sign_in(),
  public.record_sign_out(), public.admin_list_users(), public.admin_set_user_role(uuid, text),
  public.admin_set_user_active(uuid, boolean) to authenticated;

-- Supabase grants EXECUTE on new public functions to anon/authenticated by default;
-- make sure no older helper is callable by clients.
revoke execute on function public.set_updated_at(), public.generate_qr_token(), public.app_time_zone() from public, anon, authenticated;
