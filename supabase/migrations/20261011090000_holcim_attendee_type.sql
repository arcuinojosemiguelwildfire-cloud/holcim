-- Holcim Event System - Internal / External attendee type.
-- PostgreSQL twin of MySQL migration 034_add_attendee_type_to_attendees.sql:
-- an optional attendee_type ('internal' | 'external'); NULL = not specified.
-- Additive only; no existing data is changed.
alter table public.attendees
  add column attendee_type text check (attendee_type in ('internal', 'external'));
