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

        return $aiData;
    }

    // P(Ναι) για irony/distortion απο το analysis, null αν λειπει ή δεν ειναι αριθμος στο [0,1]
    public static function probability(array $aiData, string $task): ?float
    {
        $value = $aiData['analysis'][$task]['probability'] ?? null;

        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        return ($value >= 0 && $value <= 1) ? round((float) $value, 4) : null;
    }

    // ιδιο κριτηριο με το Python (argmax σε δυο κλασεις)
    public static function detected(?float $probability): ?bool
    {
        return $probability === null ? null : $probability > 0.5;
    }
}
