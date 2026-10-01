-- 022: Draws become per event day (step 1: nullable column).
ALTER TABLE randomizer_draws ADD COLUMN event_day_id INT UNSIGNED NULL DEFAULT NULL AFTER event_id;
