<?php
declare(strict_types=1);

// Ψευτικο AI service για τα tests: php -S 127.0.0.1:8000 tests/fake_ai.php (το ξεκιναει το run.php).
// Η απαντηση βγαινει απο το κειμενο: "SCORE=-0.8 EMO=Λύπη ENT=Μαρία,Νίκος IRONY=0.8 DIST=0.3".
// BADPROB -> irony/distortion με μη αριθμητικη πιθανοτητα.

ini_set('serialize_precision', '-1');
header('Content-Type: application/json; charset=utf-8');

$text = (string) (json_decode((string) file_get_contents('php://input'), true)['text'] ?? '');

if (mb_strlen($text) > 10000) {
    http_response_code(422);
    echo '{"detail":"text too long"}';
    return;
}

$find = static fn (string $key, string $pattern): ?string
    => preg_match('/' . $key . '=(' . $pattern . ')/u', $text, $m) === 1 ? $m[1] : null;

$emotion = $find('EMO', '\S+') ?? 'Ηρεμία';
$analysis = ['emotion' => ['probabilities' => [$emotion => 0.9]]];

foreach (['IRONY' => 'irony', 'DIST' => 'distortion'] as $key => $task) {
    $probability = $find($key, '-?[\d.]+');
    if ($probability !== null) {
        $analysis[$task] = ['probability' => (float) $probability];
    } elseif (str_contains($text, 'BADPROB')) {
        $analysis[$task] = ['probability' => 'high'];
    }
}

echo json_encode([
    'success' => true,
    'sentiment_score' => (float) ($find('SCORE', '-?[\d.]+') ?? 0),
    'dominant_emotion' => $emotion,
    'entities' => array_map(
        static fn (string $name): array => ['name' => $name, 'type' => 'person'],
        array_values(array_filter(explode(',', $find('ENT', '\S+') ?? '')))
    ),
    'analysis' => $analysis,
], JSON_UNESCAPED_UNICODE);
