-- Πιθανοτητα (0-1) ειρωνειας και γνωστικης διαστρεβλωσης (CBT) απο το μοντελο, ανα καταγραφη.
ALTER TABLE entries
    ADD COLUMN IF NOT EXISTS irony_probability DECIMAL(5,4) NULL AFTER dominant_emotion_id,
    ADD COLUMN IF NOT EXISTS distortion_probability DECIMAL(5,4) NULL AFTER irony_probability;
