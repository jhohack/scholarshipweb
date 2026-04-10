# Route Map

This maps the current PHP pages to suggested Lovable routes.

## Public

| Current PHP Page | Purpose | Suggested Lovable Route |
| --- | --- | --- |
| `public/index.php` | landing page, trust stats, featured scholarships, announcements | `/` |
| `public/scholarships.php` | scholarship catalog | `/scholarships` |
| `public/scholarship-details.php` | scholarship detail | `/scholarships/:id` |
| `public/announcements.php` | public announcements list | `/announcements` |
| `public/login.php` | login | `/login` |
| `public/register.php` | registration | `/register` |
| `public/verify.php` | email verification step | `/verify-email` |
| `public/profile-setup.php` | finish onboarding with avatar and profile completion | `/onboarding/profile-setup` |
| `public/forgot-password.php` | forgot password | `/forgot-password` |
| `public/reset-password.php` | reset password | `/reset-password` |
| `public/apply.php` | scholarship application form | `/scholarships/:id/apply` |
| `public/entrance-exam.php` | exam entry point | `/scholarships/:id/exam` |
| `public/exam-results.php` | exam result page | `/scholarships/:id/exam-result` |
| `public/messages.php` | student-admin chat | `/student/messages` |

## Student Area

| Current PHP Page | Purpose | Suggested Lovable Route |
| --- | --- | --- |
| `student/dashboard.php` | student dashboard | `/student/dashboard` |
| `student/applications.php` | list of student applications | `/student/applications` |
| `student/profile.php` | student profile | `/student/profile` |
| `student/documents.php` | document view/upload area | `/student/documents` |
| `student/renewal.php` | renewal flow | `/student/renewal` |
| `student/drop.php` | drop request flow | `/student/drop-request` |
| `student/take_exam.php` | exam taking page | `/student/exams/:scholarshipId` |

## Admin Area

| Current PHP Page | Purpose | Suggested Lovable Route |
| --- | --- | --- |
| `admin/dashboard.php` | admin overview and stats | `/admin/dashboard` |
| `admin/scholarships.php` | scholarship management shell | `/admin/scholarships` |
| `admin/scholarships/list.php` | scholarship list | `/admin/scholarships` |
| `admin/scholarships/create.php` | create scholarship | `/admin/scholarships/new` |
| `admin/scholarships/edit.php` | edit scholarship | `/admin/scholarships/:id/edit` |
| `admin/scholarships/archive.php` | archive action | handled inside scholarship detail or table actions |
| `admin/applications.php` | applications list | `/admin/applications` |
| `admin/applications/view.php` | application detail and review | `/admin/applications/:id` |
| `admin/exam-results.php` | exam result review | `/admin/exams` |
| `admin/view-exam.php` | single exam inspection | `/admin/exams/:submissionId` |
| `admin/users.php` | user management | `/admin/users` |
| `admin/announcements.php` | announcement management | `/admin/announcements` |
| `admin/messages.php` | chat inbox | `/admin/messages` |
| `admin/exports.php` | exports and reports | `/admin/reports` |
| `admin/login.php` | admin login | `/admin/login` or shared `/login` with role redirect |

## Supporting Concerns

- `public/portal_api.php`
  Treat this as legacy AJAX behavior. Rebuild its functionality as typed Supabase queries, server actions, or edge functions instead of copying the PHP API shape.
- `includes/*`
  These are legacy shared PHP helpers and should be replaced with client-side components, shared utilities, typed data access helpers, and Supabase-backed mutations.

## Suggested Layout Split

- Public layout: marketing and browsing pages
- Student layout: self-service dashboard and workflows
- Admin layout: operations dashboard, tables, review, and management tools

## Suggested First Build Order

1. Public layout and routes
2. Auth and onboarding
3. Student dashboard shell
4. Admin dashboard shell
5. Scholarships management
6. Application flow
7. Exam flow
8. Messaging and announcements
