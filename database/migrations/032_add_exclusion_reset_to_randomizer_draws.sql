-- 032: Randomizer Reset (Phase 9.4).
-- An admin can lift the "no repeat winner" exclusion for one event day and
-- randomizer WITHOUT deleting draw history. A draw only excludes its winner
-- while voided_at IS NULL AND reset_at IS NULL. Additive only: existing rows
-- keep reset_at = NULL, so current behaviour is unchanged.

ALTER TABLE randomizer_draws
    ADD COLUMN reset_at DATETIME NULL DEFAULT NULL AFTER void_reason,
    ADD COLUMN reset_by INT UNSIGNED NULL DEFAULT NULL AFTER reset_at,
    ADD KEY idx_randomizer_draws_reset_by (reset_by),
    ADD CONSTRAINT fk_randomizer_draws_reset_by
        FOREIGN KEY (reset_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE;
