create extension if not exists pgcrypto;

create or replace function public.set_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

create table if not exists public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  email text not null unique,
  first_name text,
  middle_name text,
  last_name text,
  contact_number text,
  birthdate date,
  school_id text unique,
  role text not null default 'student' check (role in ('student', 'admin', 'staff')),
  status text not null default 'active' check (status in ('active', 'inactive', 'archived')),
  email_verified boolean not null default false,
  email_verified_at timestamptz,
  onboarding_complete boolean not null default false,
  student_type text default 'New Applicant',
  permissions jsonb,
  profile_picture_path text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.students (
  id bigint generated always as identity primary key,
  user_id uuid not null unique references public.profiles(id) on delete cascade,
  student_name text not null,
  school_id_number text,
  email text,
  phone text,
  date_of_birth date,
  address text,
  student_type text not null default 'new' check (student_type in ('new', 'renewal')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.scholarships (
  id bigint generated always as identity primary key,
  name text not null,
  description text not null,
  deadline date not null,
  requirements text,
  application_requirements text,
  benefits text,
  available_slots integer not null default 10,
  category text default 'general',
  accepting_new_applicants boolean not null default true,
  accepting_renewal_applicants boolean not null default true,
  amount numeric(10,2) default 500.00,
  amount_type text not null default 'Peso' check (amount_type in ('Peso', 'Percentage', 'None')),
  status text not null default 'active' check (status in ('active', 'inactive', 'archived')),
  requires_exam boolean not null default false,
  passing_grade integer default 75,
  passing_score integer default 75,
  exam_duration integer default 60,
  end_of_term date,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.forms (
  id bigint generated always as identity primary key,
  scholarship_id bigint not null references public.scholarships(id) on delete cascade,
  title text not null,
  description text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.form_fields (
  id bigint generated always as identity primary key,
  scholarship_id bigint not null references public.scholarships(id) on delete cascade,
  form_id bigint references public.forms(id) on delete set null,
  field_label text not null,
  field_type text not null check (field_type in ('text', 'textarea', 'select', 'file')),
  field_options text,
  options text,
  field_name text,
  is_required boolean not null default true,
  field_order integer not null default 0,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.applications (
  id bigint generated always as identity primary key,
  student_id bigint not null references public.students(id) on delete cascade,
  scholarship_id bigint not null references public.scholarships(id) on delete cascade,
  scholarship_name text,
  application_requirements text,
  status text not null default 'Pending' check (
    status in (
      'Pending',
      'Under Review',
      'Pending Exam',
      'Approved',
      'Active',
      'For Renewal',
      'Renewal Request',
      'Drop Requested',
      'Dropped',
      'Rejected',
      'Passed',
      'Failed'
    )
  ),
  application_type text not null default 'new' check (application_type in ('new', 'renewal')),
  applicant_type text not null default 'New' check (applicant_type in ('New', 'Renewal')),
  year_program text,
  units_enrolled integer,
  gwa numeric(5,2),
  scholarship_percentage numeric(5,2),
  remarks text,
  submitted_at timestamptz not null default now(),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.application_responses (
  id bigint generated always as identity primary key,
  application_id bigint not null references public.applications(id) on delete cascade,
  form_field_id bigint not null references public.form_fields(id) on delete cascade,
  response_value text not null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.documents (
  id bigint generated always as identity primary key,
  user_id uuid not null references public.profiles(id) on delete cascade,
  application_id bigint not null references public.applications(id) on delete cascade,
  file_name text not null,
  file_path text not null,
  uploaded_at timestamptz not null default now()
);

create table if not exists public.exam_questions (
  id bigint generated always as identity primary key,
  scholarship_id bigint not null references public.scholarships(id) on delete cascade,
  question_text text not null,
  question_type text not null default 'multiple_choice',
  options text,
  correct_answer text,
  created_at timestamptz not null default now()
);

create table if not exists public.exam_submissions (
  id bigint generated always as identity primary key,
  student_id bigint not null references public.students(id) on delete cascade,
  scholarship_id bigint not null references public.scholarships(id) on delete cascade,
  score integer not null default 0,
  total_items integer not null default 0,
  status text not null default 'in_progress' check (status in ('in_progress', 'submitted', 'graded')),
  start_time timestamptz not null default now(),
  end_time timestamptz
);

create table if not exists public.exam_answers (
  id bigint generated always as identity primary key,
  submission_id bigint not null references public.exam_submissions(id) on delete cascade,
  question_id bigint not null references public.exam_questions(id) on delete cascade,
  student_answer text,
  is_correct boolean,
  created_at timestamptz not null default now()
);

create table if not exists public.application_exams (
  id bigint generated always as identity primary key,
  application_id bigint not null references public.applications(id) on delete cascade,
  score_part_a integer not null default 0,
  answers_part_b text,
  score_part_b integer not null default 0,
  grades_part_b text,
  total_score integer not null default 0,
  is_graded boolean not null default false,
  admin_remarks text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.announcements (
  id bigint generated always as identity primary key,
  title text not null,
  content text not null,
  image_path text,
  is_active boolean not null default false,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.announcement_attachments (
  id bigint generated always as identity primary key,
  announcement_id bigint not null references public.announcements(id) on delete cascade,
  file_path text not null,
  file_name text,
  created_at timestamptz not null default now()
);

create table if not exists public.notifications (
  id bigint generated always as identity primary key,
  user_id uuid not null references public.profiles(id) on delete cascade,
  title text not null,
  message text not null,
  is_read boolean not null default false,
  created_at timestamptz not null default now()
);

create table if not exists public.conversations (
  id bigint generated always as identity primary key,
  student_user_id uuid not null references public.profiles(id) on delete cascade,
  subject text not null,
  status text not null default 'open' check (status in ('open', 'closed', 'pending_admin', 'pending_student')),
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.messages (
  id bigint generated always as identity primary key,
  conversation_id bigint not null references public.conversations(id) on delete cascade,
  sender_id uuid not null references public.profiles(id) on delete cascade,
  message_text text,
  attachment_path text,
  is_read boolean not null default false,
  created_at timestamptz not null default now()
);

create index if not exists idx_students_user_id on public.students(user_id);
create index if not exists idx_scholarships_status_deadline on public.scholarships(status, deadline);
create index if not exists idx_forms_scholarship_id on public.forms(scholarship_id);
create index if not exists idx_form_fields_form_id on public.form_fields(form_id);
create index if not exists idx_applications_student_id on public.applications(student_id);
create index if not exists idx_applications_scholarship_id on public.applications(scholarship_id);
create index if not exists idx_applications_status on public.applications(status);
create index if not exists idx_exam_questions_scholarship_id on public.exam_questions(scholarship_id);
create index if not exists idx_exam_submissions_student_id on public.exam_submissions(student_id);
create index if not exists idx_notifications_user_id on public.notifications(user_id);
create index if not exists idx_conversations_student_user_id on public.conversations(student_user_id);
create index if not exists idx_messages_conversation_id on public.messages(conversation_id);

drop trigger if exists profiles_set_updated_at on public.profiles;
create trigger profiles_set_updated_at
before update on public.profiles
for each row
execute function public.set_updated_at();

drop trigger if exists students_set_updated_at on public.students;
create trigger students_set_updated_at
before update on public.students
for each row
execute function public.set_updated_at();

drop trigger if exists scholarships_set_updated_at on public.scholarships;
create trigger scholarships_set_updated_at
before update on public.scholarships
for each row
execute function public.set_updated_at();

drop trigger if exists forms_set_updated_at on public.forms;
create trigger forms_set_updated_at
before update on public.forms
for each row
execute function public.set_updated_at();

drop trigger if exists form_fields_set_updated_at on public.form_fields;
create trigger form_fields_set_updated_at
before update on public.form_fields
for each row
execute function public.set_updated_at();

drop trigger if exists applications_set_updated_at on public.applications;
create trigger applications_set_updated_at
before update on public.applications
for each row
execute function public.set_updated_at();

drop trigger if exists application_responses_set_updated_at on public.application_responses;
create trigger application_responses_set_updated_at
before update on public.application_responses
for each row
execute function public.set_updated_at();

drop trigger if exists application_exams_set_updated_at on public.application_exams;
create trigger application_exams_set_updated_at
before update on public.application_exams
for each row
execute function public.set_updated_at();

drop trigger if exists announcements_set_updated_at on public.announcements;
create trigger announcements_set_updated_at
before update on public.announcements
for each row
execute function public.set_updated_at();

drop trigger if exists conversations_set_updated_at on public.conversations;
create trigger conversations_set_updated_at
before update on public.conversations
for each row
execute function public.set_updated_at();

create or replace function public.handle_new_auth_user()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
  insert into public.profiles (
    id,
    email,
    email_verified,
    email_verified_at
  )
  values (
    new.id,
    new.email,
    coalesce(new.email_confirmed_at is not null, false),
    new.email_confirmed_at
  )
  on conflict (id) do nothing;
  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
after insert on auth.users
for each row
execute function public.handle_new_auth_user();

create or replace function public.sync_student_from_profile()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  derived_name text;
  derived_student_type text;
begin
  if new.role <> 'student' or new.onboarding_complete = false then
    return new;
  end if;

  derived_name := trim(concat_ws(' ', new.first_name, nullif(new.middle_name, ''), new.last_name));
  derived_student_type := case
    when lower(coalesce(new.student_type, 'new applicant')) like '%renew%' then 'renewal'
    else 'new'
  end;

  insert into public.students (
    user_id,
    student_name,
    school_id_number,
    email,
    phone,
    date_of_birth,
    student_type
  )
  values (
    new.id,
    coalesce(nullif(derived_name, ''), new.email),
    new.school_id,
    new.email,
    new.contact_number,
    new.birthdate,
    derived_student_type
  )
  on conflict (user_id) do update
  set
    student_name = excluded.student_name,
    school_id_number = excluded.school_id_number,
    email = excluded.email,
    phone = excluded.phone,
    date_of_birth = excluded.date_of_birth,
    student_type = excluded.student_type,
    updated_at = now();

  return new;
end;
$$;

drop trigger if exists sync_student_after_profile_change on public.profiles;
create trigger sync_student_after_profile_change
after insert or update of first_name, middle_name, last_name, email, contact_number, birthdate, school_id, role, onboarding_complete, student_type
on public.profiles
for each row
execute function public.sync_student_from_profile();

create or replace function public.current_user_role()
returns text
language sql
stable
as $$
  select role from public.profiles where id = auth.uid()
$$;

create or replace function public.is_admin_or_staff()
returns boolean
language sql
stable
as $$
  select coalesce(public.current_user_role() in ('admin', 'staff'), false)
$$;

alter table public.profiles enable row level security;
alter table public.students enable row level security;
alter table public.scholarships enable row level security;
alter table public.forms enable row level security;
alter table public.form_fields enable row level security;
alter table public.applications enable row level security;
alter table public.application_responses enable row level security;
alter table public.documents enable row level security;
alter table public.exam_questions enable row level security;
alter table public.exam_submissions enable row level security;
alter table public.exam_answers enable row level security;
alter table public.application_exams enable row level security;
alter table public.announcements enable row level security;
alter table public.announcement_attachments enable row level security;
alter table public.notifications enable row level security;
alter table public.conversations enable row level security;
alter table public.messages enable row level security;

drop policy if exists scholarships_select_public_or_admin on public.scholarships;
create policy scholarships_select_public_or_admin
on public.scholarships
for select
using (status = 'active' or public.is_admin_or_staff());

drop policy if exists scholarships_admin_write on public.scholarships;
create policy scholarships_admin_write
on public.scholarships
for all
using (public.is_admin_or_staff())
with check (public.is_admin_or_staff());

drop policy if exists forms_select_authenticated_or_admin on public.forms;
create policy forms_select_authenticated_or_admin
on public.forms
for select
using (
  public.is_admin_or_staff()
  or auth.uid() is not null
);

drop policy if exists forms_admin_write on public.forms;
create policy forms_admin_write
on public.forms
for all
using (public.is_admin_or_staff())
with check (public.is_admin_or_staff());

drop policy if exists form_fields_select_authenticated_or_admin on public.form_fields;
create policy form_fields_select_authenticated_or_admin
on public.form_fields
for select
using (
  public.is_admin_or_staff()
  or auth.uid() is not null
);

drop policy if exists form_fields_admin_write on public.form_fields;
create policy form_fields_admin_write
on public.form_fields
for all
using (public.is_admin_or_staff())
with check (public.is_admin_or_staff());

drop policy if exists profiles_select_own_or_admin on public.profiles;
create policy profiles_select_own_or_admin
on public.profiles
for select
using (auth.uid() = id or public.is_admin_or_staff());

drop policy if exists profiles_update_own_or_admin on public.profiles;
create policy profiles_update_own_or_admin
on public.profiles
for update
using (auth.uid() = id or public.is_admin_or_staff())
with check (auth.uid() = id or public.is_admin_or_staff());

drop policy if exists profiles_insert_self on public.profiles;
create policy profiles_insert_self
on public.profiles
for insert
with check (auth.uid() = id);

drop policy if exists students_select_own_or_admin on public.students;
create policy students_select_own_or_admin
on public.students
for select
using (user_id = auth.uid() or public.is_admin_or_staff());

drop policy if exists students_update_own_or_admin on public.students;
create policy students_update_own_or_admin
on public.students
for update
using (user_id = auth.uid() or public.is_admin_or_staff())
with check (user_id = auth.uid() or public.is_admin_or_staff());

drop policy if exists applications_select_own_or_admin on public.applications;
create policy applications_select_own_or_admin
on public.applications
for select
using (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.students s
    where s.id = applications.student_id
      and s.user_id = auth.uid()
  )
);

drop policy if exists applications_insert_own_student on public.applications;
create policy applications_insert_own_student
on public.applications
for insert
with check (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.students s
    where s.id = applications.student_id
      and s.user_id = auth.uid()
  )
);

drop policy if exists applications_update_admin_only on public.applications;
create policy applications_update_admin_only
on public.applications
for update
using (public.is_admin_or_staff())
with check (public.is_admin_or_staff());

drop policy if exists application_responses_select_own_or_admin on public.application_responses;
create policy application_responses_select_own_or_admin
on public.application_responses
for select
using (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.applications a
    join public.students s on s.id = a.student_id
    where a.id = application_responses.application_id
      and s.user_id = auth.uid()
  )
);

drop policy if exists application_responses_insert_own_or_admin on public.application_responses;
create policy application_responses_insert_own_or_admin
on public.application_responses
for insert
with check (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.applications a
    join public.students s on s.id = a.student_id
    where a.id = application_responses.application_id
      and s.user_id = auth.uid()
  )
);

drop policy if exists documents_select_own_or_admin on public.documents;
create policy documents_select_own_or_admin
on public.documents
for select
using (
  public.is_admin_or_staff()
  or user_id = auth.uid()
);

drop policy if exists documents_insert_own_or_admin on public.documents;
create policy documents_insert_own_or_admin
on public.documents
for insert
with check (
  public.is_admin_or_staff()
  or user_id = auth.uid()
);

drop policy if exists exam_questions_select_authenticated_or_admin on public.exam_questions;
create policy exam_questions_select_authenticated_or_admin
on public.exam_questions
for select
using (
  public.is_admin_or_staff()
  or auth.uid() is not null
);

drop policy if exists exam_questions_admin_write on public.exam_questions;
create policy exam_questions_admin_write
on public.exam_questions
for all
using (public.is_admin_or_staff())
with check (public.is_admin_or_staff());

drop policy if exists exam_submissions_select_own_or_admin on public.exam_submissions;
create policy exam_submissions_select_own_or_admin
on public.exam_submissions
for select
using (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.students s
    where s.id = exam_submissions.student_id
      and s.user_id = auth.uid()
  )
);

drop policy if exists exam_submissions_insert_own_or_admin on public.exam_submissions;
create policy exam_submissions_insert_own_or_admin
on public.exam_submissions
for insert
with check (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.students s
    where s.id = exam_submissions.student_id
      and s.user_id = auth.uid()
  )
);

drop policy if exists exam_submissions_update_own_or_admin on public.exam_submissions;
create policy exam_submissions_update_own_or_admin
on public.exam_submissions
for update
using (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.students s
    where s.id = exam_submissions.student_id
      and s.user_id = auth.uid()
  )
)
with check (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.students s
    where s.id = exam_submissions.student_id
      and s.user_id = auth.uid()
  )
);

drop policy if exists exam_answers_select_own_or_admin on public.exam_answers;
create policy exam_answers_select_own_or_admin
on public.exam_answers
for select
using (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.exam_submissions es
    join public.students s on s.id = es.student_id
    where es.id = exam_answers.submission_id
      and s.user_id = auth.uid()
  )
);

drop policy if exists exam_answers_insert_own_or_admin on public.exam_answers;
create policy exam_answers_insert_own_or_admin
on public.exam_answers
for insert
with check (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.exam_submissions es
    join public.students s on s.id = es.student_id
    where es.id = exam_answers.submission_id
      and s.user_id = auth.uid()
  )
);

drop policy if exists application_exams_select_own_or_admin on public.application_exams;
create policy application_exams_select_own_or_admin
on public.application_exams
for select
using (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.applications a
    join public.students s on s.id = a.student_id
    where a.id = application_exams.application_id
      and s.user_id = auth.uid()
  )
);

drop policy if exists application_exams_admin_write on public.application_exams;
create policy application_exams_admin_write
on public.application_exams
for all
using (public.is_admin_or_staff())
with check (public.is_admin_or_staff());

drop policy if exists announcements_select_public_or_admin on public.announcements;
create policy announcements_select_public_or_admin
on public.announcements
for select
using (is_active = true or public.is_admin_or_staff());

drop policy if exists announcements_admin_write on public.announcements;
create policy announcements_admin_write
on public.announcements
for all
using (public.is_admin_or_staff())
with check (public.is_admin_or_staff());

drop policy if exists announcement_attachments_select_public_or_admin on public.announcement_attachments;
create policy announcement_attachments_select_public_or_admin
on public.announcement_attachments
for select
using (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.announcements a
    where a.id = announcement_attachments.announcement_id
      and a.is_active = true
  )
);

drop policy if exists announcement_attachments_admin_write on public.announcement_attachments;
create policy announcement_attachments_admin_write
on public.announcement_attachments
for all
using (public.is_admin_or_staff())
with check (public.is_admin_or_staff());

drop policy if exists notifications_select_own_or_admin on public.notifications;
create policy notifications_select_own_or_admin
on public.notifications
for select
using (user_id = auth.uid() or public.is_admin_or_staff());

drop policy if exists notifications_insert_admin_or_self on public.notifications;
create policy notifications_insert_admin_or_self
on public.notifications
for insert
with check (user_id = auth.uid() or public.is_admin_or_staff());

drop policy if exists notifications_update_own_or_admin on public.notifications;
create policy notifications_update_own_or_admin
on public.notifications
for update
using (user_id = auth.uid() or public.is_admin_or_staff())
with check (user_id = auth.uid() or public.is_admin_or_staff());

drop policy if exists conversations_select_own_or_admin on public.conversations;
create policy conversations_select_own_or_admin
on public.conversations
for select
using (student_user_id = auth.uid() or public.is_admin_or_staff());

drop policy if exists conversations_insert_own_or_admin on public.conversations;
create policy conversations_insert_own_or_admin
on public.conversations
for insert
with check (student_user_id = auth.uid() or public.is_admin_or_staff());

drop policy if exists conversations_update_own_or_admin on public.conversations;
create policy conversations_update_own_or_admin
on public.conversations
for update
using (student_user_id = auth.uid() or public.is_admin_or_staff())
with check (student_user_id = auth.uid() or public.is_admin_or_staff());

drop policy if exists messages_select_own_or_admin on public.messages;
create policy messages_select_own_or_admin
on public.messages
for select
using (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.conversations c
    where c.id = messages.conversation_id
      and c.student_user_id = auth.uid()
  )
);

drop policy if exists messages_insert_own_thread_or_admin on public.messages;
create policy messages_insert_own_thread_or_admin
on public.messages
for insert
with check (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.conversations c
    where c.id = messages.conversation_id
      and c.student_user_id = auth.uid()
  )
);

drop policy if exists messages_update_own_thread_or_admin on public.messages;
create policy messages_update_own_thread_or_admin
on public.messages
for update
using (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.conversations c
    where c.id = messages.conversation_id
      and c.student_user_id = auth.uid()
  )
)
with check (
  public.is_admin_or_staff()
  or exists (
    select 1
    from public.conversations c
    where c.id = messages.conversation_id
      and c.student_user_id = auth.uid()
  )
);
