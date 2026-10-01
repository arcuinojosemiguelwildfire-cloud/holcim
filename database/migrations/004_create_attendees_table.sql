-- 004: Attendees (imported per event).
-- attendee_code is unique PER EVENT, so the same employee code can appear in
-- a future event without conflict.
-- `extra_data` preserves any spreadsheet columns that were not mapped to a
-- dedicated field, so the importer never has to discard client data.
-- The (id, event_id) unique key lets child tables use a composite foreign key
-- that guarantees the attendee really belongs to the referenced event.

CREATE TABLE IF NOT EXISTS attendees (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id          INT UNSIGNED  NOT NULL,
    attendee_code     VARCHAR(64)   NOT NULL,
    full_name         VARCHAR(200)  NOT NULL,
    department        VARCHAR(150)  NULL,
    email             VARCHAR(190)  NULL,
    extra_data        JSON          NULL,
    import_batch_id   INT UNSIGNED  NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_attendees_event_code (event_id, attendee_code),
    UNIQUE KEY uq_attendees_id_event (id, event_id),
    KEY idx_attendees_event_name (event_id, full_name),
    KEY idx_attendees_event_department (event_id, department),
    KEY idx_attendees_event_email (event_id, email),
    KEY idx_attendees_import_batch (import_batch_id),
    CONSTRAINT fk_attendees_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_attendees_import_batch
        FOREIGN KEY (import_batch_id) REFERENCES import_batches (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
