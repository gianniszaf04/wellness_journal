<?php
declare(strict_types=1);
namespace App\Services;

class AiClient
{
    public function analyze(string $text): ?array
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

        return self::withoutUntrained($aiData);
    }

    // AI_ALLOW_UNTRAINED=1: κραταμε και τα ανεκπαιδευτα αποτελεσματα (μονο για δοκιμες της ροης)
    public static function allowUntrained(): bool
    {
        return getenv('AI_ALLOW_UNTRAINED') === '1';
    }

    public static function isTrained(array $aiData, string $task): bool
    {
        if (self::allowUntrained()) {
            return true;
        }
        // χωρις τα πεδια (παλιο service) το θεωρουμε ανεκπαιδευτο
        return ($aiData['model_trained'] ?? false) === true
            && in_array($task, is_array($aiData['trained_tasks'] ?? null) ? $aiData['trained_tasks'] : [], true);
    }

    // σβηνει οτι βγαινει απο ανεκπαιδευτα heads (τυχαιες τιμες). Τα entities ερχονται απο το spaCy και μενουν
    public static function withoutUntrained(array $aiData): array
    {
        if (!self::isTrained($aiData, 'sentiment')) {
            unset($aiData['sentiment_score'], $aiData['analysis']['sentiment']);
        }
        if (!self::isTrained($aiData, 'emotion')) {
            unset($aiData['dominant_emotion'], $aiData['emotions'], $aiData['analysis']['emotion']);
        }
        foreach (['irony', 'distortion', 'risk'] as $task) {
            if (!self::isTrained($aiData, $task)) {
                unset($aiData['analysis'][$task]);
            }
        }
        if (!self::isTrained($aiData, 'distortion_types')) {
            unset($aiData['analysis']['distortion']['types'], $aiData['analysis']['distortion']['type_probabilities']);
        }
        return $aiData;
    }

    // P(Ναι) για irony/distortion απο το analysis, null αν λειπει ή δεν ειναι αριθμος στο [0,1]
    public static function probability(array $aiData, string $task): ?float
    {
        return self::unit($aiData['analysis'][$task]['probability'] ?? null);
    }

    // λιστα απο labels (π.χ. emotions, distortion types), μονο τα strings
    public static function labels(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    // label => πιθανοτητα του απο το $probabilities, null αν λειπει ή ειναι εκτος [0,1]
    public static function scored(mixed $labels, mixed $probabilities): array
    {
        $scored = [];
        foreach (self::labels($labels) as $label) {
            $scored[$label] = self::unit(is_array($probabilities) ? ($probabilities[$label] ?? null) : null);
        }
        return $scored;
    }

    private static function unit(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        return ($value >= 0 && $value <= 1) ? round((float) $value, 4) : null;
    }

    public static function detected(array $aiData, string $task): ?bool
    {
        $detected = $aiData['analysis'][$task]['detected'] ?? null;
        return is_bool($detected) ? $detected : null;
    }
}
