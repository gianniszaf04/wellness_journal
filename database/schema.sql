-- ==============
-- PROJECT WELLNESS DIARY 
-- ======😮‍💨====== 
CREATE DATABASE IF NOT EXISTS wellness_journal CHARACTER SET utf8mb4  COLLATE utf8mb4_unicode_ci;
USE wellness_journal;

DROP USER IF EXISTS 'wellness_user'@'localhost';
CREATE USER 'wellness_user'@'localhost' IDENTIFIED BY 'CHANGE_ME';

GRANT SELECT ,INSERT ,UPDATE , DELETE ON wellness_journal.* TO 'wellness_user'@'localhost';
FLUSH PRIVILEGES;

SET NAMES utf8mb4;
SET time_zone='+00:00';

-- Φαση 1 : αναγκαιοι πινακεσ για user/profile
CREATE TABLE  IF NOT EXISTS users(
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(50) NOT NULL,
name VARCHAR(70) NOT NULL,
email VARCHAR(254) NOT NULL,
processed_email VARCHAR(254) GENERATED ALWAYS AS (LOWER(TRIM(email))) STORED,
password_hash VARCHAR(255) NOT NULL,
status TINYINT UNSIGNED NOT NULL DEFAULT 1,
deletion_requested_at TIMESTAMP NULL DEFAULT NULL, -- ποτε ζητηθηκε διαγραφη, οριστικη διαγραφη μετα απο 30 ημερες
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

CONSTRAINT uq_username UNIQUE (username),
CONSTRAINT uq_processed_email UNIQUE (processed_email),
CONSTRAINT check_status CHECK ( status IN (0,1)),
CONSTRAINT check_username CHECK (CHAR_LENGTH(TRIM(username))>0),
CONSTRAINT check_email CHECK (CHAR_LENGTH(TRIM(email))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_users_deletion ON users(deletion_requested_at);

CREATE TABLE IF NOT EXISTS  user_profiles(
user_id BIGINT UNSIGNED PRIMARY KEY,
preferences JSON NULL,
theme_background VARCHAR(255) NOT NULL DEFAULT 'default_theme', -- δες αν το theme θα ειναι εικονεσ ή απλα css ή και τα δυο --
sound VARCHAR(100) NOT NULL DEFAULT 'none',
updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

CONSTRAINT fk_user_profile FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
CONSTRAINT check_not_empty_theme CHECK (TRIM(theme_background)<>''),
CONSTRAINT check_not_empty_sound CHECK (TRIM(sound)<>'')
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ΦΑΣΗ 2 - ΑΝΑΓΚΑΙΟΙ ΠΙΝΑΚΕΣ ΓΙΑ ΤΟ ΗΜΕΡΟΛΟΓΙΟ :))))))  --
 
-- αποθηκευση διαθέσιμων συναισθημάτων  που γνωρίζει το σύστημα που έχω φτιάξει (keeping it tidy af)
CREATE TABLE  IF NOT EXISTS emotions(
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(50) NOT NULL,
processed_name VARCHAR(50) GENERATED ALWAYS AS (LOWER(TRIM(name))) STORED,
color VARCHAR(7) NOT NULL DEFAULT '#808080',
emoji VARCHAR(10),
description TEXT,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

CONSTRAINT uq_emotions UNIQUE (processed_name),
CONSTRAINT check_emotion_name CHECK (CHAR_LENGTH(TRIM(name))>0),
CONSTRAINT check_emotion_active CHECK (is_active IN (0,1))

)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; 

CREATE TABLE  IF NOT EXISTS entries (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id BIGINT UNSIGNED NOT NULL,
entry_date DATE NOT NULL,
body MEDIUMTEXT NOT NULL,
sentiment_score DECIMAL (6,4) NULL,
dominant_emotion_id BIGINT UNSIGNED  NULL,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

CONSTRAINT entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
CONSTRAINT entries_emotion FOREIGN KEY (dominant_emotion_id) REFERENCES emotions(id) ON DELETE SET NULL,
CONSTRAINT check_senti_score CHECK (sentiment_score IS NULL OR (sentiment_score >=-1.0000 AND sentiment_score<=1.0000))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; 

CREATE INDEX  idx_entries_created ON entries(user_id,created_at);
CREATE INDEX  idx_entries_user_date ON entries(user_id,entry_date);
CREATE INDEX idx_emotion ON entries(dominant_emotion_id);

-- ΦΑΣΗ 3 ΣΑΠΟΡΤΑΡΟΥΜΕ ΤΟΥΣ ΧΡΗΣΤΕΣ ΜΑΣ 💪
	  
CREATE TABLE IF NOT EXISTS  motivational_messages (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
target_emotion BIGINT UNSIGNED  NOT NULL,
message_text TEXT NOT NULL,
intensity_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

CONSTRAINT fk_messages_emotion FOREIGN KEY (target_emotion) REFERENCES emotions(id) ON DELETE CASCADE,
CONSTRAINT check_text_notEmpty CHECK(CHAR_LENGTH(TRIM(message_text))>0),
CONSTRAINT check_intensityLevel CHECK (intensity_level BETWEEN 1 AND 3 ),
CONSTRAINT check_message_active CHECK (is_active IN (0,1))

)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; 

CREATE INDEX  idx_messages ON motivational_messages(target_emotion,intensity_level);

CREATE TABLE  IF NOT EXISTS daily_summaries (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id BIGINT UNSIGNED NOT NULL,
summary_date DATE NOT NULL,
avg_sentiment_score DECIMAL(6,4) NULL,
dominant_emotion_id BIGINT UNSIGNED NULL,
total_entries_count INT DEFAULT 0,
summary_text TEXT,
summary_is_done TINYINT(1) DEFAULT 0,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

CONSTRAINT fk_daily_summaries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
CONSTRAINT fk_daily_summaries_emotion FOREIGN KEY (dominant_emotion_id) REFERENCES emotions(id) ON DELETE  SET NULL,
CONSTRAINT unique_daily_sums UNIQUE (user_id,summary_date),
CONSTRAINT check_dailyAvg_score CHECK (avg_sentiment_score IS NULL OR (avg_sentiment_score >=-1.0000 AND avg_sentiment_score<=1.0000)),
CONSTRAINT check_total_entries CHECK(total_entries_count >= 0),
CONSTRAINT check_summary_done CHECK (summary_is_done IN (0,1))

)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; 

CREATE INDEX idx_daily_sums ON daily_summaries(user_id,summary_date);

CREATE TABLE  IF NOT EXISTS daily_messages(
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id BIGINT UNSIGNED NOT NULL,
message_id BIGINT UNSIGNED NOT NULL,
summary_date DATE NOT NULL,
user_saw_message TINYINT(1) NOT NULL DEFAULT 0,
shown_at TIMESTAMP NULL,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

CONSTRAINT fk_daily_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
CONSTRAINT fk_daily_messages_message FOREIGN KEY (message_id) REFERENCES motivational_messages(id) ON DELETE CASCADE,
CONSTRAINT uq_daily_messages UNIQUE (user_id,message_id,summary_date),
CONSTRAINT check_user_saw_message CHECK (user_saw_message IN (0,1))

)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; 

CREATE INDEX idx_daily_mess ON daily_messages(user_id,summary_date,user_saw_message);

-- ΦΑΣΗ 4 ΠΑΤΑΜΕ ΤΟ 0  - ΠΡΩΤΗ ΚΑΙ ΦΥΓΑΜΕ 🏎️

CREATE TABLE IF NOT EXISTS  entry_entities ( --  αποθηκευση keywords (ατομα/γεγονοτα )απο το κειμενο του χρηστη 🫠 -- 
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
entry_id BIGINT UNSIGNED NOT NULL,
entity_name VARCHAR(100) NOT NULL,
entity_type VARCHAR(50) NOT NULL,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

CONSTRAINT fk_entry_entities FOREIGN KEY (entry_id) REFERENCES entries(id) ON DELETE CASCADE,
CONSTRAINT unique_entry_entities UNIQUE(entry_id,entity_name,entity_type),
CONSTRAINT check_entityName_notEmpty CHECK(CHAR_LENGTH(TRIM(entity_name))>0),
CONSTRAINT check_entityType_notEmpty CHECK(CHAR_LENGTH(TRIM(entity_type))>0)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; 

CREATE INDEX  idx_entry_entities_name ON entry_entities(entity_name);
CREATE INDEX  idx_entry_entities_type ON entry_entities(entity_type);

-- στην προσπαθεια συνδεσης keywords  με συναισθηματα 😃
CREATE TABLE  IF NOT EXISTS relation_emotion_entity (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id BIGINT UNSIGNED NOT NULL,
entity_name VARCHAR(100) NOT NULL,
entity_type VARCHAR(50) NOT NULL,
dominant_emotion_id BIGINT UNSIGNED NULL,
avg_sentiment_score DECIMAL(6,4) NULL,
mentioned_count INT  NOT NULL DEFAULT 1,
last_mentioned_at TIMESTAMP NOT NULL  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

CONSTRAINT fk_user_correlation FOREIGN KEY  (user_id) REFERENCES users(id) ON DELETE CASCADE,
CONSTRAINT fk_emotion_correlation FOREIGN KEY  (dominant_emotion_id) REFERENCES emotions(id) ON DELETE SET NULL,
CONSTRAINT uq_emotion_entity UNIQUE  (user_id, entity_name, entity_type),
CONSTRAINT check_entity_name CHECK (CHAR_LENGTH(TRIM(entity_name))>0),
CONSTRAINT check_entity_type CHECK (CHAR_LENGTH(TRIM(entity_type))>0),
CONSTRAINT check_mentioned_count CHECK (mentioned_count >=0),
CONSTRAINT check_avg_sentiment_score  CHECK (avg_sentiment_score IS NULL OR (avg_sentiment_score >= -1.0000  AND avg_sentiment_score <= 1.0000))

)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; 

CREATE INDEX idx_match_user_entity ON  relation_emotion_entity(user_id,entity_name);

CREATE TABLE IF NOT EXISTS  insights (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
user_id BIGINT UNSIGNED NOT NULL,
insight_type ENUM('habit','trigger','improvement','warning') NOT NULL,
insight_text TEXT NOT NULL,
related_entity_name VARCHAR(100) NULL,
related_emotion_id BIGINT UNSIGNED NULL,
confidence_level DECIMAL(3,2) NULL,
priority TINYINT UNSIGNED NOT NULL DEFAULT 1,
saw_by_user TINYINT(1) NOT NULL DEFAULT 0,
read_at TIMESTAMP NULL,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

CONSTRAINT fk_insights_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
CONSTRAINT fk_insights_emotion FOREIGN KEY  (related_emotion_id) REFERENCES emotions(id) ON DELETE SET NULL,
CONSTRAINT check_insights_text CHECK (CHAR_LENGTH(TRIM(insight_text))>0),
CONSTRAINT check_confidence_level CHECK  (confidence_level  IS NULL OR (confidence_level >= 0.00  AND confidence_level <= 1.00)),
CONSTRAINT check_saw_by_user CHECK (saw_by_user IN (0,1))

)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; 

CREATE INDEX idx_insights_user ON  insights(user_id,saw_by_user,priority);



















