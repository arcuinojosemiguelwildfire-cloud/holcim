-- 003: Import batches (reusable for attendee and major-entry imports).
-- Created before attendees/major_entries because both reference it.
-- `column_mapping` stores how the client's spreadsheet headers were mapped to
-- system fields (dynamic column mapping), so every import is reproducible.

CREATE TABLE IF NOT EXISTS import_batches (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    event_id            INT UNSIGNED  NOT NULL,
    import_type         ENUM('attendees', 'major_entries') NOT NULL,
    original_filename   VARCHAR(255)  NOT NULL,
    status              ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    total_rows          INT UNSIGNED  NOT NULL DEFAULT 0,
    successful_rows     INT UNSIGNED  NOT NULL DEFAULT 0,
    failed_rows         INT UNSIGNED  NOT NULL DEFAULT 0,
    column_mapping      JSON          NULL,
    error_summary       JSON          NULL,
    imported_by         INT UNSIGNED  NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at        DATETIME      NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_import_batches_event_type (event_id, import_type),
    KEY idx_import_batches_imported_by (imported_by),
    CONSTRAINT fk_import_batches_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_import_batches_user
        FOREIGN KEY (imported_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
