-- 015: Give every existing event a "Day 1" on its event date, so Phase 1-7
-- data can be attached to it. The active event's Day 1 becomes the active day.
INSERT INTO event_days (event_id, day_number, event_date, label, status)
SELECT e.id, 1, e.event_date, NULL,
       CASE e.status WHEN 'active' THEN 'active' WHEN 'draft' THEN 'upcoming' ELSE 'completed' END
FROM events e
WHERE NOT EXISTS (SELECT 1 FROM event_days d WHERE d.event_id = e.id);
