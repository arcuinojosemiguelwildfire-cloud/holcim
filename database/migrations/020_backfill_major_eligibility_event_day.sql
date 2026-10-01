-- 020: Existing Major eligibility (Phase 6-7) belongs to Day 1; importer kept as added_by.
UPDATE major_eligibility m
JOIN event_days d ON d.event_id = m.event_id AND d.day_number = 1
LEFT JOIN import_batches b ON b.id = m.import_batch_id
SET m.event_day_id = d.id, m.added_by = COALESCE(m.added_by, b.imported_by)
WHERE m.event_day_id IS NULL;
