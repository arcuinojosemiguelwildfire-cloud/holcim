-- 023: Existing draws and voids (Phase 5-7) belong to Day 1. Nothing is deleted.
UPDATE randomizer_draws r
JOIN event_days d ON d.event_id = r.event_id AND d.day_number = 1
SET r.event_day_id = d.id
WHERE r.event_day_id IS NULL;
