<?php
declare(strict_types=1);
namespace App\Models;

use Database;
use PDO;

// τα multi-label του AI ανα καταγραφη: entry_emotions και entry_distortions
class EntryLabel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = (new Database())->getConnection();
    }

    // ονομα => πιθανοτητα (ή null). Σβηνει τα παλια, τα αγνωστα ονοματα αγνοουνται
    public function replace(int $entryId, array $emotions, array $distortionTypes): void
    {
        foreach (['entry_emotions', 'entry_distortions'] as $table) {
            $this->db->prepare("DELETE FROM {$table} WHERE entry_id = :entry_id")->execute([':entry_id' => $entryId]);
        }

        $this->insert($entryId, $emotions,
            'INSERT IGNORE INTO entry_emotions (entry_id, emotion_id, probability)
            SELECT :entry_id, id, :probability FROM emotions WHERE processed_name = LOWER(TRIM(:name))');
        $this->insert($entryId, $distortionTypes,
            'INSERT IGNORE INTO entry_distortions (entry_id, distortion_type_id, probability)
            SELECT :entry_id, id, :probability FROM distortion_types WHERE name = TRIM(:name)');
    }

    // προσθετει σε καθε καταγραφη τα 'emotions' και 'distortion_types' της
    public function attach(array $entries): array
    {
        $ids = array_map(static fn (array $e): int => (int) $e['id'], $entries);
        $emotions = $this->forEntries($ids,
            'SELECT ee.entry_id, em.name, em.emoji, em.color, ee.probability
            FROM entry_emotions ee JOIN emotions em ON em.id = ee.emotion_id
            WHERE ee.entry_id IN (%s) ORDER BY ee.probability DESC, em.id');
        $types = $this->forEntries($ids,
            'SELECT ed.entry_id, dt.name, ed.probability
            FROM entry_distortions ed JOIN distortion_types dt ON dt.id = ed.distortion_type_id
            WHERE ed.entry_id IN (%s) ORDER BY ed.probability DESC, dt.id');

        foreach ($entries as &$entry) {
            $entry['emotions'] = $emotions[(int) $entry['id']] ?? [];
            $entry['distortion_types'] = $types[(int) $entry['id']] ?? [];
        }
        unset($entry);

        return $entries;
    }

    private function insert(int $entryId, array $probabilities, string $sql): void
    {
        $stmt = $this->db->prepare($sql);
        foreach ($probabilities as $name => $probability) {
            $stmt->execute([':entry_id' => $entryId, ':probability' => $probability, ':name' => (string) $name]);
        }
    }

    // entry_id => γραμμες χωρις το entry_id
    private function forEntries(array $ids, string $sql): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
        $stmt->execute($ids);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entryId = (int) $row['entry_id'];
            unset($row['entry_id']);
            $grouped[$entryId][] = $row;
        }
        return $grouped;
    }
}
