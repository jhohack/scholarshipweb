# Vercel Deployment Notes

This project has been adapted to run on Vercel with the community PHP runtime configured in [`vercel.json`](/workspace/vercel.json).

## Important notes

- PHP on Vercel uses the community runtime `vercel-php`, not an official first-party PHP runtime.
- Vercel functions do not support persistent local writes.
- New uploads are stored in the database when `UPLOAD_DRIVER=database`.
- Existing legacy files under `public/uploads/` should be migrated before deployment if you want them to keep working.
- Uploads are capped at `4MB` by default on Vercel-friendly config.

## Required environment variables

Set these in the Vercel project settings:

```env
APP_ENV=production
APP_DEBUG=0
BASE_URL=https://your-project.vercel.app
APP_BASE_PATH=

DB_HOST=your-mysql-host
DB_PORT=3306
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=your-email@example.com
SMTP_PASS=your-app-password

GOOGLE_CLIENT_ID=your-google-client-id

UPLOAD_DRIVER=database
UPLOAD_MAX_BYTES=4194304
```

## Before first deploy

1. Make sure your MySQL database is reachable from Vercel.
2. Import your current schema/data into that external MySQL database.
3. If your database still points to old `uploads/...` file paths, run:

```bash
php tools/migrate_uploads_to_database.php
```

To preview what will change first:

```bash
php tools/migrate_uploads_to_database.php --dry-run
```

## After deploy

- Test profile picture upload
- Test scholarship application document upload
- Test renewal upload
- Test admin announcement attachments
- Test messaging attachments
