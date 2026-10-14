<?php 
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class MotivationalMessage {
    private PDO $db;
    private static ?array $activeCache = null;

    public function __construct() {
        $this->db = (new Database())->getConnection();
    }

    public function findForEmotion(int $emotionId, int $intensityLevel = 1): array {
        $stmt = $this->db->prepare(
            'SELECT mm.id, mm.message_text, mm.intensity_level,
            e.id AS emotion_id, e.name AS emotion_name, e.emoji AS emotion_emoji
            FROM motivational_messages mm 
            JOIN emotions e ON e.id = mm.target_emotion
            WHERE mm.target_emotion = :emotion_id
            AND mm.intensity_level = :intensity
            AND mm.is_active = 1
            ORDER BY RAND()'
        );
        $stmt->execute([':emotion_id' => $emotionId, ':intensity' => $intensityLevel]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function randomForEmotion(int $emotionId, int $intensityLevel = 1): ?array {
        $stmt = $this->db->prepare(
            'SELECT mm.id, mm.message_text, mm.intensity_level,
            e.id AS emotion_id, e.name AS emotion_name, e.emoji AS emotion_emoji
            FROM motivational_messages mm 
            JOIN emotions e ON e.id = mm.target_emotion
            WHERE mm.target_emotion = :emotion_id
            AND mm.intensity_level <= :intensity
            AND mm.is_active = 1
            ORDER BY RAND()
            LIMIT 1');
        $stmt->execute([':emotion_id' => $emotionId, ':intensity' => $intensityLevel]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function randomForEmotionName(string $emotionName, int $intensityLevel = 1): ?array {
        $stmt = $this->db->prepare(
            'SELECT mm.id, mm.message_text, mm.intensity_level,
            e.id AS emotion_id, e.name AS emotion_name, e.emoji AS emotion_emoji
            FROM motivational_messages mm
            JOIN emotions e ON e.id = mm.target_emotion
            WHERE e.processed_name = LOWER(TRIM(:emotion_name))
            AND mm.intensity_level <= :intensity
            AND mm.is_active = 1
            ORDER BY RAND()
            LIMIT 1
        ');
        $stmt->execute([':emotion_name' => $emotionName, ':intensity' => $intensityLevel]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function allActive(): array {
        if (self::$activeCache !== null) {
            return self::$activeCache;
        }

        $stmt = $this->db->query(
            'SELECT mm.id, mm.message_text, mm.intensity_level,
            e.id AS emotion_id, e.name AS emotion_name, e.emoji AS emotion_emoji
            FROM motivational_messages mm
            JOIN emotions e ON e.id = mm.target_emotion
            WHERE mm.is_active = 1
            ORDER BY mm.target_emotion, mm.intensity_level'
        );
        self::$activeCache = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return self::$activeCache;
    }

    public static function clearCache(): void {
        self::$activeCache = null;
    }

    public function countActive(): int {
        $stmt = $this->db->query('SELECT COUNT(*) as total FROM motivational_messages WHERE is_active = 1');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['total'] : 0;
    }
}