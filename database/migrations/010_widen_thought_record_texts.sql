ALTER TABLE thought_records
MODIFY event TEXT NOT NULL,
MODIFY thought TEXT NOT NULL,
MODIFY thought_mood TEXT NULL,
MODIFY reframe TEXT NULL,
MODIFY balanced_thought TEXT NULL;
