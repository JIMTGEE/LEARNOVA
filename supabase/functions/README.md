# Edge Functions

Server-side code that must never run in the browser. Secrets live here as Supabase
secrets (`supabase secrets set ...`), never in the React app and never in git.

| Function | Purpose | Secrets | Status |
|---|---|---|---|
| `create-user` | An admin creates a login. Uses the service role to create the auth user and sets `school_id` and `role` in **app_metadata**, which the `on_auth_user_created` trigger turns into a profile. Must check the caller is an admin of that school. | none beyond the built-ins | next |
| `send-sms` | Fee reminders, payment receipts, notices, via Arkesel | `ARKESEL_API_KEY`, `ARKESEL_SENDER_ID` | planned |
| `ai-assistant` | Gemini-powered assistant (the legacy EduBot) | `GEMINI_API_KEY` | planned |
| `payments-webhook` | Paystack / Hubtel / MoMo callbacks recording payments | provider secrets | planned |

Why `create-user` matters: public sign-up is switched off, and a profile is only created
when `app_metadata` carries a school and role. That is what stops a stranger registering
and landing inside a school.

Local development: copy `.env.example` to `.env` in this folder (it is git-ignored).
