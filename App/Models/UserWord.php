<?php
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;
use PDOException;

class UserWord {
    public const MAX_LENGTH = 100;

    private PDO $db;

    public function __construct() {
        $this->db = (new Database())->getConnection();
    }

    public static function isValid(string $word): bool {
        return mb_strlen($word) <= self::MAX_LENGTH && preg_match("/^[\\p{L}\\p{M}'’-]+$/u", $word) === 1;
    }

    public static function isValidSuggestion(string $suggestion): bool {
        $words = explode(' ', $suggestion);
        return count($words) <= 2 && mb_strlen($suggestion) <= self::MAX_LENGTH
            && array_filter($words, [self::class, 'isValid']) === $words;
    }

    public function allForUser(int $userId): array {
        try {
            $stmt = $this->db->prepare('SELECT word FROM user_words WHERE user_id = :user_id ORDER BY id');
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log('UserWord::allForUser error: ' . $e->getMessage());
            return [];
        }
    }

    public function add(int $userId, string $word): bool {
        try {
            $stmt = $this->db->prepare('INSERT IGNORE INTO user_words (user_id, word) VALUES (:user_id, :word)');
            return $stmt->execute([':user_id' => $userId, ':word' => $word]);
        } catch (PDOException $e) {
            error_log('UserWord::add error: ' . $e->getMessage());
            return false;
        }
    }
}
