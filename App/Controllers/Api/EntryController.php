<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Models\Entry;
use App\Models\Emotion;
use App\Models\EmotionEntityRelation;
use App\Models\EntryEntity;
use App\Models\DailySummary;
use App\Models\MotivationalMessage;
use App\Models\DailyMessage;
use App\Models\Insight;
use App\Core\Request;

class EntryController
{
    private Entry $entryModel;
    private const SENTIMENT_WARNING_THRESHOLD = -0.5;

    public function __construct()
    {
        $this->entryModel = new Entry();
    }

    public function store(): void
    {
        $userId = (int) current_user_id();
        $request = new Request();

        $body = trim((string) $request->input('body', ''));
        $entryDate = trim((string) $request->input('entry_date', ''));

        if ($body === '') {
            json_response(['success' => false, 'error' => 'Το κείμενο της καταγραφής είναι υποχρεωτικό.'], 422);
        }

        if ($entryDate === '' || !$this->isValidDate($entryDate)) {
            $entryDate = date('Y-m-d');
        }

        $id = $this->entryModel->create($userId, $body, $entryDate);
        if ($id === false) {
            json_response(['success' => false, 'error' => 'Αποτυχία δημιουργίας καταγραφής.'], 500);
        }

        [$sentimentScore, $dominantEmotionId, $emotionConfidence] =
            $this->analyzeAndPersist($id, $userId, $body, $entryDate, false);

        try {
            if ($sentimentScore !== null && $sentimentScore < self::SENTIMENT_WARNING_THRESHOLD) {
                $insightModel = new Insight();
                $insightModel->create(
                    $userId,
                    Insight::TYPE_WARNING,
                    'Ανιχνεύθηκε πολύ χαμηλή συναισθηματική κατάσταση. Πάρε ανάσες προσπάθησε να ηρεμήσεις και φρόντισε τον εαυτό σου.',
                    null,
                    $dominantEmotionId !== null ? (string) $dominantEmotionId : null,
                    $emotionConfidence,
                    2
                );
            }
        } catch (\Exception $e) {
            error_log('Insight creation error: ' . $e->getMessage());
        }

        try {
            if ($dominantEmotionId) {
                $messageModel = new MotivationalMessage();
                $intensity = $this->intensityFromScore($sentimentScore);
                $message = $messageModel->randomForEmotion($dominantEmotionId, $intensity);

                if ($message) {
                    $dailyMessageModel = new DailyMessage();
                    $dailyMessageModel->logShown($userId, $message['id'], $entryDate);
                }
            }
        } catch (\Exception $e) {
            error_log('Daily message assignment error: ' . $e->getMessage());
        }

        $entry = $this->entryModel->findByIdForUser($id, $userId);
        json_response(['success' => true, 'data' => $entry], 201);
    }

    /**
     * Καλει το AI service και κανει persist: sentiment/emotion στην εγγραφη,
     * (επανα)δημιουργια entities και recalculate του daily summary.
     * Επιστρεφει [?float sentimentScore, ?int dominantEmotionId, ?float emotionConfidence].
     *
     * @param bool $replaceEntities Σε edit (true): σβηνει & ξαναγραφει τα entities.
     *        Τα relation_emotion_entity δεν ξαναγραφονται σε edit για να μην γινει
     *        διπλη προσμετρηση (mentioned_count) - μενουν οπως ηταν.
     */
    private function analyzeAndPersist(int $id, int $userId, string $body, string $entryDate, bool $replaceEntities): array
    {
        $sentimentScore = null;
        $dominantEmotionId = null;
        $emotionConfidence = null;
        $entities = [];

        try {
            $aiData = $this->callAiService($body);

            if ($aiData !== null) {
                // score 0 ειναι εγκυρο ουδετερο -> το διακρινουμε απο "λειπει το πεδιο"
                $sentimentScore = isset($aiData['sentiment_score']) ? (float) $aiData['sentiment_score'] : null;

                $emotionName = $aiData['dominant_emotion'] ?? null;
                if ($emotionName) {
                    $emotion = (new Emotion())->findByName($emotionName);
                    if ($emotion) {
                        $dominantEmotionId = (int) $emotion['id'];
                        // confidence = πιθανοτητα του μοντελου για το dominant emotion
                        $prob = $aiData['analysis']['emotion']['probabilities'][$emotionName] ?? null;
                        $emotionConfidence = $prob !== null ? (float) $prob : null;
                    }
                }

                $entities = $aiData['entities'] ?? [];
            }
        } catch (\Exception $e) {
            error_log('AI Service Error: ' . $e->getMessage());
        }

        if ($sentimentScore !== null || $dominantEmotionId !== null) {
            try {
                $db = (new \Database())->getConnection();
                $stmt = $db->prepare(
                    'UPDATE entries SET sentiment_score = :sentiment, dominant_emotion_id = :emotion
                    WHERE id = :id AND user_id = :user_id'
                );

                $stmt->execute([
                    ':sentiment' => $sentimentScore,
                    ':emotion' => $dominantEmotionId,
                    ':id' => $id,
                    ':user_id' => $userId
                ]);
            } catch (\PDOException $e) {
                error_log('Entry sentiment update error: ' . $e->getMessage());
            }
        }

        if ($replaceEntities || !empty($entities)) {
            try {
                $entryEntityModel = new EntryEntity();

                if ($replaceEntities) {
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

                if (!empty($entitiesToInsert)) {
                    $entryEntityModel->createBatch($id, $entitiesToInsert);

                    if (!$replaceEntities) {
                        $relationModel = new EmotionEntityRelation();
                        foreach ($entitiesToInsert as $entity) {
                            $relationModel->upsert(
                                $userId,
                                $entity['name'],
                                $entity['type'],
                                $dominantEmotionId,
                                $sentimentScore
                            );
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log('Entity processing error: ' . $e->getMessage());
            }
        }

        try {
            (new DailySummary())->recalculate($userId, $entryDate);
        } catch (\Exception $e) {
            error_log('Daily summary recalculation error: ' . $e->getMessage());
        }

        return [$sentimentScore, $dominantEmotionId, $emotionConfidence];
    }

    private function intensityFromScore(?float $score): int
    {
        if ($score === null) {
            return 1;
        }
        if ($score <= -0.6) {
            return 3;
        }
        if ($score <= -0.3) {
            return 2;
        }
        return 1;
    }

    private function callAiService(string $text): ?array
    {
        $aiUrl = (string) getenv('AI_SERVICE_URL') ?: 'http://127.0.0.1:8000/analyze';
        $timeout = (int) getenv('AI_SERVICE_TIMEOUT') ?: 4;

        $ch = curl_init($aiUrl);

        if ($ch === false) {
            throw new \Exception('Failed to initialize cURL');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $text], JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if (!empty($curlError)) {
            throw new \Exception("cURL Error: {$curlError}");
        }

        if ($httpCode !== 200) {
            throw new \Exception("AI Service returned HTTP {$httpCode}");
        }

        if (!$response) {
            return null;
        }

        $aiData = json_decode($response, true);

        if (!is_array($aiData) || empty($aiData['success'])) {
            return null;
        }

        return $aiData;
    }

    public function show(string $id): void
    {
        $userId = (int) current_user_id();
        $entry = $this->entryModel->findByIdForUser((int) $id, $userId);

        if ($entry === null) {
            json_response(['success' => false, 'error' => 'Η καταγραφή δεν βρέθηκε.'], 404);
        }

        json_response(['success' => true, 'data' => $entry]);
    }

    public function update(string $id): void
    {
        $userId = (int) current_user_id();
        $entryId = (int) $id;
        $request = new Request();

        $existing = $this->entryModel->findByIdForUser($entryId, $userId);
        if ($existing === null) {
            json_response(['success' => false, 'error' => 'Η καταγραφή δεν βρέθηκε.'], 404);
        }

        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            json_response(['success' => false, 'error' => 'Το κείμενο της καταγραφής είναι υποχρεωτικό.'], 422);
        }

        $this->entryModel->update($entryId, $userId, $body);

        // Ξανατρεχουμε AI ωστε sentiment/emotion/entities/summary να μην μεινουν stale απο το παλιο κειμενο
        $entryDate = (string) ($existing['entry_date'] ?? date('Y-m-d'));
        $this->analyzeAndPersist($entryId, $userId, $body, $entryDate, true);

        $entry = $this->entryModel->findByIdForUser($entryId, $userId);

        json_response(['success' => true, 'data' => $entry]);
    }

    public function destroy(string $id): void
    {
        $userId = (int) current_user_id();
        $entryId = (int) $id;

        // Κραταμε το entry_date πριν τη διαγραφη για να ξαναυπολογισουμε το daily summary
        $existing = $this->entryModel->findByIdForUser($entryId, $userId);

        $deleted = $this->entryModel->delete($entryId, $userId);

        if (!$deleted) {
            json_response(['success' => false, 'error' => 'Η καταγραφή δεν βρέθηκε.'], 404);
        }

        if ($existing !== null) {
            try {
                (new DailySummary())->recalculate($userId, (string) $existing['entry_date']);
            } catch (\Exception $e) {
                error_log('Daily summary recalculation error: ' . $e->getMessage());
            }
        }

        json_response(['success' => true, 'message' => 'Η καταγραφή διαγράφηκε.']);
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
