-- 008: Audit logs.
-- Append-only record of important actions (logins, event changes, and later:
-- imports, scans, draws, winner changes). user_id / event_id are SET NULL on
-- delete so the history survives even if the referenced row is removed.
-- `action` uses dotted names, e.g. 'auth.login', 'event.updated'.

CREATE TABLE IF NOT EXISTS audit_logs (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED     NULL,
    event_id      INT UNSIGNED     NULL,
    action        VARCHAR(100)     NOT NULL,
    description   VARCHAR(500)     NULL,
    metadata      JSON             NULL,
    ip_address    VARCHAR(45)      NULL,
    user_agent    VARCHAR(255)     NULL,
    created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_audit_logs_user (user_id),
    KEY idx_audit_logs_event_time (event_id, created_at),
    KEY idx_audit_logs_action (action),
    KEY idx_audit_logs_created_at (created_at),
    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_audit_logs_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
