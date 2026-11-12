<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\Crypt;
use Database;
use PDO;
use PDOException;

class ThoughtRecord {
    public const MAX_EVENT = 1000;
    public const MAX_THOUGHT = 300;
    public const MAX_REFRAME = 1000;
    public const MAX_BALANCED = 300;
    public const MIN_THOUGHT_WORDS = 3;
    public const MAX_EMOTIONS = 3;
    public const MAX_QUESTIONS = 2;

    public const SOURCE_CONFIDENT = 'confident';
    public const SOURCE_ASKED = 'asked';
    public const SOURCE_NONE = 'none';
    public const SOURCE_DECLINED = 'declined';
    public const SOURCE_CRISIS = 'crisis';
    public const SOURCE_AI_DOWN = 'ai_down';

    public const RESPONSE_YES = 'yes';
    public const RESPONSE_NOT_NOW = 'not_now';
    public const RESPONSE_REJECTED = 'rejected';

    public const ANSWER_NEUTRAL = 'neutral';
    public const ANSWER_TRAP = 'trap';
    public const ANSWER_UNKNOWN = 'unknown';

    private const JSON_COLUMNS = ['emotions', 'model_ranking', 'answers'];
    public const ENCRYPTED = ['event', 'thought', 'thought_mood', 'reframe', 'balanced_thought'];

    private PDO $db;

    public function __construct() {
        $this->db = (new Database())->getConnection();
    }

    public static function wordCount(string $text): int {
        return preg_match_all('/[\p{L}\p{N}]+/u', $text);
    }

    public function create(int $userId, array $record): int|false {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO thought_records (user_id, event, thought, thought_mood, thought_mood_key, emotions,
                model_probability, model_detected, model_ranking, model_version, answers, observed_type,
                observation_source, response, reframe, balanced_thought, crisis)
                VALUES (:user_id, :event, :thought, :thought_mood, :thought_mood_key, :emotions,
                :probability, :detected, :ranking, :version, :answers, :observed_type,
                :source, :response, :reframe, :balanced, :crisis)'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':event' => Crypt::encrypt((string) $record['event']),
                ':thought' => Crypt::encrypt((string) $record['thought']),
                ':thought_mood' => Crypt::encryptNullable($record['thought_mood'] ?? null),
                ':thought_mood_key' => $record['thought_mood_key'],
                ':emotions' => json_encode($record['emotions'], JSON_UNESCAPED_UNICODE),
                ':probability' => $record['model_probability'] ?? null,
                ':detected' => isset($record['model_detected']) ? (int) $record['model_detected'] : null,
                ':ranking' => isset($record['model_ranking']) ? json_encode($record['model_ranking'], JSON_UNESCAPED_UNICODE) : null,
                ':version' => $record['model_version'] ?? null,
                ':answers' => !empty($record['answers']) ? json_encode($record['answers'], JSON_UNESCAPED_UNICODE) : null,
                ':observed_type' => $record['observed_type'] ?? null,
                ':source' => $record['observation_source'],
                ':response' => $record['response'] ?? null,
                ':reframe' => Crypt::encryptNullable($record['reframe'] ?? null),
                ':balanced' => Crypt::encryptNullable($record['balanced_thought'] ?? null),
                ':crisis' => empty($record['crisis']) ? 0 : 1,
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('ThoughtRecord::create error: ' . $e->getMessage());
            return false;
        }
    }

    public function findByIdForUser(int $id, int $userId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM thought_records WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::decode($row) : null;
    }

    public function allForUser(int $userId): array {
        $stmt = $this->db->prepare('SELECT * FROM thought_records WHERE user_id = :user_id ORDER BY created_at, id');
        $stmt->execute([':user_id' => $userId]);
        return array_map([self::class, 'decode'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countForUser(int $userId): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM thought_records WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    private static function decode(array $row): array {
        foreach (self::JSON_COLUMNS as $column) {
            $row[$column] = $row[$column] === null ? null : json_decode((string) $row[$column], true);
        }
        return Crypt::decryptColumns($row, self::ENCRYPTED);
    }
}
