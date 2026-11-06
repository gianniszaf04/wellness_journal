<?php
declare(strict_types=1);

// Ψευτικο AI service για τα tests: php -S 127.0.0.1:8000 tests/fake_ai.php (το ξεκιναει το run.php).
// Η απαντηση βγαινει απο το κειμενο: "SCORE=-0.8 EMO=Λύπη EMOS=Λύπη,Άγχος ENT=Μαρία,Νίκος IRONY=0.8 DIST=0.3
// TYPES=Καταστροφολογία RISK=0.6 SPELL=πεπατημα>περπάτημα|πατήματα". BADPROB -> irony/distortion/risk με μη αριθμητικη πιθανοτητα.
// Προεπιλογη: εκπαιδευμενο μοντελο σε ολα τα tasks. UNTRAINED -> model_trained=false,
// TRAINED=sentiment,emotion -> εκπαιδευμενα μονο αυτα.

ini_set('serialize_precision', '-1');
header('Content-Type: application/json; charset=utf-8');

$input = json_decode((string) file_get_contents('php://input'), true);
$text = (string) ($input['text'] ?? '');
$knownWords = array_map('mb_strtolower', is_array($input['known_words'] ?? null) ? $input['known_words'] : []);

if (mb_strlen($text) > 10000) {
    http_response_code(422);
    echo '{"detail":"text too long"}';
    return;
}

$find = static fn (string $key, string $pattern): ?string
    => preg_match('/' . $key . '=(' . $pattern . ')/u', $text, $m) === 1 ? $m[1] : null;

$list = static fn (string $key): array => array_values(array_filter(explode(',', $find($key, '\S+') ?? '')));

// EMO με 0.9, τα υπολοιπα του EMOS με 0.7
$emotion = $find('EMO', '\S+') ?? 'Ηρεμία';
$analysis = ['emotion' => ['probabilities' => [$emotion => 0.9] + array_fill_keys($list('EMOS'), 0.7)]];

foreach (['IRONY' => 'irony', 'DIST' => 'distortion', 'RISK' => 'risk'] as $key => $task) {
    $probability = $find($key, '-?[\d.]+');
    if ($probability !== null) {
        $analysis[$task] = ['probability' => (float) $probability];
    } elseif (str_contains($text, 'BADPROB')) {
        $analysis[$task] = ['probability' => 'high'];
    }
}

// τα TYPES με φθινουσα πιθανοτητα 0.9, 0.8, ... οπως τα ταξινομει το Python
if (isset($analysis['distortion'])) {
    $types = $list('TYPES');
    $analysis['distortion']['types'] = $types;
    $analysis['distortion']['type_probabilities'] = array_combine($types, array_map(
        static fn (int $i): float => round(0.9 - 0.1 * $i, 1),
        array_keys($types)
    ));
}

$spelling = [];
foreach ($list('SPELL') as $item) {
    [$word, $suggestions] = array_pad(explode('>', $item, 2), 2, '');
    if (in_array(mb_strtolower($word), $knownWords, true)
        || preg_match('/(?<![\p{L}=,])' . preg_quote($word, '/') . '(?![\p{L}>])/u', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
        continue;
    }
    $start = mb_strlen(substr($text, 0, $m[0][1]));
    $spelling[] = ['word' => $word, 'start' => $start, 'end' => $start + mb_strlen($word), 'suggestions' => explode('|', $suggestions)];
}

echo json_encode([
    'success' => true,
    'spelling' => $spelling,
    'sentiment_score' => (float) ($find('SCORE', '-?[\d.]+') ?? 0),
    'dominant_emotion' => $emotion,
    'emotions' => $list('EMOS'),
    'entities' => array_map(
        static fn (string $name): array => ['name' => $name, 'type' => 'person'],
        $list('ENT')
    ),
    'analysis' => $analysis,
    'model_trained' => !str_contains($text, 'UNTRAINED'),
    'trained_tasks' => str_contains($text, 'UNTRAINED') ? []
        : ($list('TRAINED') ?: ['sentiment', 'emotion', 'irony', 'distortion', 'distortion_types', 'risk']),
], JSON_UNESCAPED_UNICODE);
