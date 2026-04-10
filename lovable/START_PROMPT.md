Build a full-stack web app called `DVC Scholarship Hub` for Davao Vision College.

Use a modern Lovable stack with:

- React
- TypeScript
- Supabase Auth
- Supabase Postgres
- Supabase Storage
- clean mobile-first UI

Do not use mock data once the database is connected.

## Product Goal

Rebuild an existing PHP scholarship portal as a modern Lovable app. The system has three user roles:

- `student`
- `admin`
- `staff`

Students browse scholarships, register, complete their profile, apply for scholarships, upload files, take exams when required, track statuses, receive notifications, and message admins.

Admins manage scholarships, applications, announcements, users, chats, and dashboard reporting.

Staff accounts are limited-access back-office users. They can view only the modules allowed by their saved permissions and should be treated as more restricted than admins.

## Primary Pages

Public:

- landing page
- scholarships listing
- scholarship details page
- announcements page
- login
- register
- forgot password
- reset password
- email verification or onboarding confirmation

Student app:

- dashboard
- profile setup and profile edit
- applications list
- apply to scholarship page
- document uploads
- entrance exam
- exam result/status
- chat/messages with admin
- notifications

Admin app:

- dashboard with stats and recent activity
- scholarship list/create/edit/archive
- applications list
- application detail/review page
- exam review/results page
- users management
- announcements management
- messages inbox
- exports/reports placeholder page if full export is not ready in v1

## Non-Negotiable Business Rules

1. A student can only hold one active scholarship at a time.
2. A student must not create duplicate active or pending applications for the same scholarship.
3. Renewal is allowed only for the student’s current scholarship when the latest application state makes renewal valid.
4. Student profile data is the source of truth and should sync into the student record used by applications.
5. Scholarship applications can require:
   - uploaded documents
   - dynamic custom form fields
   - an entrance exam
6. Some scholarships accept new applicants, some renewal applicants, and some both.
7. Scholarships have slot limits.
8. Application review statuses must be visible to students and admins.
9. Messaging must support a conversation thread between student and admin, including optional attachment support.
10. Announcements should support file attachments.

## Application Statuses To Support

Support these statuses exactly as values in the app:

- `Pending`
- `Under Review`
- `Pending Exam`
- `Approved`
- `Active`
- `For Renewal`
- `Renewal Request`
- `Drop Requested`
- `Dropped`
- `Rejected`
- `Passed`
- `Failed`

Use user-friendly labels where needed, but keep the database values consistent.

## Data Model Requirements

Use the connected Supabase database and match the provided schema closely. The app must support these entities:

- profiles
- students
- scholarships
- forms
- form_fields
- applications
- application_responses
- documents
- exam_questions
- exam_submissions
- exam_answers
- announcements
- announcement_attachments
- notifications
- conversations
- messages

## UX Direction

Create a polished academic portal that feels trustworthy, warm, and official without looking dull.

- professional but welcoming
- clear hierarchy
- strong dashboard cards
- easy-to-scan status badges
- mobile-friendly forms
- clean admin tables with filters
- polished empty states
- upload flows that feel guided, not technical

Avoid a generic AI template look.

## Auth And Permissions

Use Supabase Auth for sign-up and sign-in.

Requirements:

- new users default to `student`
- admin and staff are managed inside the app
- route guards must respect role and permission rules
- staff permissions are stored as a JSON array and checked before showing admin modules

## Build Priorities

Phase 1:

- public pages
- auth
- student onboarding
- scholarships catalog
- application flow
- student dashboard
- admin dashboard
- admin scholarship management
- admin application review

Phase 2:

- exams
- chat
- announcements attachments
- exports and reporting

If a feature is not fully finished, still scaffold the route and UI state cleanly instead of leaving dead ends.

## Important Implementation Details

- Use Supabase Storage for avatars, scholarship documents, chat attachments, and announcement attachments.
- Keep public scholarship browsing accessible without login.
- Only show active scholarships on public pages unless the current user is admin or staff.
- Show scholarship filters and search on the listing page.
- Pre-fill application forms with known student data where possible.
- Build reusable status badge, file uploader, and permission guard components.
- Keep admin and student layouts visually distinct.
- Add loading, empty, success, and error states for major screens.

## Migration Context

This project is being rebuilt from an existing PHP portal. Recreate the behavior, but improve code organization, UX, and maintainability. Use the supplied project knowledge and route map as the source of truth for what the existing system does.

Start by generating:

1. app routing structure
2. auth and role guard setup
3. Supabase queries and typed data helpers
4. public scholarship browsing pages
5. student dashboard shell
6. admin dashboard shell

After that, continue to the application and scholarship-management flows.
