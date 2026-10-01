-- 012: Failed login attempts for brute-force protection (Phase 7).
-- Only FAILED attempts are stored; a successful login clears the user's rows.
-- The email is stored as a SHA-256 hash (attempted emails may be typos or
-- other people's addresses). Rows older than a day are purged automatically.

CREATE TABLE IF NOT EXISTS login_attempts (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    email_hash    CHAR(64)         CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_address    VARCHAR(45)      NOT NULL,
    attempted_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_login_attempts_email_time (email_hash, attempted_at),
    KEY idx_login_attempts_ip_time (ip_address, attempted_at),
    KEY idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
