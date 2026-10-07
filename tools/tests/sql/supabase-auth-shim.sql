-- TEST-ONLY stand-in for the parts of Supabase that the migrations rely on.
-- Real Supabase projects already have all of this; never apply it there.
--   * schema auth, a minimal auth.users table and auth.uid()/auth.role()/auth.jwt()
--     reading the JWT claims exactly as Supabase does (PostgREST puts the verified
--     JWT payload in the request.jwt.claims setting);
--   * the API roles anon / authenticated / service_role and the authenticator login
--     role are created cluster-wide by tests/helpers.ts.
create schema if not exists auth;
create table if not exists auth.users (
  id                 uuid primary key default gen_random_uuid(),
  email              varchar(255),
  encrypted_password varchar(255),
  raw_app_meta_data  jsonb default '{}'::jsonb,
  raw_user_meta_data jsonb default '{}'::jsonb,
  banned_until       timestamptz,
  last_sign_in_at    timestamptz,
  created_at         timestamptz default now(),
  updated_at         timestamptz default now()
);
create or replace function auth.jwt() returns jsonb language sql stable as $$
  select coalesce(nullif(current_setting('request.jwt.claims', true), '')::jsonb, '{}'::jsonb)
$$;
create or replace function auth.uid() returns uuid language sql stable as $$
  select nullif(coalesce(nullif(current_setting('request.jwt.claim.sub', true), ''), auth.jwt() ->> 'sub'), '')::uuid
$$;
create or replace function auth.role() returns text language sql stable as $$
  select coalesce(nullif(current_setting('request.jwt.claim.role', true), ''), auth.jwt() ->> 'role')
$$;
grant usage on schema auth to anon, authenticated, service_role;
grant execute on all functions in schema auth to anon, authenticated, service_role;
