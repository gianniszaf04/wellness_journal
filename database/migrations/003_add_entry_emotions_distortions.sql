-- Multi-label αποτελεσματα του μοντελου ανα καταγραφη: ολα τα συναισθηματα πανω απο 0.5
-- και οι τυποι γνωστικης διαστρεβλωσης (CBT). Τα ονοματα ιδια με το Python/labels.py.
CREATE TABLE IF NOT EXISTS entry_emotions (
entry_id BIGINT UNSIGNED NOT NULL,
emotion_id BIGINT UNSIGNED NOT NULL,
probability DECIMAL(5,4) NULL,

PRIMARY KEY (entry_id, emotion_id),
CONSTRAINT fk_entry_emotions_entry FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE CASCADE,
CONSTRAINT fk_entry_emotions_emotion FOREIGN KEY (emotion_id) REFERENCES emotions(id) ON DELETE CASCADE,
CONSTRAINT check_entry_emotion_prob CHECK (probability IS NULL OR (probability >= 0 AND probability <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS distortion_types (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(50) NOT NULL,

CONSTRAINT uq_distortion_types UNIQUE (name),
CONSTRAINT check_distortion_type_name CHECK (CHAR_LENGTH(TRIM(name))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO distortion_types (name) VALUES
('Καταστροφολογία'),
('Όλα ή τίποτα'),
('Υπεργενίκευση'),
('Ανάγνωση σκέψης'),
('Προφητεία'),
('Συναισθηματική συλλογιστική'),
('Πρέπει'),
('Ταμπέλες'),
('Προσωποποίηση'),
('Νοητικό φίλτρο');

CREATE TABLE IF NOT EXISTS entry_distortions (
entry_id BIGINT UNSIGNED NOT NULL,
distortion_type_id BIGINT UNSIGNED NOT NULL,
probability DECIMAL(5,4) NULL,

PRIMARY KEY (entry_id, distortion_type_id),
CONSTRAINT fk_entry_distortions_entry FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE CASCADE,
CONSTRAINT fk_entry_distortions_type FOREIGN KEY (distortion_type_id) REFERENCES distortion_types(id) ON DELETE CASCADE,
CONSTRAINT check_entry_distortion_prob CHECK (probability IS NULL OR (probability >= 0 AND probability <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
