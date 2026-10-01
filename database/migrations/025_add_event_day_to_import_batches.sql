-- 025: Imports remember the event day they were made for (NULL for older imports).
ALTER TABLE import_batches
    ADD COLUMN event_day_id INT UNSIGNED NULL DEFAULT NULL AFTER event_id,
    ADD KEY idx_import_batches_day (event_day_id),
    ADD CONSTRAINT fk_import_batches_day
        FOREIGN KEY (event_day_id) REFERENCES event_days (id)
        ON DELETE SET NULL ON UPDATE CASCADE;
