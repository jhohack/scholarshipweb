# Supabase Migration

This project now includes a PostgreSQL schema and a data import path that works with Supabase Postgres.

## Files

- `sql/postgres_schema.sql`
  - Creates the PostgreSQL tables used by the current app.
- `sql/postgres_data_from_mysql.sql`
  - Generated from `scholarship_db (2).sql`
  - Imports the existing MySQL data into PostgreSQL.
- `tools/convert_mysql_dump_to_postgres_sql.php`
  - Regenerates the PostgreSQL data file from a MySQL dump.
- `tools/migrate_uploads_to_database.php`
  - Moves legacy files from `public/uploads` into the DB-backed `uploaded_files` table.

## 1. Create Supabase

Create a Supabase project and note the database connection details from the dashboard.

## 2. Import the Schema

Run the contents of:

- `sql/postgres_schema.sql`

You can paste it into the Supabase SQL editor or run it with any Postgres client.

## 3. Import the Existing MySQL Data

Run the contents of:

- `sql/postgres_data_from_mysql.sql`

If your MySQL dump changes later, regenerate it with:

```powershell
C:\xampp\php\php.exe tools\convert_mysql_dump_to_postgres_sql.php
```

Or specify custom paths:

```powershell
C:\xampp\php\php.exe tools\convert_mysql_dump_to_postgres_sql.php "path\to\dump.sql" "sql\postgres_data_from_mysql.sql"
```

## 4. Point the App to Supabase

Set these environment variables in Vercel or your local `.env.local`:

```env
DB_DRIVER=pgsql
SUPABASE_PROJECT_ID=dahqlxsjsduyvksbwxqq
SUPABASE_URL=https://dahqlxsjsduyvksbwxqq.supabase.co
DB_HOST=aws-1-ap-southeast-1.pooler.supabase.com
DB_PORT=6543
DB_NAME=postgres
DB_USER=postgres.dahqlxsjsduyvksbwxqq
DB_PASS=your-supabase-db-password
DB_SSL_MODE=require
UPLOAD_DRIVER=database
```

You can also use the split variables instead of `DATABASE_URL` if you prefer:

```env
DB_DRIVER=pgsql
DB_HOST=aws-1-ap-southeast-1.pooler.supabase.com
DB_PORT=6543
DB_NAME=...
DB_USER=postgres.dahqlxsjsduyvksbwxqq
DB_PASS=...
DB_SSL_MODE=require
UPLOAD_DRIVER=database
```

## 5. Migrate Existing Uploaded Files

After the records are imported into Supabase, move legacy files from local disk into the `uploaded_files` table.

Dry run first:

```powershell
C:\xampp\php\php.exe tools\migrate_uploads_to_database.php --dry-run
```

Then run the real migration:

```powershell
C:\xampp\php\php.exe tools\migrate_uploads_to_database.php
```

Important:

- Run this from a machine that still has the old `public/uploads` files.
- Make sure the DB env vars point to Supabase before running it.

## 6. Deploy

Redeploy on Vercel after the Supabase env vars are saved.

## 7. Restore the Old Snapshot

If the source database is unavailable, you can restore the saved snapshot in this repo into any PostgreSQL database, including Supabase.

The backup lives at:

- `backups/neon-before-replace-20260411-003956.json`

Run the restore tool against an empty PostgreSQL database:

```powershell
C:\xampp\php\php.exe tools\restore_neon_backup_json.php --database-url "postgresql://..."
```

If you want to replace the data already in the target database, add `--truncate`:

```powershell
C:\xampp\php\php.exe tools\restore_neon_backup_json.php --database-url "postgresql://..." --truncate
```

Important:

- `--truncate` removes the existing rows in the target database before restoring the backup.
- The script recreates the current PostgreSQL schema first, then loads the saved snapshot.
- It also restores `uploaded_files.content_blob`, so database-stored uploads come back with the rest of the data.

## 8. Local MySQL Recovery

If you need a local fallback, you can restore the same snapshot into the local XAMPP MariaDB server and run the app from that copy.

The local restore script is:

- `tools/restore_neon_backup_json_mysql.php`

By default it creates a fresh `scholarship_old_data` database on `127.0.0.1:3306`:

```powershell
C:\xampp\php\php.exe tools\restore_neon_backup_json_mysql.php --fresh
```

Then point the app at that local database with:

```env
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=scholarship_old_data
DB_USER=root
DB_PASS=
UPLOAD_DRIVER=database
```

## Notes

- The generated PostgreSQL import already backfills:
  - `applications.program`
  - `applications.year_level`
  - `applications.student_status`
  - `applications.application_type` for renewal rows
  - `applications.scholarship_name`
  - `notifications.student_id`
- Dynamic scholarship submission tables like `scholarship_submissions_10` are included in the generated import.
