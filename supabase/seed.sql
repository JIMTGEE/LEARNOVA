-- =====================================================================
-- Sample data for local development (`supabase db reset`). Not for production.
--
-- To create the first admin for the demo school:
--   1. Create the user in Supabase Studio (Authentication > Users).
--   2. Run in the SQL editor, with your own email and name:
--
--      insert into public.profiles (id, school_id, role, full_name, email)
--      select id, '00000000-0000-4000-8000-000000000001', 'admin', 'Your Name', email
--      from auth.users where email = 'you@example.com';
--
-- Later, admins create users through a server-side function that sets
-- school_id and role in app_metadata, and the profile is created automatically.
-- =====================================================================

insert into public.schools (id, name, code, status, subscription_plan)
values ('00000000-0000-4000-8000-000000000001', 'Demo Academy', 'DEMO', 'trial', 'basic');

insert into public.departments (school_id, name, sort_order)
values
  ('00000000-0000-4000-8000-000000000001', 'Pre-School',    1),
  ('00000000-0000-4000-8000-000000000001', 'Kindergarten',  2),
  ('00000000-0000-4000-8000-000000000001', 'Lower Primary', 3),
  ('00000000-0000-4000-8000-000000000001', 'Upper Primary', 4),
  ('00000000-0000-4000-8000-000000000001', 'Junior High',   5);

-- Sample calendar. Replace with your real term dates.
insert into public.academic_years (id, school_id, name, starts_on, ends_on, is_current)
values ('00000000-0000-4000-8000-0000000000a1', '00000000-0000-4000-8000-000000000001',
        '2026/2027', '2026-09-07', '2027-07-23', true);

insert into public.terms (school_id, academic_year_id, name, starts_on, ends_on, is_current)
values
  ('00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-0000000000a1',
   'Term 1', '2026-09-07', '2026-12-18', true),
  ('00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-0000000000a1',
   'Term 2', '2027-01-11', '2027-04-02', false),
  ('00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-0000000000a1',
   'Term 3', '2027-04-26', '2027-07-23', false);

-- Letter grades copied from the legacy PHP (teacher/end_term_grades.php).
insert into public.grade_scales (school_id, min_score, grade, remark)
select '00000000-0000-4000-8000-000000000001', v.min_score, v.grade, v.remark
from (values
  (90, 'A+', 'Excellent'),
  (85, 'A',  'Great'),
  (80, 'A-', 'Very Good'),
  (75, 'B+', 'Good'),
  (70, 'B',  'Fair'),
  (65, 'B-', 'Satisfactory'),
  (60, 'C+', 'Average'),
  (55, 'C',  'Pass'),
  (50, 'C-', 'Borderline'),
  (45, 'D+', 'Weak'),
  (40, 'D',  'Poor'),
  (0,  'F',  'Fail')
) as v (min_score, grade, remark);
