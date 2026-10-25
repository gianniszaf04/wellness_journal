CREATE TABLE IF NOT EXISTS verification_codes (
user_id BIGINT UNSIGNED NOT NULL,
purpose VARCHAR(20) NOT NULL,
code_hash VARCHAR(255) NOT NULL,
expires_at DATETIME NOT NULL,
attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
sent_at DATETIME NOT NULL,

PRIMARY KEY (user_id, purpose),
CONSTRAINT check_verification_purpose CHECK (purpose IN ('email', 'password')),
CONSTRAINT fk_verification_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS email_verifications;
