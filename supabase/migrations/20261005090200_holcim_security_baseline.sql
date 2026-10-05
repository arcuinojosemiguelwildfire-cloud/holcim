-- Holcim Event System - Supabase/PostgreSQL foundation (Phase 1), part 3/3.
-- Security baseline: DENY EVERYTHING to the public Data API until later phases
-- add role-based policies and RPC functions.
--
-- Supabase exposes tables in the "public" schema through its Data API to the
-- "anon" and "authenticated" roles. These tables hold attendee personal data,
-- QR tokens and password hashes, so in Phase 1:
--   * Row Level Security is ENABLED on every table with NO policies (deny all), and
--   * all table/sequence/function privileges are REVOKED from anon/authenticated.
-- Only the database owner (migrations, the import tool, verification) can read/write.

do $$
declare
  t text;
begin
  foreach t in array array[
    'users', 'events', 'event_days', 'import_batches', 'attendees', 'attendee_qr_codes',
    'registration_scans', 'scan_logs', 'minor_manual_entries', 'major_eligibility',
    'major_entries', 'randomizer_draws', 'audit_logs', 'login_attempts', 'migration_import_runs'
  ] loop
    -- ENABLE (not FORCE): API roles get no rows; the table owner used by
    -- migrations and the import/verify tools is not subject to RLS.
    execute format('alter table public.%I enable row level security', t);
  end loop;
end $$;

do $$
declare
  r text;
begin
  foreach r in array array['anon', 'authenticated'] loop
    if exists (select 1 from pg_roles where rolname = r) then
      execute format('revoke all on all tables in schema public from %I', r);
      execute format('revoke all on all sequences in schema public from %I', r);
      execute format('revoke execute on all functions in schema public from %I', r);
    end if;
  end loop;
end $$;

-- Helper functions are internal; nobody calls them through the API.
revoke execute on function public.generate_qr_token() from public;
revoke execute on function public.set_updated_at() from public;
