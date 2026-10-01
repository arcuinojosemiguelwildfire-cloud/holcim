-- 028: Scanner Operator role + username login (Phase 8).
-- Email becomes optional (scanner operators may only have a username);
-- UNIQUE still applies to non-NULL emails and usernames.
ALTER TABLE users
    MODIFY role ENUM('admin', 'registration_staff', 'event_operator', 'scanner_operator') NOT NULL DEFAULT 'event_operator',
    MODIFY email VARCHAR(190) NULL DEFAULT NULL,
    ADD COLUMN username VARCHAR(60) NULL DEFAULT NULL AFTER email,
    ADD UNIQUE KEY uq_users_username (username);
