ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMP NULL DEFAULT NULL AFTER processed_email;

UPDATE users SET email_verified_at = created_at, updated_at = updated_at WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS email_verifications (
user_id BIGINT UNSIGNED PRIMARY KEY,
code_hash VARCHAR(255) NOT NULL,
expires_at DATETIME NOT NULL,
attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
sent_at DATETIME NOT NULL,

CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
