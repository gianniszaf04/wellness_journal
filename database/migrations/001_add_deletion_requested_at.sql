-- Για βασεις που στηθηκαν πριν προστεθει η διαγραφη λογαριασμου.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS deletion_requested_at TIMESTAMP NULL DEFAULT NULL AFTER status;

CREATE INDEX IF NOT EXISTS idx_users_deletion ON users(deletion_requested_at);
