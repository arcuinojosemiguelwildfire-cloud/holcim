-- 017: Existing check-ins (Phase 4-7) belong to their event's Day 1. Nothing is deleted.
UPDATE registration_scans r
JOIN event_days d ON d.event_id = r.event_id AND d.day_number = 1
SET r.event_day_id = d.id
WHERE r.event_day_id IS NULL;
