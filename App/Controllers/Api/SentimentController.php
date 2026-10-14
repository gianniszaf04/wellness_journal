<?php
declare(strict_types=1);
namespace App\Controllers\Api;

use App\Core\Request;
use App\Models\Emotion;
use App\Services\AiClient;

class SentimentController
{
    // κατω απο τοσους χαρακτηρες το κειμενο ειναι πολυ μικρο για αξιοπιστη αναλυση
    private const MIN_LENGTH = 20;
    private const MAX_LENGTH = 10000;

    /**
     * Ζωντανη αναλυση οσο ο χρηστης γραφει. Δεν αποθηκευει τιποτα.
     */
    public function analyzeLive(): void
    {
        $request = new Request();
        $text = trim((string) $request->input('text', ''));

        if (mb_strlen($text) < self::MIN_LENGTH) {
            json_response(['success' => true, 'data' => null]);
        }

        $text = mb_substr($text, 0, self::MAX_LENGTH);

        try {
            $aiData = (new AiClient())->analyze($text);
        } catch (\Exception $e) {
            error_log('Live sentiment error: ' . $e->getMessage());
            $aiData = null;
        }

        if ($aiData === null) {
            json_response(['success' => true, 'data' => null, 'ai_available' => false]);
        }

        $emotionName = $aiData['dominant_emotion'] ?? null;
        $emotion = $emotionName ? (new Emotion())->findByName($emotionName) : null;
        $confidence = $emotionName !== null
            ? ($aiData['analysis']['emotion']['probabilities'][$emotionName] ?? null)
            : null;

        json_response([
            'success' => true,
            'data' => [
                'sentiment_score' => isset($aiData['sentiment_score']) ? round((float) $aiData['sentiment_score'], 4) : null,
                'emotion' => $emotion ? [
                    'name' => $emotion['name'],
                    'emoji' => $emotion['emoji'],
                    'color' => $emotion['color'],
                    'confidence' => $confidence !== null ? round((float) $confidence, 4) : null,
                ] : null,
            ],
        ]);
    }
}
