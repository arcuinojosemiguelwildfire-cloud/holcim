-- 009: Attendee management (Phase 2).
-- external_identifier: the client's own ID (e.g. Employee ID), unique per event
--   when present; used first for duplicate detection on re-import.
-- status / archived_at: soft delete. Attendees are archived, never hard-deleted,
--   because QR codes and registration scans reference them.

ALTER TABLE attendees
    ADD COLUMN external_identifier VARCHAR(190) NULL DEFAULT NULL AFTER email,
    ADD COLUMN status ENUM('active', 'archived') NOT NULL DEFAULT 'active' AFTER external_identifier,
    ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER status,
    ADD UNIQUE KEY uq_attendees_event_external (event_id, external_identifier),
    ADD KEY idx_attendees_event_status (event_id, status);
