-- 031: Company (Phase 9.2). Attendees now have three business fields:
-- Full Name, Company (new, optional) and Cluster (location/region, stored in
-- the existing `department` column, optional). Company is the "Name 1" column
-- of the client's External Attendees sheet. Additive only; no data changes.
ALTER TABLE attendees
    ADD COLUMN company VARCHAR(200) NULL DEFAULT NULL AFTER full_name,
    ADD KEY idx_attendees_event_company (event_id, company);
