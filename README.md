# LEARNOVA

A multi-school management platform for schools in Ghana: attendance, results, report cards, fees and a parent portal.

> **Status of this branch.** `restructure/react-supabase` rebuilds EduSync on React + Supabase. The original PHP application is still in the repository, untouched, until the new app reaches feature parity. Nothing here changes how the live PHP site runs.

## Architecture

```
React (Vite, TypeScript)
        │
        ▼
 Supabase Auth ──► Supabase API ──► PostgreSQL  (Row Level Security on every table)
                                        ├── Storage
                                        ├── Realtime
                                        └── Edge Functions  (SMS, AI, payment webhooks)

Hosting:  GitHub ─► Render ─► custom domain
```

The browser only ever talks to Supabase with a public key. **Who can see what is decided inside the database**, not in the React code, so a bug in the UI cannot expose another school's data.

### Multi-school by design

Every business table has a `school_id`, and child tables reference their parents with `(id, school_id)` composite keys, so a record in one school can never point at a record in another. That is what lets one deployment serve many schools.

| Role | Sees | Can change |
|---|---|---|
| Admin / Headmaster | Everything in their school | Everything in their school |
| Teacher | All learners in the school | Attendance and results **only for classes they teach**; cannot publish results |
| Accountant | Learners, fees, payments | Fees and payments (payments are append-only; mistakes are voided) |
| Parent | Only their own children | Nothing |
| Student | Only themselves, and only **published** results | Nothing |
| Platform admin | Schools and subscriptions | Via the service role |

A suspended user, or any user of a suspended school (e.g. unpaid subscription), loses access immediately.

## Repository layout

```
apps/web/            React + Vite + TypeScript + Tailwind v4
  src/
    pages/ layouts/ components/ hooks/ services/ lib/
    context/ routes/ features/ utils/ types/ test/
supabase/
  migrations/        schema, access rules, fees (run in order)
  functions/         Edge Functions (secrets live here, never in the browser)
  tests/             access-control tests that run against plain Postgres
  seed.sql           sample data for development only
docs/MIGRATION_MAP.md   every legacy table → where it goes
scripts/             repo hygiene and, later, the data migration
.github/workflows/   CI
(legacy PHP: admin portal in .well-known/admin, teacher/, student/, parent/, api/ ...)
```

## Run it locally

Needs Node 22+ and a [Supabase](https://supabase.com) project (free tier is fine for development).

1. **Database.** In the Supabase SQL editor run, in order, the files in `supabase/migrations/`. For sample data also run `supabase/seed.sql` (development only). Or with the Supabase CLI: `npx supabase link --project-ref <ref>` then `npx supabase db push`.
2. **Switch off public sign-up.** Authentication → Providers → Email → turn off *Allow new users to sign up*. Accounts are created by school admins, not by visitors.
3. **Environment.** `cp apps/web/.env.example apps/web/.env.local` and fill in the project URL and anon/publishable key from Project Settings → API.
4. **First admin.** Create a user in Authentication → Users, then run the `insert into public.profiles ...` snippet at the top of `supabase/seed.sql`.
5. **Start.**
   ```bash
   cd apps/web
   npm ci
   npm run dev
   ```

## Tests

```bash
# Database: applies every migration to a scratch Postgres and runs the access-control suite
./supabase/tests/run.sh          # needs psql and a Postgres you can create databases on

# Web app
cd apps/web && npm test && npm run typecheck && npm run build
```

The database suite creates two schools and every role, then tries to cross the lines between them: reading another school's learners, marking attendance for a class you don't teach, publishing your own results, editing a payment, and so on. It also includes checks that stop being true if a rule is weakened. Both suites run in CI on every pull request.

## Deploying (Render)

Create a **Static Site** from this repository:

| Setting | Value |
|---|---|
| Root directory | `apps/web` |
| Build command | `npm ci && npm run build` |
| Publish directory | `dist` |
| Environment | `VITE_SUPABASE_URL`, `VITE_SUPABASE_ANON_KEY` |
| Redirects/Rewrites | `/*` → `/index.html` (rewrite), so page refreshes work with the router |

## Security rules for this repository

- **No secrets in git.** `.env*` is ignored. The two `VITE_` values are public by design; **never** put a service-role key, SMS key or AI key in a `VITE_` variable. Those belong in Supabase secrets, used only by Edge Functions.
- **Never commit** learner uploads, database dumps or logs. `.gitignore` covers them. If they were committed before, run `./scripts/untrack-sensitive.sh`.
- Keys that were hardcoded in the old PHP code (Gemini, Arkesel) were exposed and must stay revoked. Create fresh ones.

### Scrubbing history (decision for the owner)

Untracking files does not erase them from past commits. If the repository was ever public, treat anything it contained as already copied, and rotate every key regardless. To also remove the files from history use [`git filter-repo`](https://github.com/newren/git-filter-repo) on a fresh clone, then force-push. This rewrites every commit hash, so do it once, early, and tell collaborators to re-clone.

## Roadmap

- [x] Multi-school schema, roles and access rules (tested)
- [x] Sign-in, role-aware navigation, route guards
- [x] Learners list (first end-to-end feature)
- [x] Fees, payments, receipts and balances: schema and rules
- [ ] **Phase 1 screens:** learners (add/edit), teachers, classes, subjects, attendance, results
- [ ] `create-user` Edge Function (admins create logins)
- [ ] **Phase 2:** fees screens, parent portal, notifications, assignments, report cards (PDF)
- [ ] Data migration from the PHP/MySQL database and `Uploads/` into Supabase Storage
- [ ] **Phase 3:** payroll, library, inventory, transport, analytics
- [ ] **Phase 4:** mobile app, SMS (Arkesel), MoMo payments (Paystack / Hubtel), AI assistant

See [`docs/MIGRATION_MAP.md`](docs/MIGRATION_MAP.md) for how every legacy table maps to the new model.
