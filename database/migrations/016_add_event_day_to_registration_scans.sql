-- 016: Registration becomes per event day (step 1: nullable column).
ALTER TABLE registration_scans ADD COLUMN event_day_id INT UNSIGNED NULL DEFAULT NULL AFTER event_id;
