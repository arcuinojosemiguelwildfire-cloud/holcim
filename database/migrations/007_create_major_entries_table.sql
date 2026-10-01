-- 007: Major entries (responses imported from the client's Google Form/Sheet).
-- full_name / department / email are the commonly-used fields; `response_data`
-- keeps the COMPLETE original row as JSON so new form questions never require
-- a schema change.
-- `external_identifier` is whatever uniquely identifies a response in the
-- source sheet (e.g. response ID or timestamp+email); unique per event so a
-- re-import does not duplicate entries. NULLs are allowed and not deduplicated.
-- `attendee_id` is optional and lets a later phase link an entry to an
-- imported attendee.

CREATE TABLE IF NOT EXISTS major_entries (
    id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id              INT UNSIGNED  NOT NULL,
    attendee_id           INT UNSIGNED  NULL,
    full_name             VARCHAR(200)  NOT NULL,
    department            VARCHAR(150)  NULL,
    email                 VARCHAR(190)  NULL,
    external_identifier   VARCHAR(190)  NULL,
    response_data         JSON          NULL,
    submitted_at          DATETIME      NULL DEFAULT NULL,
    import_batch_id       INT UNSIGNED  NULL,
    created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_major_entries_event_external (event_id, external_identifier),
    KEY idx_major_entries_event_name (event_id, full_name),
    KEY idx_major_entries_event_email (event_id, email),
    KEY idx_major_entries_attendee (attendee_id),
    KEY idx_major_entries_import_batch (import_batch_id),
    CONSTRAINT fk_major_entries_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_major_entries_attendee
        FOREIGN KEY (attendee_id) REFERENCES attendees (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_major_entries_import_batch
        FOREIGN KEY (import_batch_id) REFERENCES import_batches (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
