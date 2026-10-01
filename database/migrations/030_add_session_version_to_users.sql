-- 030: Session version (Phase 8.1). Each session stores the version it was
-- created with; AuthService rejects a session whose version no longer matches.
-- Incremented when an admin resets a user's password, so sessions that were
-- already open are signed out. Existing sessions (no stored version) count as 0.
ALTER TABLE users
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash;
