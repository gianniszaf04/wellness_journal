<?php 
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class DailySummary {
    private PDO $db;

    // μεσος ορος απο ολες τις εγγραφες της ημερας (το AVG αγνοει τα NULL)
    private const AVG_SENTIMENT_QUERY =
    'SELECT AVG(sentiment_score) AS avg_sentiment_score
    FROM entries
    WHERE user_id = :user_id
    AND entry_date = :date';

    // συχνοτερο συναισθημα της ημερας - σε ισοπαλια κερδιζει το πιο προσφατο
    private const DOMINANT_EMOTION_QUERY =
    'SELECT dominant_emotion_id
    FROM entries
    WHERE user_id = :user_id
    AND entry_date = :date
    AND dominant_emotion_id IS NOT NULL
    GROUP BY dominant_emotion_id
    ORDER BY COUNT(*) DESC, MAX(id) DESC
    LIMIT 1';

    public function __construct() {
        $this->db = (new Database())->getConnection();
    }

    public function getOrCreate(int $userId, string $date): array {
        $summary = $this->findByUserAndDate($userId, $date);

        if ($summary === null) {
            $this->create($userId,$date);
            $summary=$this->findByUserAndDate($userId,$date);
        }

    return $summary ?? [];
    }

    public function findByUserAndDate(int $userId, string $date): ?array {
        $stmt=$this->db->prepare(
        'SELECT ds.*, e.name AS emotion_name,
        e.emoji AS emotion_emoji,
        e.color AS emotion_color
        FROM  daily_summaries ds
        LEFT JOIN emotions e on e.id=ds.dominant_emotion_id
        WHERE ds.user_id=:user_id
        AND ds.summary_date=:date
        LIMIT 1');

        $stmt->execute([':user_id'=> $userId,':date'=>$date]);
        $result=$stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    private function create(int $userId, string $date): bool {
        $stmt= $this->db->prepare(
        'INSERT INTO daily_summaries (user_id, summary_date) VALUES (:user_id, :date)');

        return $stmt->execute([':user_id'=>$userId, ':date'=>$date]);
    }

    public function updateSummary(
        int $userId,
        string $date,
        ?float $avgSentimentScore,
        ?int $dominantEmotionId,
        int $totalEntriesCount,
        ?string $summaryText = null 
    ):bool {
        if ($avgSentimentScore !== null && ($avgSentimentScore < -1.0 || $avgSentimentScore > 1.0)){
            return false ;
        }

        if($totalEntriesCount < 0) {
            return false;
        }

        $stmt=$this->db->prepare(
            'UPDATE daily_summaries
            SET avg_sentiment_score = :avg_sentiment_score,
                dominant_emotion_id = :dominant_emotion_id,
                total_entries_count = :total_entries_count,
                summary_text = :summary_text,
                summary_is_done=1
                WHERE user_id = :user_id
                AND summary_date = :date');

            return $stmt->execute([
                ':avg_sentiment_score'  =>$avgSentimentScore,
                ':dominant_emotion_id'  => $dominantEmotionId,
                ':total_entries_count'  => $totalEntriesCount,
                ':summary_text'         => $summaryText,
                ':user_id'              => $userId,
                ':date'                 => $date,
            ]);
    }

    public function recalculate(int $userId, string $date): void {
        $params = [':user_id' => $userId, ':date' => $date];

        $stmt = $this->db->prepare(self::AVG_SENTIMENT_QUERY);
        $stmt->execute($params);
        $avg = $stmt->fetchColumn();

        $stmt = $this->db->prepare(self::DOMINANT_EMOTION_QUERY);
        $stmt->execute($params);
        $emotionId = $stmt->fetchColumn();

        $totalEntriesCount = $this->getTotalEntriesCount($userId,$date);
        $this->getOrCreate($userId,$date);

        $this->updateSummary(
            $userId,
            $date,
            $avg !== null && $avg !== false ? round((float) $avg, 4) : null,
            $emotionId !== null && $emotionId !== false ? (int) $emotionId : null,
            $totalEntriesCount
        );
    }

    public function getTotalEntriesCount(int $userId, string $date):int {
        $stmt=$this->db->prepare(
        'SELECT COUNT(*) FROM entries
        WHERE user_id= :user_id
        AND entry_date= :date
        ');
        $stmt->execute([':user_id' => $userId,':date'=>$date]);

        return (int) $stmt->fetchColumn();
    }

    public function rangeForUser(int $userId, string $from, string $to):array {
        $stmt=$this->db->prepare(
            'SELECT ds.*,
                e.name AS emotion_name,
                e.emoji AS emotion_emoji,
                e.color AS emotion_color
            FROM daily_summaries ds
            LEFT JOIN emotions e ON e.id=ds.dominant_emotion_id
            WHERE ds.user_id = :user_id
            AND ds.summary_date BETWEEN :from_date AND :to_date
            ORDER BY ds.summary_date ASC
            ');
            $stmt->execute([
                ':user_id'=>$userId,
                ':from_date'=>$from,
                ':to_date'=>$to
            ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getMonthlyStats(int $userId, int $year, int $month):array {
        $startDate=sprintf('%04d-%02d-01',$year,$month);
        $endDate = date('Y-m-t',strtotime($startDate));


        $stmt=$this->db->prepare(
            'SELECT 
            AVG(avg_sentiment_score) AS month_avg_sentiment,
            MAX(avg_sentiment_score) AS month_max_sentiment,
            MIN(avg_sentiment_score) AS month_min_sentiment,
            SUM(total_entries_count) AS month_total_entries,
            COUNT(*) AS days_with_summaries
            FROM daily_summaries
            WHERE user_id = :user_id
            AND summary_date BETWEEN :start_date AND :end_date
            ');

            $stmt->execute([
                ':user_id'=>$userId,
                ':start_date'=>$startDate,
                ':end_date'=>$endDate
            ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function getIncompleteSummaries(int $userId, ?string $fromDate = null): array
    {
        $query = 'SELECT ds.id, ds.summary_date
                 FROM daily_summaries ds
                 WHERE ds.user_id = :user_id
                 AND ds.summary_is_done = 0';
        
        if ($fromDate !== null) {
            $query .= ' AND ds.summary_date >= :from_date';
        }
        
        $query .= ' ORDER BY ds.summary_date DESC';

        $stmt = $this->db->prepare($query);
        $params = [':user_id' => $userId];
        
        if ($fromDate !== null) {
            $params[':from_date'] = $fromDate;
        }
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}