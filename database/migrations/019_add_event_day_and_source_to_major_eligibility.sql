-- 019: Major eligibility becomes per event day and records its source
-- ('import' from a response file, 'manual' added by an operator).
ALTER TABLE major_eligibility
    ADD COLUMN event_day_id INT UNSIGNED NULL DEFAULT NULL AFTER event_id,
    ADD COLUMN source ENUM('import', 'manual') NOT NULL DEFAULT 'import' AFTER import_batch_id,
    ADD COLUMN added_by INT UNSIGNED NULL DEFAULT NULL AFTER source,
    ADD COLUMN reason VARCHAR(200) NULL DEFAULT NULL AFTER added_by,
    ADD KEY idx_major_eligibility_added_by (added_by),
    ADD CONSTRAINT fk_major_eligibility_added_by
        FOREIGN KEY (added_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE;
