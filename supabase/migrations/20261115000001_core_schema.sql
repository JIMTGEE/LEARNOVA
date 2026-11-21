-- =====================================================================
-- Learnova core schema (multi-tenant)
--
-- Rules this schema follows:
--   1. Every business table has school_id.
--   2. Child tables point at parents with (id, school_id) composite keys,
--      so a row in school A can never reference a record of school B.
--   3. People live in auth.users -> profiles. Teachers, students and
--      parents are roles/extensions of a profile, not separate logins.
--   4. Row Level Security is switched on in the next migration.
--
-- Mapped from the legacy PHP/MySQL database: see docs/MIGRATION_MAP.md
-- =====================================================================

create schema if not exists private;

-- ---------------------------------------------------------------------
-- Enums
-- ---------------------------------------------------------------------
create type public.user_role as enum
  ('super_admin', 'admin', 'headmaster', 'teacher', 'accountant', 'parent', 'student');
create type public.school_status as enum ('trial', 'active', 'suspended');
create type public.subscription_plan as enum ('basic', 'professional', 'enterprise');
create type public.subscription_status as enum ('active', 'expired', 'cancelled');
create type public.account_status as enum ('active', 'inactive', 'suspended', 'graduated');
create type public.gender as enum ('male', 'female', 'other');
create type public.attendance_status as enum ('present', 'absent', 'late', 'excused');
create type public.attendance_mode as enum ('in_person', 'live');
create type public.exam_kind as enum ('class_test', 'mid_term', 'end_term', 'mock');
create type public.result_status as enum ('draft', 'submitted', 'published');
create type public.notification_category as enum
  ('academic', 'extracurricular', 'administrative', 'system');

create function private.set_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

-- ---------------------------------------------------------------------
-- Schools (tenants) and subscriptions
-- ---------------------------------------------------------------------
create table public.schools (
  id                uuid primary key default gen_random_uuid(),
  name              text not null,
  code              text not null unique check (code ~ '^[A-Z0-9_-]{2,20}$'),
  subscription_plan public.subscription_plan not null default 'basic',
  status            public.school_status not null default 'trial',
  country           text not null default 'GH',
  timezone          text not null default 'Africa/Accra',
  logo_path         text,
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now()
);

create table public.subscriptions (
  id         uuid primary key default gen_random_uuid(),
  school_id  uuid not null references public.schools (id) on delete cascade,
  plan       public.subscription_plan not null,
  status     public.subscription_status not null default 'active',
  starts_on  date not null default current_date,
  expires_on date not null,
  created_at timestamptz not null default now(),
  check (expires_on > starts_on)
);
create index subscriptions_school_idx on public.subscriptions (school_id, expires_on desc);

-- ---------------------------------------------------------------------
-- Profiles: one row per login. Replaces legacy `users`.
-- ---------------------------------------------------------------------
create table public.profiles (
  id                    uuid primary key references auth.users (id) on delete cascade,
  school_id             uuid references public.schools (id) on delete restrict,
  role                  public.user_role not null,
  full_name             text not null,
  email                 text,
  phone                 text,
  whatsapp_number       text,
  gender                public.gender,
  national_id           text,
  address               text,
  avatar_path           text,
  status                public.account_status not null default 'active',
  force_password_change boolean not null default true,
  last_login_at         timestamptz,
  legacy_id             integer,
  created_at            timestamptz not null default now(),
  updated_at            timestamptz not null default now(),
  constraint profiles_school_required check (role = 'super_admin' or school_id is not null),
  unique (id, school_id)
);
create index profiles_school_role_idx on public.profiles (school_id, role);

-- ---------------------------------------------------------------------
-- Academic structure
-- ---------------------------------------------------------------------
create table public.departments (       -- school levels: Pre-School ... Junior High
  id         uuid primary key default gen_random_uuid(),
  school_id  uuid not null references public.schools (id) on delete cascade,
  name       text not null,
  sort_order integer not null default 0,
  legacy_id  integer,
  unique (school_id, name),
  unique (id, school_id)
);

create table public.academic_years (
  id         uuid primary key default gen_random_uuid(),
  school_id  uuid not null references public.schools (id) on delete cascade,
  name       text not null,            -- e.g. 2026/2027
  starts_on  date not null,
  ends_on    date not null,
  is_current boolean not null default false,
  legacy_id  integer,
  unique (school_id, name),
  unique (id, school_id),
  check (ends_on > starts_on)
);
create unique index academic_years_one_current on public.academic_years (school_id) where is_current;

create table public.terms (
  id               uuid primary key default gen_random_uuid(),
  school_id        uuid not null references public.schools (id) on delete cascade,
  academic_year_id uuid not null,
  name             text not null,      -- e.g. Term 1
  starts_on        date not null,
  ends_on          date not null,
  is_current       boolean not null default false,
  legacy_id        integer,
  unique (school_id, academic_year_id, name),
  unique (id, school_id),
  check (ends_on > starts_on),
  foreign key (academic_year_id, school_id)
    references public.academic_years (id, school_id) on delete cascade
);
create unique index terms_one_current on public.terms (school_id) where is_current;

create table public.teachers (
  id              uuid primary key default gen_random_uuid(),
  school_id       uuid not null references public.schools (id) on delete cascade,
  profile_id      uuid not null unique,
  employee_number text,
  department_id   uuid,
  hire_date       date,
  status          public.account_status not null default 'active',
  legacy_id       integer,
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  unique (id, school_id),
  unique (school_id, employee_number),
  foreign key (profile_id, school_id)
    references public.profiles (id, school_id) on delete cascade,
  foreign key (department_id, school_id)
    references public.departments (id, school_id)
);

create table public.classes (
  id               uuid primary key default gen_random_uuid(),
  school_id        uuid not null references public.schools (id) on delete cascade,
  department_id    uuid not null,
  name             text not null,
  class_teacher_id uuid,
  legacy_id        integer,
  created_at       timestamptz not null default now(),
  updated_at       timestamptz not null default now(),
  unique (school_id, name),
  unique (id, school_id),
  foreign key (department_id, school_id)
    references public.departments (id, school_id),
  foreign key (class_teacher_id, school_id)
    references public.teachers (id, school_id) on delete set null (class_teacher_id)
);

create table public.subjects (
  id            uuid primary key default gen_random_uuid(),
  school_id     uuid not null references public.schools (id) on delete cascade,
  department_id uuid,
  name          text not null,
  code          text not null,
  is_active     boolean not null default true,
  legacy_id     integer,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now(),
  unique (school_id, code),
  unique (id, school_id),
  foreign key (department_id, school_id)
    references public.departments (id, school_id) on delete set null (department_id)
);

create table public.class_subjects (    -- which teacher teaches which subject in which class
  id         uuid primary key default gen_random_uuid(),
  school_id  uuid not null references public.schools (id) on delete cascade,
  class_id   uuid not null,
  subject_id uuid not null,
  teacher_id uuid,
  unique (class_id, subject_id),
  foreign key (class_id, school_id)
    references public.classes (id, school_id) on delete cascade,
  foreign key (subject_id, school_id)
    references public.subjects (id, school_id) on delete cascade,
  foreign key (teacher_id, school_id)
    references public.teachers (id, school_id) on delete set null (teacher_id)
);
create index class_subjects_teacher_idx on public.class_subjects (teacher_id);

-- ---------------------------------------------------------------------
-- Students, enrolment and parents
-- ---------------------------------------------------------------------
create table public.students (
  id               uuid primary key default gen_random_uuid(),
  school_id        uuid not null references public.schools (id) on delete cascade,
  profile_id       uuid unique,        -- null for learners without a login (e.g. KG)
  admission_number text not null,
  full_name        text not null,
  gender           public.gender,
  date_of_birth    date,
  admission_date   date,
  status           public.account_status not null default 'active',
  photo_path       text,
  legacy_id        integer,
  created_at       timestamptz not null default now(),
  updated_at       timestamptz not null default now(),
  unique (school_id, admission_number),
  unique (id, school_id),
  foreign key (profile_id, school_id)
    references public.profiles (id, school_id) on delete set null (profile_id)
);
create index students_name_idx on public.students (school_id, full_name);

create table public.enrollments (       -- a student's class in an academic year (legacy: student_classes)
  id               uuid primary key default gen_random_uuid(),
  school_id        uuid not null references public.schools (id) on delete cascade,
  student_id       uuid not null,
  class_id         uuid not null,
  academic_year_id uuid not null,
  enrolled_on      date not null default current_date,
  created_at       timestamptz not null default now(),
  unique (student_id, academic_year_id),
  foreign key (student_id, school_id)
    references public.students (id, school_id) on delete cascade,
  foreign key (class_id, school_id)
    references public.classes (id, school_id) on delete cascade,
  foreign key (academic_year_id, school_id)
    references public.academic_years (id, school_id) on delete cascade
);
create index enrollments_class_idx on public.enrollments (class_id, academic_year_id);

create table public.parent_students (   -- legacy: parent_student_mapping
  id           uuid primary key default gen_random_uuid(),
  school_id    uuid not null references public.schools (id) on delete cascade,
  parent_id    uuid not null,
  student_id   uuid not null,
  relationship text,
  is_primary   boolean not null default false,
  created_at   timestamptz not null default now(),
  unique (parent_id, student_id),
  foreign key (parent_id, school_id)
    references public.profiles (id, school_id) on delete cascade,
  foreign key (student_id, school_id)
    references public.students (id, school_id) on delete cascade
);
create index parent_students_student_idx on public.parent_students (student_id);

-- ---------------------------------------------------------------------
-- Attendance
-- ---------------------------------------------------------------------
create table public.attendance (
  id          uuid primary key default gen_random_uuid(),
  school_id   uuid not null references public.schools (id) on delete cascade,
  student_id  uuid not null,
  class_id    uuid not null,
  attended_on date not null,
  status      public.attendance_status not null,
  mode        public.attendance_mode not null default 'in_person',
  remarks     text,
  marked_by   uuid default auth.uid() references public.profiles (id) on delete set null,
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now(),
  unique (student_id, class_id, attended_on, mode),
  foreign key (student_id, school_id)
    references public.students (id, school_id) on delete cascade,
  foreign key (class_id, school_id)
    references public.classes (id, school_id) on delete cascade
);
create index attendance_class_day_idx on public.attendance (school_id, class_id, attended_on);

-- ---------------------------------------------------------------------
-- Exams, results and grading
--   Legacy `grades` held mid_term_exam, end_term_exam and total on one row.
--   Here each assessment is an exam with a weight; the term total is
--   computed (see term_subject_totals). Mid-term 50 + end-term 50 matches
--   the legacy rule: total = mid * 0.5 + end * 0.5.
-- ---------------------------------------------------------------------
create table public.exams (
  id           uuid primary key default gen_random_uuid(),
  school_id    uuid not null references public.schools (id) on delete cascade,
  term_id      uuid not null,
  class_id     uuid not null,
  subject_id   uuid not null,
  name         text not null,
  kind         public.exam_kind not null,
  mock_number  smallint,
  weight       numeric(5,2) not null default 0 check (weight between 0 and 100),
  max_score    numeric(6,2) not null default 100 check (max_score > 0),
  exam_date    date,
  start_time   time,
  end_time     time,
  room         text,
  instructions text,
  created_by   uuid default auth.uid() references public.profiles (id) on delete set null,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now(),
  unique (school_id, term_id, class_id, subject_id, name),
  unique (id, school_id),
  foreign key (term_id, school_id)
    references public.terms (id, school_id) on delete cascade,
  foreign key (class_id, school_id)
    references public.classes (id, school_id) on delete cascade,
  foreign key (subject_id, school_id)
    references public.subjects (id, school_id) on delete cascade
);
create index exams_class_term_idx on public.exams (class_id, term_id);

create table public.results (
  id         uuid primary key default gen_random_uuid(),
  school_id  uuid not null references public.schools (id) on delete cascade,
  exam_id    uuid not null,
  student_id uuid not null,
  score      numeric(6,2) check (score >= 0),
  remarks    text,
  status     public.result_status not null default 'draft',
  entered_by uuid default auth.uid() references public.profiles (id) on delete set null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  unique (exam_id, student_id),
  foreign key (exam_id, school_id)
    references public.exams (id, school_id) on delete cascade,
  foreign key (student_id, school_id)
    references public.students (id, school_id) on delete cascade
);
create index results_student_idx on public.results (student_id);

create function private.check_result_score()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_max numeric;
begin
  select max_score into v_max
  from public.exams
  where id = new.exam_id and school_id = new.school_id;

  if new.score is not null and v_max is not null and new.score > v_max then
    raise exception 'Score % is higher than the maximum of % for this exam', new.score, v_max
      using errcode = 'check_violation';
  end if;
  return new;
end;
$$;

create trigger results_check_score
  before insert or update of score, exam_id on public.results
  for each row execute function private.check_result_score();

create table public.grade_scales (      -- per-school letter grades
  id        uuid primary key default gen_random_uuid(),
  school_id uuid not null references public.schools (id) on delete cascade,
  min_score numeric(5,2) not null,
  grade     text not null,
  remark    text not null,
  unique (school_id, min_score)
);

create function public.grade_for(p_school uuid, p_total numeric)
returns table (grade text, remark text)
language sql
stable
set search_path = ''
as $$
  select g.grade, g.remark
  from public.grade_scales g
  where g.school_id = p_school and g.min_score <= p_total
  order by g.min_score desc
  limit 1
$$;

-- Weighted term total per student and subject. weight_covered tells the app
-- whether the total is provisional (e.g. only mid-term entered: 50 of 100).
create view public.term_subject_totals
with (security_invoker = true) as
select
  r.school_id,
  r.student_id,
  e.term_id,
  e.class_id,
  e.subject_id,
  round(sum(least(r.score / e.max_score, 1) * e.weight), 2) as total,
  sum(e.weight)                                              as weight_covered,
  count(*)                                                   as components
from public.results r
join public.exams e on e.id = r.exam_id and e.school_id = r.school_id
where e.weight > 0 and r.score is not null
group by r.school_id, r.student_id, e.term_id, e.class_id, e.subject_id;

-- ---------------------------------------------------------------------
-- Communication and audit
-- ---------------------------------------------------------------------
create table public.notifications (     -- one row per recipient (legacy shared one row for broadcasts)
  id           uuid primary key default gen_random_uuid(),
  school_id    uuid not null references public.schools (id) on delete cascade,
  recipient_id uuid not null,
  title        text not null,
  body         text not null,
  category     public.notification_category not null default 'system',
  link         text,
  is_read      boolean not null default false,
  created_at   timestamptz not null default now(),
  foreign key (recipient_id, school_id)
    references public.profiles (id, school_id) on delete cascade
);
create index notifications_recipient_idx
  on public.notifications (recipient_id, is_read, created_at desc);

create table public.announcements (
  id           uuid primary key default gen_random_uuid(),
  school_id    uuid not null references public.schools (id) on delete cascade,
  title        text not null,
  body         text not null,
  audience     public.user_role[] not null default '{}',   -- empty = everyone in the school
  published_at timestamptz not null default now(),
  created_by   uuid default auth.uid() references public.profiles (id) on delete set null,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now()
);
create index announcements_school_idx on public.announcements (school_id, published_at desc);

create table public.audit_log (         -- replaces legacy activity_log, activity_logs, user_logs
  id         bigint generated always as identity primary key,
  school_id  uuid references public.schools (id) on delete cascade,
  actor_id   uuid default auth.uid() references public.profiles (id) on delete set null,
  action     text not null,
  entity     text,
  entity_id  text,
  metadata   jsonb not null default '{}',
  created_at timestamptz not null default now()
);
create index audit_log_school_idx on public.audit_log (school_id, created_at desc);

-- ---------------------------------------------------------------------
-- Profile creation. Accounts are created by an admin through a server-side
-- function (service role) that sets school_id and role in app_metadata,
-- which a user cannot edit. Without it no profile is created, so a
-- self-registered account has no access to any school data.
-- ---------------------------------------------------------------------
create function private.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  v_school uuid;
  v_role   public.user_role;
begin
  v_school := nullif(new.raw_app_meta_data ->> 'school_id', '')::uuid;
  v_role   := nullif(new.raw_app_meta_data ->> 'role', '')::public.user_role;

  if v_role is null or (v_school is null and v_role <> 'super_admin') then
    return new;
  end if;

  insert into public.profiles (id, school_id, role, full_name, email)
  values (
    new.id,
    v_school,
    v_role,
    coalesce(nullif(new.raw_user_meta_data ->> 'full_name', ''), new.email, 'Unnamed user'),
    new.email
  );
  return new;
end;
$$;

create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function private.handle_new_user();

-- ---------------------------------------------------------------------
-- Housekeeping: updated_at triggers and legacy_id uniqueness
-- ---------------------------------------------------------------------
do $$
declare
  t text;
begin
  foreach t in array array[
    'schools', 'profiles', 'teachers', 'classes', 'subjects', 'students',
    'attendance', 'exams', 'results', 'announcements'
  ] loop
    execute format(
      'create trigger set_updated_at before update on public.%I
         for each row execute function private.set_updated_at()', t);
  end loop;

  foreach t in array array[
    'profiles', 'departments', 'academic_years', 'terms',
    'teachers', 'classes', 'subjects', 'students'
  ] loop
    execute format(
      'create unique index %I on public.%I (school_id, legacy_id) where legacy_id is not null',
      t || '_legacy_idx', t);
  end loop;
end;
$$;
