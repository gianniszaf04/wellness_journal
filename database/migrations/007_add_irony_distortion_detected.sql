ALTER TABLE entries
    ADD COLUMN IF NOT EXISTS irony_detected TINYINT(1) NULL AFTER distortion_probability,
    ADD COLUMN IF NOT EXISTS distortion_detected TINYINT(1) NULL AFTER irony_detected;

UPDATE entries SET irony_detected = irony_probability > 0.5
WHERE irony_probability IS NOT NULL AND irony_detected IS NULL;

UPDATE entries SET distortion_detected = distortion_probability > 0.5
WHERE distortion_probability IS NOT NULL AND distortion_detected IS NULL;
