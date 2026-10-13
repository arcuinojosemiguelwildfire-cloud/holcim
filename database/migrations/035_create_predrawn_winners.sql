-- 035: Pre-drawn winner reveal.
-- Winners already selected outside this system (e.g. a Spin the Wheel app)
-- are assigned by an admin to keyboard letters A-Z for one event day and
-- revealed one by one on the LED screen. Completely separate from the
-- Minor / Major Randomizer: nothing here is written to randomizer_draws,
-- and Minor / Major eligibility, pools and winners are not affected.
--
-- One row = one assignment (letter -> attendee, optional prize label) and its
-- reveal. Lifecycle: assigned -> revealed (revealed_at) -> optionally voided
-- (voided_at; kept for the audit trail, never deleted). Unrevealed
-- assignments can be edited or removed by an admin.
-- Among NON-voided rows of a day, a letter and an attendee appear at most
-- once: live_lock is 1 for a live row and NULL for a voided one, and UNIQUE
-- keys ignore NULLs, so a voided reveal stays in history while its letter /
-- attendee can be assigned again.
-- Additive only: no existing table or row is changed.

CREATE TABLE IF NOT EXISTS predrawn_winners (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id        INT UNSIGNED NOT NULL,
    event_day_id    INT UNSIGNED NOT NULL,
    letter          CHAR(1)      NOT NULL,
    attendee_id     INT UNSIGNED NOT NULL,
    prize_label     VARCHAR(150) NULL DEFAULT NULL,
    assigned_by     INT UNSIGNED NULL DEFAULT NULL,
    assigned_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revealed_at     DATETIME     NULL DEFAULT NULL,
    revealed_by     INT UNSIGNED NULL DEFAULT NULL,
    voided_at       DATETIME     NULL DEFAULT NULL,
    voided_by       INT UNSIGNED NULL DEFAULT NULL,
    void_reason     VARCHAR(200) NULL DEFAULT NULL,
    -- 1 = live (not voided), NULL = voided (see the UNIQUE keys below).
    live_lock       TINYINT UNSIGNED AS (IF(voided_at IS NULL, 1, NULL)) STORED,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_predrawn_winners_day_letter (event_day_id, letter, live_lock),
    UNIQUE KEY uq_predrawn_winners_day_attendee (event_day_id, attendee_id, live_lock),
    KEY idx_predrawn_winners_attendee_event (attendee_id, event_id),
    KEY idx_predrawn_winners_event (event_id),
    KEY idx_predrawn_winners_assigned_by (assigned_by),
    KEY idx_predrawn_winners_revealed_by (revealed_by),
    KEY idx_predrawn_winners_voided_by (voided_by),
    CONSTRAINT chk_predrawn_winners_letter CHECK (CAST(letter AS BINARY) BETWEEN 'A' AND 'Z' AND CHAR_LENGTH(letter) = 1),
    CONSTRAINT chk_predrawn_winners_void_after_reveal CHECK (voided_at IS NULL OR revealed_at IS NOT NULL),
    -- RESTRICT: a generated column's base column cannot be on a cascading FK.
    -- The day and the attendee must belong to the SAME event.
    CONSTRAINT fk_predrawn_winners_day_event
        FOREIGN KEY (event_day_id, event_id) REFERENCES event_days (id, event_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_predrawn_winners_attendee_event
        FOREIGN KEY (attendee_id, event_id) REFERENCES attendees (id, event_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_predrawn_winners_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_predrawn_winners_revealed_by
        FOREIGN KEY (revealed_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_predrawn_winners_voided_by
        FOREIGN KEY (voided_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
