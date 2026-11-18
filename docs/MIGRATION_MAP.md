# Migration map: legacy PHP/MySQL → Learnova (Supabase/Postgres)

All 84 legacy tables are accounted for below. This file is checked against the legacy database dump (`edusync.sql`, now possibly `learnova.sql`) when it is generated, so nothing is silently dropped.

## What changed in the model

- **Multi-tenant.** The legacy system was single-school (no `school_id` anywhere). Every business table now carries `school_id`, and child tables reference their parents with `(id, school_id)` composite keys so a record can never point into another school.
- **One identity.** `users` + `teachers` + a hollow `students` table become `auth.users` → `profiles`, with `teachers` and `students` as extensions. Learners without a login (e.g. Pre-School) are supported: `students.profile_id` is optional.
- **Access is enforced by the database** (Row Level Security), not by PHP session checks. See `supabase/migrations/20261115000002_rls_policies.sql` and its tests.
- **Derived values are not stored.** Term totals and fee balances are views.
- **IDs.** New tables use UUIDs. Each migrated table keeps the old integer in `legacy_id` (unique per school) so the data migration is repeatable and checkable.

## Done: in the migrations now

| Legacy table | New home | Notes |
|---|---|---|
| `academic_years` | `academic_years` |  |
| `activity_log` | `audit_log` | Three legacy logs become one. |
| `activity_logs` | `audit_log` |  |
| `attendance` | `attendance` | present / absent / late (+ excused), in-person or live. |
| `class_subjects` | `class_subjects` | Who teaches what, where. Drives teacher permissions. |
| `classes` | `classes` |  |
| `debts` | derived: `student_fee_balances` | Not stored. Always computed from fees minus payments. |
| `departments` | `departments` | The five school levels, Pre-School to Junior High. |
| `exam_schedules` | `exams` | Date, time and room live on the exam. |
| `fees` | `fee_types` + `student_fees` | Class- and school-wide fees are expanded into one row per learner. |
| `grades` | `exams` + `results` | mid_term_exam and end_term_exam become two weighted exams (50 + 50). `total` is computed by `term_subject_totals`. |
| `mock_entries` | `exams` (kind = mock) + `results` | draft/submitted/reviewed becomes draft/submitted/published. |
| `notifications` | `notifications` | One row per recipient. Legacy shared one row for broadcasts, so read-state was shared. |
| `parent_student_mapping` | `parent_students` |  |
| `payments` | `payments` | Append-only. Mistakes are voided with a reason. Receipt numbers issued by the database. |
| `student_classes` | `enrollments` | A learner's class per academic year. |
| `students` | `students` | Legacy table held only id + name; real learner data was in `users`. Merged. |
| `subjects` | `subjects` | Subject-to-teacher link moves to `class_subjects`. |
| `teachers` | `teachers` | Linked to a profile. Name/email/phone live on the profile. |
| `terms` | `terms` |  |
| `user_logs` | `audit_log` |  |
| `users` | `auth.users` + `profiles` | One login per person. Role and school come from the admin, never from the user. |

## Planned: schema comes with its module

| Legacy table | Planned for | Notes |
|---|---|---|
| `academic_progress_entries` | Later: learning and engagement |  |
| `academic_progress_logs` | Later: learning and engagement |  |
| `advisor_appointments` | Later: learning and engagement |  |
| `annual_plans` | Phase 3: teacher planning |  |
| `application_form` | Phase 2: admissions |  |
| `assignment_questions` | Phase 2: assignments |  |
| `assignment_submissions` | Phase 2: assignments |  |
| `assignments` | Phase 2: assignments |  |
| `badges` | Later: learning and engagement |  |
| `behavior_comments` | Phase 2: report cards |  |
| `books` | Phase 3: library |  |
| `chat_history` | Phase 4: AI assistant | Via an Edge Function; the AI key stays server-side. |
| `chat_messages` | Phase 3: messaging (Realtime) |  |
| `chat_participants` | Phase 3: messaging (Realtime) |  |
| `chat_sessions` | Phase 3: messaging (Realtime) |  |
| `class_assignments` | Phase 2: timetable |  |
| `class_schedules` | Phase 2: timetable |  |
| `club_events` | Phase 3: school life |  |
| `club_memberships` | Phase 3: school life |  |
| `clubs` | Phase 3: school life |  |
| `course_enrollments` | Later: learning and engagement |  |
| `evaluation_criteria` | Phase 4: teacher performance |  |
| `evaluation_scores` | Phase 4: teacher performance |  |
| `event_rsvps` | Phase 3: school life |  |
| `extracurricular_activities` | Phase 3: school life |  |
| `forum_posts` | Phase 3: messaging (Realtime) |  |
| `forum_reactions` | Phase 3: messaging (Realtime) |  |
| `forum_threads` | Phase 3: messaging (Realtime) |  |
| `games` | Later: learning and engagement |  |
| `group_members` | Phase 3: messaging (Realtime) |  |
| `groups` | Phase 3: messaging (Realtime) |  |
| `help_tickets` | Later: support |  |
| `learning_history` | Later: learning and engagement |  |
| `learning_resources` | Later: learning and engagement |  |
| `lesson_observations` | Phase 4: teacher performance |  |
| `lesson_plans` | Phase 3: teacher planning |  |
| `library_books` | Phase 3: library |  |
| `library_fines` | Phase 3: library |  |
| `library_loans` | Phase 3: library |  |
| `live_classes` | Phase 3: live classes | Attendance already supports mode = live. |
| `private_chat_messages` | Phase 3: messaging (Realtime) |  |
| `private_chat_sessions` | Phase 3: messaging (Realtime) |  |
| `progress_aggregates` | Phase 2: report cards (as a view) |  |
| `promotion_logs` | Phase 2: year-end promotion |  |
| `quiz_submissions` | Later: learning and engagement |  |
| `quizzes` | Later: learning and engagement |  |
| `report_summaries` | Phase 2: report cards |  |
| `resource_feedback` | Later: learning and engagement |  |
| `school_events` | Phase 3: school life |  |
| `student_feedback` | Later: learning and engagement |  |
| `student_progress` | Phase 2: report cards |  |
| `student_reports` | Phase 2: report cards |  |
| `teacher_evaluations` | Phase 4: teacher performance |  |
| `teacher_notes` | Phase 4: teacher performance |  |
| `termly_plans` | Phase 3: teacher planning |  |
| `transcript_requests` | Phase 2: documents |  |
| `user_library` | Phase 3: library |  |
| `wellness_resources` | Later: learning and engagement |  |

## Not migrated

| Legacy table | Why |
|---|---|
| `class_stats_cache` | Replaced by views and queries. |
| `csrf_tokens` | Supabase Auth and the API handle this. |
| `response_cache` | Replaced by query caching in the app (TanStack Query). |
| `student_classes_backup` | A stale backup copy. Not migrated. |

## Rules carried over from the PHP code

- Term total = 50% mid-term + 50% end-term (`teacher/view_report_card.php`). Seeded as two exams of weight 50.
- Letter grades A+ (90) down to F (below 40), with remarks, from `teacher/end_term_grades.php`. Seeded into `grade_scales` for the demo school; each school can edit its own.

## Decisions to confirm with the school

1. **Teacher visibility.** Teachers currently see every learner in the school but can only mark attendance and enter results for classes they teach. Tighten to "only learners in my classes" if you prefer.
2. **Result publishing.** Parents and learners see a result only after an admin or headmaster publishes it. Legacy `reviewed` maps to `published`.
3. **Overpayment.** A payment may exceed what is owed (treated as credit). Say if that should be blocked.
4. **Learners without logins.** Confirm which levels (Pre-School, Kindergarten?) have no student login.

## How the data will move (later phase)

1. Export from MySQL, transform with a script in `scripts/` that writes `legacy_id` on every row.
2. Create auth users from legacy `users` with `app_metadata` (school, role). Bcrypt hashes from PHP should be importable so people keep their passwords; verify on one account first.
3. Move `Uploads/` into Supabase Storage buckets under `<school_id>/...`.
4. Reconcile row counts per table, then cut over.
