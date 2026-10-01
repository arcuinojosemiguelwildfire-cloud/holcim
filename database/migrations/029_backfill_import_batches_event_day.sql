-- 029: Major eligibility import batches recorded before Phase 8 belonged to
-- the (only) Day 1. Attendee import batches stay event-wide (NULL).
UPDATE import_batches b
JOIN event_days d ON d.event_id = b.event_id AND d.day_number = 1
SET b.event_day_id = d.id
WHERE b.import_type = 'major_entries' AND b.event_day_id IS NULL;
