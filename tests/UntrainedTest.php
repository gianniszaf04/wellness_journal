<?php
declare(strict_types=1);

use App\Services\AiClient;

// μια πληρης απαντηση του AI service, με οτι model_trained/trained_tasks δοθουν
function ai_reply(array $extra): array
{
    return $extra + [
        'success' => true,
        'sentiment_score' => -0.7,
        'dominant_emotion' => 'Λύπη',
        'emotions' => ['Λύπη'],
        'entities' => [['name' => 'Μαρία', 'type' => 'person']],
        'analysis' => [
            'sentiment' => ['score' => -0.7],
            'emotion' => ['probabilities' => ['Λύπη' => 0.9]],
            'irony' => ['probability' => 0.8],
            'distortion' => ['probability' => 0.8, 'types' => ['Καταστροφολογία'], 'type_probabilities' => ['Καταστροφολογία' => 0.9]],
            'risk' => ['probability' => 0.9],
        ],
    ];
}

test('AiClient::withoutUntrained κρατά μόνο τα εκπαιδευμένα tasks', function (): void {
    putenv('AI_ALLOW_UNTRAINED');
    $all = ['sentiment', 'emotion', 'irony', 'distortion', 'distortion_types', 'risk'];

    $none = AiClient::withoutUntrained(ai_reply(['model_trained' => false, 'trained_tasks' => []]));
    assert_same([null, null, null], [$none['sentiment_score'] ?? null, $none['dominant_emotion'] ?? null, $none['emotions'] ?? null], 'ανεκπαίδευτο: sentiment/emotion');
    assert_same([null, null, null], [AiClient::probability($none, 'irony'), AiClient::probability($none, 'distortion'), AiClient::probability($none, 'risk')], 'ανεκπαίδευτο: irony/distortion/risk');
    assert_same('Μαρία', $none['entities'][0]['name'], 'τα entities μένουν');

    $missing = AiClient::withoutUntrained(ai_reply([]));
    assert_same(null, $missing['sentiment_score'] ?? null, 'χωρίς τα πεδία (παλιό service) = ανεκπαίδευτο');

    $flagOnly = AiClient::withoutUntrained(ai_reply(['model_trained' => false, 'trained_tasks' => $all]));
    assert_same(null, $flagOnly['sentiment_score'] ?? null, 'model_trained=false υπερισχύει');

    $partial = AiClient::withoutUntrained(ai_reply(['model_trained' => true, 'trained_tasks' => ['sentiment', 'distortion']]));
    assert_same([-0.7, null], [$partial['sentiment_score'] ?? null, $partial['dominant_emotion'] ?? null], 'μόνο sentiment');
    assert_same([0.8, null], [AiClient::probability($partial, 'distortion'), $partial['analysis']['distortion']['types'] ?? null], 'distortion χωρίς τύπους');

    $full = AiClient::withoutUntrained(ai_reply(['model_trained' => true, 'trained_tasks' => $all]));
    assert_same(ai_reply(['model_trained' => true, 'trained_tasks' => $all]), $full, 'εκπαιδευμένο: τίποτα δεν σβήνεται');

    putenv('AI_ALLOW_UNTRAINED=1');
    try {
        $allowed = AiClient::withoutUntrained(ai_reply(['model_trained' => false, 'trained_tasks' => []]));
        assert_same(-0.7, $allowed['sentiment_score'] ?? null, 'AI_ALLOW_UNTRAINED=1 κρατά τα πάντα');
    } finally {
        putenv('AI_ALLOW_UNTRAINED');
    }
});

test('καταγραφή με ανεκπαίδευτο μοντέλο: χωρίς sentiment και συναισθήματα, με entities και έλεγχο κρίσης', function (): void {
    FakeAi::start();
    $user = make_user();
    $post = fn (string $body): array => $user->http->api('POST', 'api/entries', ['body' => $body])->json();

    $r = $post('UNTRAINED SCORE=-0.9 EMO=Λύπη EMOS=Λύπη,Άγχος IRONY=0.9 DIST=0.9 TYPES=Καταστροφολογία RISK=0.9 ENT=Μαρία κακή μέρα');
    $d = $r['data'];
    assert_same(true, $r['ai_available'], 'το AI απάντησε');
    assert_same([null, null, null, null], [$d['sentiment_score'], $d['emotion_name'], $d['irony_probability'], $d['distortion_probability']], 'τίποτα από τα heads');
    assert_same([null, null], [$r['message'], $r['support']], 'χωρίς μήνυμα και χωρίς ψεύτικο συναγερμό από το risk');

    $count = fn (string $sql): int => (int) db()->query(str_replace(':id', (string) (int) $d['id'], $sql))->fetchColumn();
    assert_same(1, $count('SELECT COUNT(*) FROM entry_entities WHERE entry_id = :id AND entity_name = "Μαρία"'), 'entity αποθηκεύτηκε');
    assert_same(0, $count('SELECT COUNT(*) FROM entry_emotions WHERE entry_id = :id'), 'χωρίς entry_emotions');
    assert_same(0, $count('SELECT COUNT(*) FROM entry_distortions WHERE entry_id = :id'), 'χωρίς entry_distortions');
    assert_same(0, (int) db()->query('SELECT COUNT(*) FROM insights WHERE user_id = ' . $user->id)->fetchColumn(), 'χωρίς warning insight');

    assert_same('crisis', $post('UNTRAINED SCORE=0.9 Δεν θέλω άλλο να ζω')['support']['level'] ?? null, 'οι λέξεις-κλειδιά δουλεύουν');

    $partial = $post('TRAINED=sentiment SCORE=-0.4 EMO=Λύπη καταγραφή')['data'];
    assert_same([-0.4, null], [(float) $partial['sentiment_score'], $partial['emotion_name']], 'εκπαιδευμένο μόνο το sentiment');
});

test('ζωντανή ανάλυση με ανεκπαίδευτο μοντέλο', function (): void {
    FakeAi::start();
    $user = make_user();
    $live = fn (string $text): array => $user->http->api('POST', 'api/sentiment/live', ['text' => $text])->json();

    $d = $live('UNTRAINED SCORE=-0.9 EMO=Λύπη IRONY=0.9 DIST=0.9 RISK=0.9 αρκετά μεγάλο κείμενο')['data'];
    assert_same([null, null, null, null], [$d['sentiment_score'], $d['emotion'], $d['irony'], $d['distortion']], 'κενά πεδία');
    assert_same([[], []], [$d['emotions'], $d['distortion_types']], 'κενές λίστες');

    assert_same('crisis', $live('UNTRAINED αρκετά μεγάλο κείμενο, θέλω να πεθάνω')['support']['level'] ?? null, 'λέξεις-κλειδιά');
});
