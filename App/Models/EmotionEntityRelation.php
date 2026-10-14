<?php
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class EmotionEntityRelation
{
    private PDO $db;

    public function __construct()
    {
        $this->db = (new Database())->getConnection();
    }

    public function upsert(int $userId, string $entityName, string $entityType, ?int $dominantEmotionId, ?float $avgScore): bool
    {
        $entityName = trim($entityName);
        $entityType = trim($entityType);

        if ($entityName === '' || strlen($entityName) > 100) {
            return false;
        }

        if ($entityType === '' || strlen($entityType) > 50) {
            return false;
        }

        if ($avgScore !== null && ($avgScore < -1.0 || $avgScore > 1.0)) {
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                'UPDATE relation_emotion_entity 
                SET dominant_emotion_id = :emotion_id,
                avg_sentiment_score = :avg_score,
                mentioned_count = mentioned_count + 1,
                last_mentioned_at = NOW()
                WHERE user_id = :user_id
                AND entity_name = :name
                AND entity_type = :type
                '
            );

            $stmt->execute([
                ':emotion_id' => $dominantEmotionId,
                ':avg_score' => $avgScore,
                ':user_id' => $userId,
                ':name' => $entityName,
                ':type' => $entityType,
            ]);

            if ($stmt->rowCount() > 0) {
                return true;
            }

            $insert = $this->db->prepare(
                'INSERT INTO relation_emotion_entity
                (user_id, entity_name, entity_type,  dominant_emotion_id, avg_sentiment_score, mentioned_count)
                VALUES (:user_id, :name, :type, :emotion_id, :avg_score, 1)
                '
            );

            return $insert->execute([
                ':user_id' => $userId,
                ':name' => $entityName,
                ':type' => $entityType,
                ':emotion_id' => $dominantEmotionId,
                ':avg_score' => $avgScore,
            ]);
        } catch (\PDOException $e) {
            error_log('EmotionEntityRelation::upsert error: ' . $e->getMessage());
            return false;
        }
    }

    public function topForUser(int $userId, int $limit = 10): array
    {
        if ($limit < 1 || $limit > 1000) {
            $limit = 10;
        }

        $stmt = $this->db->prepare(
            'SELECT ree.id, ree.user_id, ree.entity_name, ree.entity_type,
            ree.dominant_emotion_id, ree.avg_sentiment_score, ree.mentioned_count,
            ree.last_mentioned_at,
            e.name AS  emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
            FROM relation_emotion_entity ree
            LEFT JOIN emotions e ON  e.id = ree.dominant_emotion_id
            WHERE  ree.user_id = :user_id
            ORDER BY ree.mentioned_count DESC 
            LIMIT :lim
            '
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByEmotion(int $userId, int $emotionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, entity_name, entity_type, dominant_emotion_id,
            avg_sentiment_score, mentioned_count, last_mentioned_at
            FROM relation_emotion_entity
            WHERE user_id = :user_id AND dominant_emotion_id = :emotion_id
            ORDER BY mentioned_count DESC
            '
        );

        $stmt->execute([':user_id' => $userId, ':emotion_id' => $emotionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByName(int $userId, string $entityName): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, entity_name, entity_type, dominant_emotion_id,
            avg_sentiment_score, mentioned_count, last_mentioned_at
            FROM relation_emotion_entity
            WHERE user_id = :user_id AND entity_name = :name
            LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => trim($entityName),
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function allForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT  ree.id, ree.user_id, ree.entity_name, ree.entity_type,
            ree.dominant_emotion_id, ree.avg_sentiment_score, ree.mentioned_count,
            ree.last_mentioned_at,
            e.name AS emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
            FROM relation_emotion_entity ree
            LEFT JOIN emotions e ON e.id = ree.dominant_emotion_id
            WHERE ree.user_id = :user_id
            ORDER BY ree.mentioned_count DESC, ree.last_mentioned_at DESC
            '
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM relation_emotion_entity WHERE id = :id AND user_id = :user_id');
        return $stmt->execute([':id' => $id, ':user_id' => $userId]);
    }

    public function findTendsInRange(int $userId, string $fromDate, string $toDate): array
    {
        $stmt = $this->db->prepare(
            'SELECT ree.entity_name, ree.entity_type, 
                    COUNT(ee.id) AS total_mentions,
                    AVG(e.sentiment_score) AS avg_sentiment,
                    ree.dominant_emotion_id,
                    em.name AS emotion_name, em.emoji AS emotion_emoji
             FROM relation_emotion_entity ree
             JOIN entry_entities ee ON ee.entity_name = ree.entity_name AND ee.entity_type = ree.entity_type
             JOIN entries e ON e.id = ee.entry_id
             LEFT JOIN emotions em ON em.id = ree.dominant_emotion_id
             WHERE ree.user_id = :user_id
               AND e.entry_date BETWEEN :from_date AND :to_date
             GROUP BY ree.entity_name, ree.entity_type, ree.dominant_emotion_id, em.name, em.emoji
             ORDER BY total_mentions DESC'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function emotionsForEntity(int $userId, string $entityName): array
    {
        $entityName = trim($entityName);

        $stmt = $this->db->prepare(
            'SELECT e.id, e.name, e.emoji, e.color,
                AVG(ree.avg_sentiment_score) AS evg_sentiment,
                COUNT(DISTINCT ee.entry_id) AS mention_count
            FROM emotions e
            LEFT JOIN relation_emotion_entity ree on ree.dominant_emotion_id = e.id
            AND ree.user_id = :user_id
            AND ree.entity_name = :name
            LEFT JOIN entry_entities ee ON ee.entity_name = :name
            AND  ee.entry_id IN ( SELECT e2.id FROM entries e2 WHERE e2.user_id = :user_id AND e2.dominant_emotion_id = e.id)
            WHERE e.is_active = 1
            GROUP BY e.id, e.name, e.emoji, e.color
            ORDER BY mention_count DESC
            '
        );

        $stmt->execute([':user_id' => $userId, ':name' => $entityName,]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function avgSentimentByType(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT entity_type,
                AVG(avg_sentiment_score) AS avg_sentiment,
                    COUNT(*) AS entity_count,
                    SUM(mentioned_count) AS total_mentions
             FROM relation_emotion_entity
             WHERE user_id = :user_id
             GROUP BY entity_type
             ORDER BY total_mentions DESC
            '
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cleanUpOldRelations(int $daysOld = 90): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM relation_emotion_entity WHERE last_mentioned_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
        );

        $stmt->execute([':days' => $daysOld]);
        return $stmt->rowCount();
    }

    public function exists(int $userId, string $entityName, string $entityType): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM relation_emotion_entity 
            WHERE user_id = :user_id 
            AND entity_name = :name 
            AND entity_type = :type 
            LIMIT 1');

        $stmt->execute([
            ':user_id' => $userId,
            ':name' => trim($entityName),
            ':type' => trim($entityType),
        ]);

        return $stmt->fetch() !== false;
    }

}