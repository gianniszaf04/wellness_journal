<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Models\Entry;
use App\Models\EmotionEntityRelation;
use App\Models\EntryEntity;
use App\Models\DailySummary;
use App\Models\MotivationalMessage;
use App\Models\DailyMessage;
use App\Models\Insight;
use App\Core\Request;
use App\Services\CrisisSupport;
use App\Services\EntryAnalysis;

class EntryController
{
    private Entry $entryModel;
    // προεπιλογη αν το SENTIMENT_WARNING_THRESHOLD λειπει ή ειναι λαθος στο .env
    private const DEFAULT_WARNING_THRESHOLD = -0.5;
    private const MAX_BODY_LENGTH = 10000;

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

        $this->validateBody($body);

        if ($entryDate === '' || !$this->isValidDate($entryDate)) {
            $entryDate = date('Y-m-d');
        }

        release_session();

        $id = $this->entryModel->create($userId, $body, $entryDate);
        if ($id === false) {
            json_response(['success' => false, 'error' => 'Αποτυχία δημιουργίας καταγραφής.'], 500);
        }

        [$sentimentScore, $dominantEmotionId, $emotionConfidence, $aiAvailable, $riskProbability] =
            (new EntryAnalysis())->run($id, $userId, $body, $entryDate, false);
        $support = CrisisSupport::forEntry($body, $riskProbability, $sentimentScore, $this->warningThreshold());

        try {
            $insightModel = new Insight();
            // ενα warning την ημερα αρκει, αλλιως γεμιζει σε καθε αρνητικη καταγραφη
            if ($sentimentScore !== null && $sentimentScore < $this->warningThreshold()
                && !$insightModel->existsTodayOfType($userId, Insight::TYPE_WARNING)) {
                $insightModel->create(
                    $userId,
                    Insight::TYPE_WARNING,
                    'Ανιχνεύθηκε πολύ χαμηλή συναισθηματική κατάσταση. Πάρε ανάσες, προσπάθησε να ηρεμήσεις και φρόντισε τον εαυτό σου.',
                    null,
                    $dominantEmotionId !== null ? (string) $dominantEmotionId : null,
                    $emotionConfidence,
                    2
                );
            }
        } catch (\Exception $e) {
            error_log('Insight creation error: ' . $e->getMessage());
        }

        $calmMessage = null;
        try {
            // σε κριση δεν ταιριαζει ενα ανεμελο motivational μηνυμα, μενει μονο το support
            if ($dominantEmotionId && ($support['level'] ?? null) !== CrisisSupport::LEVEL_CRISIS) {
                $messageModel = new MotivationalMessage();
                $intensity = $this->intensityFromScore($sentimentScore);
                $message = $messageModel->randomForEmotion($dominantEmotionId, $intensity);

                if ($message) {
                    $dailyMessageModel = new DailyMessage();
                    $dailyMessageModel->logShown($userId, (int) $message['id'], $entryDate);
                    // η γραμμη του daily_messages, για να τη σημειωσει ο client ως διαβασμενη
                    foreach ($dailyMessageModel->byDateForUser($userId, $entryDate) as $row) {
                        if ((int) $row['message_id'] === (int) $message['id']) {
                            $calmMessage = $row;
                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('Daily message assignment error: ' . $e->getMessage());
        }

        $entry = $this->entryModel->findByIdForUser($id, $userId);
        json_response([
            'success' => true,
            'data' => $entry,
            'message' => $calmMessage,
            'support' => $support,
            'ai_available' => $aiAvailable,
        ], 201);
    }

    // ιδιο οριο με το AI service, αλλιως η καταγραφη σωζεται χωρις αναλυση
    private function validateBody(string $body): void
    {
        if ($body === '') {
            json_response(['success' => false, 'error' => 'Το κείμενο της καταγραφής είναι υποχρεωτικό.'], 422);
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            json_response(['success' => false, 'error' => 'Το κείμενο ξεπερνά τους ' . self::MAX_BODY_LENGTH . ' χαρακτήρες.'], 422);
        }
    }

    private function warningThreshold(): float
    {
        $value = getenv('SENTIMENT_WARNING_THRESHOLD');

        if ($value === false || !is_numeric(trim($value))) {
            return self::DEFAULT_WARNING_THRESHOLD;
        }

        $threshold = (float) $value;
        // το sentiment score ειναι παντα στο [-1, 1]
        if ($threshold < -1.0 || $threshold > 1.0) {
            return self::DEFAULT_WARNING_THRESHOLD;
        }

        return $threshold;
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

    // GET /api/entries?page=1&per_page=20
    public function index(): void
    {
        $userId = (int) current_user_id();
        $perPage = query_int('per_page', 20, 1, 100);
        $total = $this->entryModel->countForUser($userId);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = query_int('page', 1, 1, PHP_INT_MAX);

        json_response([
            'success' => true,
            'data' => $page <= $totalPages ? $this->entryModel->allForUser($userId, $perPage, ($page - 1) * $perPage) : [],
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages],
        ]);
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
        $this->validateBody($body);

        release_session();

        $this->entryModel->update($entryId, $userId, $body);

        // Ξανατρεχουμε AI ωστε sentiment/emotion/entities/summary να μην μεινουν stale απο το παλιο κειμενο
        $entryDate = (string) ($existing['entry_date'] ?? date('Y-m-d'));
        [$sentimentScore, , , $aiAvailable, $riskProbability] = (new EntryAnalysis())->run($entryId, $userId, $body, $entryDate, true);
        $support = CrisisSupport::forEntry($body, $riskProbability, $sentimentScore, $this->warningThreshold());

        $entry = $this->entryModel->findByIdForUser($entryId, $userId);

        json_response(['success' => true, 'data' => $entry, 'support' => $support, 'ai_available' => $aiAvailable]);
    }

    public function destroy(string $id): void
    {
        $userId = (int) current_user_id();
        $entryId = (int) $id;

        // Κραταμε entry_date και entities πριν τη διαγραφη για να ξαναυπολογισουμε summary και relations
        $existing = $this->entryModel->findByIdForUser($entryId, $userId);
        $entities = $existing !== null ? (new EntryEntity())->findForEntry($entryId) : [];

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
            try {
                (new EmotionEntityRelation())->recalculate($userId, $entities);
            } catch (\Exception $e) {
                error_log('Relation recalculation error: ' . $e->getMessage());
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
