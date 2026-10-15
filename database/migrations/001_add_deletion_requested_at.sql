-- Για βασεις που στηθηκαν πριν προστεθει η διαγραφη λογαριασμου. Τρεχει μια φορα.
USE wellness_journal;

ALTER TABLE users
    ADD COLUMN deletion_requested_at TIMESTAMP NULL DEFAULT NULL AFTER status;

CREATE INDEX idx_users_deletion ON users(deletion_requested_at);
