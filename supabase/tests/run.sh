#!/usr/bin/env bash
# Applies every migration and seed.sql to a throwaway Postgres database, then runs
# the RLS tests. Needs a Postgres you can create databases on (psql env vars:
# PGHOST, PGUSER, PGPASSWORD...). Nothing here touches a Supabase project.
#
#   ./supabase/tests/run.sh
set -euo pipefail
cd "$(dirname "$0")/../.."

DB="${TEST_DB:-learnova_test}"
psql -v ON_ERROR_STOP=1 -q -d postgres -c "drop database if exists ${DB}" -c "create database ${DB}"

run() { psql -v ON_ERROR_STOP=1 -q -d "${DB}" -f "$1"; }

run supabase/tests/stub_supabase.sql
for f in supabase/migrations/*.sql; do
  echo "applying ${f}"
  run "${f}"
done
echo "applying supabase/seed.sql"
run supabase/seed.sql
echo "running supabase/tests/rls_test.sql"
run supabase/tests/rls_test.sql
