# DVC Scholarship Hub Instructions

## Product

This project is a Lovable rebuild of a PHP scholarship portal for Davao Vision College.

Main roles:

- student
- admin
- staff

## Stack Rules

- Use TypeScript strict mode.
- Prefer typed Supabase helpers over inline queries spread across components.
- Keep auth, data access, and UI concerns separated.
- Do not fall back to mock data after the database is connected.
- Use reusable components for status badges, permission guards, file uploads, and dashboard cards.

## Domain Rules

- A student can only hold one active scholarship at a time.
- Students cannot submit duplicate pending applications for the same scholarship.
- Student profile data is the source of truth and must stay in sync with the student record.
- Scholarship statuses must preserve the current workflow vocabulary:
  - Pending
  - Under Review
  - Pending Exam
  - Approved
  - Active
  - For Renewal
  - Renewal Request
  - Drop Requested
  - Dropped
  - Rejected
  - Passed
  - Failed
- Scholarships may require custom form fields, uploads, and exams.
- Staff users are permission-scoped and are not equivalent to admins.

## UX Rules

- Keep the student experience guided and reassuring.
- Keep the admin experience dense, fast, and easy to scan.
- Avoid generic placeholder copy.
- Prefer clear empty states, progress states, and validation messages.

## File And Route Guidance

- Public routes cover landing, scholarships, auth, and announcements.
- Student routes cover dashboard, profile, applications, documents, exams, notifications, and messages.
- Admin routes cover dashboards, scholarships, applications, users, announcements, messages, and reports.

## Migration Intent

Recreate the behavior of the legacy PHP system, but improve maintainability, consistency, and UX. Do not blindly mirror the old file structure or PHP patterns.
