-- 011: Major eligibility (Phase 6).
-- One row = one attendee made Major Eligible by an imported response file
-- (Google Sheet exported as CSV/XLSX). Kept separate from registration_scans
-- (Minor eligibility) and from the response-oriented major_entries table: only
-- the match is stored, not the client's spreadsheet contents.
-- UNIQUE (event_id, attendee_id): re-importing the same responses never creates
-- duplicate eligibility. The composite FK guarantees the attendee belongs to
-- the same event.

CREATE TABLE IF NOT EXISTS major_eligibility (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id          INT UNSIGNED  NOT NULL,
    attendee_id       INT UNSIGNED  NOT NULL,
    import_batch_id   INT UNSIGNED  NULL,
    imported_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_major_eligibility_event_attendee (event_id, attendee_id),
    KEY idx_major_eligibility_attendee_event (attendee_id, event_id),
    KEY idx_major_eligibility_batch (import_batch_id),
    CONSTRAINT fk_major_eligibility_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_major_eligibility_attendee_event
        FOREIGN KEY (attendee_id, event_id) REFERENCES attendees (id, event_id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_major_eligibility_batch
        FOREIGN KEY (import_batch_id) REFERENCES import_batches (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
