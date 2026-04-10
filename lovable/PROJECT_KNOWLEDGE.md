# DVC Scholarship Hub Project Knowledge

## Existing System Summary

The current project is a PHP/MySQL scholarship portal with three major areas:

- public scholarship discovery and onboarding
- student self-service portal
- admin and staff back office

The rebuild target is a cleaner full-stack Lovable app backed by Supabase.

## Roles

- `student`
  End user applying for scholarships.
- `admin`
  Full access to all back-office modules.
- `staff`
  Restricted back-office access based on saved permissions.

## Current Core Modules

- public landing page
- scholarship catalog and detail pages
- registration, login, email verification, profile setup
- student dashboard
- scholarship application flow
- renewal flow
- drop request flow
- dynamic scholarship-specific form fields
- document upload handling
- entrance exams and exam results
- admin dashboard and review flows
- user management
- announcements with attachments
- student-admin chat
- notifications

## Source Of Truth Rule

`profiles` or user account data is the source of truth.

The legacy app mirrors student-facing identity data into a separate `students` record when onboarding finishes and whenever key data changes. Preserve that behavior in the new app because applications and admin review are built around the student record.

Fields that should stay synced:

- full name
- school ID number
- email
- phone or contact number
- birthdate
- student type

## Scholarship Rules

- Only one active scholarship per student at a time.
- A student should not be able to submit multiple pending applications for the same scholarship.
- Renewal should target the student’s current scholarship, not any scholarship.
- Some scholarships require an entrance exam before full review.
- Some scholarships may be available only to new applicants, only to renewal applicants, or to both.
- Scholarships have a maximum number of active slots.

## Application Status Rules

Preserve these status values because they drive dashboards and user decisions:

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

Suggested behavior:

- `Pending`, `Under Review`, `Pending Exam`, and `Renewal Request` count as in-progress states.
- `Approved`, `Active`, and `For Renewal` count as active-like states.
- `Dropped` and `Rejected` are terminal states.

## Dynamic Form Model

Each scholarship can have an optional custom form:

- one `form` record per scholarship
- many `form_fields`
- application answers stored separately in `application_responses`

Required field types already used in the PHP app:

- `text`
- `textarea`
- `select`
- `file`

## Exam Model

Exam-capable scholarships use:

- `exam_questions`
- `exam_submissions`
- `exam_answers`

Question type currently seen:

- `multiple_choice`

The app should support:

- starting an exam
- saving submission timing
- calculating score
- comparing score with scholarship passing requirements
- surfacing the result to the student and admin

## Messaging Model

Students and admins communicate in threaded conversations.

Needed behavior:

- one student can have multiple conversations
- messages belong to a conversation
- sender is a user profile
- attachments are optional
- unread state matters

## Announcement Model

Announcements are public or admin-managed content items.

Needed behavior:

- title
- content
- active or inactive state
- optional image
- optional multiple attachments

## Permissions Model For Staff

Staff is not the same as admin.

Staff permissions are currently stored as a list of allowed page identifiers in the legacy app. In the rebuild, keep this idea but make it cleaner:

- store permissions as JSON
- build a reusable permission guard
- hide routes and actions the user cannot access
- allow view-only staff patterns where appropriate

## Rebuild Priorities

High priority:

- auth and onboarding
- public scholarship browsing
- applications
- admin review
- dashboard visibility

Medium priority:

- exams
- chat
- announcements with attachments

Lower priority:

- advanced exports
- fully polished analytics

## UX Notes

This app is for college scholarship operations, so it should feel:

- trustworthy
- structured
- supportive
- easy to understand for students
- efficient for admins handling many records

Avoid a plain CRUD look. Use clear cards, timelines, status badges, and guided upload states.
