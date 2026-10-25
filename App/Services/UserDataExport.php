<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\EmotionEntityRelation;
use App\Models\EntryLabel;
use App\Models\Insight;
use App\Models\UserProfile;
use Database;
use PDO;

// ολα τα δεδομενα ενος χρηστη σε ενα array, για ληψη ως JSON (χωρις το hash του κωδικου)
class UserDataExport
{
    private PDO $db;

    public function __construct()
    {
        $this->db = (new Database())->getConnection();
    }

    public function forUser(int $userId): array
    {
        $profileModel = new UserProfile();
        $profile = $profileModel->getOrCreate($userId);

        return [
            'exported_at' => date(DATE_ATOM),
            'user' => $this->fetchOne(
                'SELECT id, username, name, email, email_verified_at, status, deletion_requested_at, created_at, updated_at
                FROM users WHERE id = :user_id', $userId),
            'profile' => [
                'theme' => $profile['theme_background'] ?? null,
                'sound' => $profile['sound'] ?? null,
                'preferences' => (object) $profileModel->getPreferences($userId),
            ],
            'entries' => $this->entries($userId),
            'insights' => (new Insight())->allForUser($userId),
            'daily_summaries' => $this->fetchAll(
                'SELECT ds.*, e.name AS emotion_name FROM daily_summaries ds
                LEFT JOIN emotions e ON e.id = ds.dominant_emotion_id
                WHERE ds.user_id = :user_id ORDER BY ds.summary_date', $userId),
            'daily_messages' => $this->fetchAll(
                'SELECT dm.id, dm.summary_date, dm.user_saw_message, dm.shown_at, dm.created_at, mm.message_text
                FROM daily_messages dm JOIN motivational_messages mm ON mm.id = dm.message_id
                WHERE dm.user_id = :user_id ORDER BY dm.summary_date, dm.id', $userId),
            'entity_relations' => (new EmotionEntityRelation())->allForUser($userId),
        ];
    }

    // καθε καταγραφη μαζι με τα entities, emotions και distortion types της
    private function entries(int $userId): array
    {
        $entries = $this->fetchAll(
            'SELECT e.*, em.name AS emotion_name FROM entries e
            LEFT JOIN emotions em ON em.id = e.dominant_emotion_id
            WHERE e.user_id = :user_id ORDER BY e.entry_date, e.id', $userId);

        $entities = [];
        foreach ($this->fetchAll(
            'SELECT ee.entry_id, ee.entity_name, ee.entity_type FROM entry_entities ee
            JOIN entries e ON e.id = ee.entry_id
            WHERE e.user_id = :user_id ORDER BY ee.id', $userId) as $row) {
            $entities[$row['entry_id']][] = ['name' => $row['entity_name'], 'type' => $row['entity_type']];
        }

        foreach ($entries as &$entry) {
            $entry['entities'] = $entities[$entry['id']] ?? [];
        }
        unset($entry);

        return (new EntryLabel())->attach($entries);
    }

    private function fetchAll(string $sql, int $userId): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchOne(string $sql, int $userId): ?array
    {
        return $this->fetchAll($sql, $userId)[0] ?? null;
    }
}
