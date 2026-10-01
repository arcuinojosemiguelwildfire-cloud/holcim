-- 013: Draw voiding (Phase 7).
-- A voided draw stays in history (audit trail) and is shown as VOID.
-- Voiding never touches registration, Minor or Major eligibility.

ALTER TABLE randomizer_draws
    ADD COLUMN voided_at DATETIME NULL DEFAULT NULL AFTER selected_at,
    ADD COLUMN voided_by INT UNSIGNED NULL DEFAULT NULL AFTER voided_at,
    ADD COLUMN void_reason VARCHAR(200) NULL DEFAULT NULL AFTER voided_by,
    ADD KEY idx_randomizer_draws_voided_by (voided_by),
    ADD CONSTRAINT fk_randomizer_draws_voided_by
        FOREIGN KEY (voided_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE;
