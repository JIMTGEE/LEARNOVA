-- =====================================================================
-- Table privileges: what each database role may even attempt
--
-- Two layers protect the data:
--   1. GRANTs decide whether a role may touch a table or column at all.
--   2. Row Level Security (earlier migrations) decides which rows.
--
-- Supabase no longer grants new tables to the Data API automatically (new projects
-- since 30 May 2026, every project from 30 Oct 2026). So every privilege is stated
-- here explicitly, at the least level each role needs. This migration runs last and
-- is the single source of truth: it clears whatever defaults the project had and
-- then grants exactly what is listed. It works the same on old and new projects.
--
-- RULE: any future migration that adds a table must add its grants here or in its own file.
-- =====================================================================

grant usage on schema public to anon, authenticated, service_role;

-- Start from nothing for browser-facing roles. Visitors (anon) get nothing at all.
revoke all on all tables    in schema public from anon, authenticated;
revoke all on all sequences in schema public from anon, authenticated;
revoke execute on all functions in schema public from public, anon;

-- Reading: a signed-in user may attempt to read; RLS decides which rows come back.
grant select on all tables in schema public to authenticated;

-- Writing: only where the app writes, and only the operations needed.
grant insert, update, delete on
  public.departments, public.academic_years, public.terms, public.classes, public.subjects,
  public.class_subjects, public.grade_scales, public.students, public.teachers,
  public.parent_students, public.enrollments, public.attendance, public.exams, public.results,
  public.announcements, public.fee_types, public.student_fees
to authenticated;

grant update (name, logo_path, timezone) on public.schools to authenticated;  -- not plan or status
grant update on public.profiles to authenticated;       -- role/school/status guarded by trigger
grant insert, delete on public.notifications to authenticated;
grant update (is_read) on public.notifications to authenticated;
grant insert on public.audit_log to authenticated;      -- append-only
grant insert on public.payments to authenticated;       -- append-only
grant update (voided_at, void_reason) on public.payments to authenticated;  -- voiding only

-- Subscriptions are read-only from the browser (select above); changes go through the service role.

grant execute on function public.grade_for(uuid, numeric) to authenticated;

-- Server-side code (Edge Functions) uses service_role, which bypasses RLS but still needs grants.
grant all on all tables    in schema public to service_role;
grant all on all sequences in schema public to service_role;
grant execute on all functions in schema public to service_role;
