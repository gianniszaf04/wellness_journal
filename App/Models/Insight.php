<?php 
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class Insight {
    private PDO $db;
    public const TYPE_HABIT='habit';
    public const TYPE_TRIGGER='trigger';
    public const TYPE_IMPROVEMENT='improvement';
    public const TYPE_WARNING='warning';
    
    public function __construct() {
        $this->db=(new Database())->getConnection();
    }

    public function create(
        int $userId,
        string $type,
        string $text,
        ?string $relatedEntityName = null,
        ?string $relatedEmotionId = null,
        ?float $confidenceLevel = null,
        int $priority = 1
    ): int|false  {
        $text = trim($text);
        $type = trim($type);
        $relatedEntityName = $relatedEntityName ? trim($relatedEntityName) : null;

        if($text === '' || strlen($text) > 1000) {
            return false;
        }

        if (!in_array($type , [self::TYPE_HABIT, self::TYPE_TRIGGER, self::TYPE_IMPROVEMENT, self::TYPE_WARNING],true)) {
            return false;
        }

        if ($confidenceLevel !== null && ($confidenceLevel < 0.0 || $confidenceLevel > 1.0)) {
            return false;
        }

        if($priority < 1 || $priority > 3) {
            return false;
        }

        if($relatedEntityName !== null && strlen($relatedEntityName) > 100) {
            $relatedEntityName = substr($relatedEntityName, 0, 100);
        }

        try {
            $stmt = $this->db->prepare(
            'INSERT INTO insights (user_id, insight_type, insight_text, related_entity_name, related_emotion_id, confidence_level, priority)
            VALUES (:user_id, :type, :text, :entity, :emotion_id, :confidence, :priority)'
            );
        $stmt->execute([
            ':user_id' => $userId,
            ':type'  => $type,
            ':text'  => $text,
            ':entity'=> $relatedEntityName,
            ':emotion_id' => $relatedEmotionId,
            ':confidence' => $confidenceLevel,
            ':priority' => $priority,
        ]);
        return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log('Insight::create error: ' . $e->getMessage());
            return false;
        }
    }

    public function unseenForUser(int $userId) : array {
        $stmt = $this->db->prepare(
            'SELECT i.id, i.user_id, i.insight_type, i.insight_text,
            i.related_entity_name, i.related_emotion_id, i.confidence_level,
            i.priority, i.saw_by_user, e.emoji AS emotion_emoji, e.color AS emotion_color
            FROM insights i 
            LEFT JOIN  emotions e ON e.id  = i.related_emotion_id
            WHERE i.user_id = :user_id AND i.saw_by_user = 0
            ORDER BY  i.priority DESC, i.created_at DESC
            ');

            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markRead(int $id, int $userId) : bool {
        $stmt=$this->db->prepare(
            'UPDATE insights SET  saw_by_user = 1 , read_at = NOW() 
            WHERE id = :id AND user_id = :user_id
            ');
        return $stmt->execute([':id' => $id , ':user_id' => $userId]);
    }

    public function forUserByType(int $userId, string $type, int $limit = 20): array {
        if ($limit < 1 ||  $limit > 1000 ) {
            $limit = 20;
        }

        $stmt= $this->db->prepare(
            'SELECT i.id, i.user_id, i.insight_type, i.insight_text,
            i.related_entity_name, i.related_emotion_id, i.confidence_level,
            i.priority, i.saw_by_user, i.read_at, i.created_at, i.updated_at,
            e.name AS emotion_name, e.emoji AS emotion_emoji, e.color as emotion_color
            FROM insights i
            LEFT JOIN emotions e ON e.id = i.related_emotion_id
            WHERE i.user_id = :user_id AND i.insight_type = :type 
            ORDER BY i.priority DESC, i.created_at DESC
            LIMIT :lim');

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':type',$type);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function recentForUser(int $userId, int $limit = 10):array {
        if ($limit < 1 || $limit >1000 ) {
            $limit = 10;
        }

        $stmt = $this->db->prepare(
            'SELECT i.id, i.user_id, i.insight_type, i.insight_text, 
            i.related_entity_name, i.related_emotion_id, i.confidence_level,
            i.priority, i.saw_by_user, i.read_at, i.created_at, i.updated_at,
            e.name AS emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
            FROM insights i
            LEFT JOIN emotions e ON e.id = i.related_emotion_id
            WHERE  i.user_id = :user_id
            ORDER BY i.priority DESC, i.created_at DESC
            LIMIT :lim');

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function unseenCount(int $userId):int {
        $stmt= $this->db->prepare(
        'SELECT COUNT(*) FROM insights 
        WHERE user_id = :user_id AND saw_by_user=0');
    
        $stmt->execute([':user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function existsTodayOfType(int $userId, string $type): bool {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM insights
            WHERE user_id = :user_id AND insight_type = :type
            AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
            LIMIT 1');
        $stmt->execute([':user_id' => $userId, ':type' => $type]);

        return $stmt->fetchColumn() !== false;
    }

    public function allForUser(int $userId): array {
        $stmt=$this->db->prepare(
            'SELECT i.id, i.user_id, i.insight_type, i.insight_text,
            i.related_entity_name, i.related_emotion_id, i.confidence_level,
            i.priority, i.saw_by_user, i.read_at, i.created_at, i.updated_at,
            e.name AS emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
            FROM insights i
            LEFT JOIN emotions e on e.id = i.related_emotion_id
            WHERE i.user_id = :user_id
            ORDER BY i.priority DESC, i.created_at DESC
            ');

        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function forEntity(int $userId, string $entityName):array {
        $stmt=$this->db->prepare(
            'SELECT i.id, i.user_id, i.insight_type, i.insight_text,
            i.related_entity_name, i.related_emotion_id, i.confidence_level,
            i.priority, i.saw_by_user, i.read_at, i.created_at, i.updated_at,
            e.name AS emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
            FROM insights i
            LEFT JOIN emotions e ON e.id = i.related_emotion_id
            WHERE i.user_id = :user_id AND i.related_entity_name = :entity
            ORDER BY i.priority DESC, i.created_at DESC
            ');

            $stmt->execute([
                ':user_id' =>$userId,
                ':entity'  =>trim($entityName),
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function forEmotion(int $userId, int $emotionId ):array {
        $stmt=$this->db->prepare(
            'SELECT i.id, i.user_id, i.insight_type, i.insight_text,
            i.related_entity_name, i.related_emotion_id, i.confidence_level,
            i.priority, i.saw_by_user, i.read_at, i.created_at, i.updated_at,
            e.name AS emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
            FROM insights i
            LEFT JOIN emotions e ON e.id = i.related_emotion_id
            WHERE i.user_id = :user_id AND i.related_emotion_id = :emotion_id
            ORDER BY i.priority DESC, i.created_at DESC
            ');

            $stmt->execute([
                ':user_id' =>$userId,
                ':emotion_id'  =>$emotionId ,
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function delete(int $id, int $userId): bool {
        $stmt = $this->db->prepare(
            'DELETE FROM insights WHERE id = :id AND user_id = :user_id');
            return $stmt->execute([':id' =>$id , ':user_id' => $userId]);
        }



        public function highConfidenceForUser(int $userId, float $minConfidence = 0.7 ):array {
            $stmt=$this->db->prepare(
                'SELECT i.id, i.user_id, i.insight_type, i.insight_text,
                i.related_entity_name, i.related_emotion_id, i.confidence_level,
                i.priority, i.saw_by_user, i.read_at, i.created_at, i.updated_at,
                e.name AS emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
                FROM insights i
                LEFT JOIN emotions e ON e.id = i.related_emotion_id
                WHERE i.user_id = :user_id AND i.confidence_level >= :confidence
                ORDER BY  i.confidence_level DESC, i.priority DESC
                ');
    
                $stmt->execute([
                    ':user_id' =>$userId,
                    ':confidence'  => min(1.0, max(0.0, $minConfidence)) ,
                ]);
    
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }


        public function forUserInRange(int $userId, string $fromDate, string $toDate ):array {
            $stmt=$this->db->prepare(
                'SELECT i.id, i.user_id, i.insight_type, i.insight_text,
                i.related_entity_name, i.related_emotion_id, i.confidence_level,
                i.priority, i.saw_by_user, i.read_at, i.created_at, i.updated_at,
                e.name AS emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
                FROM insights i
                LEFT JOIN emotions e ON e.id = i.related_emotion_id
                WHERE i.user_id = :user_id 
                AND DATE(i.created_at) BETWEEN :from_date AND :to_date
                ORDER BY i.priority DESC, i.created_at DESC
                ');
    
                $stmt->execute([
                    ':user_id' =>$userId,
                    ':from_date'  =>$fromDate ,
                    ':to_date' => $toDate,
                ]);
    
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        public function cleanUpOld(int $daysOld = 100) : int{
            $stmt = $this->db->prepare(
                'DELETE FROM insights WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');

                $stmt->execute([':days' => $daysOld]);
                return $stmt->rowCount();
        }

        public function statsForUser(int $userId): array {
            $stmt= $this->db->prepare(
                'SELECT insight_type, COUNT(*) AS total,
                SUM(CASE WHEN saw_by_user = 0 THEN 1 ELSE 0 END) AS unseen,
                AVG(confidence_level) AS avg_confidence,
                MAX(created_at) AS last_created
                FROM insights
                WHERE user_id = :user_id
                GROUP BY insight_type
                ');

                $stmt->execute([':user_id' => $userId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
}