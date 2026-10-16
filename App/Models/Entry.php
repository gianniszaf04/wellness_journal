<?php
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class Entry{
    private PDO $db;

    public function __construct(){
        $this->db = (new Database())->getConnection();
    }

    public function create(int $userId, string $body, string $entryDate) : int|false {

        $body= trim($body);
        if($body === '' || strlen($body) > 16777215) {
            return false;
        }

        if(!$this->isValidDate($entryDate)){
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO entries (user_id, body, entry_date)
                VALUES (:user_id, :body, :entry_date)
                ');
            $stmt->execute([
                ':user_id' =>$userId,
                ':body' => $body,
                ':entry_date' => $entryDate,
            ]);
            return (int) $this->db->lastInsertId();
        }catch (\PDOException $e) {
            error_log('Entry::create error: ' . $e->getMessage());
            return false;
        }
    }

    public function findByIdForUser(int $id, int $userId): ?array {
        $stmt=$this->db->prepare(
            'SELECT e.id, e.user_id, e.body, e.sentiment_score, e.dominant_emotion_id,
            e.irony_probability, e.distortion_probability,
            e.irony_probability > 0.5 AS irony_detected, e.distortion_probability > 0.5 AS distortion_detected,
            e.entry_date, e.created_at, e.updated_at,
            em.name AS emotion_name, em.emoji AS emotion_emoji, em.color AS emotion_color
            FROM entries e
            LEFT JOIN emotions em ON em.id = e.dominant_emotion_id
            WHERE e.id = :id AND e.user_id = :user_id 
            LIMIT 1');
            $stmt->execute([':id' => $id, ':user_id' => $userId]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            return $entry ? (new EntryLabel())->attach([$entry])[0] : null;
    }

    public function update(int $id, int $userId,  string $body):bool {
        $body=trim($body);
        if($body === '' || strlen($body) > 16777215) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE entries SET body = :body, updated_at = CURRENT_TIMESTAMP
            WHERE id =:id  AND  user_id = :user_id');
        return $stmt->execute([
            ':body' =>$body,
            ':id' => $id,
            ':user_id' => $userId,
        ]);
    }

    public function delete(int $id, int $userId):bool {
        $stmt = $this->db->prepare(
            'DELETE FROM entries WHERE id = :id AND user_id = :user_id');
            $stmt->execute([':id' => $id ,':user_id' => $userId]);
            // true μονο αν σβηστηκε πραγματικα γραμμη (αλλιως δεν υπαρχει ή ανηκει σε αλλον)
            return $stmt->rowCount() > 0;
    }

    public function allForUser(int $userId, int $limit = 50, int $offset = 0) : array {
        if ($limit < 1 || $limit > 1000 ) {
            $limit = 50;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        $stmt = $this->db->prepare(
            'SELECT e.id, e.user_id, e.body, e.sentiment_score, e.dominant_emotion_id,
            e.irony_probability, e.distortion_probability,
            e.irony_probability > 0.5 AS irony_detected, e.distortion_probability > 0.5 AS distortion_detected,
            e.entry_date, e.created_at, e.updated_at,
            em.name AS emotion_name, em.emoji AS emotion_emoji, em.color AS emotion_color
            FROM entries e
            LEFT JOIN emotions em ON em.id = e.dominant_emotion_id
            WHERE e.user_id = :user_id
            ORDER BY e.created_at DESC 
            LIMIT :lim OFFSET :off
            ');
        
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return (new EntryLabel())->attach($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countForUser(int $userId) : int {
        $stmt=$this->db->prepare('SELECT COUNT(*) FROM entries WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function dailyScores(int $userId, int $days = 30) : array {
        if ($days<1 || $days > 3650) {
            $days=30;
        }
        $stmt = $this->db->prepare(
            'SELECT entry_date AS date ,
            AVG(sentiment_score) AS avg_score,
            COUNT(*) AS count
            FROM entries 
            WHERE user_id = :user_id
            AND sentiment_score IS NOT NULL 
            AND entry_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY entry_date
            ORDER BY entry_date ASC
            ');

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function emotionDistribution(int $userId, int $days = 30) : array {
        if ($days < 1 || $days > 3650) {
            $days = 30;
        }
        $stmt = $this->db->prepare(
            'SELECT em.id, em.name AS emotion, em.emoji, em.color, COUNT(*) AS count
            FROM entries e 
            JOIN emotions em  ON em.id = e.dominant_emotion_id
            WHERE e.user_id = :user_id
            AND e.entry_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY em.id, em.name, em.emoji, em.color
            ORDER BY count DESC
            ');

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function weeklyAverage(int $userId, int $weeks = 12) : array {
        if ($weeks < 1 || $weeks > 260) {
            $weeks = 12 ;
        }
        $stmt = $this->db->prepare(
            'SELECT YEARWEEK(entry_date, 1) AS week_num ,
            MIN(entry_date) AS week_start,
            AVG(sentiment_score) AS avg_score,
            COUNT(*) AS count
            FROM entries 
            WHERE user_id = :user_id
            AND sentiment_score IS NOT NULL 
            AND entry_date >= DATE_SUB(CURDATE(), INTERVAL :weeks WEEK)
            GROUP BY YEARWEEK(entry_date, 1)
            ORDER BY week_num ASC
            ');

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':weeks', $weeks, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function currentStreak(int $userId): int {
        $stmt= $this->db->prepare(
            'SELECT DISTINCT entry_date
            FROM entries WHERE user_id = :user_id
            ORDER BY entry_date DESC 
            LIMIT 365
            ');
            $stmt->execute([':user_id' => $userId]);
            $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if(empty($dates)){
                return 0;
            }

            $streak = 0;
            $checkDate = new \DateTime('today');
            
            if ($dates[0] !== $checkDate->format('Y-m-d')) {
                $checkDate->modify('-1 day');
            }
            
            foreach($dates as $date) {
                if ($date === $checkDate->format('Y-m-d')) {
                    $streak++;
                    $checkDate->modify('-1 day');
                }else {
                    break;
                }
            }
        return $streak;
    }

    public function monthlyComparison(int $userId): array {
        $stmt= $this->db->prepare(
            'SELECT 
            AVG(CASE WHEN entry_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01") THEN sentiment_score END) AS current_avg,
            COUNT(CASE WHEN entry_date >= DATE_FORMAT(CURDATE(), "%Y-%m-01") THEN 1 END) AS current_count,
            AVG(CASE WHEN entry_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), "%Y-%m-01")
            AND entry_date < DATE_FORMAT(CURDATE(), "%Y-%m-01") THEN sentiment_score END) AS previous_avg,
            COUNT(CASE WHEN entry_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), "%Y-%m-01")
            AND entry_date < DATE_FORMAT(CURDATE(), "%Y-%m-01") THEN 1 END) AS previous_count
            FROM entries
            WHERE user_id = :user_id AND sentiment_score IS NOT NULL
            ');

        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $currentAvg = $row['current_avg'] !== null ? round((float) $row['current_avg'], 4) : null;
        $previousAvg = $row['previous_avg'] !== null ? round((float) $row['previous_avg'], 4) : null;
    
        $change = null;
        if ($currentAvg !== null && $previousAvg !== null && $previousAvg != 0) {
            $change = round((($currentAvg - $previousAvg) / abs($previousAvg)) * 100, 1);
        }

        return [
            'current_avg'    => $currentAvg,
            'current_count'  => (int) ($row['current_count'] ?? 0),
            'previous_avg'   => $previousAvg,
            'previous_count' => (int) ($row['previous_count'] ?? 0),
            'change_percent' => $change,
            ];
        }

        public function entriesForDate(int $userId, string $date): array {
        if (!$this->isValidDate($date)) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT e.id, e.user_id, e.body, e.sentiment_score, e.dominant_emotion_id,
            e.irony_probability, e.distortion_probability,
            e.irony_probability > 0.5 AS irony_detected, e.distortion_probability > 0.5 AS distortion_detected,
            e.entry_date, e.created_at, e.updated_at,
            em.name AS emotion_name, em.emoji AS emotion_emoji, em.color AS emotion_color
            FROM entries e
            LEFT JOIN emotions em ON em.id = e.dominant_emotion_id
            WHERE e.user_id = :user_id AND e.entry_date = :date
            ORDER BY e.created_at ASC'
        );
        $stmt->execute([':user_id' => $userId, ':date' => $date]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isValidDate(string $date) : bool {
        $d =\DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

}