-- Minimal stand-in for the parts of Supabase that the migrations rely on, so the
-- migrations and RLS tests can run on a plain Postgres (CI or a laptop).
-- Never applied to a real Supabase project: Supabase already provides all of this.

do $$
begin
  if not exists (select from pg_roles where rolname = 'anon') then
    create role anon nologin;
  end if;
  if not exists (select from pg_roles where rolname = 'authenticated') then
    create role authenticated nologin;
  end if;
  if not exists (select from pg_roles where rolname = 'service_role') then
    create role service_role nologin bypassrls;
  end if;
end;
$$;

create schema if not exists auth;

create table auth.users (
  id                 uuid primary key default gen_random_uuid(),
  email              text,
  raw_app_meta_data  jsonb not null default '{}',
  raw_user_meta_data jsonb not null default '{}',
  created_at         timestamptz not null default now()
);

-- Supabase reads the user id from the request's JWT; the tests set it directly.
create function auth.uid()
returns uuid
language sql
stable
as $$
  select nullif(current_setting('request.jwt.claim.sub', true), '')::uuid
$$;

grant usage on schema auth, public to anon, authenticated, service_role;
grant execute on function auth.uid() to anon, authenticated, service_role;

-- Mimic a current Supabase project: new tables and functions are NOT granted to the
-- Data API roles automatically (new projects since 30 May 2026, all since 30 Oct 2026).
-- The migrations must grant everything they need themselves, and the tests prove they do.
alter default privileges in schema public revoke execute on functions from public;
