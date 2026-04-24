# Neon Migration

This project now includes a PostgreSQL schema and a converted data import for Neon.

## Files

- `sql/postgres_schema.sql`
  - Creates the PostgreSQL tables used by the current app.
- `sql/postgres_data_from_mysql.sql`
  - Generated from `scholarship_db (2).sql`
  - Imports the existing MySQL data into PostgreSQL/Neon.
- `tools/convert_mysql_dump_to_postgres_sql.php`
  - Regenerates the PostgreSQL data file from a MySQL dump.
- `tools/migrate_uploads_to_database.php`
  - Moves legacy files from `public/uploads` into the DB-backed `uploaded_files` table.

## 1. Create Neon

Create a Neon Postgres database and copy its connection string.

## 2. Import the Schema

Run the contents of:

- `sql/postgres_schema.sql`

You can paste it into the Neon SQL editor or run it with any Postgres client.

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

## 4. Point the App to Neon

Set these environment variables in Vercel:

```env
DB_DRIVER=pgsql
DATABASE_URL=postgresql://...
UPLOAD_DRIVER=database
```

You can also use the split variables instead of `DATABASE_URL`:

```env
DB_DRIVER=pgsql
DB_HOST=...
DB_PORT=5432
DB_NAME=...
DB_USER=...
DB_PASS=...
DB_SSL_MODE=require
UPLOAD_DRIVER=database
```

## 5. Migrate Existing Uploaded Files

After the records are imported into Neon, move legacy files from local disk into the `uploaded_files` table.

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
- Make sure the DB env vars point to Neon before running it.

## 6. Deploy

Redeploy on Vercel after the Neon env vars are saved.

## 7. Restore the Old Snapshot

If the old Neon project is paused or you cannot reach it because of the transfer limit, you can restore the saved snapshot in this repo into a PostgreSQL database.

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

If Neon is still blocked, you can restore the same snapshot into the local XAMPP MariaDB server and run the app from that copy.

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
