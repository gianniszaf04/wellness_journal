<?php
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

class DailyMessage{
    private PDO $db;

    public function __construct() {
        $this->db=(new Database())->getConnection();
    }

    public function logShown(int $userId, int $messageId, string $date): bool {
        $stmt = $this->db->prepare(
            'INSERT INTO daily_messages (user_id, message_id, summary_date, shown_at)
            VALUES (:user_id, :message_id, :date, NOW())
            ON DUPLICATE KEY UPDATE shown_at= NOW()');

            return  $stmt->execute([
                ':user_id'=> $userId,
                ':message_id'=>$messageId,
                ':date'    => $date,
            ]);
    }

    // false αν δεν υπαρχει ή ανηκει σε αλλον
    public function markSeen(int $id, int $userId): bool {
        $check = $this->db->prepare('SELECT 1 FROM daily_messages WHERE id = :id AND user_id = :user_id');
        $check->execute([':id' => $id, ':user_id' => $userId]);
        if ($check->fetchColumn() === false) {
            return false;
        }

        $stmt=$this->db->prepare(
        'UPDATE daily_messages SET user_saw_message = 1, shown_at = NOW()
        WHERE id = :id AND user_id = :user_id
        ');

        return $stmt->execute([':id' => $id, ':user_id'=>$userId]);
    }

    public function unseenForUser(int $userId):array{
        $stmt=$this->db->prepare(
        'SELECT  dm.id, dm.user_id, dm.message_id, dm.summary_date,
                dm.user_saw_message, dm.shown_at, dm.created_at,
                mm.message_text, mm.intensity_level,
                e.name AS emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
                FROM daily_messages dm
                JOIN motivational_messages mm ON mm.id = dm.message_id
                JOIN emotions e  ON e.id = mm.target_emotion
                WHERE  dm.user_id = :user_id AND dm.user_saw_message = 0
                ORDER BY dm.created_at DESC
        ');
        $stmt->execute([':user_id'=>$userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function todayForUser(int $userId) : array{
        $stmt=$this->db->prepare('SELECT  dm.id, dm.user_id, dm.message_id, dm.summary_date,
                dm.user_saw_message, dm.shown_at, dm.created_at,
                mm.message_text, mm.intensity_level,
                e.name AS emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
                FROM daily_messages dm
                JOIN motivational_messages mm ON mm.id = dm.message_id
                JOIN emotions e  ON e.id = mm.target_emotion
                WHERE  dm.user_id = :user_id AND dm.summary_date = CURDATE()
                ORDER BY dm.shown_at DESC, dm.created_at DESC
        ');
    $stmt->execute([':user_id'=>$userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function exists(int $userId, int $messageId, string $date):bool {
        $stmt=$this->db->prepare(
            'SELECT 1 FROM daily_messages WHERE  user_id = :user_id AND message_id = :message_id AND summary_date = :date LIMIT 1');

            $stmt->execute([
                ':user_id' => $userId,
                ':message_id' => $messageId,
                ':date'      => $date,
            ]);
        return $stmt->fetch() !== false;
    }

    public function cleanUpOld(int $daysOld=30): int {
        $stmt=$this->db->prepare('DELETE FROM daily_messages WHERE   created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');

        $stmt->execute([':days' => $daysOld]);

        return $stmt->rowCount();
    }

    public function byDateForUser(int $userId, string $date):array {
        $stmt=$this->db->prepare('SELECT  dm.id, dm.user_id, dm.message_id, dm.summary_date,
                dm.user_saw_message, dm.shown_at, dm.created_at,
                mm.message_text, mm.intensity_level,
                e.name AS emotion_name, e.emoji AS emotion_emoji, e.color AS emotion_color
                FROM daily_messages dm
                JOIN motivational_messages mm ON mm.id = dm.message_id
                JOIN emotions e  ON e.id = mm.target_emotion
                WHERE  dm.user_id = :user_id AND dm.summary_date = :date
                ORDER BY dm.user_saw_message ASC , dm.shown_at DESC
        ');
    $stmt->execute([':user_id'=>$userId, ':date'=>$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}