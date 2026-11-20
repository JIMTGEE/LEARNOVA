-- =====================================================================
-- Access-control and integrity tests.
-- Two schools, every role, and attempts to cross the lines between them.
-- Runs inside one transaction and rolls back, so it leaves nothing behind.
-- Any failed expectation aborts the script with a FAIL message.
-- =====================================================================
\set ON_ERROR_STOP on
begin;

-- ---------- fixture ids ----------
\set schoolA 'aaaaaaaa-0000-4000-8000-000000000001'
\set schoolB 'bbbbbbbb-0000-4000-8000-000000000001'
\set adminA 'a0000000-0000-4000-8000-000000000001'
\set teacherA1 'a0000000-0000-4000-8000-000000000002'
\set teacherA2 'a0000000-0000-4000-8000-000000000003'
\set accountantA 'a0000000-0000-4000-8000-000000000004'
\set parentA1 'a0000000-0000-4000-8000-000000000005'
\set parentA2 'a0000000-0000-4000-8000-000000000006'
\set studentUserA 'a0000000-0000-4000-8000-000000000007'
\set adminB 'b0000000-0000-4000-8000-000000000001'
\set depA 'aaaaaaaa-0000-4000-8000-0000000000d1'
\set depB 'bbbbbbbb-0000-4000-8000-0000000000d1'
\set yearA 'aaaaaaaa-0000-4000-8000-0000000000e1'
\set yearB 'bbbbbbbb-0000-4000-8000-0000000000e1'
\set termA 'aaaaaaaa-0000-4000-8000-0000000000f1'
\set termB 'bbbbbbbb-0000-4000-8000-0000000000f1'
\set tA1 'aaaaaaaa-0000-4000-8000-0000000000a1'
\set tA2 'aaaaaaaa-0000-4000-8000-0000000000a2'
\set class5A 'aaaaaaaa-0000-4000-8000-0000000000c1'
\set class5B 'aaaaaaaa-0000-4000-8000-0000000000c2'
\set class6X 'bbbbbbbb-0000-4000-8000-0000000000c1'
\set mathsA 'aaaaaaaa-0000-4000-8000-0000000000b1'
\set mathsB 'bbbbbbbb-0000-4000-8000-0000000000b1'
\set sA1 'aaaaaaaa-0000-4000-8000-000000000051'
\set sA2 'aaaaaaaa-0000-4000-8000-000000000052'
\set sA3 'aaaaaaaa-0000-4000-8000-000000000053'
\set sB1 'bbbbbbbb-0000-4000-8000-000000000051'
\set examMid 'aaaaaaaa-0000-4000-8000-0000000000e5'
\set examEnd 'aaaaaaaa-0000-4000-8000-0000000000e6'
\set examMid5B 'aaaaaaaa-0000-4000-8000-0000000000e7'
\set feeTuition 'aaaaaaaa-0000-4000-8000-0000000000f5'
\set sfA1 'aaaaaaaa-0000-4000-8000-0000000000f6'
\set sfA2 'aaaaaaaa-0000-4000-8000-0000000000f7'

-- ---------- test helpers ----------
create schema test;

create function test.expect_count(p_label text, p_actual bigint, p_expected bigint)
returns void language plpgsql as $$
begin
  if p_actual is distinct from p_expected then
    raise exception 'FAIL: % (expected %, got %)', p_label, p_expected, p_actual;
  end if;
  raise notice 'PASS: %', p_label;
end; $$;

create function test.expect_text(p_label text, p_actual text, p_expected text)
returns void language plpgsql as $$
begin
  if p_actual is distinct from p_expected then
    raise exception 'FAIL: % (expected %, got %)', p_label, p_expected, p_actual;
  end if;
  raise notice 'PASS: %', p_label;
end; $$;

-- rows a query returns when run as the given role and user
create function test.count_as(p_role text, p_user uuid, p_sql text)
returns bigint language plpgsql as $$
declare n bigint;
begin
  perform set_config('request.jwt.claim.sub', coalesce(p_user::text, ''), true);
  execute format('set local role %I', p_role);
  execute 'select count(*) from (' || p_sql || ') q' into n;
  execute 'reset role';
  perform set_config('request.jwt.claim.sub', '', true);
  return n;
end; $$;

create function test.count_as(p_user uuid, p_sql text)
returns bigint language sql as $$ select test.count_as('authenticated', p_user, p_sql) $$;

-- rows a write statement changed
create function test.changed_as(p_user uuid, p_sql text)
returns bigint language plpgsql as $$
declare n bigint;
begin
  perform set_config('request.jwt.claim.sub', p_user::text, true);
  execute 'set local role authenticated';
  execute p_sql;
  get diagnostics n = row_count;
  execute 'reset role';
  perform set_config('request.jwt.claim.sub', '', true);
  return n;
end; $$;

-- 'ok' when the statement succeeds, otherwise the SQLSTATE it failed with
create function test.error_as(p_role text, p_user uuid, p_sql text)
returns text language plpgsql as $$
begin
  perform set_config('request.jwt.claim.sub', coalesce(p_user::text, ''), true);
  execute format('set local role %I', p_role);
  execute p_sql;
  execute 'reset role';
  perform set_config('request.jwt.claim.sub', '', true);
  return 'ok';
exception when others then
  return sqlstate;
end; $$;

create function test.error_as(p_user uuid, p_sql text)
returns text language sql as $$ select test.error_as('authenticated', p_user, p_sql) $$;

-- ---------- fixtures (as superuser) ----------
insert into public.schools (id, name, code, status) values
  (:'schoolA', 'School A', 'AAA', 'active'),
  (:'schoolB', 'School B', 'BBB', 'active');

insert into auth.users (id, email, raw_app_meta_data, raw_user_meta_data) values
  (:'adminA',       'admin@a.test',      jsonb_build_object('role','admin',      'school_id', :'schoolA'::text), '{"full_name":"Admin A"}'),
  (:'teacherA1',    'teacher1@a.test',   jsonb_build_object('role','teacher',    'school_id', :'schoolA'::text), '{"full_name":"Teacher A1"}'),
  (:'teacherA2',    'teacher2@a.test',   jsonb_build_object('role','teacher',    'school_id', :'schoolA'::text), '{"full_name":"Teacher A2"}'),
  (:'accountantA',  'acct@a.test',       jsonb_build_object('role','accountant', 'school_id', :'schoolA'::text), '{"full_name":"Accountant A"}'),
  (:'parentA1',     'parent1@a.test',    jsonb_build_object('role','parent',     'school_id', :'schoolA'::text), '{"full_name":"Parent A1"}'),
  (:'parentA2',     'parent2@a.test',    jsonb_build_object('role','parent',     'school_id', :'schoolA'::text), '{"full_name":"Parent A2"}'),
  (:'studentUserA', 'student@a.test',    jsonb_build_object('role','student',    'school_id', :'schoolA'::text), '{"full_name":"Student A1"}'),
  (:'adminB',       'admin@b.test',      jsonb_build_object('role','admin',      'school_id', :'schoolB'::text), '{"full_name":"Admin B"}');

insert into public.departments (id, school_id, name) values
  (:'depA', :'schoolA', 'Upper Primary'), (:'depB', :'schoolB', 'Upper Primary');
insert into public.academic_years (id, school_id, name, starts_on, ends_on, is_current) values
  (:'yearA', :'schoolA', '2026/2027', '2026-09-07', '2027-07-23', true),
  (:'yearB', :'schoolB', '2026/2027', '2026-09-07', '2027-07-23', true);
insert into public.terms (id, school_id, academic_year_id, name, starts_on, ends_on, is_current) values
  (:'termA', :'schoolA', :'yearA', 'Term 1', '2026-09-07', '2026-12-18', true),
  (:'termB', :'schoolB', :'yearB', 'Term 1', '2026-09-07', '2026-12-18', true);
insert into public.teachers (id, school_id, profile_id, employee_number) values
  (:'tA1', :'schoolA', :'teacherA1', 'T001'), (:'tA2', :'schoolA', :'teacherA2', 'T002');
insert into public.classes (id, school_id, department_id, name, class_teacher_id) values
  (:'class5A', :'schoolA', :'depA', 'Class 5A', :'tA1'),
  (:'class5B', :'schoolA', :'depA', 'Class 5B', null),
  (:'class6X', :'schoolB', :'depB', 'Class 6X', null);
insert into public.subjects (id, school_id, name, code) values
  (:'mathsA', :'schoolA', 'Mathematics', 'MATH'), (:'mathsB', :'schoolB', 'Mathematics', 'MATH');
insert into public.class_subjects (school_id, class_id, subject_id, teacher_id) values
  (:'schoolA', :'class5A', :'mathsA', :'tA1'),
  (:'schoolA', :'class5B', :'mathsA', :'tA2');
insert into public.students (id, school_id, profile_id, admission_number, full_name) values
  (:'sA1', :'schoolA', :'studentUserA', 'A-001', 'Student A1'),
  (:'sA2', :'schoolA', null,            'A-002', 'Student A2'),
  (:'sA3', :'schoolA', null,            'A-003', 'Student A3'),
  (:'sB1', :'schoolB', null,            'B-001', 'Student B1');
insert into public.enrollments (school_id, student_id, class_id, academic_year_id) values
  (:'schoolA', :'sA1', :'class5A', :'yearA'),
  (:'schoolA', :'sA2', :'class5B', :'yearA'),
  (:'schoolA', :'sA3', :'class5A', :'yearA'),
  (:'schoolB', :'sB1', :'class6X', :'yearB');
insert into public.parent_students (school_id, parent_id, student_id) values
  (:'schoolA', :'parentA1', :'sA1'), (:'schoolA', :'parentA2', :'sA2');
insert into public.exams (id, school_id, term_id, class_id, subject_id, name, kind, weight) values
  (:'examMid',   :'schoolA', :'termA', :'class5A', :'mathsA', 'Mid-term', 'mid_term', 50),
  (:'examEnd',   :'schoolA', :'termA', :'class5A', :'mathsA', 'End-term', 'end_term', 50),
  (:'examMid5B', :'schoolA', :'termA', :'class5B', :'mathsA', 'Mid-term', 'mid_term', 50);
insert into public.results (school_id, exam_id, student_id, score, status) values
  (:'schoolA', :'examMid',   :'sA1', 40, 'published'),
  (:'schoolA', :'examEnd',   :'sA1', 60, 'published'),
  (:'schoolA', :'examMid5B', :'sA2', 30, 'draft');
insert into public.attendance (school_id, student_id, class_id, attended_on, status) values
  (:'schoolA', :'sA1', :'class5A', '2026-11-02', 'present'),
  (:'schoolA', :'sA2', :'class5B', '2026-11-02', 'absent');
insert into public.grade_scales (school_id, min_score, grade, remark) values
  (:'schoolA', 90, 'A+', 'Excellent'), (:'schoolA', 85, 'A', 'Great'), (:'schoolA', 0, 'F', 'Fail');
insert into public.fee_types (id, school_id, name) values (:'feeTuition', :'schoolA', 'Tuition');
insert into public.student_fees (id, school_id, student_id, fee_type_id, academic_year_id, term_id, amount_due) values
  (:'sfA1', :'schoolA', :'sA1', :'feeTuition', :'yearA', :'termA', 500),
  (:'sfA2', :'schoolA', :'sA2', :'feeTuition', :'yearA', :'termA', 300);

\o /dev/null
-- =====================================================================
-- Setup sanity
-- =====================================================================
select test.expect_count('every public table has RLS enabled',
  (select count(*) from pg_tables where schemaname = 'public' and not rowsecurity), 0);
select test.expect_count('trigger created a profile for each invited user',
  (select count(*) from public.profiles), 8);

-- =====================================================================
-- Sign-up safety
-- =====================================================================
insert into auth.users (id, email) values ('c0000000-0000-4000-8000-000000000001', 'stranger@x.test');
insert into auth.users (id, email, raw_user_meta_data) values
  ('c0000000-0000-4000-8000-000000000002', 'sneaky@x.test',
   jsonb_build_object('role', 'admin', 'school_id', :'schoolA'::text));
select test.expect_count('self-registered account gets no profile',
  (select count(*) from public.profiles where id = 'c0000000-0000-4000-8000-000000000001'), 0);
select test.expect_count('role claimed in user-editable metadata is ignored',
  (select count(*) from public.profiles where id = 'c0000000-0000-4000-8000-000000000002'), 0);
select test.expect_text('anonymous visitors cannot read students',
  test.error_as('anon', null, 'select * from public.students'), '42501');

-- =====================================================================
-- School isolation
-- =====================================================================
select test.expect_count('admin A sees only their own school', test.count_as(:'adminA', 'select * from public.schools'), 1);
select test.expect_count('admin A sees School A learners only', test.count_as(:'adminA', 'select * from public.students'), 3);
select test.expect_count('admin B sees School B learners only', test.count_as(:'adminB', 'select * from public.students'), 1);
select test.expect_count('admin B sees none of School A classes',
  test.count_as(:'adminB', format('select * from public.classes where school_id = %L', :'schoolA')), 0);
select test.expect_count('staff see profiles of their own school only',
  test.count_as(:'accountantA', 'select * from public.profiles'), 7);
select test.expect_text('admin A cannot create a learner in School B',
  test.error_as(:'adminA', format(
    $q$insert into public.students (school_id, admission_number, full_name) values (%L, 'X-1', 'Intruder')$q$, :'schoolB')),
  '42501');
select test.expect_text('a row in School A cannot point at a learner of School B',
  test.error_as(:'adminA', format(
    $q$insert into public.enrollments (school_id, student_id, class_id, academic_year_id) values (%L, %L, %L, %L)$q$,
    :'schoolA', :'sB1', :'class5A', :'yearA')),
  '23503');
select test.expect_count('admin A cannot edit a School B learner',
  test.changed_as(:'adminA', format($q$update public.students set full_name = 'Hacked' where id = %L$q$, :'sB1')), 0);

-- =====================================================================
-- Who sees which learners
-- =====================================================================
select test.expect_count('teacher sees all learners in the school', test.count_as(:'teacherA1', 'select * from public.students'), 3);
select test.expect_count('parent sees only their own child', test.count_as(:'parentA1', 'select * from public.students'), 1);
select test.expect_count('student sees only themselves', test.count_as(:'studentUserA', 'select * from public.students'), 1);
select test.expect_count('parent sees only their own profile', test.count_as(:'parentA1', 'select * from public.profiles'), 1);

-- =====================================================================
-- Attendance
-- =====================================================================
select test.expect_count('class teacher sees attendance of their class',
  test.count_as(:'teacherA1', format('select * from public.attendance where student_id = %L', :'sA1')), 1);
select test.expect_count('another teacher does not see that class attendance',
  test.count_as(:'teacherA2', format('select * from public.attendance where student_id = %L', :'sA1')), 0);
select test.expect_count('parent sees their child attendance',
  test.count_as(:'parentA1', format('select * from public.attendance where student_id = %L', :'sA1')), 1);
select test.expect_count('parent does not see another child attendance',
  test.count_as(:'parentA1', format('select * from public.attendance where student_id = %L', :'sA2')), 0);
select test.expect_text('teacher cannot mark attendance for a class they do not teach',
  test.error_as(:'teacherA2', format(
    $q$insert into public.attendance (school_id, student_id, class_id, attended_on, status) values (%L, %L, %L, '2026-11-03', 'present')$q$,
    :'schoolA', :'sA1', :'class5A')),
  '42501');
select test.expect_text('teacher can mark attendance for their own class',
  test.error_as(:'teacherA1', format(
    $q$insert into public.attendance (school_id, student_id, class_id, attended_on, status) values (%L, %L, %L, '2026-11-03', 'present')$q$,
    :'schoolA', :'sA1', :'class5A')),
  'ok');
select test.expect_count('marked_by is filled in from the signed-in user',
  (select count(*) from public.attendance where attended_on = '2026-11-03' and marked_by = :'teacherA1'), 1);
select test.expect_text('teacher cannot record attendance as someone else',
  test.error_as(:'teacherA1', format(
    $q$insert into public.attendance (school_id, student_id, class_id, attended_on, status, marked_by) values (%L, %L, %L, '2026-11-04', 'present', %L)$q$,
    :'schoolA', :'sA1', :'class5A', :'adminA')),
  '42501');

-- =====================================================================
-- Results: visibility and publishing
-- =====================================================================
select test.expect_count('parent sees published results of their child', test.count_as(:'parentA1', 'select * from public.results'), 2);
select test.expect_count('student sees their own published results', test.count_as(:'studentUserA', 'select * from public.results'), 2);
select test.expect_count('parent does not see draft results', test.count_as(:'parentA2', 'select * from public.results'), 0);
select test.expect_count('teacher sees results only for classes they teach (5A)', test.count_as(:'teacherA1', 'select * from public.results'), 2);
select test.expect_count('teacher sees results only for classes they teach (5B)', test.count_as(:'teacherA2', 'select * from public.results'), 1);
select test.expect_count('term total = 40/100*50 + 60/100*50 = 50 with full weight covered',
  test.count_as(:'adminA', format('select * from public.term_subject_totals where student_id = %L and total = 50.00 and weight_covered = 100', :'sA1')), 1);
select test.expect_count('parent sees the term total of their child', test.count_as(:'parentA1', 'select * from public.term_subject_totals'), 1);
select test.expect_count('parent of a draft-only child sees no totals', test.count_as(:'parentA2', 'select * from public.term_subject_totals'), 0);
select test.expect_count('letter grade for 87 is A',
  test.count_as(:'adminA', format('select * from public.grade_for(%L, 87) where grade = %L', :'schoolA', 'A')), 1);

select test.expect_text('teacher can enter a draft result for their class',
  test.error_as(:'teacherA1', format(
    $q$insert into public.results (school_id, exam_id, student_id, score, status) values (%L, %L, %L, 45, 'draft')$q$,
    :'schoolA', :'examMid', :'sA3')), 'ok');
select test.expect_text('teacher cannot enter a result as already published',
  test.error_as(:'teacherA1', format(
    $q$insert into public.results (school_id, exam_id, student_id, score, status) values (%L, %L, %L, 45, 'published')$q$,
    :'schoolA', :'examEnd', :'sA3')), '42501');
select test.expect_text('teacher cannot enter results for a class they do not teach',
  test.error_as(:'teacherA2', format(
    $q$insert into public.results (school_id, exam_id, student_id, score, status) values (%L, %L, %L, 45, 'draft')$q$,
    :'schoolA', :'examEnd', :'sA3')), '42501');
select test.expect_text('score above the exam maximum is rejected',
  test.error_as(:'teacherA1', format(
    $q$insert into public.results (school_id, exam_id, student_id, score, status) values (%L, %L, %L, 120, 'draft')$q$,
    :'schoolA', :'examEnd', :'sA3')), '23514');
select test.expect_count('teacher cannot change a published result',
  test.changed_as(:'teacherA1', format($q$update public.results set score = 99 where student_id = %L and exam_id = %L$q$, :'sA1', :'examMid')), 0);
select test.expect_text('teacher cannot publish a result',
  test.error_as(:'teacherA1', format($q$update public.results set status = 'published' where student_id = %L and exam_id = %L$q$, :'sA3', :'examMid')), '42501');
select test.expect_count('admin can publish a result',
  test.changed_as(:'adminA', format($q$update public.results set status = 'published' where student_id = %L and exam_id = %L$q$, :'sA3', :'examMid')), 1);

-- =====================================================================
-- Profiles and schools: no self-promotion
-- =====================================================================
select test.expect_text('teacher cannot make themselves admin',
  test.error_as(:'teacherA1', format($q$update public.profiles set role = 'admin' where id = %L$q$, :'teacherA1')), '42501');
select test.expect_count('teacher can edit their own phone number',
  test.changed_as(:'teacherA1', format($q$update public.profiles set phone = '0240000000' where id = %L$q$, :'teacherA1')), 1);
select test.expect_count('teacher cannot edit someone else',
  test.changed_as(:'teacherA1', format($q$update public.profiles set phone = '0' where id = %L$q$, :'parentA1')), 0);
select test.expect_text('school admin cannot create a platform administrator',
  test.error_as(:'adminA', format($q$update public.profiles set role = 'super_admin' where id = %L$q$, :'teacherA2')), '42501');
select test.expect_count('school admin can edit a user in their school',
  test.changed_as(:'adminA', format($q$update public.profiles set full_name = 'Teacher A2 (renamed)' where id = %L$q$, :'teacherA2')), 1);
select test.expect_text('school admin cannot upgrade their own subscription plan',
  test.error_as(:'adminA', format($q$update public.schools set subscription_plan = 'enterprise' where id = %L$q$, :'schoolA')), '42501');
select test.expect_count('school admin can rename their school',
  test.changed_as(:'adminA', format($q$update public.schools set name = 'School A Renamed' where id = %L$q$, :'schoolA')), 1);

-- =====================================================================
-- Fees and payments
-- =====================================================================
select test.expect_text('accountant records a payment without supplying a receipt number',
  test.error_as(:'accountantA', format(
    $q$insert into public.payments (school_id, student_id, student_fee_id, amount, method) values (%L, %L, %L, 200, 'cash')$q$,
    :'schoolA', :'sA1', :'sfA1')), 'ok');
select test.expect_count('database issued receipt RC-<year>-000001',
  (select count(*) from public.payments where receipt_number ~ '^RC-[0-9]{4}-000001$'), 1);
select test.expect_text('accountant records a second payment',
  test.error_as(:'accountantA', format(
    $q$insert into public.payments (school_id, student_id, student_fee_id, amount, method, reference) values (%L, %L, %L, 100, 'momo', 'TXN-1')$q$,
    :'schoolA', :'sA1', :'sfA1')), 'ok');
select test.expect_count('receipt numbers increase',
  (select count(*) from public.payments where receipt_number ~ '^RC-[0-9]{4}-000002$'), 1);
select test.expect_text('a payment cannot point at another learner fee',
  test.error_as(:'accountantA', format(
    $q$insert into public.payments (school_id, student_id, student_fee_id, amount, method) values (%L, %L, %L, 50, 'cash')$q$,
    :'schoolA', :'sA2', :'sfA1')), '23503');
select test.expect_text('parent cannot record a payment',
  test.error_as(:'parentA1', format(
    $q$insert into public.payments (school_id, student_id, student_fee_id, amount, method) values (%L, %L, %L, 1, 'cash')$q$,
    :'schoolA', :'sA1', :'sfA1')), '42501');
select test.expect_text('payment amounts cannot be edited',
  test.error_as(:'accountantA', 'update public.payments set amount = 1'), '42501');
select test.expect_text('payments cannot be deleted',
  test.error_as(:'accountantA', 'delete from public.payments'), '42501');
select test.expect_text('voiding a payment requires a reason',
  test.error_as(:'accountantA', $q$update public.payments set voided_at = now() where amount = 100$q$), '23514');
select test.expect_count('accountant can void a payment with a reason',
  test.changed_as(:'accountantA', $q$update public.payments set voided_at = now(), void_reason = 'Duplicate MoMo entry' where amount = 100$q$), 1);
select test.expect_count('balance = 500 due - 200 paid (voided 100 ignored)',
  test.count_as(:'adminA', format('select * from public.student_fee_balances where student_fee_id = %L and amount_paid = 200 and balance = 300', :'sfA1')), 1);
select test.expect_count('parent sees fees of their own child only', test.count_as(:'parentA1', 'select * from public.student_fees'), 1);
select test.expect_count('parent sees payments of their own child only', test.count_as(:'parentA1', 'select * from public.payments'), 2);
select test.expect_count('other parent sees no payments', test.count_as(:'parentA2', 'select * from public.payments'), 0);
select test.expect_count('student sees their own fees', test.count_as(:'studentUserA', 'select * from public.student_fees'), 1);
select test.expect_count('teachers have no access to payments', test.count_as(:'teacherA1', 'select * from public.payments'), 0);
select test.expect_count('teachers have no access to balances', test.count_as(:'teacherA1', 'select * from public.student_fee_balances'), 0);
select test.expect_count('accountant sees all balances in the school', test.count_as(:'accountantA', 'select * from public.student_fee_balances'), 2);

-- =====================================================================
-- Notifications, announcements, audit log
-- =====================================================================
select test.expect_text('staff can notify a user in their school',
  test.error_as(:'teacherA1', format(
    $q$insert into public.notifications (school_id, recipient_id, title, body) values (%L, %L, 'Hello', 'Parent meeting on Friday')$q$,
    :'schoolA', :'parentA1')), 'ok');
select test.expect_count('recipient sees their notification', test.count_as(:'parentA1', 'select * from public.notifications'), 1);
select test.expect_count('other users do not see it', test.count_as(:'teacherA2', 'select * from public.notifications'), 0);
select test.expect_text('recipient cannot rewrite a notification',
  test.error_as(:'parentA1', $q$update public.notifications set title = 'Changed'$q$), '42501');
select test.expect_count('recipient can mark it read',
  test.changed_as(:'parentA1', 'update public.notifications set is_read = true'), 1);
select test.expect_text('parents cannot send notifications',
  test.error_as(:'parentA1', format(
    $q$insert into public.notifications (school_id, recipient_id, title, body) values (%L, %L, 'x', 'y')$q$,
    :'schoolA', :'teacherA1')), '42501');

select test.expect_text('admin posts an announcement for everyone',
  test.error_as(:'adminA', format($q$insert into public.announcements (school_id, title, body) values (%L, 'Term dates', 'See notice board')$q$, :'schoolA')), 'ok');
select test.expect_text('admin posts an announcement for parents only',
  test.error_as(:'adminA', format($q$insert into public.announcements (school_id, title, body, audience) values (%L, 'PTA meeting', 'Friday 4pm', '{parent}')$q$, :'schoolA')), 'ok');
select test.expect_count('parents see both announcements', test.count_as(:'parentA1', 'select * from public.announcements'), 2);
select test.expect_count('teachers see only the one meant for everyone', test.count_as(:'teacherA1', 'select * from public.announcements'), 1);

select test.expect_text('users cannot write audit entries in someone else name',
  test.error_as(:'teacherA1', format(
    $q$insert into public.audit_log (school_id, actor_id, action) values (%L, %L, 'fake')$q$, :'schoolA', :'adminA')), '42501');
select test.expect_text('users can write audit entries as themselves',
  test.error_as(:'teacherA1', format(
    $q$insert into public.audit_log (school_id, action) values (%L, 'results.entered')$q$, :'schoolA')), 'ok');
select test.expect_count('only admins can read the audit log', test.count_as(:'teacherA1', 'select * from public.audit_log'), 0);
select test.expect_count('admin reads the audit log', test.count_as(:'adminA', 'select * from public.audit_log'), 1);

-- =====================================================================
-- Suspension (unpaid school, or a disabled account) cuts access immediately
-- =====================================================================
update public.schools set status = 'suspended' where id = :'schoolB';
select test.expect_count('suspended school: its admin sees nothing', test.count_as(:'adminB', 'select * from public.students'), 0);
update public.schools set status = 'active' where id = :'schoolB';
select test.expect_count('reactivated school: access returns', test.count_as(:'adminB', 'select * from public.students'), 1);

update public.profiles set status = 'suspended' where id = :'teacherA2';
select test.expect_count('suspended user sees nothing', test.count_as(:'teacherA2', 'select * from public.students'), 0);
update public.profiles set status = 'active' where id = :'teacherA2';
select test.expect_count('reactivated user: access returns', test.count_as(:'teacherA2', 'select * from public.students'), 3);

\o
do $$ begin raise notice 'ALL TESTS PASSED'; end $$;
rollback;
