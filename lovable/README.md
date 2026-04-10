# Lovable Handoff Kit

This folder turns the current PHP/MySQL scholarship portal into a Lovable-ready project brief.

As of April 10, 2026, Lovable does not support starting a brand-new project by importing an existing external codebase directly, so the best path is to rebuild this app in Lovable using the files in this folder.

## What Is Included

- `START_PROMPT.md`
  A paste-ready first prompt for a new Lovable project.
- `PROJECT_KNOWLEDGE.md`
  Domain rules and product behavior to add as a knowledge file or long-form instruction.
- `AGENTS.md`
  Optional repo-level instruction file to copy into the root of the new Lovable-generated repository after GitHub sync is enabled.
- `ROUTE_MAP.md`
  Maps the current PHP pages to suggested Lovable routes and app areas.
- `supabase_schema.sql`
  A Postgres/Supabase schema draft based on the actual project tables and runtime migrations found in this repo.

## Recommended Workflow

1. Create a new project in Lovable.
2. Connect the project to a new Supabase instance.
3. Run `lovable/supabase_schema.sql` in the Supabase SQL editor.
4. Add `lovable/PROJECT_KNOWLEDGE.md` to Lovable as project knowledge.
5. Paste `lovable/START_PROMPT.md` as the first build prompt.
6. Use `lovable/ROUTE_MAP.md` while checking that every important page and flow from the PHP app has been rebuilt.

## Important Notes

- This is a rebuild plan, not a literal import of the PHP source.
- The schema includes the main entities used in the current system: users/profiles, students, scholarships, dynamic forms, applications, documents, exams, announcements, notifications, and chat.
- Some current PHP behavior relies on runtime schema migrations inside pages. I folded the important ones into the new schema, including:
  - `users.permissions`
  - `users.profile_picture_path`
  - `scholarships.amount_type`
  - `announcements.image_path`
  - chat tables
- Supabase Auth should replace the current custom PHP login flow.
- Supabase Storage should replace the current file uploads under `public/uploads/`.

## Suggested Storage Buckets

- `avatars`
- `scholarship-documents`
- `announcement-attachments`
- `chat-attachments`

## Manual Migration Still Needed

- Importing real production data from MySQL into Supabase
- Re-uploading existing files if you want historical documents and avatars
- Rebuilding email flows with Supabase or an external mail provider
- Recreating CSV/export features and any admin-only reporting details not yet covered by the initial Lovable build
