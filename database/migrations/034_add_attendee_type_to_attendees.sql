-- 034: Internal / External attendee type.
-- The client's standard attendee sheet has an INTERNAL/EXTERNAL column
-- (GUEST NO. | SURNAME | FIRST NAME | MIDDLE NAME | INTERNAL/EXTERNAL |
-- DESIGNATION / COMPANY). It is not a Company, Cluster or Department, so it
-- gets its own optional column. NULL = not specified (every existing
-- attendee). Additive only; no existing data is changed.
ALTER TABLE attendees
    ADD COLUMN attendee_type ENUM('internal', 'external') NULL DEFAULT NULL AFTER company;
