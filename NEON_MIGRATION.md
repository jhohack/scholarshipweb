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

## Notes

- The generated PostgreSQL import already backfills:
  - `applications.program`
  - `applications.year_level`
  - `applications.student_status`
  - `applications.application_type` for renewal rows
  - `applications.scholarship_name`
  - `notifications.student_id`
- Dynamic scholarship submission tables like `scholarship_submissions_10` are included in the generated import.
