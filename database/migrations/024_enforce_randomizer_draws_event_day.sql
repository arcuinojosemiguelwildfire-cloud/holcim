-- 024: Every draw belongs to an event day of its event.
ALTER TABLE randomizer_draws
    MODIFY event_day_id INT UNSIGNED NOT NULL,
    ADD KEY idx_randomizer_draws_day_type_time (event_day_id, randomizer_type, selected_at),
    ADD CONSTRAINT fk_randomizer_draws_day_event
        FOREIGN KEY (event_day_id, event_id) REFERENCES event_days (id, event_id)
        ON DELETE RESTRICT ON UPDATE CASCADE;
