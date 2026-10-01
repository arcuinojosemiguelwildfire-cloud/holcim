-- 005: Attendee QR codes.
-- `token` is an OPAQUE random identifier (e.g. 32 random bytes, base64url).
-- It must never contain personal data; the scanner resolves it server-side.
-- ascii_bin collation makes token comparison exact and case-sensitive.
--
-- One QR record per attendee (unique attendee_id). Re-issuing a code (lost ID,
-- suspected leak) replaces the token in place; the previous token is recorded
-- in audit_logs so the change is traceable.

CREATE TABLE IF NOT EXISTS attendee_qr_codes (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    attendee_id   INT UNSIGNED  NOT NULL,
    token         VARCHAR(64)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_attendee_qr_codes_token (token),
    UNIQUE KEY uq_attendee_qr_codes_attendee (attendee_id),
    CONSTRAINT fk_attendee_qr_codes_attendee
        FOREIGN KEY (attendee_id) REFERENCES attendees (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
