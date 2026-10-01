-- 021: One Major eligibility per attendee PER DAY (import or manual).
ALTER TABLE major_eligibility
    MODIFY event_day_id INT UNSIGNED NOT NULL,
    ADD KEY idx_major_eligibility_event (event_id),
    DROP INDEX uq_major_eligibility_event_attendee,
    ADD UNIQUE KEY uq_major_eligibility_day_attendee (event_day_id, attendee_id),
    ADD CONSTRAINT fk_major_eligibility_day_event
        FOREIGN KEY (event_day_id, event_id) REFERENCES event_days (id, event_id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
