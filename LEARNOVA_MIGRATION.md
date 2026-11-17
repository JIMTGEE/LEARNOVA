# Learnova rebrand and database migration

Learnova (Learn + Nova) is the new product identity: a new beginning for learning.

## Database migration

The application now uses `LEARNOVA_DB_NAME` (defaults to `learnova`) and reads its connection values from environment variables:

- `LEARNOVA_DB_HOST` (defaults to `localhost`)
- `LEARNOVA_DB_NAME` (defaults to `learnova`)
- `LEARNOVA_DB_USER` (defaults to `root`)
- `LEARNOVA_DB_PASSWORD` (defaults to an empty string; set a strong secret in production)

Do not change the production database name until its data has been copied and verified. Back up the existing database first, then create the new schema and copy data:

```sh
mysqldump --single-transaction --routines --triggers -u DB_USER -p edusync > edusync-before-learnova.sql
mysql -u DB_USER -p -e 'CREATE DATABASE learnova CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysqldump --single-transaction --routines --triggers --no-create-db -u DB_USER -p edusync | mysql -u DB_USER -p learnova
```

Set `LEARNOVA_DB_NAME=learnova` and the other connection variables in the deployment environment only after checking that Learnova can read the migrated records and the application works. Keep the old database backup until the migration is accepted. For rollback, restore `LEARNOVA_DB_NAME=edusync`.

The repository also contains `edusync.sql` and historical database backups. They are intentionally retained as migration/rollback artifacts; do not bulk-replace identifiers inside SQL data or historical backups.

## Remaining deployment branding

The web manifest, shared PDO database configuration, service-worker cache identity, and service-worker IndexedDB identity are now Learnova-branded. The service worker copies existing schedule and notification data from `QueensPaDB` into `LearnovaDB` during activation. Refresh/reinstall the service worker after deploying it. Existing database snapshots and school-specific names are preserved pending an explicit school-level identity/data migration.
