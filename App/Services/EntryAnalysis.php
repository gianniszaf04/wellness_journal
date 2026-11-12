<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\DailySummary;
use App\Models\Emotion;
use App\Models\EmotionEntityRelation;
use App\Models\EntryEntity;
use App\Models\EntryLabel;
use App\Models\UserWord;

class EntryAnalysis
{
    /**
     * Καλει το AI service και κανει persist: sentiment/emotion στην εγγραφη,
     * (επανα)δημιουργια entities, emotions/distortion types, relations και daily summary.
     * Επιστρεφει [?float sentimentScore, ?int dominantEmotionId, ?float emotionConfidence, bool aiAvailable, ?float riskProbability].
     *
     * @param bool $replaceEntities Σε edit (true): σβηνει & ξαναγραφει τα entities.
     */
    public function run(int $id, int $userId, string $body, string $entryDate, bool $replaceEntities): array
    {
        $result = $this->analyze($userId, $body);
        $this->persist($id, $userId, $result, $replaceEntities);
        $this->refreshSummary($userId, $entryDate);

        return [
            $result['sentiment_score'],
            $result['dominant_emotion_id'],
            $result['emotion_confidence'],
            $result['ai_available'],
            $result['risk_probability'],
        ];
    }

    private function analyze(int $userId, string $body): array
    {
        $result = [
            'ai_available' => false,
            'sentiment_score' => null,
            'dominant_emotion_id' => null,
            'emotion_confidence' => null,
            'entities' => [],
            'irony_probability' => null,
            'distortion_probability' => null,
            'irony_detected' => null,
            'distortion_detected' => null,
            'risk_probability' => null,
            'emotions' => [],
            'distortion_types' => [],
        ];

        try {
            $aiData = (new AiClient())->analyze($body, (new UserWord())->allForUser($userId));
            if ($aiData === null) {
                return $result;
            }
            $result['ai_available'] = true;

            // score 0 ειναι εγκυρο ουδετερο -> το διακρινουμε απο "λειπει το πεδιο"
            $result['sentiment_score'] = isset($aiData['sentiment_score']) ? (float) $aiData['sentiment_score'] : null;

            $emotionName = $aiData['dominant_emotion'] ?? null;
            if ($emotionName) {
                $emotion = (new Emotion())->findByName($emotionName);
                if ($emotion) {
                    $result['dominant_emotion_id'] = (int) $emotion['id'];
                    // confidence = πιθανοτητα του μοντελου για το dominant emotion
                    $prob = $aiData['analysis']['emotion']['probabilities'][$emotionName] ?? null;
                    $result['emotion_confidence'] = $prob !== null ? (float) $prob : null;
                }
            }

            $result['entities'] = $aiData['entities'] ?? [];
            $result['irony_probability'] = AiClient::probability($aiData, 'irony');
            $result['distortion_probability'] = AiClient::probability($aiData, 'distortion');
            $result['irony_detected'] = AiClient::detected($aiData, 'irony');
            $result['distortion_detected'] = AiClient::detected($aiData, 'distortion');
            $result['risk_probability'] = AiClient::probability($aiData, 'risk');
            $result['emotions'] = AiClient::scored($aiData['emotions'] ?? null, $aiData['analysis']['emotion']['probabilities'] ?? null);
            $result['distortion_types'] = AiClient::scored(
                $aiData['analysis']['distortion']['types'] ?? null,
                $aiData['analysis']['distortion']['type_probabilities'] ?? null
            );
        } catch (\Exception $e) {
            error_log('AI Service Error: ' . $e->getMessage());
        }

        return $result;
    }

    private function persist(int $id, int $userId, array $result, bool $replaceEntities): void
    {
        $db = null;
        try {
            $db = (new \Database())->getConnection();
            $db->beginTransaction();

            // σε edit γραφουμε παντα, ωστε αν το AI ειναι κατω να μη μεινει η αναλυση του παλιου κειμενου
            if ($replaceEntities || $result['sentiment_score'] !== null || $result['dominant_emotion_id'] !== null) {
                $stmt = $db->prepare(
                    'UPDATE entries SET sentiment_score = :sentiment, dominant_emotion_id = :emotion,
                    irony_probability = :irony, distortion_probability = :distortion,
                    irony_detected = :irony_detected, distortion_detected = :distortion_detected
                    WHERE id = :id AND user_id = :user_id'
                );

                $stmt->execute([
                    ':sentiment' => $result['sentiment_score'],
                    ':emotion' => $result['dominant_emotion_id'],
                    ':irony' => $result['irony_probability'],
                    ':distortion' => $result['distortion_probability'],
                    ':irony_detected' => $result['irony_detected'] === null ? null : (int) $result['irony_detected'],
                    ':distortion_detected' => $result['distortion_detected'] === null ? null : (int) $result['distortion_detected'],
                    ':id' => $id,
                    ':user_id' => $userId
                ]);
            }

            if ($replaceEntities || $result['emotions'] !== [] || $result['distortion_types'] !== []) {
                (new EntryLabel())->replace($id, $result['emotions'], $result['distortion_types']);
            }

            if ($replaceEntities || !empty($result['entities'])) {
                $this->replaceEntities($id, $userId, $result['entities'], $replaceEntities);
            }

            $db->commit();
        } catch (\Exception $e) {
            if ($db !== null && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Entry analysis persist error: ' . $e->getMessage());
        }
    }

    private function replaceEntities(int $id, int $userId, array $entities, bool $deleteOld): void
    {
        $entryEntityModel = new EntryEntity();
        $previous = [];

        if ($deleteOld) {
            $previous = $entryEntityModel->findForEntry($id);
            $entryEntityModel->deleteForEntry($id);
        }

        $entitiesToInsert = [];
        foreach ($entities as $entity) {
            if (isset($entity['name'], $entity['type'])) {
                $entitiesToInsert[] = [
                    'name' => $entity['name'],
                    'type' => $entity['type']
                ];
            }
        }

        if (!$entryEntityModel->createBatch($id, $entitiesToInsert)) {
            throw new \RuntimeException('EntryEntity::createBatch failed');
        }

        // και τα παλια entities, για να βγει απο τα relations ο,τι δεν αναφερεται πια
        (new EmotionEntityRelation())->recalculate(
            $userId,
            array_merge($previous, $entryEntityModel->findForEntry($id))
        );
    }

    private function refreshSummary(int $userId, string $entryDate): void
    {
        try {
            (new DailySummary())->recalculate($userId, $entryDate);
        } catch (\Exception $e) {
            error_log('Daily summary recalculation error: ' . $e->getMessage());
        }
    }
}
