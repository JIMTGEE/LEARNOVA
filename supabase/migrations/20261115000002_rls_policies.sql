-- =====================================================================
-- Row Level Security
--
-- Access model
--   admin / headmaster : full access inside their own school
--   teacher            : sees all learners in the school; marks attendance and
--                        enters results ONLY for classes they teach; cannot publish
--   accountant         : sees learners; finance access (see fees migration)
--   parent             : sees only their own children
--   student            : sees only themselves, and only PUBLISHED results
--   super_admin        : platform operator; sees schools and subscriptions only
--                        (school data is touched through the service role)
--
-- A suspended user, or any user of a suspended school, resolves to no school
-- and no role, so every policy below returns nothing for them.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Helper functions (private schema, not exposed through the API)
-- SECURITY DEFINER so they can read profiles without recursing into RLS.
-- ---------------------------------------------------------------------
create function private.app_school_id()
returns uuid
language sql
stable
security definer
set search_path = ''
as $$
  select p.school_id
  from public.profiles p
  join public.schools s on s.id = p.school_id
  where p.id = (select auth.uid())
    and p.status = 'active'
    and s.status <> 'suspended'
$$;

create function private.app_role()
returns public.user_role
language sql
stable
security definer
set search_path = ''
as $$
  select p.role
  from public.profiles p
  left join public.schools s on s.id = p.school_id
  where p.id = (select auth.uid())
    and p.status = 'active'
    and (p.role = 'super_admin' or s.status <> 'suspended')
$$;

create function private.is_super_admin()
returns boolean language sql stable security definer set search_path = ''
as $$ select coalesce(private.app_role() = 'super_admin', false) $$;

create function private.is_school_admin()
returns boolean language sql stable security definer set search_path = ''
as $$ select coalesce(private.app_role() in ('admin', 'headmaster'), false) $$;

create function private.is_school_staff()
returns boolean language sql stable security definer set search_path = ''
as $$ select coalesce(private.app_role() in ('admin', 'headmaster', 'teacher', 'accountant'), false) $$;

create function private.is_teacher()
returns boolean language sql stable security definer set search_path = ''
as $$ select coalesce(private.app_role() = 'teacher', false) $$;

create function private.is_parent_of(p_student uuid)
returns boolean language sql stable security definer set search_path = ''
as $$
  select exists (
    select 1 from public.parent_students ps
    where ps.student_id = p_student and ps.parent_id = (select auth.uid())
  )
$$;

create function private.is_self_student(p_student uuid)
returns boolean language sql stable security definer set search_path = ''
as $$
  select exists (
    select 1 from public.students s
    where s.id = p_student and s.profile_id = (select auth.uid())
  )
$$;

-- True when the signed-in teacher is the class teacher, or teaches any subject, in the class.
create function private.teaches_class(p_class uuid)
returns boolean language sql stable security definer set search_path = ''
as $$
  select exists (
    select 1
    from public.teachers t
    where t.profile_id = (select auth.uid())
      and t.status = 'active'
      and (
        exists (select 1 from public.classes c
                where c.id = p_class and c.class_teacher_id = t.id)
        or exists (select 1 from public.class_subjects cs
                   where cs.class_id = p_class and cs.teacher_id = t.id)
      )
  )
$$;

create function private.teaches_exam(p_exam uuid)
returns boolean language sql stable security definer set search_path = ''
as $$
  select exists (
    select 1 from public.exams e
    where e.id = p_exam and private.teaches_class(e.class_id)
  )
$$;

-- ---------------------------------------------------------------------
-- Privileges
-- ---------------------------------------------------------------------
revoke all on all tables in schema public from anon;
alter default privileges in schema public revoke all on tables from anon;

alter default privileges in schema private revoke execute on functions from public, anon;
revoke execute on all functions in schema private from public, anon;
grant usage on schema private to authenticated;
grant execute on all functions in schema private to authenticated;
alter default privileges in schema private grant execute on functions to authenticated;

-- Writes that RLS cannot express by column
revoke insert, delete on public.schools from authenticated;
revoke update on public.schools from authenticated;
grant update (name, logo_path, timezone) on public.schools to authenticated;

revoke insert, update, delete on public.subscriptions from authenticated;
revoke insert, delete on public.profiles from authenticated;

revoke update on public.notifications from authenticated;
grant update (is_read) on public.notifications to authenticated;

revoke update, delete on public.audit_log from authenticated;

-- ---------------------------------------------------------------------
-- Enable RLS on every table in public
-- ---------------------------------------------------------------------
do $$
declare
  t text;
begin
  for t in select tablename from pg_tables where schemaname = 'public' loop
    execute format('alter table public.%I enable row level security', t);
  end loop;
end;
$$;

-- ---------------------------------------------------------------------
-- schools, subscriptions
-- ---------------------------------------------------------------------
create policy schools_select on public.schools for select to authenticated
  using (id = (select private.app_school_id()) or (select private.is_super_admin()));

create policy schools_update_admin on public.schools for update to authenticated
  using (id = (select private.app_school_id()) and (select private.is_school_admin()))
  with check (id = (select private.app_school_id()));

create policy subscriptions_select on public.subscriptions for select to authenticated
  using (
    (school_id = (select private.app_school_id()) and (select private.is_school_admin()))
    or (select private.is_super_admin())
  );

-- ---------------------------------------------------------------------
-- profiles
-- ---------------------------------------------------------------------
create policy profiles_select on public.profiles for select to authenticated
  using (
    id = (select auth.uid())
    or (school_id = (select private.app_school_id()) and (select private.is_school_staff()))
    or (select private.is_super_admin())
  );

create policy profiles_update_self on public.profiles for update to authenticated
  using (id = (select auth.uid()))
  with check (id = (select auth.uid()));

create policy profiles_update_admin on public.profiles for update to authenticated
  using (school_id = (select private.app_school_id()) and (select private.is_school_admin()))
  with check (school_id = (select private.app_school_id()) and (select private.is_school_admin()));

-- RLS cannot stop a user editing their own role. This trigger does.
create function private.profiles_guard()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
  -- Service role, dashboard and migrations run without a signed-in user.
  if (select auth.uid()) is null then
    return new;
  end if;

  if new.role = 'super_admin' and not private.is_super_admin() then
    raise exception 'Only a platform administrator can assign this role'
      using errcode = 'insufficient_privilege';
  end if;

  if private.is_super_admin() then
    return new;
  end if;

  if private.is_school_admin() then
    if new.school_id is distinct from old.school_id then
      raise exception 'A user cannot be moved to another school'
        using errcode = 'insufficient_privilege';
    end if;
    return new;
  end if;

  if new.role is distinct from old.role
     or new.school_id is distinct from old.school_id
     or new.status is distinct from old.status then
    raise exception 'You cannot change your own role, school or status'
      using errcode = 'insufficient_privilege';
  end if;
  return new;
end;
$$;

create trigger profiles_guard
  before update on public.profiles
  for each row execute function private.profiles_guard();

-- ---------------------------------------------------------------------
-- Reference data: everyone in the school can read, school admins can write
-- ---------------------------------------------------------------------
do $$
declare
  t text;
begin
  foreach t in array array[
    'departments', 'academic_years', 'terms', 'classes',
    'subjects', 'class_subjects', 'grade_scales'
  ] loop
    execute format(
      'create policy %I on public.%I for select to authenticated
         using (school_id = (select private.app_school_id()))',
      t || '_select_school', t);
    execute format(
      'create policy %I on public.%I for all to authenticated
         using (school_id = (select private.app_school_id()) and (select private.is_school_admin()))
         with check (school_id = (select private.app_school_id()) and (select private.is_school_admin()))',
      t || '_write_admin', t);
  end loop;
end;
$$;

-- ---------------------------------------------------------------------
-- People
-- ---------------------------------------------------------------------
create policy students_select on public.students for select to authenticated
  using (
    school_id = (select private.app_school_id())
    and (
      (select private.is_school_staff())
      or profile_id = (select auth.uid())
      or private.is_parent_of(id)
    )
  );
create policy students_write_admin on public.students for all to authenticated
  using (school_id = (select private.app_school_id()) and (select private.is_school_admin()))
  with check (school_id = (select private.app_school_id()) and (select private.is_school_admin()));

create policy teachers_select_staff on public.teachers for select to authenticated
  using (school_id = (select private.app_school_id()) and (select private.is_school_staff()));
create policy teachers_write_admin on public.teachers for all to authenticated
  using (school_id = (select private.app_school_id()) and (select private.is_school_admin()))
  with check (school_id = (select private.app_school_id()) and (select private.is_school_admin()));

create policy parent_students_select on public.parent_students for select to authenticated
  using (
    school_id = (select private.app_school_id())
    and ((select private.is_school_staff()) or parent_id = (select auth.uid()))
  );
create policy parent_students_write_admin on public.parent_students for all to authenticated
  using (school_id = (select private.app_school_id()) and (select private.is_school_admin()))
  with check (school_id = (select private.app_school_id()) and (select private.is_school_admin()));

create policy enrollments_select on public.enrollments for select to authenticated
  using (
    school_id = (select private.app_school_id())
    and (
      (select private.is_school_staff())
      or private.is_parent_of(student_id)
      or private.is_self_student(student_id)
    )
  );
create policy enrollments_write_admin on public.enrollments for all to authenticated
  using (school_id = (select private.app_school_id()) and (select private.is_school_admin()))
  with check (school_id = (select private.app_school_id()) and (select private.is_school_admin()));

-- ---------------------------------------------------------------------
-- Attendance: admins everywhere; teachers only for classes they teach
-- ---------------------------------------------------------------------
create policy attendance_select on public.attendance for select to authenticated
  using (
    school_id = (select private.app_school_id())
    and (
      (select private.is_school_admin())
      or ((select private.is_teacher()) and private.teaches_class(class_id))
      or private.is_parent_of(student_id)
      or private.is_self_student(student_id)
    )
  );

create policy attendance_insert on public.attendance for insert to authenticated
  with check (
    school_id = (select private.app_school_id())
    and marked_by = (select auth.uid())
    and (
      (select private.is_school_admin())
      or ((select private.is_teacher()) and private.teaches_class(class_id))
    )
  );

create policy attendance_update on public.attendance for update to authenticated
  using (
    school_id = (select private.app_school_id())
    and (
      (select private.is_school_admin())
      or ((select private.is_teacher()) and private.teaches_class(class_id))
    )
  )
  with check (
    school_id = (select private.app_school_id())
    and (
      (select private.is_school_admin())
      or ((select private.is_teacher()) and private.teaches_class(class_id))
    )
  );

create policy attendance_delete_admin on public.attendance for delete to authenticated
  using (school_id = (select private.app_school_id()) and (select private.is_school_admin()));

-- ---------------------------------------------------------------------
-- Exams and results
-- ---------------------------------------------------------------------
create policy exams_select_school on public.exams for select to authenticated
  using (school_id = (select private.app_school_id()));

create policy exams_insert on public.exams for insert to authenticated
  with check (
    school_id = (select private.app_school_id())
    and (
      (select private.is_school_admin())
      or ((select private.is_teacher()) and private.teaches_class(class_id))
    )
  );

create policy exams_update on public.exams for update to authenticated
  using (
    school_id = (select private.app_school_id())
    and (
      (select private.is_school_admin())
      or ((select private.is_teacher()) and private.teaches_class(class_id))
    )
  )
  with check (
    school_id = (select private.app_school_id())
    and (
      (select private.is_school_admin())
      or ((select private.is_teacher()) and private.teaches_class(class_id))
    )
  );

create policy exams_delete_admin on public.exams for delete to authenticated
  using (school_id = (select private.app_school_id()) and (select private.is_school_admin()));

create policy results_select on public.results for select to authenticated
  using (
    school_id = (select private.app_school_id())
    and (
      (select private.is_school_admin())
      or ((select private.is_teacher()) and private.teaches_exam(exam_id))
      or (status = 'published' and (private.is_parent_of(student_id) or private.is_self_student(student_id)))
    )
  );

create policy results_insert_teacher on public.results for insert to authenticated
  with check (
    school_id = (select private.app_school_id())
    and (select private.is_teacher())
    and private.teaches_exam(exam_id)
    and status <> 'published'
    and entered_by = (select auth.uid())
  );

create policy results_update_teacher on public.results for update to authenticated
  using (
    school_id = (select private.app_school_id())
    and (select private.is_teacher())
    and private.teaches_exam(exam_id)
    and status <> 'published'
  )
  with check (
    school_id = (select private.app_school_id())
    and (select private.is_teacher())
    and private.teaches_exam(exam_id)
    and status <> 'published'
  );

create policy results_write_admin on public.results for all to authenticated
  using (school_id = (select private.app_school_id()) and (select private.is_school_admin()))
  with check (school_id = (select private.app_school_id()) and (select private.is_school_admin()));

-- ---------------------------------------------------------------------
-- Communication and audit
-- ---------------------------------------------------------------------
create policy notifications_select_own on public.notifications for select to authenticated
  using (recipient_id = (select auth.uid()));
create policy notifications_update_own on public.notifications for update to authenticated
  using (recipient_id = (select auth.uid()))
  with check (recipient_id = (select auth.uid()));
create policy notifications_delete_own on public.notifications for delete to authenticated
  using (recipient_id = (select auth.uid()));
create policy notifications_insert_staff on public.notifications for insert to authenticated
  with check (school_id = (select private.app_school_id()) and (select private.is_school_staff()));

create policy announcements_select on public.announcements for select to authenticated
  using (
    school_id = (select private.app_school_id())
    and (
      (select private.is_school_admin())
      or (
        published_at <= now()
        and (cardinality(audience) = 0 or (select private.app_role()) = any (audience))
      )
    )
  );
create policy announcements_write_admin on public.announcements for all to authenticated
  using (school_id = (select private.app_school_id()) and (select private.is_school_admin()))
  with check (school_id = (select private.app_school_id()) and (select private.is_school_admin()));

create policy audit_log_select_admin on public.audit_log for select to authenticated
  using (
    (school_id = (select private.app_school_id()) and (select private.is_school_admin()))
    or (select private.is_super_admin())
  );
create policy audit_log_insert on public.audit_log for insert to authenticated
  with check (
    school_id = (select private.app_school_id())
    and actor_id = (select auth.uid())
  );
